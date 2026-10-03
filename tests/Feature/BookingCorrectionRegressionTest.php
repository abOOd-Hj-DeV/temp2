<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistBlockedPeriod;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\Account\StaffAccountService;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use App\Services\Therapist\TherapistBlockedPeriodService;
use App\Services\Therapist\TherapistService;
use App\Services\Therapist\TherapistSwitchService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class BookingCorrectionRegressionTest extends TestCase
{
    use CommittedDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(8, 0, 37));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('correction-private');
        config(['sakina.uploads_disk' => 'correction-private', 'sakina.package_policy.allow_pay_per_session' => false]);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('deliver')->zeroOrMoreTimes());
    }

    private function user(string $role): User
    {
        $n = ++$this->sequence;

        return User::create([
            'name' => "Synthetic correction {$n}", 'email' => "correction{$n}@example.test", 'password' => 'local-test',
            'whatsapp_number' => '+96391000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
    }

    private function therapist(int $limit = 20): Therapist
    {
        return Therapist::create([
            'user_id' => $this->user('therapist')->id, 'full_name' => 'Synthetic', 'specialty' => 'cbt', 'country' => 'JO',
            'approval_status' => 'approved', 'clients_limit' => $limit,
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-23:00']),
        ]);
    }

    private function patient(?Therapist $therapist = null): Patient
    {
        return Patient::create([
            'user_id' => $this->user('patient')->id, 'full_name' => 'Synthetic', 'age' => 30,
            'gender' => 'other', 'language' => 'en', 'therapist_id' => $therapist?->user_id,
        ]);
    }

    private function subscription(Patient $patient, Therapist $therapist): Subscription
    {
        return Subscription::create([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapist->user_id, 'type' => '4_weeks',
            'verification_status' => 'approved', 'start_date' => '2026-01-01', 'end_date' => '2026-01-30',
            'price' => 150, 'sessions_total' => 4, 'daily_sessions_quota' => 1, 'duration_days' => 28,
        ]);
    }

    private function book(Patient $patient, Therapist $therapist, string $date = '2026-01-05'): TherapySession
    {
        return app(SessionService::class)->book($patient, [
            'therapist_id' => $therapist->user_id, 'session_date' => $date, 'session_time' => '10:00', 'medium' => 'meet',
        ]);
    }

    private function rejected(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected safe validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_disabled_approved_therapist_rejects_booking_but_reactivation_restores_it(): void
    {
        $therapist = $this->therapist();
        $patient = $this->patient();
        $admin = $this->user('super_admin');
        app(StaffAccountService::class)->setActive($admin, $therapist->user, false);
        $this->rejected(fn () => $this->book($patient, $therapist), 'therapist_id');
        $this->assertSame(0, TherapySession::count());
        $this->assertNull($patient->fresh()->therapist_id);
        $this->assertFalse($therapist->fresh()->can_accept_new_clients);
        app(StaffAccountService::class)->setActive($admin, $therapist->user->fresh(), true);
        $this->assertSame($therapist->user_id, $this->book($patient, $therapist)->therapist_id);
    }

    public function test_disabled_switch_target_cannot_move_package_or_cancel_old_appointments(): void
    {
        $old = $this->therapist();
        $target = $this->therapist();
        $patient = $this->patient($old);
        $subscription = $this->subscription($patient, $old);
        $session = $this->book($patient, $old);
        $service = app(TherapistSwitchService::class);
        $switch = $service->request($patient->fresh(), $target->user_id, 'Synthetic preference', $patient->user);
        $switch = $service->therapistDecide($switch, true, $target->user);
        $admin = $this->user('super_admin');
        app(StaffAccountService::class)->setActive($admin, $target->user, false);
        $this->rejected(fn () => $service->decide($switch, true, $this->user('clinical_supervisor')), 'therapist');
        $this->assertSame($old->user_id, $patient->fresh()->therapist_id);
        $this->assertSame($old->user_id, $subscription->fresh()->therapist_id);
        $this->assertSame(SessionStatus::PENDING, $session->fresh()->status);
        $this->assertSame('requested', $switch->fresh()->status);
        app(StaffAccountService::class)->setActive($admin, $target->user->fresh(), true);
        $service->decide($switch->fresh(), true, $this->user('clinical_supervisor'));
        $this->assertSame($target->user_id, $patient->fresh()->therapist_id);
    }

    public function test_completed_history_frees_former_capacity_without_destroying_history_or_revenue(): void
    {
        $old = $this->therapist(1);
        $target = $this->therapist();
        $patient = $this->patient($old);
        $this->subscription($patient, $old);
        $service = app(SessionService::class);
        $session = $service->confirm($this->book($patient, $old, '2026-01-03'), $old->user);
        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(10, 0));
        $session = $service->confirmAttendance($session, $patient->user);
        $session = $service->complete($session, $old->user, 'Synthetic historical report');
        $earned = app(WalletService::class)->summary($old)['earned'];
        $this->assertEquals(30, $earned);
        $switchService = app(TherapistSwitchService::class);
        $switch = $switchService->request($patient->fresh(), $target->user_id, 'Synthetic preference', $patient->user);
        $switch = $switchService->therapistDecide($switch, true, $target->user);
        $switchService->decide($switch, true, $this->user('clinical_supervisor'));
        $this->assertSame(0, $old->reservedClients()->count());
        $this->assertSame(0, $old->fresh()->clients_count);
        $this->assertSame($old->user_id, $this->book($this->patient(), $old)->therapist_id);
        $this->assertSame(SessionStatus::COMPLETED, $session->fresh()->status);
        $this->assertSame('Synthetic historical report', $session->fresh()->summary);
        $this->assertEquals($earned, app(WalletService::class)->summary($old)['earned']);
    }

    public function test_capacity_deduplicates_assignments_and_only_counts_live_reservations(): void
    {
        $therapist = $this->therapist();
        $assigned = $this->patient($therapist);
        $this->book($assigned, $therapist);
        $unassigned = $this->patient();
        $live = $this->book($unassigned, $therapist, '2026-01-06');
        $unassigned->update(['therapist_id' => null]);
        $this->assertSame(2, $therapist->reservedClients()->count());
        $live->update(['status' => 'confirmed']);
        $this->assertSame(2, $therapist->reservedClients()->count());
        $live->update(['status' => 'completed']);
        $this->assertSame(1, $therapist->reservedClients()->count());
        $live->update(['status' => 'cancelled']);
        $this->assertSame(1, $therapist->reservedClients()->count());
        $live->update(['status' => 'pending', 'session_date' => '2025-12-31']);
        $this->assertSame(1, $therapist->reservedClients()->count());
    }

    public function test_in_progress_reservation_survives_utc_midnight_until_its_end(): void
    {
        $therapist = $this->therapist();
        $patient = $this->patient();
        $session = $this->book($patient, $therapist);
        $patient->update(['therapist_id' => null]);
        $session->update(['session_date' => '2026-01-01', 'session_time' => '23:30']);
        $this->travelTo(now('UTC')->setDate(2026, 1, 2)->setTime(0, 15));
        $this->assertSame(1, $therapist->reservedClients()->count());
        $this->travelTo(now('UTC')->setTime(0, 30));
        $this->assertSame(0, $therapist->reservedClients()->count());
    }

    public function test_accepting_clients_directory_uses_live_capacity_instead_of_cached_count(): void
    {
        $free = $this->therapist(1);
        $free->update(['clients_count' => 1]);
        $full = $this->therapist(1);
        $this->patient($full);
        $disabled = $this->therapist(1);
        $disabled->user->update(['is_active' => false]);
        $unapproved = $this->therapist(1);
        $unapproved->update(['approval_status' => 'pending']);
        $ids = app(TherapistRepositoryInterface::class)->listApproved(['accepting_clients' => true], 50)->pluck('user_id')->all();
        $this->assertSame([$free->user_id], $ids);
        $reserved = $this->patient();
        $this->book($reserved, $free);
        $reserved->update(['therapist_id' => null]);
        $free->update(['clients_count' => 0]);
        $this->assertSame(0, app(TherapistRepositoryInterface::class)->listApproved(['accepting_clients' => true], 50)->total());
    }

    private function pendingLicense(Therapist $therapist): string
    {
        $path = "licenses/{$therapist->user_id}/synthetic.pdf";
        $therapist->update(['approval_status' => 'pending', 'license_file_path' => $path]);

        return $path;
    }

    public function test_missing_license_on_configured_disk_can_be_reuploaded_then_approved(): void
    {
        $therapist = $this->therapist();
        $path = $this->pendingLicense($therapist);
        Storage::fake('other-private')->put($path, 'Synthetic wrong disk file');
        $service = app(TherapistService::class);
        $admin = $this->user('admin');
        $this->rejected(fn () => $service->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $admin), 'therapist');
        $this->assertSame(ApprovalStatus::PENDING, $therapist->fresh()->approval_status);
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_APPROVAL_DECIDED)->count());
        $recovered = $service->submitForApproval($therapist->fresh(), UploadedFile::fake()->create('synthetic.pdf', 2, 'application/pdf'));
        Storage::disk('correction-private')->assertExists($recovered->license_file_path);
        $approved = $service->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $admin);
        $this->assertSame(ApprovalStatus::APPROVED, $approved->approval_status);
        $this->assertSame(1, AuditLog::where('action', AuditLogService::THERAPIST_APPROVAL_DECIDED)->count());
    }

    public function test_license_approval_rejects_foreign_traversal_and_wrong_family_locators(): void
    {
        $therapist = $this->therapist();
        $other = $this->therapist();
        $admin = $this->user('admin');
        foreach (["licenses/{$other->user_id}/synthetic.pdf", "licenses/{$therapist->user_id}/../synthetic.pdf", "payment-proofs/{$therapist->user_id}/synthetic.pdf"] as $path) {
            $therapist->update(['approval_status' => 'pending', 'license_file_path' => $path]);
            if (! str_contains($path, '..')) {
                Storage::disk('correction-private')->put($path, 'Synthetic license');
            }
            $this->rejected(fn () => app(TherapistService::class)->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $admin), 'therapist');
            $this->assertSame(ApprovalStatus::PENDING, $therapist->fresh()->approval_status);
        }
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_APPROVAL_DECIDED)->count());
    }

    public function test_public_disk_is_not_valid_for_private_license_approval(): void
    {
        $therapist = $this->therapist();
        $path = $this->pendingLicense($therapist);
        Storage::fake('public')->put($path, 'Synthetic license');
        config(['sakina.uploads_disk' => 'public']);
        try {
            app(TherapistService::class)->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $this->user('admin'));
            $this->fail('Expected public storage configuration to reject approval.');
        } catch (ServiceUnavailableHttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame(ApprovalStatus::PENDING, $therapist->fresh()->approval_status);
    }

    public function test_license_storage_failure_fails_closed_without_approval_or_audit(): void
    {
        $therapist = $this->therapist();
        $this->pendingLicense($therapist);
        $admin = $this->user('admin');
        Storage::shouldReceive('disk')->with('correction-private')->once()->andThrow(new \RuntimeException('Synthetic unavailable storage'));
        try {
            app(TherapistService::class)->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $admin);
            $this->fail('Expected unavailable storage to reject approval.');
        } catch (ServiceUnavailableHttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame(ApprovalStatus::PENDING, $therapist->fresh()->approval_status);
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_APPROVAL_DECIDED)->count());
    }

    public function test_client_limit_and_mandatory_audit_roll_back_together(): void
    {
        $therapist = $this->therapist(20);
        $admin = $this->user('admin');
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure')));
        try {
            app(TherapistService::class)->updateClientsLimit($therapist, 1, $admin);
            $this->fail('Expected mandatory audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame(20, $therapist->fresh()->clients_limit);
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_LIMIT_CHANGED)->count());
    }

    public function test_client_limit_audit_uses_locked_current_value_not_stale_route_model(): void
    {
        $therapist = $this->therapist(20);
        Therapist::whereKey($therapist->user_id)->update(['clients_limit' => 5]);
        $updated = app(TherapistService::class)->updateClientsLimit($therapist, 7, $this->user('admin'));
        $this->assertSame(7, $updated->clients_limit);
        $audit = AuditLog::where('action', AuditLogService::THERAPIST_LIMIT_CHANGED)->sole();
        $this->assertSame(['from' => 5, 'to' => 7], $audit->details);
    }

    public function test_approval_and_rejection_audit_failure_leave_pending_and_emit_nothing(): void
    {
        $admin = $this->user('admin');
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('deliver')->never());
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Synthetic audit failure')));
        foreach ([ApprovalStatus::APPROVED, ApprovalStatus::REJECTED] as $status) {
            $therapist = $this->therapist();
            $path = $this->pendingLicense($therapist);
            Storage::disk('correction-private')->put($path, 'Synthetic license');
            try {
                app(TherapistService::class)->decideApproval($therapist->user_id, $status, $admin);
                $this->fail('Expected mandatory audit failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Synthetic audit failure', $exception->getMessage());
            }
            $this->assertSame(ApprovalStatus::PENDING, $therapist->fresh()->approval_status);
        }
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_APPROVAL_DECIDED)->count());
    }

    private function rejectAuditInserts(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION correction_reject_audit_insert() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'synthetic audit outage'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER correction_reject_audit_insert BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION correction_reject_audit_insert();");
        } else {
            DB::unprepared("CREATE TRIGGER correction_reject_audit_insert BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit outage'); END;");
        }
    }

    private function restoreAuditInserts(): void
    {
        DB::unprepared(DB::getDriverName() === 'pgsql'
            ? 'DROP TRIGGER correction_reject_audit_insert ON audit_logs'
            : 'DROP TRIGGER correction_reject_audit_insert');
    }

    private function auditInsertFailure(callable $operation): void
    {
        $this->assertSame(0, DB::transactionLevel());
        try {
            $operation();
            $this->fail('The database must reject the mandatory audit insert.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic audit outage', $exception->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_blocked_period_create_rolls_back_when_database_rejects_audit_then_recovers(): void
    {
        $therapist = $this->therapist();
        $service = app(TherapistBlockedPeriodService::class);
        $data = ['start_date' => '2026-01-05', 'end_date' => '2026-01-06', 'reason' => 'Synthetic absence'];
        $this->rejectAuditInserts();
        $this->auditInsertFailure(fn () => $service->create($therapist, $data));
        $this->assertSame(0, TherapistBlockedPeriod::count());
        $this->assertSame(0, AuditLog::count());
        $this->restoreAuditInserts();
        $period = $service->create($therapist, $data);
        $this->assertSame(1, TherapistBlockedPeriod::count());
        $audit = AuditLog::sole();
        $this->assertSame(AuditLogService::THERAPIST_BLOCKED_PERIOD_CREATED, $audit->action);
        $this->assertSame($therapist->user_id, $audit->user_id);
        $this->assertSame($period->id, $audit->entity_id);
        $this->assertSame(['start_date' => '2026-01-05', 'end_date' => '2026-01-06'], $audit->details);
    }

    public function test_blocked_period_delete_rolls_back_when_database_rejects_audit_then_recovers(): void
    {
        $therapist = $this->therapist();
        $service = app(TherapistBlockedPeriodService::class);
        $period = $service->create($therapist, ['start_date' => '2026-01-05', 'reason' => 'Synthetic absence']);
        $this->rejectAuditInserts();
        $this->auditInsertFailure(fn () => $service->delete($therapist, $period->id));
        $this->assertSame(1, TherapistBlockedPeriod::whereKey($period->id)->count());
        $this->assertSame('Synthetic absence', $period->fresh()->reason);
        $this->assertSame(1, AuditLog::count());
        $this->assertSame(0, AuditLog::where('action', AuditLogService::THERAPIST_BLOCKED_PERIOD_DELETED)->count());
        $this->restoreAuditInserts();
        $service->delete($therapist, $period->id);
        $this->assertSame(0, TherapistBlockedPeriod::count());
        $this->assertSame(2, AuditLog::count());
        $audit = AuditLog::where('action', AuditLogService::THERAPIST_BLOCKED_PERIOD_DELETED)->sole();
        $this->assertSame($therapist->user_id, $audit->user_id);
        $this->assertSame($period->id, $audit->entity_id);
        $this->assertSame(['start_date' => '2026-01-05', 'end_date' => '2026-01-05'], $audit->details);
    }

    public function test_switch_request_rolls_back_when_database_rejects_audit_and_notifies_only_after_commit(): void
    {
        $old = $this->therapist();
        $target = $this->therapist();
        $patient = $this->patient($old);
        $subscription = $this->subscription($patient, $old);
        $deliveries = [];
        $this->mock(NotificationService::class, function ($mock) use (&$deliveries): void {
            $mock->shouldReceive('deliver')->zeroOrMoreTimes()->andReturnUsing(function ($method, $switch) use (&$deliveries): void {
                $deliveries[] = [
                    'method' => $method, 'transaction_level' => DB::transactionLevel(),
                    'switch_exists' => TherapistSwitch::whereKey($switch->id)->exists(),
                    'audit_exists' => AuditLog::where('entity_id', $switch->id)->where('action', AuditLogService::THERAPIST_SWITCH_REQUESTED)->exists(),
                ];
            });
        });
        $service = app(TherapistSwitchService::class);
        $this->rejectAuditInserts();
        $this->auditInsertFailure(fn () => $service->request($patient, $target->user_id, 'Synthetic preference', $patient->user));
        $this->assertSame([], $deliveries);
        $this->assertSame(0, TherapistSwitch::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($old->user_id, $patient->fresh()->therapist_id);
        $this->assertSame($old->user_id, $subscription->fresh()->therapist_id);
        $this->restoreAuditInserts();
        $switch = $service->request($patient, $target->user_id, 'Synthetic preference', $patient->user);
        $this->assertSame(1, TherapistSwitch::count());
        $audit = AuditLog::sole();
        $this->assertSame($patient->user_id, $audit->user_id);
        $this->assertSame($switch->id, $audit->entity_id);
        $this->assertSame(['from' => $old->user_id, 'to' => $target->user_id], $audit->details);
        $this->assertSame([[
            'method' => 'therapistSwitchRequested', 'transaction_level' => 0,
            'switch_exists' => true, 'audit_exists' => true,
        ]], $deliveries);
    }
}
