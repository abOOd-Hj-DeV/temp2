<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TherapistSwitchCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $oldTherapistUser;

    private User $newTherapistUser;

    private User $admin;

    private Subscription $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000820');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Switch Patient',
            'age' => 30, 'gender' => 'male', 'language' => 'en',
        ]);
        $this->oldTherapistUser = $this->makeUser('therapist', '+963900000821');
        $this->makeTherapist($this->oldTherapistUser);
        $this->newTherapistUser = $this->makeUser('therapist', '+963900000822');
        $this->makeTherapist($this->newTherapistUser);
        $this->admin = $this->makeUser('admin', '+963900000823');

        $this->package = Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'sessions_total' => 4, 'daily_sessions_quota' => 2,
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 200, 'verification_status' => 'approved', 'therapist_id' => $this->oldTherapistUser->id,
        ]);
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x', 'whatsapp_number' => $whatsapp, 'role' => $role,
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTherapist(User $user): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id, 'full_name' => "Dr {$user->id}", 'specialty' => 'cbt', 'country' => 'JO',
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']),
            'approval_status' => 'approved',
        ]);
    }

    private function book(User $therapist, string $time, int $daysAhead): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        return $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->id,
            'session_date' => now()->addDays($daysAhead)->toDateString(),
            'session_time' => $time, 'medium' => 'meet',
        ]);
    }

    private function requestSwitch(): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        return $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $this->newTherapistUser->id, 'reason' => 'Prefer a different approach.',
        ])->assertStatus(202)->json('data.id');
    }

    private function accept(string $switchId): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->newTherapistUser, ['*'], 'api');
        $this->postJson("/api/v1/therapists/me/switch-requests/{$switchId}/decide", ['action' => 'accept'])->assertOk();
    }

    private function review(string $switchId, string $action): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin, ['*'], 'api');

        return $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => $action]);
    }

    public function test_switch_reason_must_be_at_least_ten_characters(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $this->newTherapistUser->id, 'reason' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_approving_a_switch_cancels_open_sessions_with_the_previous_therapist_and_restores_capacity(): void
    {
        $delivered = $this->book($this->oldTherapistUser, '09:00', 4)->assertCreated()->json('session.id');
        TherapySession::whereKey($delivered)->update(['status' => 'completed', 'attendance_confirmed_at' => now()]);

        $openA = $this->book($this->oldTherapistUser, '10:00', 5)->assertCreated()->json('session.id');
        $openB = $this->book($this->oldTherapistUser, '11:00', 6)->assertCreated()->json('session.id');
        TherapySession::whereKey($openB)->update([
            'status' => 'confirmed', 'reschedule_date' => now()->addDays(7)->toDateString(), 'reschedule_time' => '09:00',
            'reschedule_requested_by' => $this->patientUser->id, 'reschedule_requested_at' => now(),
        ]);

        // 3 of 4 package sessions are taken.
        $this->book($this->oldTherapistUser, '09:00', 8)->assertCreated();
        $this->book($this->oldTherapistUser, '10:00', 9)->assertStatus(422)->assertJsonValidationErrors(['subscription']);
        $fourth = TherapySession::where('patient_id', $this->patientUser->id)->where('status', 'pending')
            ->orderByDesc('session_date')->firstOrFail();

        $switchId = $this->requestSwitch();
        $this->accept($switchId);

        // Rejection touches nothing.
        $rejectedProbe = TherapistSwitch::findOrFail($switchId);
        $this->assertNull($rejectedProbe->cancelled_session_ids);
        $this->assertSame(3, TherapySession::where('patient_id', $this->patientUser->id)
            ->whereIn('status', ['pending', 'confirmed'])->count());

        $response = $this->review($switchId, 'approve')->assertOk();
        $ids = $response->json('data.cancelled_session_ids');
        $this->assertEqualsCanonicalizing([$openA, $openB, $fourth->id], $ids);

        foreach ($ids as $id) {
            $session = TherapySession::findOrFail($id);
            $this->assertSame('cancelled', $session->status->value);
            $this->assertFalse((bool) $session->cancel_rejected);
            $this->assertNull($session->reschedule_date);
            $this->assertNull($session->cancel_requested_by);
            $this->assertDatabaseHas('session_status_logs', [
                'session_id' => $id, 'to_status' => 'cancelled', 'actor_id' => $this->admin->id,
            ]);
        }

        // The delivered session stays credited to the previous therapist.
        $this->assertSame('completed', TherapySession::findOrFail($delivered)->status->value);
        $this->assertSame($this->newTherapistUser->id, $this->patient->refresh()->therapist_id);
        $this->assertSame($this->newTherapistUser->id, $this->package->refresh()->therapist_id);
        $this->assertSame(1, Therapist::findOrFail($this->newTherapistUser->id)->clients_count);

        // Previous therapist is told which sessions were released.
        $notification = DB::table('notifications')
            ->where('notifiable_id', $this->oldTherapistUser->id)
            ->where('data', 'like', '%patient_transferred%')
            ->first();
        $this->assertNotNull($notification);
        $payload = json_decode((string) $notification->data, true);
        $this->assertEqualsCanonicalizing($ids, $payload['cancelled_session_ids']);

        // Capacity: 4 - 1 delivered = 3 bookable with the new therapist, then the quota bites again.
        $this->book($this->newTherapistUser, '09:00', 5)->assertCreated()->assertJsonPath('session.subscription_id', $this->package->id);
        $this->book($this->newTherapistUser, '10:00', 6)->assertCreated();
        $this->book($this->newTherapistUser, '11:00', 7)->assertCreated();
        $this->book($this->newTherapistUser, '09:00', 8)->assertStatus(422)->assertJsonValidationErrors(['subscription']);

        // Booking with the previous therapist is closed.
        $this->book($this->oldTherapistUser, '09:00', 10)->assertStatus(422)->assertJsonValidationErrors(['therapist_id']);

        // Re-deciding is a conflict and leaves everything untouched.
        $this->review($switchId, 'approve')->assertStatus(409);
        $this->assertSame(3, TherapySession::where('therapist_id', $this->oldTherapistUser->id)->where('status', 'cancelled')->count());
        $this->assertCount(3, DB::table('audit_logs')->where('action', 'session.transitioned')
            ->where('details', 'like', '%therapist_switch_approved%')->get());
    }

    public function test_second_switch_on_the_same_package_is_rejected(): void
    {
        $this->patient->update(['therapist_id' => $this->oldTherapistUser->id]);
        $switchId = $this->requestSwitch();
        $this->accept($switchId);
        $this->review($switchId, 'approve')->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser->fresh(), ['*'], 'api');
        $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $this->oldTherapistUser->id, 'reason' => 'Changed my mind again.',
        ])->assertStatus(422)->assertJsonValidationErrors(['therapist']);
    }
}
