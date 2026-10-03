<?php

namespace Tests\Feature;

use App\Exceptions\ConflictException;
use App\Jobs\SendSessionRemindersJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\NotificationLog;
use App\Models\NotificationOutbox;
use App\Models\Package;
use App\Models\Patient;
use App\Models\Program;
use App\Models\Support;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\NotificationService;
use App\Services\Package\PackageService;
use App\Services\Program\ProgramService;
use App\Services\Support\SupportService;
use App\Services\Wallet\WalletService;
use App\Support\SessionClock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class OpsCorrectiveRegressionTest extends TestCase
{
    use CommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 10:05:00', 'UTC'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->app->forgetInstance('encrypter');
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::create([
            'name' => 'Synthetic '.$role, 'email' => Str::uuid().'@example.invalid',
            'password' => 'synthetic-password', 'whatsapp_number' => '+1555'.random_int(1000000, 9999999),
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
        $user->assignRole($role);

        return $user;
    }

    public static function auditActions(): array
    {
        return array_map(fn ($action) => [$action], [
            'package_create', 'withdrawal', 'program_create', 'program_update', 'program_delete',
            'module_create', 'module_update', 'module_delete', 'support_assign', 'support_status',
        ]);
    }

    private function rejectAudit(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION ops_corrective_reject_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ops_corrective_reject_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION ops_corrective_reject_audit();");
        } else {
            DB::unprepared("CREATE TRIGGER ops_corrective_reject_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END;");
        }
    }

    private function workflow(string $action): array
    {
        $actor = $this->user();
        $program = Program::create(['name' => 'Original', 'description' => 'Synthetic program']);
        $module = $program->modules()->create(['title' => 'Original', 'description' => 'Synthetic', 'content_type' => 'text', 'order' => 1]);
        $programs = app(ProgramService::class);
        if ($action === 'package_create') {
            return [fn () => app(PackageService::class)->create($actor, [
                'code' => 'audit-corrective', 'name' => 'Synthetic', 'price' => 100,
                'number_of_sessions' => 4, 'duration_days' => 28, 'daily_sessions_quota' => 1,
            ]), fn () => Package::where('code', 'audit-corrective')->count(), 0, 1, AuditLogService::PACKAGE_CREATED];
        }
        if ($action === 'withdrawal') {
            [$session, $therapist] = $this->appointment();
            $session->update(['status' => 'completed', 'payment_status' => 'paid', 'price' => 100, 'is_initial' => false, 'attendance_confirmed_at' => now()]);

            return [fn () => app(WalletService::class)->requestWithdrawal($therapist, 20, ['bank' => 'Synthetic'], $therapist->user),
                fn () => WalletWithdrawal::where('therapist_id', $therapist->user_id)->count(), 0, 1, AuditLogService::WITHDRAWAL_REQUESTED];
        }
        if (str_starts_with($action, 'support_')) {
            $ticket = Support::create(['id' => (string) Str::uuid(), 'user_id' => $actor->id, 'type' => 'technical', 'description' => 'Synthetic', 'status' => 'open']);
            $support = app(SupportService::class);

            return $action === 'support_assign'
                ? [fn () => $support->assign($actor, $ticket->id, $actor->id), fn () => $ticket->fresh()->assigned_to, null, $actor->id, AuditLogService::SUPPORT_TICKET_ASSIGNED]
                : [fn () => $support->setStatus($actor, $ticket->id, 'closed'), fn () => $ticket->fresh()->status, 'open', 'closed', AuditLogService::SUPPORT_TICKET_STATUS];
        }

        return match ($action) {
            'program_create' => [fn () => $programs->create($actor, ['name' => 'New', 'description' => 'Synthetic']), fn () => Program::where('name', 'New')->count(), 0, 1, AuditLogService::PROGRAM_CREATED],
            'program_update' => [fn () => $programs->update($actor, $program, ['name' => 'New']), fn () => $program->fresh()->name, 'Original', 'New', AuditLogService::PROGRAM_UPDATED],
            'program_delete' => [fn () => $programs->delete($actor, $program), fn () => Program::whereKey($program->id)->exists(), true, false, AuditLogService::PROGRAM_DELETED],
            'module_create' => [fn () => $programs->addModule($actor, $program, ['title' => 'New', 'description' => 'Synthetic', 'content_type' => 'text']), fn () => Module::where('title', 'New')->count(), 0, 1, AuditLogService::MODULE_CREATED],
            'module_update' => [fn () => $programs->updateModule($actor, $module, ['title' => 'New']), fn () => $module->fresh()->title, 'Original', 'New', AuditLogService::MODULE_UPDATED],
            'module_delete' => [fn () => $programs->deleteModule($actor, $module), fn () => Module::whereKey($module->id)->exists(), true, false, AuditLogService::MODULE_DELETED],
        };
    }

    private function appointment(): array
    {
        $patientUser = $this->user('patient');
        $therapistUser = $this->user('therapist');
        $therapist = Therapist::create(['user_id' => $therapistUser->id, 'full_name' => 'Synthetic therapist', 'specialty' => 'cbt', 'country' => 'JO', 'approval_status' => 'approved']);
        Patient::create(['user_id' => $patientUser->id, 'full_name' => 'Synthetic patient', 'age' => 30, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $therapistUser->id]);

        return [TherapySession::create([
            'patient_id' => $patientUser->id, 'therapist_id' => $therapistUser->id,
            'session_date' => '2026-11-03', 'session_time' => '10:00',
            'status' => 'confirmed', 'payment_status' => 'pending', 'price' => 50, 'is_initial' => false, 'medium' => 'meet',
        ]), $therapist];
    }

    #[DataProvider('auditActions')]
    public function test_required_audit_failure_rolls_back_entire_action_and_savepoint(string $action): void
    {
        [$mutate, $state, $before] = $this->workflow($action);
        $this->rejectAudit();
        DB::beginTransaction();
        try {
            try {
                $mutate();
                $this->fail('Mandatory audit failure must abort the action.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('synthetic audit failure', $exception->getMessage());
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $state());
            $this->assertSame(0, AuditLog::count());
            $this->assertNotEmpty(DB::select('SELECT 1'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $state());
    }

    #[DataProvider('auditActions')]
    public function test_legitimate_action_commits_with_exactly_one_required_audit(string $action): void
    {
        [$mutate, $state, , $after, $auditAction] = $this->workflow($action);
        $mutate();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($after, $state());
        $this->assertSame(1, AuditLog::where('action', $auditAction)->count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_duplicate_package_conflict_preserves_enclosing_postgres_transaction(): void
    {
        $actor = $this->user();
        $data = ['code' => 'duplicate-safe', 'name' => 'Synthetic', 'price' => 100, 'number_of_sessions' => 4, 'duration_days' => 28];
        app(PackageService::class)->create($actor, $data);
        DB::transaction(function () use ($actor, $data): void {
            try {
                app(PackageService::class)->create($actor, $data);
                $this->fail('Duplicate package must be rejected.');
            } catch (ConflictException) {
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(1, Package::where('code', 'duplicate-safe')->count());
                $this->assertNotEmpty(DB::select('SELECT 1'));
            }
        });
        $this->assertSame(1, AuditLog::where('action', AuditLogService::PACKAGE_CREATED)->count());
    }

    private function sender(): CorrectiveWhatsAppSender
    {
        $sender = new CorrectiveWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $sender);

        return $sender;
    }

    private function rejectOutboxCompletion(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION ops_corrective_reject_ack() RETURNS trigger AS $$ BEGIN IF NEW.completed_at IS NOT NULL THEN RAISE EXCEPTION 'synthetic acknowledgement failure'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ops_corrective_reject_ack BEFORE UPDATE ON ops_notification_outbox FOR EACH ROW EXECUTE FUNCTION ops_corrective_reject_ack();");
        } else {
            DB::unprepared("CREATE TRIGGER ops_corrective_reject_ack BEFORE UPDATE ON ops_notification_outbox WHEN NEW.completed_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'synthetic acknowledgement failure'); END;");
        }
    }

    public function test_successful_whatsapp_ledger_survives_ack_failure_and_suppresses_replay(): void
    {
        Queue::fake();
        $sender = $this->sender();
        $recipient = $this->user('patient');
        app(NotificationDispatcher::class)->whatsApp($recipient, 'Synthetic private message', 'acknowledgement-case');
        $outbox = NotificationOutbox::sole();
        $this->rejectOutboxCompletion();
        app(NotificationOutboxService::class)->execute($outbox->id, $outbox->claim_token);
        $this->assertCount(1, $sender->messages);
        $this->assertSame(NotificationLog::STATUS_SENT, NotificationLog::sole()->status);
        $this->assertSame(1, NotificationLog::sole()->attempts);
        $this->assertSame('pending', $outbox->fresh()->status);
        $this->assertSame('delivery_failed', $outbox->fresh()->last_error);
        DB::unprepared(DB::getDriverName() === 'pgsql'
            ? 'DROP TRIGGER ops_corrective_reject_ack ON ops_notification_outbox'
            : 'DROP TRIGGER ops_corrective_reject_ack');
        $this->travelTo(now()->addSeconds(31));
        app(NotificationOutboxService::class)->execute($outbox->id);
        $this->assertCount(1, $sender->messages);
        $this->assertSame(1, NotificationLog::sole()->attempts);
        $this->assertSame('completed', $outbox->fresh()->status);
        $this->assertNull($outbox->fresh()->payload);
    }

    public function test_ledger_channel_and_erasure_are_not_bypassed_by_replay_guard(): void
    {
        $sender = $this->sender();
        $user = $this->user('patient');
        $log = NotificationLog::create(['user_id' => $user->id, 'channel' => 'in_app', 'event' => 'synthetic', 'event_key' => 'wrong-channel', 'status' => 'queued']);
        $job = new SendWhatsAppMessageJob($user->whatsapp_number, 'Synthetic', ['notification_log_id' => $log->id]);
        $job->handle($sender);
        $this->assertSame('queued', $log->fresh()->status);
        $this->assertCount(0, $sender->messages);
        $log->update(['channel' => 'whatsapp']);
        DB::table('clinical_erasure_plans')->insert(['user_id' => $user->id, 'paths' => '[]', 'directories' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $job->handle($sender);
        $this->assertCount(0, $sender->messages);
        $this->assertSame('queued', $log->fresh()->status);
        $log->delete();
        $job->handle($sender);
        $this->assertCount(0, $sender->messages);
    }

    public function test_provider_failure_is_retryable_without_persisting_plaintext_error(): void
    {
        $sender = $this->sender();
        $user = $this->user('patient');
        $log = NotificationLog::create(['user_id' => $user->id, 'channel' => 'whatsapp', 'event' => 'synthetic', 'event_key' => 'provider-retry', 'status' => 'queued']);
        $job = new SendWhatsAppMessageJob($user->whatsapp_number, 'Synthetic private message', ['notification_log_id' => $log->id]);
        $sender->unavailable = true;
        try {
            $job->handle($sender);
            $this->fail('Provider failure must reach the worker.');
        } catch (\RuntimeException) {
            $this->assertSame('queued', $log->fresh()->status);
            $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_PROVIDER_BYTES', $log->fresh()->error);
        }
        $sender->unavailable = false;
        $job->handle($sender);
        $job->handle($sender);
        $this->assertCount(1, $sender->messages);
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public static function reminderTimes(): array
    {
        return [['2026-11-03 10:00:00', false], ['2026-11-04 10:00:00', false], ['2026-11-03 09:59:59', true]];
    }

    #[DataProvider('reminderTimes')]
    public function test_reminder_is_sent_only_while_current_appointment_is_upcoming(string $deliveryTime, bool $send): void
    {
        Queue::fake();
        $sender = $this->sender();
        [$session] = $this->appointment();
        $recipient = $session->patient->user;
        app(NotificationDispatcher::class)->whatsApp($recipient, 'Synthetic reminder', 'expiry-case', [
            'session_id' => $session->id, 'schedule_key' => hash('sha256', SessionClock::fromStored($session->session_date, (string) $session->session_time)->startOfMinute()->toIso8601String()), 'window' => '24h',
        ]);
        $outbox = NotificationOutbox::sole();
        $this->travelTo(Carbon::parse($deliveryTime, 'UTC'));
        app(NotificationOutboxService::class)->execute($outbox->id, $outbox->claim_token);
        $this->assertCount($send ? 1 : 0, $sender->messages);
        $this->assertSame($send ? 'sent' : 'skipped', NotificationLog::sole()->status);
        $this->assertSame('completed', $outbox->fresh()->status);
        $this->assertNull($outbox->fresh()->payload);
    }

    public function test_reminder_fence_rejects_pending_erasure_before_claiming_or_staging(): void
    {
        Queue::fake();
        [$session] = $this->appointment();
        DB::table('clinical_erasure_plans')->insert(['user_id' => $session->patient_id, 'paths' => '[]', 'directories' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        (new SendSessionRemindersJob)->handle(app(SessionRepositoryInterface::class), app(NotificationService::class));
        $this->assertDatabaseCount('ops_session_reminders', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
        $this->assertFalse($session->fresh()->reminder_sent);
    }
}

class CorrectiveWhatsAppSender implements WhatsAppSenderInterface
{
    public array $messages = [];

    public bool $unavailable = false;

    public function send(string $to, string $message): bool
    {
        if ($this->unavailable) {
            throw new \RuntimeException('SYNTHETIC_PRIVATE_PROVIDER_BYTES');
        }
        $this->messages[] = [$to, $message];

        return true;
    }
}
