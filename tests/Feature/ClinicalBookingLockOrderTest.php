<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendSessionRemindersJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\Billing\PaymentReviewService;
use App\Services\Files\AccountFileFence;
use App\Services\NotificationService;
use App\Services\Session\BookingLocks;
use App\Services\Session\SessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\ClinicalCorrectionFixtures;
use Tests\TestCase;

class ClinicalBookingLockOrderTest extends TestCase
{
    use ClinicalCorrectionFixtures;

    private function owners(): array
    {
        $attributes = ['password' => 'Synthetic password', 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC'];
        $tu = User::create($attributes + ['id' => '10000000-0000-4000-8000-000000000001', 'name' => 'Synthetic therapist',
            'email' => 'therapist-order@example.invalid', 'whatsapp_number' => '+15550001001', 'role' => 'therapist']);
        $pu = User::create($attributes + ['id' => 'f0000000-0000-4000-8000-000000000002', 'name' => 'Synthetic patient',
            'email' => 'patient-order@example.invalid', 'whatsapp_number' => '+15550001002', 'role' => 'patient']);
        $t = Therapist::create(['user_id' => $tu->id, 'full_name' => $tu->name, 'specialty' => 'General', 'country' => 'US',
            'approval_status' => 'approved', 'clients_limit' => 20, 'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-23:00'])]);
        $p = Patient::create(['user_id' => $pu->id, 'full_name' => $pu->name, 'age' => 25, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $tu->id]);
        $this->assertTrue(strcmp($tu->id, $pu->id) < 0, 'Therapist UUID must sort before patient to expose the original inversion.');

