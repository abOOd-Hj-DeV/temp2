<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private Therapist $therapist;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000020');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id,
            'full_name' => 'Booking Patient', 'age' => 30,
            'gender' => 'other', 'language' => 'en',
        ]);

        $this->therapistUser = $this->makeUser('therapist', '+963900000021');
        $this->therapist = Therapist::create([
            'user_id' => $this->therapistUser->id,
            'full_name' => 'Dr. Book', 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['en'],
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']
            ),
            'approval_status' => 'approved',
        ]);

        $this->date = now()->addDay()->toDateString();
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

    private function book(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/sessions/book', array_merge([
            'therapist_id' => $this->therapist->user_id,
            'session_date' => $this->date,
            'session_time' => '10:00',
            'medium' => 'zoom',
        ], $overrides));
    }

    public function test_booking_without_subscription_charges_configured_price(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $res = $this->book()->assertCreated()
            ->assertJsonPath('session.status', 'pending')
            ->assertJsonPath('session.is_initial', true)
            ->assertJsonPath('session.payment_status', 'pending')
            ->assertJsonPath('session.medium', 'zoom');

        $this->assertSame('50.00', $res->json('session.price'));
        $this->assertDatabaseHas('therapy_sessions', [
            'patient_id' => $this->patient->user_id,
            'is_initial' => true,
            'payment_status' => 'pending',
        ]);
        $this->assertSame($this->therapist->user_id, $this->patient->refresh()->therapist_id);
        // patient + therapist both notified in-app
        $this->assertSame(1, $this->patientUser->notifications()->count());
        $this->assertSame(1, $this->therapistUser->notifications()->count());
        // status log records the pending transition
        $this->assertDatabaseHas('session_status_logs', ['to_status' => 'pending']);
    }

    public function test_initial_session_is_free_with_active_subscription(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id,
            'type' => '4_weeks',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertCreated()
            ->assertJsonPath('session.price', '0.00')
            ->assertJsonPath('session.payment_status', 'free')
            ->assertJsonPath('session.is_initial', true);
    }

    public function test_second_booking_is_not_initial(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertCreated();
        $this->book(['session_time' => '11:00'])
            ->assertCreated()
            ->assertJsonPath('session.is_initial', false);
    }

    public function test_double_booking_same_slot_is_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertCreated();
        $this->book()->assertStatus(422);
    }

    public function test_booking_outside_availability_is_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book(['session_time' => '20:00'])->assertStatus(422);
    }

    public function test_booking_unapproved_therapist_is_rejected(): void
    {
        $this->therapist->update(['approval_status' => 'pending']);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertStatus(422);
    }

    public function test_booking_past_slot_is_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book(['session_date' => '2020-01-01'])->assertStatus(422);
    }

    public function test_therapist_cannot_book_as_patient_route(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->book()->assertForbidden();
    }

    public function test_cancel_frees_slot_and_logs_status(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');

        $this->postJson("/api/v1/sessions/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        $this->assertDatabaseHas('session_status_logs', [
            'session_id' => $id, 'from_status' => 'pending', 'to_status' => 'cancelled',
        ]);

        // Freed slot: same slot is bookable again after cancel.
        $this->book()->assertCreated();
    }

    public function test_confirm_requires_non_pending_payment(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id'); // pending payment

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/confirm")->assertStatus(422);
    }

    public function test_therapist_lifecycle_confirm_link_complete(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id,
            'type' => '4_weeks', 'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id'); // free → pending status

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/confirm")
            ->assertOk()->assertJsonPath('session.status', 'confirmed');
        $this->postJson("/api/v1/sessions/{$id}/link", ['link' => 'https://meet.example.com/abc'])
            ->assertStatus(422);
        $this->postJson("/api/v1/sessions/{$id}/link", ['link' => 'https://zoom.us/j/123456'])
            ->assertOk()->assertJsonPath('session.link', 'https://zoom.us/j/123456');

        // Cannot complete before the session starts.
        $this->postJson("/api/v1/sessions/{$id}/complete", ['summary' => 'went well'])
            ->assertStatus(422);

        $this->travelTo(now()->addDays(2));
        $this->postJson("/api/v1/sessions/{$id}/complete", ['summary' => 'went well'])
            ->assertOk()->assertJsonPath('session.status', 'completed');

        $this->assertDatabaseHas('therapy_sessions', ['id' => $id, 'summary' => 'went well']);
        $this->assertDatabaseHas('session_status_logs', [
            'session_id' => $id, 'from_status' => 'confirmed', 'to_status' => 'completed',
        ]);
    }

    public function test_other_therapist_cannot_manage_session(): void
    {
        $other = $this->makeUser('therapist', '+963900000022');
        Therapist::create([
            'user_id' => $other->id, 'full_name' => 'Other', 'specialty' => 'x',
            'country' => 'US', 'approval_status' => 'approved',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');

        Sanctum::actingAs($other, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/confirm")->assertStatus(422);
    }

    public function test_session_show_is_participant_only(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');

        $this->getJson("/api/v1/sessions/{$id}")->assertOk();

        $stranger = $this->makeUser('patient', '+963900000023');
        Sanctum::actingAs($stranger, ['*'], 'api');
        $this->getJson("/api/v1/sessions/{$id}")->assertForbidden();
        $this->postJson("/api/v1/sessions/{$id}/cancel")->assertForbidden();
    }

    public function test_guest_cannot_book(): void
    {
        $this->book()->assertUnauthorized();
    }
}
