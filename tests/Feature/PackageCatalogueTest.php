<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $headMaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        config(['sakina.uploads_disk' => 'proofs']);
        Storage::fake('proofs');

        $this->patientUser = $this->makeUser('patient', '+963900000090');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Pkg Patient', 'age' => 25, 'gender' => 'female', 'language' => 'en',
        ]);
        $this->headMaster = $this->makeUser('clinical_supervisor', '+963900000091');
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

    public function test_head_master_defines_and_publishes_packages_patients_only_see_published(): void
    {
        // Patients and therapists cannot manage the catalogue.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/admin/packages', ['code' => 'x', 'name' => 'x', 'price' => 1, 'number_of_sessions' => 1, 'duration_days' => 1])
            ->assertForbidden();

        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $id = $this->postJson('/api/v1/admin/packages', [
            'code' => 'intensive_12', 'name' => 'Intensive', 'price' => 400,
            'number_of_sessions' => 12, 'duration_days' => 42, 'daily_sessions_quota' => 2,
        ])->assertCreated()->assertJsonPath('package.is_published', false)->json('package.id');

        $this->postJson('/api/v1/admin/packages', ['code' => 'intensive_12', 'name' => 'Dup', 'price' => 1, 'number_of_sessions' => 1, 'duration_days' => 1])
            ->assertStatus(409);

        // Unpublished -> invisible to patients and not purchasable.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $codes = collect($this->getJson('/api/v1/subscriptions/packages')->assertOk()->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('intensive_12'));
        $this->assertTrue($codes->contains('4_weeks')); // legacy packages seeded as published
        $this->postJson('/api/v1/subscriptions', ['package_id' => $id, 'proof' => UploadedFile::fake()->image('r.png')])
            ->assertStatus(422);

        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $this->postJson("/api/v1/admin/packages/{$id}/publish")->assertOk()->assertJsonPath('package.is_published', true);
        $this->postJson("/api/v1/admin/packages/{$id}/publish")->assertStatus(409);
        // Terms are frozen while published; only copy may change.
        $this->putJson("/api/v1/admin/packages/{$id}", ['number_of_sessions' => 1])->assertStatus(409);
        $this->putJson("/api/v1/admin/packages/{$id}", ['name' => 'Intensive+'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.published', 'entity_id' => $id]);

        // Purchase copies the package terms onto the subscription.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $sub = $this->postJson('/api/v1/subscriptions', ['package_id' => $id, 'proof' => UploadedFile::fake()->image('r.png')])
            ->assertCreated()->json('subscription');
        $this->assertSame('intensive_12', $sub['type']);
        $this->assertSame(12, $sub['sessions_total']);
        $this->assertSame(42, $sub['duration_days']);
        $this->assertSame(2, $sub['daily_sessions_quota']);
        $this->assertEquals(400, $sub['price']);

        // Later catalogue edits do not touch what was bought.
        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $this->postJson("/api/v1/admin/packages/{$id}/unpublish")->assertOk();
        $this->putJson("/api/v1/admin/packages/{$id}", ['number_of_sessions' => 3])->assertOk();
        $this->assertSame(12, Subscription::findOrFail($sub['id'])->sessions_total);
    }

    public function test_publishing_requires_sessions_and_duration(): void
    {
        $package = Package::create(['code' => 'draft', 'name' => 'Draft', 'price' => 10, 'number_of_sessions' => 0, 'duration_days' => 0]);

        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $this->postJson("/api/v1/admin/packages/{$package->id}/publish")->assertStatus(422);
    }

    public function test_booking_enforces_package_daily_and_total_quota(): void
    {
        $therapistUser = $this->makeUser('therapist', '+963900000092');
        $therapist = Therapist::create([
            'user_id' => $therapistUser->id, 'full_name' => 'Dr. Q', 'specialty' => 'anxiety', 'country' => 'DE', 'languages' => ['en'],
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['09:00-12:00']),
            'approval_status' => 'approved', 'clients_limit' => 10,
        ]);
        Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => 'mini', 'sessions_total' => 2, 'duration_days' => 28, 'daily_sessions_quota' => 1,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(28)->toDateString(), 'price' => 50, 'verification_status' => 'approved',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $book = fn (string $date, string $time) => $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->user_id, 'session_date' => $date, 'session_time' => $time, 'medium' => 'zoom',
        ]);

        $d1 = now()->addDay()->toDateString();
        $d2 = now()->addDays(2)->toDateString();
        $d3 = now()->addDays(3)->toDateString();

        $book($d1, '09:00')->assertCreated();
        $book($d1, '10:00')->assertStatus(422)->assertJsonValidationErrorFor('session_date'); // daily quota
        $book($d2, '09:00')->assertCreated();
        $book($d3, '09:00')->assertStatus(422)->assertJsonValidationErrorFor('subscription'); // total quota

        // Cancelling frees a slot in the quota.
        $first = TherapySession::where('patient_id', $this->patient->user_id)->orderBy('session_date')->firstOrFail();
        $this->postJson("/api/v1/sessions/{$first->id}/cancel")->assertOk();
        $book($d3, '09:00')->assertCreated();
    }
}