        return [$pu, $p, $tu, $t];
    }

    private function clinicalSession(User $pu, User $tu, string $operation, ?Subscription $subscription = null): TherapySession
    {
        return TherapySession::create(['patient_id' => $pu->id, 'therapist_id' => $tu->id, 'subscription_id' => $subscription?->id,
            'session_date' => $operation === 'report' ? '2026-10-02' : '2026-10-03', 'session_time' => '12:55', 'medium' => 'meet',
            'status' => match ($operation) {
                'report' => 'completed', 'reminder' => 'confirmed', default => 'pending'
            },
            'price' => 50, 'payment_status' => in_array($operation, ['proof', 'review']) ? 'pending' : 'paid']);
    }

    private function unavailable(User $user, string $state): void
    {
        match ($state) {
            'inactive' => $user->update(['is_active' => false]),
            'anonymized' => $user->forceFill(['anonymized_at' => now()])->save(),
            'plan' => DB::table('clinical_erasure_plans')->insert(['user_id' => $user->id, 'paths' => '[]', 'directories' => '[]', 'created_at' => now(), 'updated_at' => now()]),
        };
    }

    public static function unavailableStates(): array
    {
        return [['inactive'], ['anonymized'], ['plan']];
    }

    #[DataProvider('unavailableStates')]
    public function test_internal_patient_availability_default_is_false_and_explicit_true_refuses(string $state): void
    {
        [$pu, $p] = $this->owners();
        $this->unavailable($pu, $state);
        $this->assertSame($p->user_id, DB::transaction(fn () => BookingLocks::patient($pu->id))->user_id);
        $this->expectException(AccessDeniedHttpException::class);
        DB::transaction(fn () => BookingLocks::patient($pu->id, true));
    }

    #[DataProvider('unavailableStates')]
    public function test_internal_session_default_keeps_locked_therapist_user_relation_but_explicit_true_refuses(string $state): void
    {
        [$pu, $p, $tu, $t] = $this->owners();
        $session = $this->clinicalSession($pu, $tu, 'report');
        $this->unavailable($tu, $state);
        DB::transaction(function () use ($session, $t, $tu) {
            $this->assertSame($session->id, BookingLocks::session($session->id)->id);
            $locked = BookingLocks::therapists([$t->user_id])->firstOrFail();
            $this->assertTrue($locked->relationLoaded('user'));
            $this->assertSame($tu->fresh()->getAttributes(), $locked->user->getAttributes());
        });
        $this->expectException(AccessDeniedHttpException::class);
        DB::transaction(fn () => BookingLocks::session($session->id, true));
    }

    public static function clinicalOwnerOperations(): array
    {
        $cases = [];
        foreach (['patient', 'therapist'] as $owner) {
            foreach (['book', 'report', 'proof', 'reminder'] as $operation) {
                $cases[] = [$owner, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('clinicalOwnerOperations')]
    public function test_explicit_clinical_writes_recheck_both_owner_erasure_plans_before_any_mutation(string $owner, string $operation): void
    {
        Storage::fake('audit');
        [$pu, $p, $tu, $t] = $this->owners();
        $session = $this->clinicalSession($pu, $tu, $operation);
        $before = DB::table('therapy_sessions')->get()->toJson();
        $this->unavailable($owner === 'patient' ? $pu : $tu, 'plan');
        try {
            match ($operation) {
                'book' => app(SessionService::class)->book($p, ['therapist_id' => $tu->id, 'session_date' => '2026-10-05', 'session_time' => '14:00', 'medium' => 'meet']),
                'report' => app(SessionService::class)->report($session, $tu, 'Must not write'),
                'proof' => app(PaymentReviewService::class)->submitSessionProof($session, UploadedFile::fake()->create('synthetic.pdf', 1, 'application/pdf')),
                'reminder' => app(SendSessionRemindersJob::class)->handle(app(SessionRepositoryInterface::class), app(NotificationService::class)),
            };
            $this->assertSame('reminder', $operation, 'User-facing writes must explicitly refuse unavailable owners.');
        } catch (AccessDeniedHttpException $e) {
            $this->assertNotSame('reminder', $operation);
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, DB::table('therapy_sessions')->get()->toJson());
        foreach (['payments', 'booking_report_revisions', 'ops_session_reminders', 'notifications', 'ops_notification_outbox', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame([], Storage::disk('audit')->allFiles());
    }

    public static function canonicalRaces(): array
    {
        $cases = [];
        foreach (['book', 'report', 'proof', 'review', 'reminder'] as $operation) {
            foreach ([true, false] as $cancelFirst) {
                $cases[] = [$operation, $cancelFirst];
            }
        }

        return $cases;
    }

    private function worker(array $payload, bool $pause): Process
    {
        $connection = config('database.connections.pgsql');

        return new Process([PHP_BINARY, base_path('tests/Fixtures/ClinicalBookingRaceWorker.php'), json_encode($payload + ['pause' => $pause])], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'CLINICAL_TEST_STORAGE' => Storage::disk('audit')->path(''),
        ], timeout: 25);
    }

    private function assertCanonicalTrace(string $output, bool $hasSession): void
    {
        $markers = ['owner:patient', 'row:patients', 'row:subscriptions', 'owner:therapist', 'row:therapists'];
        if ($hasSession) {
            $markers[] = 'row:therapy_sessions';
        }
        if (str_contains($output, 'row:payments')) {
            $markers[] = 'row:payments';
        }
        $last = -1;
        foreach ($markers as $marker) {
            $index = strpos($output, $marker);
            $this->assertNotFalse($index, $output);
            $this->assertGreaterThan($last, $index, 'First-acquisition order: '.$output);
            $last = $index;
        }
    }

    #[DataProvider('canonicalRaces')]
    public function test_actual_pg_low_uuid_therapist_cancellation_races_follow_patient_first_in_both_orders(string $operation, bool $cancelFirst): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent row-lock processes require disposable PostgreSQL.');
        }
        Storage::fake('audit');
        [$pu, $p, $tu, $t] = $this->owners();
        $subscription = Subscription::create(['patient_id' => $pu->id, 'therapist_id' => $tu->id, 'type' => '4_weeks', 'price' => 150,
            'verification_status' => 'approved', 'sessions_total' => 4, 'daily_sessions_quota' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-30']);
        $session = $operation === 'book' ? null : $this->clinicalSession($pu, $tu, $operation, $subscription);
        $payment = null;
        if ($operation === 'review') {
            $this->user(UserRole::ADMIN);
            $payment = Payment::create(['therapy_session_id' => $session->id, 'amount' => 50, 'status' => 'pending', 'proof_file_path' => 'synthetic-unused.pdf']);
        }
        $payload = ['patient' => $pu->id, 'therapist' => $tu->id, 'subscription' => $subscription->id, 'session' => $session?->id, 'payment' => $payment?->id];
        $input = new InputStream;
        $a = $this->worker($payload + ['operation' => $cancelFirst ? 'cancel' : $operation], true);
        $b = $this->worker($payload + ['operation' => $cancelFirst ? $operation : 'cancel'], false);
        $a->setInput($input);
        try {
            $a->start();
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), 'holding_patient')), $a->getErrorOutput());
            $b->start();
            $this->assertTrue($b->waitUntil(fn () => str_contains($b->getOutput(), 'ready:')), $b->getErrorOutput());
            preg_match('/ready:(\d+)/', $a->getOutput(), $first);
            preg_match('/ready:(\d+)/', $b->getOutput(), $second);
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [(int) $first[1], (int) $second[1]])->blocked;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Second process must wait on the first patient owner, not hold a lower UUID therapist first.');
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $this->assertSame(0, $b->wait(), $b->getErrorOutput());
            $outputs = [$cancelFirst ? 'cancel' : $operation => $a->getOutput(), $cancelFirst ? $operation : 'cancel' => $b->getOutput()];
            $this->assertCanonicalTrace($outputs['cancel'], false);
            $this->assertCanonicalTrace($outputs[$operation], $operation !== 'book');
            $this->assertStringContainsString('result:cancel', $outputs['cancel']);
            $this->assertFalse($subscription->fresh()->is_active);
            $this->assertNotNull($subscription->fresh()->cancelled_at);
            if ($operation === 'book') {
                $saved = TherapySession::sole();
                $this->assertSame($cancelFirst ? null : $subscription->id, $saved->subscription_id);
                $this->assertSame($cancelFirst ? 'pending' : 'cancelled', $saved->status->value);
            } elseif ($operation === 'report') {
                $this->assertSame('Canonical clinical report', $session->fresh()->summary);
                $this->assertStringStartsWith('clinical:v1:', $session->fresh()->getRawOriginal('summary'));
                $this->assertSame(1, DB::table('booking_report_revisions')->count());
            } elseif ($operation === 'proof') {
                $this->assertSame($cancelFirst ? 0 : 1, Payment::count());
                $this->assertSame($cancelFirst ? 0 : 1, count(Storage::disk('audit')->allFiles()));
                if ($cancelFirst) {
                    $this->assertStringContainsString('result:rejected:payment_status', $outputs[$operation]);
                }
            } elseif ($operation === 'review') {
                $this->assertSame('rejected', $payment->fresh()->status->value);
            } else {
                $this->assertSame($cancelFirst ? 0 : 1, DB::table('ops_session_reminders')->count());
            }
            $this->observed('pg-canonical-'.$operation.'-'.($cancelFirst ? 'cancel-first' : 'clinical-first'), ['therapist_uuid_lower' => true, 'blocked_on_patient' => $blocked, 'deadlock' => false]);
        } finally {
            $a->stop();
            $b->stop();
        }
    }

    public static function sharedFenceStates(): array
    {
        return [['inactive', true, true], ['inactive', false, false], ['anonymized', true, true], ['anonymized', false, true],
            ['plan', true, true], ['plan', false, true], ['missing', true, true], ['missing', false, true]];
    }

    #[DataProvider('sharedFenceStates')]
    public function test_shared_patient_first_fence_preserves_all_active_erasure_missing_and_duplicate_checks(string $state, bool $requireActive, bool $denied): void
    {
        [$pu, $p, $tu] = $this->owners();
        $ids = [$tu->id, $pu->id, $tu->id];
        if ($state === 'missing') {
            $ids[] = '90000000-0000-4000-8000-000000000099';
        } else {
            $this->unavailable($tu, $state);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'CASE WHEN role')) {
                $queries[] = $query;
            }
        });
        try {
            DB::transaction(fn () => AccountFileFence::lock($ids, $requireActive));
            $this->assertFalse($denied);
        } catch (AccessDeniedHttpException $e) {
            $this->assertTrue($denied);
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('CASE WHEN role = ? THEN 0 ELSE 1 END', $queries[0]->sql);
        $this->assertStringContainsString('"id" asc', $queries[0]->sql);
        $this->assertSame('patient', end($queries[0]->bindings));
    }

    public static function sharedBookingRaces(): array
    {
        return [['chat-open', true], ['chat-open', false], ['chat-send', true], ['chat-send', false], ['download', true], ['download', false]];
    }

    #[DataProvider('sharedBookingRaces')]
    public function test_actual_pg_chat_and_file_stream_fences_race_booking_without_opposite_user_locks(string $operation, bool $bookingFirst): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent Chat/file versus booking processes require disposable PostgreSQL.');
        }
        Storage::fake('audit');
        [$pu, $p, $tu] = $this->owners();
        $path = null;
        if ($operation === 'download') {
            $thread = Conversation::create(['patient_id' => $pu->id, 'therapist_id' => $tu->id, 'status' => 'active']);
            $path = "chat/{$thread->id}/synthetic.pdf";
            Storage::disk('audit')->put($path, 'Synthetic file bytes');
            Message::create(['conversation_id' => $thread->id, 'sender_id' => $tu->id, 'receiver_id' => $pu->id,
                'content' => 'Synthetic attachment', 'file_path' => $path, 'timestamp' => now(), 'is_read' => false]);
        }
        $payload = ['patient' => $pu->id, 'therapist' => $tu->id, 'session' => null, 'subscription' => null, 'payment' => null, 'path' => $path];
        $input = new InputStream;
        $a = $this->worker($payload + ['operation' => $bookingFirst ? 'book' : $operation], true);
        $b = $this->worker($payload + ['operation' => $bookingFirst ? $operation : 'book'], false);
        $a->setInput($input);
        try {
            $a->start();
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), 'holding_patient')), $a->getErrorOutput());
            $b->start();
            $this->assertTrue($b->waitUntil(fn () => str_contains($b->getOutput(), 'ready:')), $b->getErrorOutput());
            preg_match('/ready:(\d+)/', $a->getOutput(), $first);
            preg_match('/ready:(\d+)/', $b->getOutput(), $second);
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [(int) $first[1], (int) $second[1]])->blocked;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'The shared helper and booking must serialize on the patient User.');
            if ($bookingFirst) {
                // PostgreSQL NOWAIT proves the waiting shared query has not locked the therapist first.
                $this->assertSame($tu->id, DB::transaction(fn () => User::whereKey($tu->id)->lock('FOR UPDATE NOWAIT')->firstOrFail())->id);
            }
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $this->assertSame(0, $b->wait(), $b->getErrorOutput());
            $bookOutput = $bookingFirst ? $a->getOutput() : $b->getOutput();
            $sharedOutput = $bookingFirst ? $b->getOutput() : $a->getOutput();
            $this->assertCanonicalTrace($bookOutput, false);
            $this->assertStringContainsString('result:book', $bookOutput);
            $this->assertStringContainsString('result:'.$operation, $sharedOutput);
            $this->assertSame(1, TherapySession::count());
            $this->assertSame(1, Conversation::count());
            if ($operation === 'chat-send') {
                $this->assertSame('Synthetic clinical message', Message::sole()->content);
                $this->assertNotSame('Synthetic clinical message', Message::sole()->getRawOriginal('content'));
            } elseif ($operation === 'download') {
                $this->assertStringContainsString('stream_sha256:'.hash('sha256', 'Synthetic file bytes'), $sharedOutput);
                $this->assertSame('Synthetic file bytes', Storage::disk('audit')->get($path));
            }
            $this->observed('pg-shared-'.$operation.'-'.($bookingFirst ? 'booking-first' : 'shared-first'), ['therapist_uuid_lower' => true, 'blocked_on_patient' => $blocked, 'deadlock' => false]);
        } finally {
            $a->stop();
            $b->stop();
        }
    }
}
