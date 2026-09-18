<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::create([
            'name' => 'Patient One',
            'email' => 'p1@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'patient',
            'whatsapp_number' => '+963900000002',
            'is_active' => true,
            'phone_verified_at' => now(),
            'login_attempts' => 0,
        ]);
        $this->user->assignRole('patient');

        Sanctum::actingAs($this->user, ['*'], 'api');
    }

    private function createProfile(): void
    {
        Patient::create([
            'user_id' => $this->user->id,
            'full_name' => 'Patient One',
            'age' => 30,
            'gender' => 'male',
            'language' => 'ar',
        ]);
    }

    public function test_profile_upsert_and_read(): void
    {
        $this->putJson('/api/v1/patients/profile', [
            'full_name' => 'Patient One',
            'age' => 30,
            'gender' => 'male',
            'language' => 'ar',
        ])->assertOk()->assertJsonPath('patient.full_name', 'Patient One');

        $this->getJson('/api/v1/patients/profile')
            ->assertOk()
            ->assertJsonPath('patient.age', 30);
    }

    public function test_profile_update_ignores_clinical_fields(): void
    {
        $this->putJson('/api/v1/patients/profile', [
            'full_name' => 'Patient One',
            'age' => 30,
            'gender' => 'male',
            'language' => 'ar',
            'safety_flag' => false,
            'assessment_score' => 0,
            'compliance_level' => 'high',
            'therapist_id' => 'self-assigned',
        ])->assertOk();

        $patient = Patient::where('user_id', $this->user->id)->firstOrFail();
        $this->assertFalse($patient->safety_flag);
        $this->assertSame(0, $patient->assessment_score);
        $this->assertSame('medium', $patient->compliance_level);
        $this->assertNull($patient->therapist_id);
    }

    public function test_profile_requires_minimum_age(): void
    {
        $this->putJson('/api/v1/patients/profile', [
            'full_name' => 'Kid',
            'age' => 15,
            'gender' => 'male',
            'language' => 'ar',
        ])->assertUnprocessable();
    }

    public function test_dashboard_requires_profile(): void
    {
        $this->getJson('/api/v1/patients/dashboard')->assertUnprocessable();

        $this->createProfile();
        $this->getJson('/api/v1/patients/dashboard')
            ->assertOk()
            ->assertJsonPath('patient.full_name', 'Patient One');
    }

    public function test_onboarding_and_progress_endpoints(): void
    {
        $this->getJson('/api/v1/patients/onboarding')
            ->assertOk()
            ->assertJsonPath('has_profile', false);

        $this->createProfile();

        $this->getJson('/api/v1/patients/progress')
            ->assertOk()
            ->assertJsonPath('assessment_count', 0);

        $this->getJson('/api/v1/patients/appointments')
            ->assertOk()
            ->assertJsonStructure(['upcoming', 'past']);

        $this->getJson('/api/v1/patients/programs')
            ->assertOk()
            ->assertJsonStructure(['programs']);
    }

    public function test_export_data_returns_full_payload(): void
    {
        $this->createProfile();

        $response = $this->get('/api/v1/patients/export-data');
        $response->assertOk();
        $data = json_decode($response->streamedContent(), true);

        $this->assertSame('p1@example.com', $data['user']['email']);
        $this->assertSame('Patient One', $data['patient_profile']['full_name']);
    }

    public function test_account_deletion_schedules_and_revokes_tokens(): void
    {
        $this->createProfile();
        $token = $this->user->createToken('api')->plainTextToken;

        $this->deleteJson('/api/v1/patients/account', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->user->refresh();
        $this->assertTrue($this->user->is_active);
        $this->assertNotNull($this->user->deletion_scheduled_at);
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_guest_gets_401_json_even_without_accept_header(): void
    {
        auth()->forgetGuards(); // drop the actingAs() user from setUp

        $this->get('/api/v1/patients/profile')->assertUnauthorized();
    }

    public function test_therapist_cannot_access_patient_endpoints(): void
    {
        $therapist = User::create([
            'name' => 'Dr T',
            'email' => 't@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'therapist',
            'whatsapp_number' => '+963900000003',
            'is_active' => true,
            'phone_verified_at' => now(),
            'login_attempts' => 0,
        ]);
        $therapist->assignRole('therapist');

        Sanctum::actingAs($therapist, ['*'], 'api');

        $this->getJson('/api/v1/patients/profile')->assertForbidden();
    }

    public function test_unverified_or_inactive_user_is_blocked(): void
    {
        $this->user->update(['is_active' => false]);

        $this->getJson('/api/v1/patients/profile')->assertForbidden();
    }
}
