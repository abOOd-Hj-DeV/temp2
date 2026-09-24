<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Therapist;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TherapistContentTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->patientUser = $this->makeUser('patient', '+963900000020');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id,
            'full_name' => 'Patient One',
            'age' => 30, 'gender' => 'male', 'language' => 'ar',
        ]);

        $this->therapistUser = $this->makeUser('therapist', '+963900000021');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id,
            'full_name' => 'Dr. Test', 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x',
            'whatsapp_number' => $whatsapp,
            'role' => $role,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_therapist_content_lifecycle_and_patient_feed(): void
    {
        // Assign the patient to this therapist → he becomes a client.
        $this->patient->update(['therapist_id' => $this->therapist->user_id]);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        // Create → library holds it.
        $created = $this->postJson('/api/v1/therapists/content', [
            'patient_id' => $this->patient->user_id,
            'title' => 'Breathing exercise',
            'content_type' => 'text',
            'body' => 'Inhale 4s, hold 4s, exhale 6s.',
        ])->assertCreated()->json('content');
        $this->assertSame('Breathing exercise', $created['title']);

        $this->getJson('/api/v1/therapists/content')->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson("/api/v1/therapists/content/{$created['id']}")->assertOk();

        // Validation: url required for link/video; client check enforced.
        $this->postJson('/api/v1/therapists/content', [
            'patient_id' => $this->patient->user_id,
            'title' => 'Video', 'content_type' => 'video',
        ])->assertUnprocessable();

        // Update + delete.
        $this->putJson("/api/v1/therapists/content/{$created['id']}", [
            'title' => 'Updated title',
        ])->assertOk()->assertJsonPath('content.title', 'Updated title');
        $this->deleteJson("/api/v1/therapists/content/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('therapist_contents', ['id' => $created['id']]);

        // Patient feed sees own items only.
        $this->postJson('/api/v1/therapists/content', [
            'patient_id' => $this->patient->user_id,
            'title' => 'For you', 'content_type' => 'text', 'body' => 'Hi',
        ]);
        $otherPatient = $this->makeUser('patient', '+963900000022');
        Patient::create([
            'user_id' => $otherPatient->id, 'full_name' => 'Other',
            'age' => 25, 'gender' => 'female', 'language' => 'ar',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/patients/content')->assertOk()->assertJsonCount(1, 'data.data');
        Sanctum::actingAs($otherPatient, ['*'], 'api');
        $this->getJson('/api/v1/patients/content')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_parallel_layer_per_client_with_edit_log(): void
    {
        $this->patient->update(['therapist_id' => $this->therapist->user_id]);
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        // Empty until first save.
        $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/parallel")
            ->assertOk()->assertJsonPath('layer', null);

        // Save → layer created; second save → same layer + edit_log grows.
        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/parallel", [
            'content' => ['focus' => 'sleep hygiene', 'plan' => 'week 1'],
        ])->assertOk()->assertJsonPath('layer.content.focus', 'sleep hygiene');

        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/parallel", [
            'content' => ['focus' => 'sleep hygiene', 'plan' => 'week 2'],
        ])->assertOk();

        $layer = $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/parallel")
            ->assertOk()->json('layer');
        $this->assertSame('week 2', $layer['content']['plan']);
        $this->assertCount(2, $layer['edit_log']);
        $this->assertSame(['created', 'updated'], array_column($layer['edit_log'], 'action'));

        // A non-client patient cannot get a layer.
        $other = $this->makeUser('patient', '+963900000023');
        Patient::create(['user_id' => $other->id, 'full_name' => 'X', 'age' => 20, 'gender' => 'male', 'language' => 'ar']);
        $this->postJson("/api/v1/therapists/clients/{$other->id}/parallel", [
            'content' => ['a' => 'b'],
        ])->assertNotFound();
    }

    public function test_library_item_is_reusable_across_clients(): void
    {
        $this->patient->update(['therapist_id' => $this->therapist->user_id]);
        $secondUser = $this->makeUser('patient', '+963900000024');
        $second = Patient::create([
            'user_id' => $secondUser->id, 'full_name' => 'Second', 'age' => 28,
            'gender' => 'female', 'language' => 'ar', 'therapist_id' => $this->therapist->user_id,
        ]);
        $strangerUser = $this->makeUser('patient', '+963900000025');
        Patient::create(['user_id' => $strangerUser->id, 'full_name' => 'S', 'age' => 22, 'gender' => 'male', 'language' => 'ar']);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        // A library item with no addressee is allowed and invisible to patients.
        $item = $this->postJson('/api/v1/therapists/content', [
            'title' => 'Grounding', 'content_type' => 'link', 'url' => 'https://example.org/grounding',
        ])->assertCreated()->json('content');
        $this->assertSame([], $item['assigned_patients']);
        $this->assertDatabaseCount('therapist_content_assignments', 0);

        // Share with client one, then reuse for client two — same item id, two assignments.
        $this->postJson("/api/v1/therapists/content/{$item['id']}/assign", ['patient_ids' => [$this->patient->user_id]])
            ->assertOk()->assertJsonPath('content.assigned_count', 1);
        $this->postJson("/api/v1/therapists/content/{$item['id']}/assign", ['patient_ids' => [$second->user_id, $this->patient->user_id]])
            ->assertOk()->assertJsonPath('content.assigned_count', 2);
        $this->assertDatabaseCount('therapist_content_assignments', 2);
        $this->assertDatabaseCount('therapist_contents', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.item_assigned', 'entity_id' => $item['id']]);

        // Non-client → 404 and nothing attached; bad payload → 422.
        $this->postJson("/api/v1/therapists/content/{$item['id']}/assign", ['patient_ids' => [$strangerUser->id]])->assertNotFound();
        $this->postJson("/api/v1/therapists/content/{$item['id']}/assign", ['patient_ids' => []])->assertUnprocessable();
        $this->assertDatabaseCount('therapist_content_assignments', 2);

        // Library filters: by client, by type, by search.
        $this->postJson('/api/v1/therapists/content', [
            'patient_id' => $second->user_id, 'title' => 'Sleep diary', 'content_type' => 'text', 'body' => 'Track bedtime.',
        ])->assertCreated()->assertJsonPath('content.assigned_count', 1);
        $this->getJson('/api/v1/therapists/content')->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson("/api/v1/therapists/content?patient_id={$this->patient->user_id}")->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson("/api/v1/therapists/content?patient_id={$second->user_id}")->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson('/api/v1/therapists/content?content_type=text')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/v1/therapists/content?search=diary')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Sleep diary');

        // Both clients see the shared item; only the second sees the diary.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/patients/content')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Grounding');
        Sanctum::actingAs($secondUser, ['*'], 'api');
        $this->getJson('/api/v1/patients/content')->assertOk()->assertJsonCount(2, 'data.data');

        // Un-share from client one: item survives, client one loses access.
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->deleteJson("/api/v1/therapists/content/{$item['id']}/assign/{$this->patient->user_id}")
            ->assertOk()->assertJsonPath('content.assigned_count', 1);
        $this->deleteJson("/api/v1/therapists/content/{$item['id']}/assign/{$this->patient->user_id}")->assertNotFound();
        $this->assertDatabaseHas('therapist_contents', ['id' => $item['id'], 'patient_id' => $second->user_id]);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/patients/content')->assertOk()->assertJsonCount(0, 'data.data');

        // Another therapist cannot see or share the item.
        $otherTherapistUser = $this->makeUser('therapist', '+963900000026');
        Therapist::create([
            'user_id' => $otherTherapistUser->id, 'full_name' => 'Dr. Other', 'specialty' => 'anxiety',
            'country' => 'DE', 'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
        Sanctum::actingAs($otherTherapistUser, ['*'], 'api');
        $this->getJson("/api/v1/therapists/content/{$item['id']}")->assertNotFound();
        $this->postJson("/api/v1/therapists/content/{$item['id']}/assign", ['patient_ids' => [$second->user_id]])->assertNotFound();
    }

    public function test_content_rejected_for_non_client_patient(): void
    {
        // Patient NOT assigned to this therapist → 404 (no enumeration).
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/therapists/content', [
            'patient_id' => $this->patient->user_id,
            'title' => 'X', 'content_type' => 'text', 'body' => 'Y',
        ])->assertNotFound();
    }
}
