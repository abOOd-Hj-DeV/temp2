<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        config(['sakina.uploads_disk' => 'proofs']);
        Storage::fake('proofs');

        $this->patientUser = $this->makeUser('patient', '+963900000140');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Pkg Patient',
            'age' => 30, 'gender' => 'male', 'language' => 'en',
        ]);
        $this->therapistUser = $this->makeUser('therapist', '+963900000141');
        Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr Pkg',
            'specialty' => 'cbt', 'country' => 'JO',
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-18:00']),
            'approval_status' => 'approved',
        ]);
        $this->finance = $this->makeUser('finance_partner', '+963900000142');
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

    private function book(string $time, int $daysAhead = 1): TestResponse
    {
        return $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $this->therapistUser->id,
            'session_date' => now()->addDays($daysAhead)->toDateString(),
            'session_time' => $time, 'medium' => 'meet',
        ]);
    }

    /** Buy the 4-week package and approve it as finance. */
    private function approvedPackage(): Subscription
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $paymentId = $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks', 'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertCreated()->assertJsonPath('policy.refundable', false)->json('payment.id');

        Sanctum::actingAs($this->finance, ['*'], 'api');
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        return Subscription::firstOrFail();
    }

    public function test_cancelled_free_initial_session_is_not_consumed(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $id = $this->book('10:00', 3)->assertCreated()
            ->assertJsonPath('session.is_initial', true)
            ->assertJsonPath('session.payment_status', 'free')
            ->json('session.id');

        $this->postJson("/api/v1/sessions/{$id}/cancel")->assertOk();

        $this->book('11:00', 3)->assertCreated()
            ->assertJsonPath('session.is_initial', true)
            ->assertJsonPath('session.payment_status', 'free');

        // Once a trial is actually kept, the next booking is no longer free.
        $this->book('12:00', 3)->assertCreated()
            ->assertJsonPath('session.is_initial', false)
            ->assertJsonPath('session.payment_status', 'pending');
    }

    public function test_without_pay_per_session_a_package_is_required_after_the_trial(): void
    {
        config(['sakina.package_policy.allow_pay_per_session' => false]);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $this->book('10:00')->assertCreated()->assertJsonPath('session.is_initial', true);
        $this->book('11:00')->assertStatus(422)->assertJsonValidationErrors('subscription');

        $this->approvedPackage();
        $this->book('11:00')->assertCreated()
            ->assertJsonPath('session.payment_status', 'free')
            ->assertJsonPath('session.subscription_id', Subscription::first()->id);
    }

    public function test_quota_counts_only_sessions_charged_to_the_package(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        // Trial + a pay-per-session booking before the package exists.
        $this->book('09:00')->assertCreated();
        $this->book('10:00')->assertCreated()->assertJsonPath('session.payment_status', 'pending');

        $subscription = $this->approvedPackage();
        $subscription->update(['sessions_total' => 2, 'daily_sessions_quota' => 1]);

        $this->book('11:00')->assertCreated()->assertJsonPath('session.subscription_id', $subscription->id);
        // Same local day → per-day quota.
        $this->book('12:00')->assertStatus(422)->assertJsonValidationErrors('session_date');
        $this->book('11:00', 2)->assertCreated();
        // Package total (2) exhausted — earlier non-package sessions must not have counted.
        $this->book('11:00', 3)->assertStatus(422)->assertJsonValidationErrors('subscription');
        // Outside the package term.
        $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $this->therapistUser->id,
            'session_date' => $subscription->end_date->copy()->addDays(2)->toDateString(),
            'session_time' => '11:00', 'medium' => 'meet',
        ])->assertStatus(422)->assertJsonValidationErrors('session_date');

        $this->assertSame(2, TherapySession::where('subscription_id', $subscription->id)->count());
    }

    public function test_patient_cancels_package_without_refund_and_open_sessions_are_cancelled(): void
    {
        $subscription = $this->approvedPackage();
        // With a live package even the first session is charged to it.
        $this->book('09:00')->assertCreated()->assertJsonPath('session.subscription_id', $subscription->id);
        $open = $this->book('11:00', 3)->assertCreated()->json('session.id');
        $completedId = $this->book('11:00', 2)->assertCreated()->json('session.id');
        TherapySession::whereKey($open)->update(['status' => 'confirmed']);
        TherapySession::whereKey($completedId)->update(['status' => 'completed']);

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['reason' => 'moving abroad'])
            ->assertOk()
            ->assertJsonPath('cancelled_sessions', 2)
            ->assertJsonPath('subscription.is_active', false)
            ->assertJsonPath('subscription.policy.refundable', false);

        $this->assertDatabaseHas('therapy_sessions', ['id' => $open, 'status' => 'cancelled']);
        $this->assertDatabaseHas('therapy_sessions', ['id' => $completedId, 'status' => 'completed']);
        $this->assertDatabaseHas('session_status_logs', ['session_id' => $open, 'from_status' => 'confirmed', 'to_status' => 'cancelled']);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::SUBSCRIPTION_CANCELLED, 'entity_id' => $subscription->id]);
        // Money stays where it was.
        $this->assertSame('approved', Payment::where('subscription_id', $subscription->id)->firstOrFail()->status->value);

        // Cancelling twice is a conflict; the patient may now buy a new package.
        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")->assertStatus(409);
        $this->getJson('/api/v1/subscriptions/current')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks', 'proof' => UploadedFile::fake()->image('r2.png'),
        ])->assertCreated();
    }

    public function test_only_the_owner_or_staff_can_cancel_and_a_cancelled_pending_package_cannot_be_approved(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $paymentId = $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks', 'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertCreated()->json('payment.id');
        $subscription = Subscription::firstOrFail();

        $other = $this->makeUser('patient', '+963900000143');
        Patient::create(['user_id' => $other->id, 'full_name' => 'Other', 'age' => 22, 'gender' => 'female', 'language' => 'ar']);
        Sanctum::actingAs($other, ['*'], 'api');
        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")->assertNotFound();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/admin/subscriptions/{$subscription->id}/cancel", ['reason' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->finance, ['*'], 'api');
        $this->postJson("/api/v1/admin/subscriptions/{$subscription->id}/cancel")->assertStatus(422);
        $this->postJson("/api/v1/admin/subscriptions/{$subscription->id}/cancel", ['reason' => 'fraud check'])
            ->assertOk()->assertJsonPath('subscription.cancellation_reason', 'fraud check');

        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])->assertStatus(409);
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'reject', 'note' => 'cancelled'])->assertOk();
        $this->assertSame('rejected', $subscription->fresh()->verification_status);
    }
}
