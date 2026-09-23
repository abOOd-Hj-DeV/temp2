<?php

namespace Tests\Feature;

use App\Models\Therapist;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TherapistApiTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private User $therapistUser;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000010');
        $this->therapistUser = $this->makeUser('therapist', '+963900000011');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id,
            'full_name' => 'Dr. Test',
            'specialty' => 'anxiety',
            'country' => 'DE',
            'languages' => ['ar', 'en'],
            'availability' => [
                'monday' => ['09:00-12:00'],
                'tuesday' => ['09:00-12:00'],
                'wednesday' => ['09:00-12:00'],
                'thursday' => ['09:00-12:00'],
                'friday' => ['09:00-12:00'],
                'saturday' => ['09:00-12:00'],
                'sunday' => ['09:00-12:00'],
            ],
            'approval_status' => 'approved',
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

    private function nextWeekdayWithSlots(): string
    {
        // Availability is set for every weekday in setUp — tomorrow always works.
        return now()->addDay()->toDateString();
    }

    public function test_patient_lists_approved_therapists_only(): void
    {
        $pending = $this->makeUser('therapist', '+963900000012');
        Therapist::create([
            'user_id' => $pending->id, 'full_name' => 'Pending Dr',
            'specialty' => 'anxiety', 'country' => 'DE',
            'approval_status' => 'pending',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $res = $this->getJson('/api/v1/therapists')->assertOk();

        $names = collect($res->json('data'))->pluck('full_name');
        $this->assertContains('Dr. Test', $names);
        $this->assertNotContains('Pending Dr', $names);
    }

    public function test_therapist_show_and_filters(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $this->getJson("/api/v1/therapists/{$this->therapist->user_id}")
            ->assertOk()
            ->assertJsonPath('data.specialty', 'anxiety')
            ->assertJsonPath('data.approval_status', 'approved');

        $this->getJson('/api/v1/therapists?specialty=nope')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/therapists?language=ar')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_slots_endpoint_returns_availability_windows(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $date = $this->nextWeekdayWithSlots();

        $res = $this->getJson("/api/v1/therapists/{$this->therapist->user_id}/slots?date={$date}")
            ->assertOk();

        $this->assertSame(['09:00', '10:00', '11:00'], $res->json('slots'));
    }

    public function test_unapproved_therapist_is_not_visible(): void
    {
        $this->therapist->update(['approval_status' => 'pending']);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson("/api/v1/therapists/{$this->therapist->user_id}")
            ->assertStatus(422);
    }

    public function test_therapist_settings_update_whitelists_fields(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        $this->putJson('/api/v1/therapists/me/settings', [
            'bio' => 'new bio',
            'availability' => ['monday' => ['13:00-15:00']],
            'rating' => 99,           // must be ignored — system-owned
            'approval_status' => 'approved', // must be ignored
        ])->assertOk()->assertJsonPath('data.bio', 'new bio');

        $fresh = $this->therapist->refresh();
        $this->assertSame(['monday' => ['13:00-15:00']], $fresh->availability);
        $this->assertSame(0.0, (float) $fresh->rating);
        $this->assertSame('approved', $fresh->approval_status->value);
    }

    public function test_bad_availability_shape_rejected(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');

        $this->putJson('/api/v1/therapists/me/settings', [
            'availability' => ['funday' => ['9-5']],
        ])->assertStatus(422);

        $this->putJson('/api/v1/therapists/me/settings', [
            'availability' => ['monday' => ['morning']],
        ])->assertStatus(422);

        $this->putJson('/api/v1/therapists/me/settings', [
            'availability' => ['monday' => ['12:00-09:00']],
        ])->assertStatus(422);

        $this->putJson('/api/v1/therapists/me/settings', [
            'availability' => ['monday' => ['09:30-09:45']],
        ])->assertOk(); // same-hour ascending window is valid
    }

    public function test_patient_cannot_use_therapist_self_routes(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/therapists/me/dashboard')->assertForbidden();
    }

    public function test_guest_cannot_list_therapists(): void
    {
        $this->getJson('/api/v1/therapists')->assertUnauthorized();
    }

    public function test_approval_flow_repending_then_admin_approve(): void
    {
        $this->therapist->update(['approval_status' => 'rejected']);
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/therapists/me/approval')->assertStatus(422);

        $this->postJson('/api/v1/therapists/me/approval', [
            'license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
        ])->assertStatus(202);
        $this->assertSame('pending', $this->therapist->refresh()->approval_status->value);

        $admin = $this->makeUser('admin', '+963900000013');
        Sanctum::actingAs($admin, ['*'], 'api');

        $this->getJson('/api/v1/admin/therapists')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/therapists/{$this->therapist->user_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');
        $this->assertSame('approved', $this->therapist->refresh()->approval_status->value);
        $this->assertSame(1, $this->therapistUser->notifications()->count());
    }

    public function test_patient_cannot_approve_therapists(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson("/api/v1/admin/therapists/{$this->therapist->user_id}/approve")
            ->assertForbidden();
    }
}
