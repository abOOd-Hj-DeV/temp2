<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Billing\PaymentReviewService;
use App\Services\NotificationService;
use App\Services\Patient\AccountAnonymizer;
use App\Services\Session\SessionService;
use App\Services\Subscription\SubscriptionService;
use App\Services\Therapist\TherapistSwitchService;
use App\Services\Wallet\WalletService;
use Database\Seeders\PackageSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingRepairRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(8, 0, 37));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('booking-proofs');
        config(['sakina.uploads_disk' => 'booking-proofs', 'sakina.package_policy.allow_pay_per_session' => false]);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('deliver')->zeroOrMoreTimes());
    }

    private function user(string $role, string $timezone = 'UTC'): User
    {
        $n = ++$this->sequence;

        return User::create([
            'name' => "Synthetic {$n}", 'email' => "booking{$n}@example.test", 'password' => 'local-test',
            'whatsapp_number' => '+96390000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => $timezone,
        ]);
    }

    private function patient(string $timezone = 'UTC'): Patient
    {
        $user = $this->user('patient', $timezone);

        return Patient::create(['user_id' => $user->id, 'full_name' => $user->name, 'age' => 30, 'gender' => 'other', 'language' => 'en']);
    }

    private function therapist(int $limit = 20, string $timezone = 'UTC'): Therapist
    {
        $user = $this->user('therapist', $timezone);

        return Therapist::create([
            'user_id' => $user->id, 'full_name' => $user->name, 'specialty' => 'cbt', 'country' => 'JO',
            'clients_limit' => $limit, 'approval_status' => 'approved',
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-23:00']),
        ]);
    }

    private function subscription(Patient $patient, Therapist $therapist, array $extra = []): Subscription
    {
        return Subscription::create(array_merge([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapist->user_id, 'type' => '4_weeks',
            'verification_status' => 'approved', 'start_date' => '2026-01-01', 'end_date' => '2026-01-30',
            'price' => 150, 'sessions_total' => 4, 'daily_sessions_quota' => 1, 'duration_days' => 28,
        ], $extra));
    }

    private function book(Patient $patient, Therapist $therapist, string $date = '2026-01-03', string $time = '10:00'): TherapySession
    {
        return app(SessionService::class)->book($patient, [
            'therapist_id' => $therapist->user_id, 'session_date' => $date, 'session_time' => $time, 'medium' => 'meet',
        ]);
    }

    private function rejected(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected a safe validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function switchRequest(Patient $patient, Therapist $target): TherapistSwitch
    {
        $service = app(TherapistSwitchService::class);
        $request = $service->request($patient, $target->user_id, 'Synthetic preference change', $patient->user);

        return $service->therapistDecide($request, true, $target->user);
    }

    public function test_b1_cancelled_package_is_not_reused_by_a_stale_patient_and_booking(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $subscription = $this->subscription($patient, $therapist);
        $booked = $this->book($patient, $therapist);
        $stale = $patient->fresh();
        app(SubscriptionService::class)->cancel($subscription, $patient->user);
        $this->assertSame(SessionStatus::CANCELLED, $booked->fresh()->status);
        $this->assertNull(app(SubscriptionRepositoryInterface::class)->activeForPatient($patient->user_id));
        $this->assertFalse($subscription->fresh()->is_active);
        // The trial remains usable, but must never reference the cancelled package.
        $trial = $this->book($stale, $therapist, '2026-01-04');
        $this->assertNull($trial->subscription_id);
        $this->rejected(fn () => $this->book($stale, $therapist, '2026-01-05'), 'subscription');
    }

    public function test_b2_switch_assignment_reserves_capacity_for_booking_and_cancellation(): void
    {
        $old = $this->therapist();
        $target = $this->therapist(1);
        $patient = $this->patient();
        $this->subscription($patient, $old);
        $this->book($patient, $old, '2026-01-05');
        $switch = $this->switchRequest($patient->fresh(), $target);
        app(TherapistSwitchService::class)->decide($switch, true, $this->user('clinical_supervisor'));
        $this->assertSame(1, $target->fresh()->clients_count);
        $this->assertFalse($target->fresh()->can_accept_new_clients);
        $this->rejected(fn () => $this->book($this->patient(), $target), 'therapist_id');
        $session = $this->book($patient->fresh(), $target);
        $this->assertSame(1, $target->fresh()->clients_count);
        app(SessionService::class)->cancel($session, $target->user);
        $this->assertSame(1, $target->fresh()->clients_count);
        $this->assertSame(1, $target->reservedClients()->count());
    }

    public function test_b2_real_reservations_are_counted_even_without_assignment_or_cached_count(): void
    {
        $therapist = $this->therapist(1);
        $first = $this->patient();
        $this->book($first, $therapist);
        $first->update(['therapist_id' => null]);
        $therapist->update(['clients_count' => 0]);
        $this->rejected(fn () => $this->book($this->patient(), $therapist, '2026-01-04'), 'therapist_id');
        config(['sakina.package_policy.allow_pay_per_session' => true]);
        $this->book($first->fresh(), $therapist, '2026-01-04');
        $this->assertSame(1, $therapist->fresh()->clients_count);
    }

    public function test_b3_b4_rescheduling_invalidates_old_attendance_and_stale_route_models(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $this->subscription($patient, $therapist);
        $service = app(SessionService::class);
        $session = $service->confirm($this->book($patient, $therapist), $therapist->user);
        $service->requestReschedule($session, $patient->user, '2026-01-05', '10:00');
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0));
        $stale = $service->confirmAttendance($session, $patient->user);
        $moved = $service->decideReschedule($session, $therapist->user, true);
        $this->assertNull($moved->attendance_confirmed_at);
        $this->assertSame(2, $moved->schedule_version);
        $this->assertSame($stale->attendance_confirmed_at->toISOString(), $moved->schedule_history[0]['attendance_confirmed_at']);
        $this->rejected(fn () => $service->complete($stale, $therapist->user, 'Never delivered'), 'status');
        $this->rejected(fn () => $service->confirmAttendance($stale, $patient->user), 'attendance');
        $this->assertSame(0.0, app(WalletService::class)->summary($therapist)['earned']);
        $this->travelTo(now()->setDate(2026, 1, 5)->setTime(10, 0));
        $this->rejected(fn () => $service->complete($stale, $therapist->user, 'Still not attended'), 'attendance');
        $service->confirmAttendance($stale, $patient->user);
        $completed = $service->complete($stale, $therapist->user, 'Actually delivered');
        $this->assertSame(SessionStatus::COMPLETED, $completed->status);
        $this->assertSame(2, $completed->attendance_schedule_version);
        $this->assertSame(30.0, app(WalletService::class)->summary($therapist)['earned']);
    }

    public function test_b4_owner_and_state_are_reloaded_not_trusted_from_route_models(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $session = $this->book($patient, $therapist);
        $service = app(SessionService::class);
        $stale = clone $session;
        $stale->therapist_id = $this->therapist()->user_id;
        $this->rejected(fn () => $service->confirm($stale, User::findOrFail($stale->therapist_id)), 'session');
        $confirmed = $service->confirm($stale, $therapist->user);
        $this->assertSame(SessionStatus::CONFIRMED, $confirmed->status);
        $forgedPatient = clone $confirmed;
        $forgedPatient->patient_id = $this->patient()->user_id;
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0));
        try {
            $service->confirmAttendance($forgedPatient, User::findOrFail($forgedPatient->patient_id));
            $this->fail('A stale owner must not confirm attendance.');
        } catch (AuthorizationException) {
            $this->assertNull($confirmed->fresh()->attendance_confirmed_at);
        }
        $service->cancel($confirmed, $therapist->user);
        $this->expectException(ConflictException::class);
        $service->complete($confirmed, $therapist->user, 'Cancelled');
    }

    public function test_b4_reschedule_request_rechecks_current_cancellation_notice(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $stale = $this->book($patient, $therapist);
        $stale->update(['session_date' => '2026-01-01', 'session_time' => '12:00']);
        $stale->session_date = '2026-01-03';
        $this->rejected(fn () => app(SessionService::class)->requestReschedule($stale, $patient->user, '2026-01-05', '10:00'), 'status');
    }

    public function test_b4_completion_requires_current_attendance_version_and_link_checks_current_state(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $service = app(SessionService::class);
        $session = $service->confirm($this->book($patient, $therapist), $therapist->user);
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0, 37));
        $session = $service->confirmAttendance($session, $patient->user);
        foreach ([null, 0] as $version) {
            $session->update(['attendance_schedule_version' => $version]);
            $this->rejected(fn () => $service->complete($session, $therapist->user, 'Invalid version'), 'attendance');
        }
        $session->update(['attendance_schedule_version' => 1, 'attendance_confirmed_at' => now()->subHour()]);
        $this->rejected(fn () => $service->complete($session, $therapist->user, 'Old timestamp'), 'attendance');
        $session->update(['attendance_confirmed_at' => now()]);
        $service->complete($session, $therapist->user, 'Valid attendance');
        $this->rejected(fn () => $service->setLink($session, $therapist->user, 'https://meet.google.com/synthetic'), 'status');
    }

    public function test_b5_final_switch_rechecks_48h_window_and_accepts_after_it_clears(): void
    {
        $patient = $this->patient();
        $old = $this->therapist();
        $target = $this->therapist();
        $this->subscription($patient, $old);
        $this->book($patient, $old, '2026-01-05');
        $request = $this->switchRequest($patient->fresh(), $target);
        $soon = $this->book($patient->fresh(), $old, '2026-01-02');
        $admin = $this->user('clinical_supervisor');
        $service = app(TherapistSwitchService::class);
        $this->rejected(fn () => $service->decide($request, true, $admin), 'therapist');
        $this->assertSame(SessionStatus::PENDING, $soon->fresh()->status);
        $this->assertSame($old->user_id, $patient->fresh()->therapist_id);
        app(SessionService::class)->cancel($soon, $old->user);
        $this->assertSame('approved', $service->decide($request, true, $admin)->status);
    }

    public function test_b5_final_switch_rejects_cancelled_or_expired_package(): void
    {
        foreach (['cancelled', 'expired'] as $condition) {
            $patient = $this->patient();
            $old = $this->therapist();
            $target = $this->therapist();
            $subscription = $this->subscription($patient, $old);
            $this->book($patient, $old, '2026-01-05');
            $request = $this->switchRequest($patient->fresh(), $target);
            if ($condition === 'cancelled') {
                app(SubscriptionService::class)->cancel($subscription, $patient->user);
            } else {
                $subscription->update(['end_date' => '2025-12-31']);
            }
            $this->rejected(fn () => app(TherapistSwitchService::class)->decide($request, true, $this->user('clinical_supervisor')), 'subscription');
            $this->assertSame($old->user_id, $patient->fresh()->therapist_id);
            $this->assertSame('requested', $request->fresh()->status);
        }
    }

    public function test_b5_final_switch_rechecks_target_capacity_approval_and_package_identity(): void
    {
        $patient = $this->patient();
        $old = $this->therapist();
        $target = $this->therapist(1);
        $subscription = $this->subscription($patient, $old);
        $this->book($patient, $old, '2026-01-05');
        $request = $this->switchRequest($patient->fresh(), $target);
        $admin = $this->user('clinical_supervisor');
        $service = app(TherapistSwitchService::class);
        $target->update(['approval_status' => 'rejected']);
        $this->rejected(fn () => $service->decide($request, true, $admin), 'therapist');
        $target->update(['approval_status' => 'approved']);
        $reservation = $this->patient();
        $reservation->update(['therapist_id' => $target->user_id]);
        $this->rejected(fn () => $service->decide($request, true, $admin), 'therapist');
        $reservation->update(['therapist_id' => null]);
        app(SubscriptionService::class)->cancel($subscription, $patient->user);
        $this->subscription($patient, $old);
        $this->rejected(fn () => $service->decide($request, true, $admin), 'subscription');
        $this->assertSame('requested', $request->fresh()->status);
        $this->assertSame($old->user_id, $patient->fresh()->therapist_id);
    }

    public function test_b6_cancelled_pending_subscription_allows_repurchase_but_not_old_approval(): void
    {
        $this->seed(PackageSeeder::class);
        $patient = $this->patient();
        $service = app(SubscriptionService::class);
        $first = $service->createWithProof($patient, 'placeholder_starter_4', UploadedFile::fake()->image('first.png'));
        $service->cancel($first['subscription'], $patient->user);
        $second = $service->createWithProof($patient, 'placeholder_starter_4', UploadedFile::fake()->image('second.png'));
        $this->assertNotSame($first['subscription']->id, $second['subscription']->id);
        $this->assertFalse($first['subscription']->fresh()->is_active);
        $review = app(PaymentReviewService::class);
        $reviewer = $this->user('finance_partner');
        try {
            $review->review($first['payment'], $reviewer, 'approve', null);
            $this->fail('Cancelled payment approval must be rejected.');
        } catch (ConflictException) {
            $this->assertSame('pending', $first['subscription']->fresh()->verification_status);
        }
        $review->review($second['payment'], $reviewer, 'approve', null);
        $this->assertTrue($second['subscription']->fresh()->is_active);
        $this->rejected(fn () => $service->createWithProof($patient, 'placeholder_starter_4', UploadedFile::fake()->image('duplicate.png')), 'subscription');
    }

    public function test_b7_package_lookup_booking_and_wallet_use_patient_local_last_day(): void
    {
        $patient = $this->patient('America/Los_Angeles');
        $therapist = $this->therapist(20, 'America/Los_Angeles');
        $subscription = $this->subscription($patient, $therapist);
        $this->travelTo(now('UTC')->setDate(2026, 1, 31)->setTime(0, 30));
        $this->assertTrue($subscription->fresh()->is_active);
        $this->assertSame($subscription->id, app(SubscriptionRepositoryInterface::class)->activeForPatient($patient->user_id)?->id);
        $session = $this->book($patient, $therapist, '2026-01-30', '17:00');
        $this->assertSame($subscription->id, $session->subscription_id);
        $this->assertSame('0.00', $session->price);
        $this->assertSame('2026-01-31', $session->session_date->toDateString());
        $this->assertSame('01:00', substr($session->session_time, 0, 5));
        $this->assertSame(120.0, app(WalletService::class)->summary($therapist)['pending_earnings']);
        $this->travelTo(now('UTC')->setTime(8, 0));
        $this->assertFalse($subscription->fresh()->is_active);
        $this->assertNull(app(SubscriptionRepositoryInterface::class)->activeForPatient($patient->user_id));
        $this->assertSame(0.0, app(WalletService::class)->summary($therapist)['pending_earnings']);
    }

    public function test_b7_invalid_local_dst_time_is_not_silently_shifted(): void
    {
        $patient = $this->patient('America/Los_Angeles');
        $therapist = $this->therapist(20, 'America/Los_Angeles');
        $this->rejected(fn () => $this->book($patient, $therapist, '2026-03-08', '02:00'), 'session_date');
        $valid = $this->book($patient, $therapist, '2026-03-08', '03:00');
        $this->assertSame('10:00', substr($valid->session_time, 0, 5));
    }

    public function test_b8_report_revisions_preserve_previous_body_and_hash_in_one_transaction(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $service = app(SessionService::class);
        $session = $service->confirm($this->book($patient, $therapist), $therapist->user);
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0));
        $service->confirmAttendance($session, $patient->user);
        $stale = $service->complete($session, $therapist->user, 'Original synthetic report');
        $service->report($stale, $therapist->user, 'Second synthetic report');
        $final = $service->report($stale, $therapist->user, 'Third synthetic report');
        $this->assertSame(2, $final->report_revision);
        $history = DB::table('booking_report_revisions')->where('session_id', $session->id)->orderBy('revision')->get();
        $this->assertCount(2, $history);
        $this->assertSame('Original synthetic report', $history[0]->previous_summary);
        $this->assertSame('Second synthetic report', $history[0]->new_summary);
        $this->assertSame($history[0]->new_summary, $history[1]->previous_summary);
        $log = AuditLog::where('entity_id', $session->id)->where('action', 'session.report_revised')->get()->firstWhere('details.revision', 1);
        $this->assertSame(hash('sha256', 'Original synthetic report'), $log->details['previous_sha256']);
        $this->assertSame(hash('sha256', 'Second synthetic report'), $log->details['new_sha256']);
        DB::beginTransaction();
        try {
            DB::table('booking_report_revisions')->where('id', $history[0]->id)->update(['previous_summary' => 'Tampered']);
            $this->fail('Stored revision body must be immutable.');
        } catch (QueryException) {
            DB::rollBack();
            $this->assertSame('Original synthetic report', DB::table('booking_report_revisions')->where('id', $history[0]->id)->value('previous_summary'));
        }
        DB::beginTransaction();
        try {
            DB::table('booking_report_revisions')->where('session_id', $session->id)->delete();
            $this->fail('Stored revisions cannot be deleted independently.');
        } catch (QueryException) {
            DB::rollBack();
            $this->assertSame(2, DB::table('booking_report_revisions')->where('session_id', $session->id)->count());
        }
        DB::table('therapy_sessions')->where('id', $session->id)->delete();
        $this->assertSame(0, DB::table('booking_report_revisions')->where('session_id', $session->id)->count());
    }

    public function test_b8_revision_bodies_follow_existing_patient_anonymization(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $service = app(SessionService::class);
        $session = $service->confirm($this->book($patient, $therapist), $therapist->user);
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0));
        $service->confirmAttendance($session, $patient->user);
        $service->report($session, $therapist->user, 'Original identifier: '.$patient->full_name);
        $service->report($session, $therapist->user, 'Current summary has no identifiers.');
        $this->assertSame(1, DB::table('booking_report_revisions')->where('session_id', $session->id)->count());
        app(AccountAnonymizer::class)->anonymize($patient->user);
        $this->assertNotNull($patient->user->fresh()->anonymized_at);
        $this->assertSame('Current summary has no identifiers.', $session->fresh()->summary);
        $this->assertSame(0, DB::table('booking_report_revisions')->where('session_id', $session->id)->count());
        $service->report($session, $therapist->user, 'Subsequent synthetic clinical report');
        $record = DB::table('booking_report_revisions')->where('session_id', $session->id)->sole();
        $this->assertNull($record->previous_summary);
        $this->assertNull($record->new_summary);
        $this->assertSame(hash('sha256', 'Subsequent synthetic clinical report'), $record->new_sha256);
    }
}
