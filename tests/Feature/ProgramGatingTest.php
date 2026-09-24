<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramGatingTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private Therapist $therapist;

    private Program $program;

    /** @var Module[] */
    private array $modules = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->therapistUser = $this->makeUser('therapist', '+963900000811');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr. Gate', 'specialty' => 'cbt',
            'country' => 'DE', 'languages' => ['ar'], 'approval_status' => 'approved',
        ]);

        $this->patientUser = $this->makeUser('patient', '+963900000810');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Gated Patient', 'age' => 30,
            'gender' => 'female', 'language' => 'ar', 'therapist_id' => $this->therapistUser->id,
        ]);

        $this->program = Program::create(['id' => (string) Str::uuid(), 'name' => 'Core', 'description' => 'd', 'is_core' => true]);
        foreach ([1, 2, 3] as $i) {
            $this->modules[$i] = Module::create([
                'id' => (string) Str::uuid(), 'program_id' => $this->program->id,
                'title' => "Module {$i}", 'description' => 'd', 'content_type' => 'text', 'order' => $i,
                'body' => "[PLACEHOLDER] body {$i}", 'homework_prompt' => $i === 2 ? 'Write three thoughts.' : null,
                'is_hideable' => $i === 3,
            ]);
        }
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com", 'password' => bcrypt('x'),
            'role' => $role, 'whatsapp_number' => $whatsapp, 'is_active' => true,
            'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function activateSubscription(): Subscription
    {
        return Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks', 'sessions_total' => 4,
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 100, 'verification_status' => 'approved', 'therapist_id' => $this->therapistUser->id,
        ]);
    }

    public function test_first_module_is_free_and_later_modules_require_active_subscription(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $list = $this->getJson('/api/v1/patients/programs')->assertOk()
            ->assertJsonPath('has_active_subscription', false)
            ->assertJsonPath('programs.0.modules.0.is_free', true)
            ->assertJsonPath('programs.0.modules.0.locked', false)
            ->assertJsonPath('programs.0.modules.1.locked', true)
            ->assertJsonPath('programs.0.modules.1.lock_reason', 'subscription_required');
        $this->assertCount(3, $list->json('programs.0.modules'));

        $this->getJson("/api/v1/patients/modules/{$this->modules[1]->id}")->assertOk()
            ->assertJsonPath('module.body', '[PLACEHOLDER] body 1');
        $this->postJson("/api/v1/patients/modules/{$this->modules[1]->id}/complete")->assertOk()
            ->assertJsonPath('module.status', 'completed');

        // Without a package, module 2 stays closed even though module 1 is done.
        $this->getJson("/api/v1/patients/modules/{$this->modules[2]->id}")->assertForbidden();
        $this->postJson("/api/v1/patients/modules/{$this->modules[2]->id}/complete")->assertForbidden();
        $this->assertDatabaseMissing('patient_modules', ['module_id' => $this->modules[2]->id]);
    }

    public function test_subscribed_patient_unlocks_modules_sequentially_and_homework_is_stored(): void
    {
        $this->activateSubscription();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        // Module 2 is locked until module 1 is completed; module 3 until module 2.
        $this->getJson("/api/v1/patients/modules/{$this->modules[2]->id}")->assertForbidden();
        $this->postJson("/api/v1/patients/modules/{$this->modules[3]->id}/complete")->assertForbidden();

        $this->postJson("/api/v1/patients/modules/{$this->modules[1]->id}/complete")->assertOk();

        $this->getJson('/api/v1/patients/programs')->assertOk()
            ->assertJsonPath('programs.0.completed_count', 1)
            ->assertJsonPath('programs.0.modules.1.locked', false)
            ->assertJsonPath('programs.0.modules.1.has_homework', true)
            ->assertJsonPath('programs.0.modules.2.lock_reason', 'previous_module_incomplete');

        $this->putJson("/api/v1/patients/modules/{$this->modules[2]->id}/homework", ['homework' => []])
            ->assertStatus(422);

        $this->putJson("/api/v1/patients/modules/{$this->modules[2]->id}/homework", [
            'homework' => ['thought_1' => 'I can cope', 'thought_2' => 'One step at a time'],
        ])->assertOk()
            ->assertJsonPath('module.status', 'pending')
            ->assertJsonPath('module.homework.thought_1', 'I can cope');

        $this->postJson("/api/v1/patients/modules/{$this->modules[2]->id}/complete", [
            'homework' => ['thought_1' => 'Revised', 'thought_2' => 'Still fine', 'thought_3' => 'Done'],
        ])->assertOk()
            ->assertJsonPath('module.status', 'completed')
            ->assertJsonPath('module.homework.thought_3', 'Done');

        $row = PatientModule::where('module_id', $this->modules[2]->id)->firstOrFail();
        $this->assertSame('Revised', $row->homework['thought_1']);
        $this->assertNotNull($row->homework_submitted_at);

        $this->getJson("/api/v1/patients/modules/{$this->modules[3]->id}")->assertOk()
            ->assertJsonPath('module.locked', false);
    }

    public function test_therapist_can_hide_hideable_modules_for_a_client_only(): void
    {
        $this->activateSubscription();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        // Module 1 is not marked hideable.
        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules/{$this->modules[1]->id}/hide")
            ->assertStatus(422);

        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules/{$this->modules[3]->id}/hide")
            ->assertOk()->assertJsonPath('module.is_hidden', true);

        $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules")->assertOk()
            ->assertJsonPath('programs.0.modules.2.is_hidden', true)
            ->assertJsonPath('programs.0.modules.2.lock_reason', 'hidden_by_therapist');

        // Another approved therapist has no relationship with this patient: 404, no enumeration.
        $otherUser = $this->makeUser('therapist', '+963900000812');
        Therapist::create([
            'user_id' => $otherUser->id, 'full_name' => 'Dr. Other', 'specialty' => 'cbt',
            'country' => 'DE', 'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
        Sanctum::actingAs($otherUser, ['*'], 'api');
        $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules")->assertNotFound();
        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules/{$this->modules[3]->id}/hide")
            ->assertNotFound();

        // The patient no longer sees module 3 at all.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $programs = $this->getJson('/api/v1/patients/programs')->assertOk()
            ->assertJsonPath('programs.0.modules_count', 2);
        $this->assertNotContains($this->modules[3]->id, array_column($programs->json('programs.0.modules'), 'id'));
        $this->getJson("/api/v1/patients/modules/{$this->modules[3]->id}")->assertNotFound();
        $this->postJson("/api/v1/patients/modules/{$this->modules[3]->id}/complete")->assertNotFound();

        // Unhide restores it in sequence.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->deleteJson("/api/v1/therapists/clients/{$this->patient->user_id}/modules/{$this->modules[3]->id}/hide")
            ->assertOk()->assertJsonPath('module.is_hidden', false);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/patients/programs')->assertOk()->assertJsonPath('programs.0.modules_count', 3);
    }

    public function test_admin_can_manage_content_fields_and_hideable_flag(): void
    {
        $head = $this->makeUser('clinical_supervisor', '+963900000813');
        Sanctum::actingAs($head, ['*'], 'api');

        $this->postJson("/api/v1/admin/programs/{$this->program->id}/modules", [
            'title' => 'Module 4', 'description' => 'd', 'content_type' => 'video',
            'media_url' => 'https://example.invalid/placeholder.mp4', 'is_hideable' => true,
            'homework_prompt' => '[PLACEHOLDER] homework',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('module.is_hideable', true)
            ->assertJsonPath('module.media_url', 'https://example.invalid/placeholder.mp4')
            ->assertJsonPath('module.homework_prompt', '[PLACEHOLDER] homework');

        $this->putJson("/api/v1/admin/programs/{$this->program->id}/modules/{$this->modules[1]->id}", [
            'body' => 'New body', 'is_hideable' => true,
        ])->assertOk()->assertJsonPath('module.body', 'New body')->assertJsonPath('module.is_hideable', true);
    }
}
