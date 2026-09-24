<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapistBlockedPeriod;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TherapistBlockedPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private User $therapistUser;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000860');
        Patient::create(['user_id' => $this->patientUser->id, 'full_name' => 'P', 'age' => 30, 'gender' => 'other', 'language' => 'en']);

        $this->therapistUser = $this->makeUser('therapist', '+963900000861', 'Asia/Amman');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr. Away', 'specialty' => 'anxiety', 'country' => 'JO',
            'languages' => ['en'],
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['09:00-12:00']),
            'approval_status' => 'approved',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role, string $whatsapp, string $tz = 'UTC'): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com", 'password' => 'x',
            'whatsapp_number' => $whatsapp, 'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
            'timezone' => $tz,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');
    }

    public function test_blocked_days_remove_slots_and_refuse_booking_then_reappear_on_delete(): void
    {
        $this->actAs($this->patientUser);
        $slotsUrl = "/api/v1/therapists/{$this->therapist->user_id}/slots?date=2026-10-06";
        $this->assertNotEmpty($this->getJson($slotsUrl)->assertOk()->json('slots'));

        // Therapist blocks Oct 5–7 (inclusive).
        $this->actAs($this->therapistUser);
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-05', 'end_date' => '2026-09-30'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-09-30'])
            ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-05', 'end_date' => '2027-02-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);

        $id = $this->postJson('/api/v1/therapists/me/blocked-periods', [
            'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'reason' => 'Conference',
        ])->assertCreated()->assertJsonPath('data.end_date', '2026-10-07')->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'therapist.blocked_period_created', 'entity_id' => $id]);

        // Overlapping period → 409; adjacent one is fine.
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-07', 'end_date' => '2026-10-09'])->assertStatus(409);
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-08'])->assertCreated();
        $this->assertCount(2, $this->getJson('/api/v1/therapists/me/blocked-periods')->assertOk()->json('data'));

        // Patient: no slots on a blocked day, slots on the day after; booking on a blocked day is refused.
        $this->actAs($this->patientUser);
        $this->assertSame([], $this->getJson($slotsUrl)->assertOk()->json('slots'));
        $this->assertNotEmpty($this->getJson("/api/v1/therapists/{$this->therapist->user_id}/slots?date=2026-10-09")->json('slots'));
        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $this->therapist->user_id, 'session_date' => '2026-10-06', 'session_time' => '07:00', 'medium' => 'zoom',
        ])->assertStatus(422)->assertJsonValidationErrors(['session_time']);

        // Delete → slots come back and booking succeeds.
        $this->actAs($this->therapistUser);
        $this->deleteJson("/api/v1/therapists/me/blocked-periods/{$id}")->assertOk();
        $this->assertDatabaseMissing('therapist_blocked_periods', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'therapist.blocked_period_deleted', 'entity_id' => $id]);

        $this->actAs($this->patientUser);
        $this->assertNotEmpty($this->getJson($slotsUrl)->json('slots'));
        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $this->therapist->user_id, 'session_date' => '2026-10-06', 'session_time' => '07:00', 'medium' => 'zoom',
        ])->assertCreated();

        // A period covering an already-booked session is refused.
        $this->actAs($this->therapistUser);
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-06'])
            ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    }

    public function test_ownership_and_roles(): void
    {
        $other = $this->makeUser('therapist', '+963900000862');
        Therapist::create([
            'user_id' => $other->id, 'full_name' => 'Dr. Other', 'specialty' => 'anxiety', 'country' => 'JO',
            'availability' => [], 'approval_status' => 'approved',
        ]);
        $period = TherapistBlockedPeriod::create([
            'therapist_id' => $this->therapist->user_id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-21',
        ]);

        $this->actAs($other);
        $this->assertCount(0, $this->getJson('/api/v1/therapists/me/blocked-periods')->assertOk()->json('data'));
        $this->deleteJson("/api/v1/therapists/me/blocked-periods/{$period->id}")->assertNotFound();
        $this->assertDatabaseHas('therapist_blocked_periods', ['id' => $period->id]);

        $this->actAs($this->patientUser);
        $this->getJson('/api/v1/therapists/me/blocked-periods')->assertForbidden();
        $this->postJson('/api/v1/therapists/me/blocked-periods', ['start_date' => '2026-10-25'])->assertForbidden();

        // Past periods are hidden unless requested.
        TherapistBlockedPeriod::create(['therapist_id' => $this->therapist->user_id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']);
        $this->actAs($this->therapistUser);
        $this->assertCount(1, $this->getJson('/api/v1/therapists/me/blocked-periods')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/therapists/me/blocked-periods?include_past=1')->json('data'));
    }
}
