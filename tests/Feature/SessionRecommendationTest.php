<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Patient;
use App\Models\Program;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private User $therapistUser;

    private User $otherTherapistUser;

    private Package $package;

    private Program $program;

    private int $sessionHour = 8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000870');
        Patient::create(['user_id' => $this->patientUser->id, 'full_name' => 'P', 'age' => 30, 'gender' => 'other', 'language' => 'en']);

        $this->therapistUser = $this->makeUser('therapist', '+963900000871');
        $this->makeTherapist($this->therapistUser);
        $this->otherTherapistUser = $this->makeUser('therapist', '+963900000872');
        $this->makeTherapist($this->otherTherapistUser);

        $this->package = Package::create([
            'code' => 'placeholder_4w', 'name' => 'Placeholder 4 weeks', 'price' => 120, 'number_of_sessions' => 4,
            'duration_days' => 28, 'is_published' => true,
        ]);
        $this->program = Program::create(['id' => (string) Str::uuid(), 'name' => 'Core', 'description' => 'd', 'is_core' => true]);
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com", 'password' => 'x',
            'whatsapp_number' => $whatsapp, 'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTherapist(User $user): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id, 'full_name' => "Dr {$user->whatsapp_number}", 'specialty' => 'cbt', 'country' => 'JO',
            'availability' => [], 'approval_status' => 'approved',
        ]);
    }

    private function makeSession(User $therapist, string $status): TherapySession
    {
        return TherapySession::create([
            'patient_id' => $this->patientUser->id, 'therapist_id' => $therapist->id,
            'session_date' => now()->subDays(2)->toDateString(),
            'session_time' => sprintf('%02d:00', $this->sessionHour++),
            'medium' => 'meet', 'status' => $status, 'is_initial' => true, 'price' => 0, 'payment_status' => 'free',
        ]);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');
    }

    public function test_therapist_recommends_after_completed_session_and_patient_sees_it(): void
    {
        $completed = $this->makeSession($this->therapistUser, 'completed');
        $pending = $this->makeSession($this->therapistUser, 'pending');
        $url = "/api/v1/sessions/{$completed->id}/recommendation";

        $this->actAs($this->therapistUser);
        $this->putJson("/api/v1/sessions/{$pending->id}/recommendation", ['package_id' => $this->package->id])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $this->putJson($url, ['package_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $this->putJson($url, ['program_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors(['program_id']);

        $draft = Package::create(['code' => 'draft', 'name' => 'Draft', 'price' => 10, 'number_of_sessions' => 1, 'duration_days' => 7, 'is_published' => false]);
        $this->putJson($url, ['package_id' => $draft->id])->assertStatus(422)->assertJsonValidationErrors(['package_id']);

        $res = $this->putJson($url, [
            'package_id' => $this->package->id, 'program_id' => $this->program->id, 'note' => 'Four weekly sessions plus the core program.',
        ])->assertOk()
            ->assertJsonPath('recommendation.package.code', 'placeholder_4w')
            ->assertJsonPath('recommendation.program.name', 'Core')
            ->assertJsonPath('recommendation.revision', 1);
        $id = $res->json('recommendation.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.recommendation_saved', 'entity_id' => $id]);
        $this->assertSame(1, $this->patientUser->notifications()->count());

        // Revising replaces the single recommendation for the session.
        $this->putJson($url, ['note' => 'Program only for now.'])->assertOk()
            ->assertJsonPath('recommendation.id', $id)
            ->assertJsonPath('recommendation.revision', 2)
            ->assertJsonPath('recommendation.package', null);
        $this->assertDatabaseCount('session_recommendations', 1);
        $this->assertSame(2, $this->patientUser->notifications()->count());

        // Patient reads it on the session, the post-session screen and the list.
        $this->actAs($this->patientUser);
        $this->getJson($url)->assertOk()->assertJsonPath('recommendation.note', 'Program only for now.');
        $this->getJson("/api/v1/patients/post-session/{$completed->id}")->assertOk()
            ->assertJsonPath('recommendation.id', $id)->assertJsonPath('recommendation.note', 'Program only for now.');
        $this->getJson("/api/v1/patients/post-session/{$pending->id}")->assertOk()->assertJsonPath('recommendation', null);
        $this->getJson('/api/v1/patients/recommendations')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.therapist_id', $this->therapistUser->id);

        // Patients cannot write recommendations.
        $this->putJson($url, ['note' => 'x'])->assertForbidden();
    }

    public function test_only_the_owning_therapist_and_participants_have_access(): void
    {
        $completed = $this->makeSession($this->therapistUser, 'completed');
        $url = "/api/v1/sessions/{$completed->id}/recommendation";

        $this->actAs($this->otherTherapistUser);
        $this->putJson($url, ['note' => 'Not my patient.'])->assertStatus(422)->assertJsonValidationErrors(['session']);
        $this->getJson($url)->assertForbidden();

        $this->actAs($this->therapistUser);
        $this->putJson($url, ['note' => 'Mine.'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('recommendation.note', 'Mine.');

        $stranger = $this->makeUser('patient', '+963900000873');
        Patient::create(['user_id' => $stranger->id, 'full_name' => 'S', 'age' => 30, 'gender' => 'other', 'language' => 'en']);
        $this->actAs($stranger);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/v1/patients/recommendations')->assertOk()->assertJsonCount(0, 'data');
    }
}
