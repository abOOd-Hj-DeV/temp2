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

    public function test_replies_thread_between_patient_and_staff_until_closed(): void
    {
        $ticket = Support::create([
            'id' => (string) Str::uuid(), 'user_id' => $this->patient->id,
            'type' => 'technical', 'description' => 'الرابط لا يعمل', 'status' => 'open',
        ]);
        $url = "/api/v1/patients/support/{$ticket->id}/replies";

        // Patient reply on an unassigned ticket: stored, audited, nobody paged.
        Sanctum::actingAs($this->patient, ['*'], 'api');
        $this->postJson($url, ['body' => ''])->assertUnprocessable();
        $this->postJson($url, ['body' => 'ما زالت المشكلة موجودة'])->assertCreated()
            ->assertJsonPath('data.from_staff', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.ticket_replied', 'entity_id' => $ticket->id]);
        $this->assertSame(0, $this->agent->notifications()->count());

        // Staff assigns herself and replies → patient notified once.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->agent, ['*'], 'api');
        $this->postJson("/api/v1/admin/support/{$ticket->id}/assign", ['assigned_to' => $this->agent->id])->assertOk();
        $this->postJson("/api/v1/admin/support/{$ticket->id}/replies", ['body' => 'جرّب تحديث التطبيق'])->assertCreated()
            ->assertJsonPath('data.from_staff', true);
        $this->assertSame(1, $this->patient->notifications()->count());

        $show = $this->getJson("/api/v1/admin/support/{$ticket->id}")->assertOk()->json('data');
        $this->assertCount(2, $show['replies']);
        $this->assertFalse($show['replies'][0]['from_staff']);
        $this->assertSame('support_agent', $show['replies'][1]['author_name']);

        // Patient replies again → assigned agent notified.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patient, ['*'], 'api');
        $this->postJson($url, ['body' => 'تم، شكراً'])->assertCreated();
        $this->assertSame(1, $this->agent->notifications()->count());
        $this->assertCount(3, $this->getJson("/api/v1/patients/support/{$ticket->id}")->json('data.replies'));

        // Strangers cannot reply; closed tickets reject replies from both sides.
        $other = $this->makeUser('patient', '+963900000013');
        Patient::create(['user_id' => $other->id, 'full_name' => 'O', 'age' => 25, 'gender' => 'female', 'language' => 'ar']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, ['*'], 'api');
        $this->postJson($url, ['body' => 'hi'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->agent, ['*'], 'api');
        $this->postJson("/api/v1/admin/support/{$ticket->id}/status", ['status' => 'closed'])->assertOk();
        $this->postJson("/api/v1/admin/support/{$ticket->id}/replies", ['body' => 'late'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patient, ['*'], 'api');
        $this->postJson($url, ['body' => 'late'])->assertStatus(409);
        $this->assertDatabaseCount('support_replies', 3);
    }
}
