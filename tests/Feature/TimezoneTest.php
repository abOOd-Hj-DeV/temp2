<?php

namespace Tests\Feature;

use App\Jobs\SendSessionRemindersJob;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppSender $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->whatsapp = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->whatsapp);
    }

    private function makeUser(string $role, string $whatsapp, string $timezone): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x',
            'whatsapp_number' => $whatsapp,
            'timezone' => $timezone,
            'role' => $role,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makePatient(string $timezone): Patient
    {
        $user = $this->makeUser('patient', '+963900000120', $timezone);

        return Patient::create([
            'user_id' => $user->id, 'full_name' => 'TZ Patient', 'age' => 30, 'gender' => 'other', 'language' => 'en',
        ]);
    }

    private function makeTherapist(string $timezone, array $availability): Therapist
    {
        $user = $this->makeUser('therapist', '+963900000121', $timezone);

        return Therapist::create([
            'user_id' => $user->id, 'full_name' => 'Dr. Zone', 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['en'], 'availability' => $availability, 'approval_status' => 'approved',
        ]);
    }

    public function test_slots_and_booking_are_converted_between_patient_and_therapist_zones(): void
    {
        // Monday 2026-10-05, 06:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-05 06:00:00', 'UTC'));

        // New York (EDT, UTC-4): Monday 09:00-12:00 local = 13:00-16:00 UTC.
        $therapist = $this->makeTherapist('America/New_York', ['monday' => ['09:00-12:00']]);
        // Riyadh (UTC+3): the same window is 16:00-19:00 local.
        $patient = $this->makePatient('Asia/Riyadh');

        Sanctum::actingAs($patient->user, ['*'], 'api');

        $this->getJson("/api/v1/therapists/{$therapist->user_id}/slots?date=2026-10-05")
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Riyadh')
            ->assertJsonPath('slots', ['16:00', '17:00', '18:00'])
            ->assertJsonPath('slot_details.0.starts_at', '2026-10-05T13:00:00.000000Z');

        $response = $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->user_id,
            'session_date' => '2026-10-05',
            'session_time' => '17:00',
            'medium' => 'zoom',
        ])->assertCreated();

        $response->assertJsonPath('session.session_date', '2026-10-05')
            ->assertJsonPath('session.session_time', '14:00')
            ->assertJsonPath('session.starts_at', '2026-10-05T14:00:00.000000Z')
            ->assertJsonPath('session.local.time', '17:00')
            ->assertJsonPath('session.local.timezone', 'Asia/Riyadh');

        // The therapist sees the same instant as 10:00 New York time and the slot is gone for others.
        Sanctum::actingAs($therapist->user, ['*'], 'api');
        $this->getJson('/api/v1/therapists/me/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.local.time', '10:00')
            ->assertJsonPath('data.0.local.timezone', 'America/New_York');

        Sanctum::actingAs($patient->user, ['*'], 'api');
        $this->getJson("/api/v1/therapists/{$therapist->user_id}/slots?date=2026-10-05")
            ->assertOk()->assertJsonPath('slots', ['16:00', '18:00']);

        // Booking confirmation quotes the patient's local time.
        $this->assertNotEmpty(array_filter(
            $this->whatsapp->messages,
            fn (array $m) => str_contains($m['message'], '2026-10-05 at 17:00 (Asia/Riyadh)')
        ));
    }

    public function test_slots_crossing_utc_midnight_land_on_the_correct_local_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:00:00', 'UTC'));

        // Auckland (NZDT, UTC+13): Tuesday 08:00-10:00 local = Monday 19:00-21:00 UTC.
        $therapist = $this->makeTherapist('Pacific/Auckland', ['tuesday' => ['08:00-10:00']]);
        $patient = $this->makePatient('Pacific/Auckland');

        Sanctum::actingAs($patient->user, ['*'], 'api');

        $this->getJson("/api/v1/therapists/{$therapist->user_id}/slots?date=2026-10-06")
            ->assertOk()->assertJsonPath('slots', ['08:00', '09:00']);

        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->user_id,
            'session_date' => '2026-10-06',
            'session_time' => '08:00',
            'medium' => 'zoom',
        ])->assertCreated()
            ->assertJsonPath('session.session_date', '2026-10-05')
            ->assertJsonPath('session.session_time', '19:00')
            ->assertJsonPath('session.local.date', '2026-10-06')
            ->assertJsonPath('session.local.time', '08:00');

    }

    public function test_today_is_judged_in_the_viewers_timezone(): void
    {
        // 03:00 UTC on the 6th is still the evening of the 5th in Los Angeles.
        Carbon::setTestNow(Carbon::parse('2026-10-06 03:00:00', 'UTC'));

        $therapist = $this->makeTherapist('America/Los_Angeles', ['monday' => ['20:00-23:00']]);
        $patient = $this->makePatient('America/Los_Angeles');

        Sanctum::actingAs($patient->user, ['*'], 'api');

        $this->getJson("/api/v1/therapists/{$therapist->user_id}/slots?date=2026-10-05")
            ->assertOk()->assertJsonPath('slots', ['21:00', '22:00']);

        $this->getJson("/api/v1/therapists/{$therapist->user_id}/slots?date=2026-10-04")
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        // 20:00 local already passed (it is 20:00 exactly) and is not bookable.
        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->user_id,
            'session_date' => '2026-10-05',
            'session_time' => '20:00',
            'medium' => 'zoom',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->user_id,
            'session_date' => '2026-10-05',
            'session_time' => '21:00',
            'medium' => 'zoom',
        ])->assertCreated()->assertJsonPath('session.session_date', '2026-10-06')->assertJsonPath('session.session_time', '04:00');
    }

    public function test_reminders_use_absolute_instants_and_local_wording(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));

        $therapist = $this->makeTherapist('UTC', ['tuesday' => ['09:00-12:00']]);
        $patient = $this->makePatient('Asia/Tokyo');

        TherapySession::create([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapist->user_id,
            'session_date' => '2026-10-06', 'session_time' => '11:30', 'medium' => 'zoom',
            'price' => 0, 'status' => 'confirmed', 'is_initial' => true, 'payment_status' => 'free',
        ]);

        (new SendSessionRemindersJob)->handle(
            app(SessionRepositoryInterface::class),
            app(NotificationService::class)
        );

        $reminders = array_values(array_filter($this->whatsapp->messages, fn (array $m) => str_contains($m['message'], 'reminder')));
        $this->assertCount(1, $reminders);
        $this->assertStringContainsString('2026-10-06 at 20:30 (Asia/Tokyo)', $reminders[0]['message']);
    }

    public function test_timezone_is_validated_on_registration_and_update(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'whatsapp_number' => '+963900000130',
            'timezone' => 'Mars/Olympus',
        ])->assertUnprocessable()->assertJsonValidationErrors('timezone');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'whatsapp_number' => '+963900000130',
            'timezone' => 'Europe/Berlin',
        ])->assertCreated();
        $this->assertSame('Europe/Berlin', User::where('whatsapp_number', '+963900000130')->firstOrFail()->timezone());

        $patient = $this->makePatient('UTC');
        Sanctum::actingAs($patient->user, ['*'], 'api');

        $this->putJson('/api/v1/auth/user/timezone', ['timezone' => 'Nowhere'])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/api/v1/auth/user/timezone', ['timezone' => 'Asia/Amman'])
            ->assertOk()->assertJsonPath('user.timezone', 'Asia/Amman');

        // Corrupt or empty values fall back to the application zone instead of throwing.
        $patient->user->forceFill(['timezone' => ''])->save();
        $this->assertSame('UTC', $patient->user->fresh()->timezone());
    }
}
