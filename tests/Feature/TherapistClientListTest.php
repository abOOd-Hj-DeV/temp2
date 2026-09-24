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

/** I-17: therapist client list search / status filter / sort / activity summary. */
class TherapistClientListTest extends TestCase
{
    use RefreshDatabase;

    private Therapist $therapist;

    private User $therapistUser;

    private int $hour = 9;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00');
        $this->seed(RolePermissionSeeder::class);

        $this->therapistUser = $this->makeUser('therapist', '+963900000900');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr. List', 'specialty' => 'anxiety', 'country' => 'JO',
            'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
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

    private function makePatient(string $name, string $whatsapp, ?string $therapistId): Patient
    {
        $user = $this->makeUser('patient', $whatsapp);

        return Patient::create([
            'user_id' => $user->id, 'full_name' => $name, 'age' => 30, 'gender' => 'other', 'language' => 'ar',
            'therapist_id' => $therapistId,
        ]);
    }

    private function makeSession(Patient $patient, string $date, string $status, ?string $therapistId = null): TherapySession
    {
        return TherapySession::create([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapistId ?? $this->therapist->user_id,
            'session_date' => $date, 'session_time' => sprintf('%02d:00', $this->hour++),
            'medium' => 'meet', 'status' => $status, 'is_initial' => false, 'price' => 0, 'payment_status' => 'free',
        ]);
    }

    public function test_search_status_filter_sort_and_activity_summary(): void
    {
        $amal = $this->makePatient('Amal Active', '+963900000901', $this->therapist->user_id);
        $this->makeSession($amal, '2026-09-20', 'completed');
        $this->makeSession($amal, '2026-09-27', 'completed');
        $this->makeSession($amal, '2026-10-05', 'confirmed');

        $basel = $this->makePatient('Basel Active', '+963900000902', $this->therapist->user_id);

        $past = $this->makePatient('Past Client', '+963900000903', null);
        $this->makeSession($past, '2026-08-01', 'completed');

        // Cancelled-only history does not make a client; neither does another therapist's patient.
        $cancelledOnly = $this->makePatient('Cancelled Only', '+963900000904', null);
        $this->makeSession($cancelledOnly, '2026-08-01', 'cancelled');
        $otherTherapistUser = $this->makeUser('therapist', '+963900000905');
        Therapist::create([
            'user_id' => $otherTherapistUser->id, 'full_name' => 'Dr. Other', 'specialty' => 'anxiety', 'country' => 'JO',
            'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
        $this->makePatient('Foreign Patient', '+963900000906', $otherTherapistUser->id);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        // Default: all clients sorted by name with per-client status + activity.
        $all = $this->getJson('/api/v1/therapists/clients')->assertOk()->assertJsonPath('pagination.total', 3)->json('data');
        $this->assertSame(['Amal Active', 'Basel Active', 'Past Client'], array_column($all, 'full_name'));
        $this->assertSame(['active', 'active', 'past'], array_column($all, 'status'));
        $this->assertSame(2, $all[0]['completed_sessions_count']);
        $this->assertSame('2026-09-27', $all[0]['last_session_date']);
        $this->assertSame('2026-10-05', $all[0]['next_session_date']);
        $this->assertSame(0, $all[1]['completed_sessions_count']);
        $this->assertNull($all[1]['next_session_date']);

        // Status filter.
        $this->getJson('/api/v1/therapists/clients?status=active')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/v1/therapists/clients?status=past')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.full_name', 'Past Client');
        $this->getJson('/api/v1/therapists/clients?status=bogus')->assertUnprocessable();

        // Search (case-insensitive on SQLite/PG default collation for ASCII) and LIKE-escaping.
        $this->getJson('/api/v1/therapists/clients?search=basel')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.full_name', 'Basel Active');
        $this->getJson('/api/v1/therapists/clients?search=Active&status=past')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/v1/therapists/clients?search=%25')->assertOk()->assertJsonPath('pagination.total', 0);

        // Sort by recent completed activity: Amal (Sep 27) → Past (Aug 1) → Basel (none).
        $recent = $this->getJson('/api/v1/therapists/clients?sort=recent')->assertOk()->json('data');
        $this->assertSame(['Amal Active', 'Past Client', 'Basel Active'], array_column($recent, 'full_name'));

        // Other therapist sees only her own client.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($otherTherapistUser, ['*'], 'api');
        $this->getJson('/api/v1/therapists/clients')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.full_name', 'Foreign Patient');
    }
}
