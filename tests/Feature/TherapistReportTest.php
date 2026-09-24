<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** I-18 / TRACK-06: therapist activity + earnings report aggregation. */
class TherapistReportTest extends TestCase
{
    use RefreshDatabase;

    private Therapist $therapist;

    private User $therapistUser;

    private Patient $p1;

    private Patient $p2;

    private int $hour = 8;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->seed(RolePermissionSeeder::class);

        $this->therapistUser = $this->makeUser('therapist', '+963900000910');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr. Report', 'specialty' => 'anxiety', 'country' => 'JO',
            'languages' => ['ar'], 'approval_status' => 'approved', 'clients_count' => 2, 'clients_limit' => 10,
        ]);
        $this->p1 = $this->makePatient('+963900000911');
        $this->p2 = $this->makePatient('+963900000912');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com", 'password' => 'x',
            'whatsapp_number' => $whatsapp, 'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
            'timezone' => 'UTC',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makePatient(string $whatsapp): Patient
    {
        $user = $this->makeUser('patient', $whatsapp);

        return Patient::create([
            'user_id' => $user->id, 'full_name' => 'P', 'age' => 30, 'gender' => 'other', 'language' => 'ar',
            'therapist_id' => $this->therapist->user_id,
        ]);
    }

    private function makeSession(Patient $patient, string $date, string $status, array $extra = []): TherapySession
    {
        return TherapySession::create(array_merge([
            'patient_id' => $patient->user_id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => $date, 'session_time' => sprintf('%02d:00', $this->hour++),
            'medium' => 'meet', 'status' => $status, 'is_initial' => false, 'price' => 40, 'payment_status' => 'paid',
        ], $extra));
    }

    public function test_report_aggregates_status_counts_clients_earnings_and_per_day(): void
    {
        // In-range (October 2026).
        $this->makeSession($this->p1, '2026-10-02', 'completed', ['is_initial' => true, 'price' => 0, 'payment_status' => 'free', 'attendance_confirmed_at' => now()]);
        $this->makeSession($this->p1, '2026-10-09', 'completed', ['attendance_confirmed_at' => now(), 'price' => 40]);
        $this->makeSession($this->p2, '2026-10-09', 'completed', ['attendance_confirmed_at' => now(), 'price' => 60]);
        // Completed but attendance not confirmed → not counted as earned yet.
        $this->makeSession($this->p2, '2026-10-12', 'completed', ['price' => 100]);
        $this->makeSession($this->p1, '2026-10-20', 'confirmed');
        $this->makeSession($this->p2, '2026-10-22', 'pending');
        $this->makeSession($this->p2, '2026-10-23', 'cancelled');
        $this->makeSession($this->p1, '2026-10-24', 'cancelled');
        // Out of range: previous month.
        $this->makeSession($this->p1, '2026-09-28', 'completed', ['attendance_confirmed_at' => now(), 'price' => 999]);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        $report = $this->getJson('/api/v1/therapists/reports')->assertOk()->json();

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $report['period']);
        $this->assertSame([
            'total' => 8, 'pending' => 1, 'confirmed' => 1, 'completed' => 4, 'cancelled' => 2,
            'cancellation_rate' => 0.25, 'free_initial' => 1, 'package_covered' => 0,
        ], $report['sessions']);
        $this->assertSame(['distinct' => 2, 'active_assigned' => 2, 'limit' => 10], $report['clients']);
        $this->assertEqualsWithDelta(100.0, $report['earnings']['gross_completed_paid'], 0.001);
        $this->assertSame(2, $report['earnings']['completed_paid_sessions']);
        $this->assertSame(
            [['2026-10-02', 1], ['2026-10-09', 2], ['2026-10-12', 1], ['2026-10-20', 1], ['2026-10-22', 1]],
            array_map(fn ($d) => [$d['date'], $d['sessions']], $report['per_day']),
        );

        // Explicit range picks up September only.
        $sep = $this->getJson('/api/v1/therapists/reports?from=2026-09-01&to=2026-09-30')->assertOk()->json();
        $this->assertSame(1, $sep['sessions']['total']);
        $this->assertEqualsWithDelta(999.0, $sep['earnings']['gross_completed_paid'], 0.001);

        // Invalid range and non-therapist access.
        $this->getJson('/api/v1/therapists/reports?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/therapists/reports?from=oct')->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->p1->user, ['*'], 'api');
        $this->getJson('/api/v1/therapists/reports')->assertForbidden();
    }

    public function test_empty_report_is_zeroed(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        $report = $this->getJson('/api/v1/therapists/reports?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertSame(0, $report['sessions']['total']);
        $this->assertSame(0, $report['sessions']['cancellation_rate']);
        $this->assertSame(0, $report['clients']['distinct']);
        $this->assertEqualsWithDelta(0.0, $report['earnings']['gross_completed_paid'], 0.001);
        $this->assertSame([], $report['per_day']);
    }
}
