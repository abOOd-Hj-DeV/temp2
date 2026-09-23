<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
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

    public function test_first_session_is_free_once_then_configured_price_applies_without_subscription(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $free = $this->book()->assertCreated()
            ->assertJsonPath('session.status', 'pending')
            ->assertJsonPath('session.is_initial', true)
            ->assertJsonPath('session.payment_status', 'free')
            ->assertJsonPath('session.medium', 'zoom');
        $this->assertSame('0.00', $free->json('session.price'));

        $res = $this->book(['session_time' => '11:00'])->assertCreated()
            ->assertJsonPath('session.is_initial', false)
            ->assertJsonPath('session.payment_status', 'pending')
            ->assertJsonPath('session.subscription_id', null);

        $this->assertSame('50.00', $res->json('session.price'));
        $this->assertDatabaseHas('therapy_sessions', [
            'patient_id' => $this->patient->user_id,
            'is_initial' => false,
            'payment_status' => 'pending',
        ]);
        $this->assertSame($this->therapist->user_id, $this->patient->refresh()->therapist_id);
        // patient + therapist both notified in-app, once per booking
        $this->assertSame(2, $this->patientUser->notifications()->count());
        $this->assertSame(2, $this->therapistUser->notifications()->count());
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

    public function test_booking_another_therapist_does_not_reassign_the_patient(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertCreated();
        $this->assertSame($this->therapist->user_id, $this->patient->refresh()->therapist_id);

        $otherUser = $this->makeUser('therapist', '+963900000022');
        $other = Therapist::create([
            'user_id' => $otherUser->id, 'full_name' => 'Dr. Other', 'specialty' => 'x', 'country' => 'DE',
            'languages' => ['en'], 'availability' => $this->therapist->availability, 'approval_status' => 'approved',
        ]);

        $this->book(['therapist_id' => $other->user_id, 'session_time' => '11:00'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('therapist_id');

        $this->assertSame($this->therapist->user_id, $this->patient->refresh()->therapist_id);
        $this->assertDatabaseMissing('therapy_sessions', ['therapist_id' => $other->user_id]);
        $this->assertSame(0, $other->refresh()->clients_count);

        // Booking the assigned therapist again is still fine.
        $this->book(['session_time' => '11:00'])->assertCreated();
    }

    public function test_sessions_may_not_overlap_after_an_availability_shift(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->book()->assertCreated(); // 10:00-11:00

        // Therapist later shifts the day by half an hour: 09:30, 10:30, 11:30 slots.
        $day = strtolower(now()->addDay()->format('l'));
        $this->therapist->update(['availability' => array_merge($this->therapist->availability, [$day => ['09:30-12:30']])]);

        $this->getJson("/api/v1/therapists/{$this->therapist->user_id}/slots?date={$this->date}")
            ->assertOk()->assertJsonPath('slots', ['11:30']);

        $otherUser = $this->makeUser('patient', '+963900000023');
        Patient::create(['user_id' => $otherUser->id, 'full_name' => 'Second', 'age' => 30, 'gender' => 'other', 'language' => 'en']);
        Sanctum::actingAs($otherUser, ['*'], 'api');

        $this->book(['session_time' => '10:30'])->assertUnprocessable()->assertJsonValidationErrorFor('session_time');
        $this->book(['session_time' => '09:30'])->assertUnprocessable();
        $id = $this->book(['session_time' => '11:30'])->assertCreated()->json('session.id');

        // Rescheduling into an overlapping interval is refused as well.
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $this->date, 'session_time' => '10:30'])
            ->assertUnprocessable();

        // Back-to-back sessions remain allowed.
        $this->assertTrue(TherapySession::startTimesOverlap('10:00', '10:59'));
        $this->assertFalse(TherapySession::startTimesOverlap('10:00', '11:00'));
        $this->assertFalse(TherapySession::startTimesOverlap('11:00', '10:00'));
    }

    public function test_rescheduling_excludes_only_the_session_being_moved(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');
        $day = strtolower(now()->addDay()->format('l'));
        $this->therapist->update(['availability' => [$day => ['09:30-12:30']]]);

        $this->postJson("/api/v1/sessions/{$id}/reschedule", [
            'session_date' => $this->date, 'session_time' => '10:30',
        ])->assertAccepted();
        $this->assertSame('10:00', substr(TherapySession::findOrFail($id)->session_time, 0, 5));
        $this->getJson("/api/v1/therapists/{$this->therapist->user_id}/slots?date={$this->date}")
            ->assertOk()->assertJsonPath('slots', ['11:30']);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])->assertOk();
        $this->assertSame('10:30', substr(TherapySession::findOrFail($id)->session_time, 0, 5));
        $this->assertDatabaseCount('therapy_sessions', 1);
    }

    public function test_reschedule_approval_rechecks_conflicts_with_new_bookings(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');
        $day = strtolower(now()->addDay()->format('l'));
        $this->therapist->update(['availability' => [$day => ['10:30-13:30']]]);
        $this->postJson("/api/v1/sessions/{$id}/reschedule", [
            'session_date' => $this->date, 'session_time' => '10:30',
        ])->assertAccepted();

        $this->therapist->update(['availability' => [$day => ['11:00-14:00']]]);
        $other = $this->makeUser('patient', '+963900000090');
        Patient::create([
            'user_id' => $other->id, 'full_name' => 'Another patient',
            'age' => 30, 'gender' => 'other', 'language' => 'en',
        ]);
        Sanctum::actingAs($other, ['*'], 'api');
        $otherId = $this->book(['session_time' => '11:00'])->assertCreated()->json('session.id');

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('session_time');
        $this->assertSame('10:00', substr(TherapySession::findOrFail($id)->session_time, 0, 5));
        $this->assertSame('11:00', substr(TherapySession::findOrFail($otherId)->session_time, 0, 5));
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
        $this->book()->assertCreated(); // free initial session
        $id = $this->book(['session_time' => '11:00'])->assertCreated()->json('session.id'); // pending payment

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
        // Nor without the patient's attendance confirmation (no fabricated earnings).
        $this->postJson("/api/v1/sessions/{$id}/complete", ['summary' => 'went well'])
            ->assertStatus(422)->assertJsonValidationErrorFor('attendance');

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/attendance")->assertOk();
        $this->postJson("/api/v1/sessions/{$id}/attendance")->assertStatus(409);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
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

    public function test_reschedule_requires_therapist_approval_and_keeps_original_slot_until_then(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $id = $this->book()->assertCreated()->json('session.id');

        $newDate = now()->addDays(3)->toDateString();

        // A stranger patient cannot touch it.
        $stranger = $this->makeUser('patient', '+963900000077');
        Patient::create(['user_id' => $stranger->id, 'full_name' => 'S', 'age' => 25, 'gender' => 'other', 'language' => 'en']);
        Sanctum::actingAs($stranger, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $newDate, 'session_time' => '11:00'])
            ->assertForbidden();

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        // Outside availability -> 422; valid -> 202 and the booked slot is unchanged.
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $newDate, 'session_time' => '15:00'])
            ->assertStatus(422);
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $newDate, 'session_time' => '11:00'])
            ->assertStatus(202)->assertJsonPath('session.reschedule.date', $newDate);
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $newDate, 'session_time' => '11:30'])
            ->assertStatus(409);
        $this->assertSame($this->date, TherapySession::findOrFail($id)->session_date->toDateString());
        $this->assertSame('10:00', substr(TherapySession::findOrFail($id)->session_time, 0, 5));

        // The original slot is still reserved for the therapist.
        $this->book(['session_time' => '10:00'])->assertStatus(422);

        // Only the owning therapist decides.
        $other = $this->makeUser('therapist', '+963900000078');
        Therapist::create(['user_id' => $other->id, 'full_name' => 'Dr. O', 'specialty' => 'x', 'country' => 'DE', 'languages' => ['en'], 'approval_status' => 'approved']);
        Sanctum::actingAs($other, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])->assertStatus(422);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'reject'])
            ->assertOk()->assertJsonPath('session.reschedule', null);
        $this->assertSame($this->date, TherapySession::findOrFail($id)->session_date->toDateString());
        $this->assertDatabaseHas('therapy_sessions', ['id' => $id, 'reschedule_date' => null]);
        // Nothing left to decide.
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])->assertStatus(409);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule", ['session_date' => $newDate, 'session_time' => '11:00'])->assertStatus(202);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('session.session_date', $newDate);
        $moved = TherapySession::findOrFail($id);
        $this->assertSame($newDate, $moved->session_date->toDateString());
        $this->assertSame('11:00', substr($moved->session_time, 0, 5));
        $this->assertNull($moved->reschedule_date);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.reschedule_decided', 'entity_id' => $id]);

        // Attendance cannot be confirmed before the (new) start time; therapist cannot self-confirm.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/attendance")->assertStatus(422);
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/attendance")->assertForbidden();
    }
}
