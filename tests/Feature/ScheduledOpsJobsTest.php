<?php

namespace Tests\Feature;

use App\Enums\ComplianceLevel;
use App\Jobs\ComputeWeeklyComplianceJob;
use App\Jobs\RemindStalePaymentReviewsJob;
use App\Models\Module;
use App\Models\MoodLog;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Models\Payment;
use App\Models\Program;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Patient\ComplianceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledOpsJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private Subscription $subscription;

    private User $finance;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->patientUser = $this->makeUser('patient', '+963900000300');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'P', 'age' => 30,
            'gender' => 'other', 'language' => 'en',
        ]);
        $this->subscription = Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'start_date' => now()->subWeek()->toDateString(), 'end_date' => now()->addWeeks(3)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);
        $this->finance = $this->makeUser('finance_partner', '+963900000301');
        $this->admin = $this->makeUser('admin', '+963900000302');
        $this->makeUser('clinical_supervisor', '+963900000303');
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        return User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com",
            'password' => Hash::make('Secret123!'), 'whatsapp_number' => $whatsapp,
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
        ]);
    }

    private function pendingPayment(): Payment
    {
        return Payment::create([
            'subscription_id' => $this->subscription->id, 'amount' => 150,
            'proof_file_path' => 'proofs/x.png', 'status' => 'pending',
        ]);
    }

    private function runReminder(): void
    {
        (new RemindStalePaymentReviewsJob)->handle(app(NotificationService::class));
    }

    // ------------------------------------------------ 12h payment review SLA

    public function test_pending_payment_older_than_sla_reminds_finance_staff_exactly_once(): void
    {
        $payment = $this->pendingPayment();

        $this->runReminder();
        $this->assertNull($payment->fresh()->review_reminder_sent_at);
        $this->assertSame(0, DB::table('notifications')->where('type', 'like', '%Overdue%')->count());

        $this->travel(13)->hours();
        $this->runReminder();

        $this->assertNotNull($payment->fresh()->review_reminder_sent_at);
        $overdue = DB::table('notifications')->where('type', 'like', '%Overdue%');
        $this->assertSame(2, $overdue->count()); // finance + admin, not the supervisor
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->finance->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);

        // Idempotent across overlapping runs.
        $this->runReminder();
        $this->assertSame(2, DB::table('notifications')->where('type', 'like', '%Overdue%')->count());
    }

    public function test_reviewed_payments_are_never_reminded(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['status' => 'approved', 'reviewed_at' => now()]);

        $this->travel(2)->days();
        $this->runReminder();

        $this->assertNull($payment->fresh()->review_reminder_sent_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // ------------------------------------------------ weekly compliance

    private function logMoodDays(int $days): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $date = now()->subDays($i)->toDateString();

            if (MoodLog::where('patient_id', $this->patient->user_id)->whereDate('log_date', $date)->exists()) {
                continue;
            }

            MoodLog::create([
                'id' => (string) Str::uuid(), 'patient_id' => $this->patient->user_id,
                'score' => 6, 'log_date' => $date,
            ]);
        }
    }

    private function assignModules(int $total, int $completed): void
    {
        $program = Program::create(['id' => (string) Str::uuid(), 'name' => 'Core', 'description' => 'd', 'is_core' => true]);

        for ($i = 0; $i < $total; $i++) {
            $module = Module::create([
                'id' => (string) Str::uuid(), 'program_id' => $program->id, 'title' => "M{$i}",
                'description' => 'd', 'content_type' => 'text', 'order' => $i,
            ]);
            $done = $i < $completed;
            PatientModule::create([
                'id' => (string) Str::uuid(), 'patient_id' => $this->patient->user_id, 'module_id' => $module->id,
                'status' => $done ? 'completed' : 'pending', 'completed_at' => $done ? now()->subDays(2) : null,
            ]);
        }
        PatientModule::query()->update(['created_at' => now()->subDays(10)]);
    }

    public function test_compliance_snapshot_scoring(): void
    {
        $service = app(ComplianceService::class);

        // No modules: mood only. 7/7 days => 100 => high.
        $this->logMoodDays(7);
        $snap = $service->snapshot($this->patient, now());
        $this->assertSame(100, $snap['score']);
        $this->assertSame(ComplianceLevel::HIGH, $snap['level']);

        // Modules count 40%: 7/7 mood (60) + 1/4 modules (10) => 70 => medium.
        $this->assignModules(4, 1);
        $snap = $service->snapshot($this->patient, now());
        $this->assertSame(70, $snap['score']);
        $this->assertSame(ComplianceLevel::MEDIUM, $snap['level']);
    }

    public function test_weekly_job_sets_low_level_and_raises_one_non_compliance_flag(): void
    {
        // Only 2 check-ins in the last 7 days and no modules => 29 => low.
        $this->logMoodDays(2);
        $this->assertSame('medium', $this->patient->fresh()->compliance_level?->value ?? $this->patient->fresh()->compliance_level);

        (new ComputeWeeklyComplianceJob)->handle(app(ComplianceService::class));

        $level = $this->patient->fresh()->compliance_level;
        $this->assertSame('low', $level instanceof ComplianceLevel ? $level->value : $level);
        $flag = RedFlag::sole();
        $this->assertSame('non_compliance', $flag->type->value);
        $this->assertSame('open', $flag->status);
        $this->assertNotNull($flag->assigned_to);

        // Re-running (same or later week) while the flag is open must not duplicate it.
        (new ComputeWeeklyComplianceJob)->handle(app(ComplianceService::class));
        $this->assertSame(1, RedFlag::count());

        // Once the patient recovers, the level rises and no new flag is created.
        $this->logMoodDays(7);
        (new ComputeWeeklyComplianceJob)->handle(app(ComplianceService::class));
        $level = $this->patient->fresh()->compliance_level;
        $this->assertSame('high', $level instanceof ComplianceLevel ? $level->value : $level);
        $this->assertSame(1, RedFlag::count());
    }

    public function test_weekly_job_skips_patients_without_an_active_subscription(): void
    {
        $this->subscription->update(['end_date' => now()->subDay()->toDateString()]);

        (new ComputeWeeklyComplianceJob)->handle(app(ComplianceService::class));

        $this->assertSame(0, RedFlag::count());
        $level = $this->patient->fresh()->compliance_level;
        $this->assertSame('medium', $level instanceof ComplianceLevel ? $level->value : $level);
    }
}
