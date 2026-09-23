<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Support;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->patient = $this->makeUser('patient', '+963900000010');
        $this->agent = $this->makeUser('support_agent', '+963900000011');
        Patient::create(['user_id' => $this->patient->id, 'full_name' => 'P', 'age' => 25, 'gender' => 'male', 'language' => 'ar']);
    }

    private function makeUser(string $role, string $phone): User
    {
        $u = User::create([
            'name' => $role, 'email' => "{$role}{$phone}@x.com", 'password' => bcrypt('Secret123!'),
            'role' => $role, 'whatsapp_number' => $phone, 'is_active' => true,
            'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_patient_support_ticket_crud(): void
    {
        Sanctum::actingAs($this->patient, ['*'], 'api');

        $this->postJson('/api/v1/patients/support', [
            'type' => 'technical',
            'subject' => 'مشكلة تقنية',
            'description' => 'لا أستطيع فتح الجلسة',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'technical')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.subject', 'مشكلة تقنية');

        $id = Support::firstOrFail()->id;

        $this->getJson('/api/v1/patients/support')->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson("/api/v1/patients/support/{$id}")->assertOk()
            ->assertJsonPath('data.id', $id);

        // Type validation
        $this->postJson('/api/v1/patients/support', [
            'type' => 'nonsense', 'description' => 'x',
        ])->assertUnprocessable();

        // A non-staff stranger cannot read the ticket.
        $other = $this->makeUser('patient', '+963900000012');
        Patient::create(['user_id' => $other->id, 'full_name' => 'O', 'age' => 25, 'gender' => 'female', 'language' => 'ar']);
        Sanctum::actingAs($other, ['*'], 'api');
        $this->getJson("/api/v1/patients/support/{$id}")->assertNotFound();
    }

    public function test_staff_triage_assign_and_close(): void
    {
        $ticket = Support::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->patient->id,
            'type' => 'clinical', 'description' => 'أحتاج مساعدة',
            'status' => 'open',
        ]);

        // Patients cannot reach the admin endpoints.
        Sanctum::actingAs($this->patient, ['*'], 'api');
        $this->getJson('/api/v1/admin/support')->assertForbidden();

        Sanctum::actingAs($this->agent, ['*'], 'api');
        $this->getJson('/api/v1/admin/support')->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson("/api/v1/admin/support/{$ticket->id}")->assertOk();

        // Assign to the agent herself.
        $this->postJson("/api/v1/admin/support/{$ticket->id}/assign", [
            'assigned_to' => $this->agent->id,
        ])->assertOk()->assertJsonPath('data.assigned_to', $this->agent->id);

        // Cannot assign to a patient.
        $this->postJson("/api/v1/admin/support/{$ticket->id}/assign", [
            'assigned_to' => $this->patient->id,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/admin/support/{$ticket->id}/status", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
    }
}
