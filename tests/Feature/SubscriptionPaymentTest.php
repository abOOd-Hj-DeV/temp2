<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $admin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        config(['sakina.uploads_disk' => 'proofs']);
        Storage::fake('proofs');

        $this->patientUser = $this->makeUser('patient', '+963900000030');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id,
            'full_name' => 'Sub Patient', 'age' => 25,
            'gender' => 'female', 'language' => 'en',
        ]);
        $this->admin = $this->makeUser('admin', '+963900000031');
        $this->finance = $this->makeUser('finance_partner', '+963900000032');
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

    private function subscribe(): TestResponse
    {
        return $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks',
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ]);
    }

    public function test_subscription_upload_stores_proof_creates_payment_and_queues_review(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $res = $this->subscribe()->assertCreated()
            ->assertJsonPath('subscription.type', '4_weeks')
            ->assertJsonPath('subscription.verification_status', 'pending')
            ->assertJsonPath('subscription.is_active', false)
            ->assertJsonPath('payment.status', 'pending');

        $sub = Subscription::find($res->json('subscription.id'));
        $this->assertNull($sub->start_date);   // dates land on approval
        $this->assertNull($sub->end_date);
        Storage::disk('proofs')->assertExists($sub->payment_proof_path);

        $this->assertDatabaseHas('payments', [
            'subscription_id' => $sub->id,
            'status' => 'pending',
            'therapy_session_id' => null,
        ]);

        // QUEUE_CONNECTION=sync → review job ran → reviewers notified.
        $this->assertSame(1, $this->finance->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_missing_proof_or_bad_type_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/subscriptions', ['type' => '4_weeks'])->assertStatus(422);
        $this->postJson('/api/v1/subscriptions', [
            'type' => '99_weeks',
            'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertStatus(422);
    }

    public function test_duplicate_pending_subscription_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->subscribe()->assertCreated();
        $this->subscribe()->assertStatus(422);
    }

    public function test_approval_activates_subscription_and_notifies_patient(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $paymentId = $this->subscribe()->assertCreated()->json('payment.id');

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId);

        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'approved')
            ->assertJsonPath('payment.reviewer_id', $this->admin->id);

        $sub = Subscription::where('patient_id', $this->patient->user_id)->sole();
        $this->assertSame('approved', $sub->verification_status);
        $this->assertNotNull($sub->start_date);
        $this->assertNotNull($sub->end_date);
        $this->assertTrue($sub->end_date->gt(now()->addWeeks(3)));
        $this->assertTrue($sub->is_active);
        $this->assertSame($sub->id, $this->patient->refresh()->subscription_id);
        $this->assertSame(1, $this->patientUser->notifications()->count());
    }

    public function test_rejection_requires_note_and_marks_rejected(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $paymentId = $this->subscribe()->assertCreated()->json('payment.id');

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'reject'])
            ->assertStatus(422); // note required on reject

        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", [
            'action' => 'reject', 'note' => 'illegible',
        ])->assertOk()->assertJsonPath('payment.status', 'rejected');

        $this->assertSame('rejected', Subscription::first()->verification_status);
        // Double-review is blocked.
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])
            ->assertStatus(409);
    }

    public function test_session_payment_proof_flow(): void
    {
        // Book a paid session first.
        $therapistUser = $this->makeUser('therapist', '+963900000033');
        Therapist::create([
            'user_id' => $therapistUser->id, 'full_name' => 'Dr P',
            'specialty' => 'x', 'country' => 'US',
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']),
            'approval_status' => 'approved',
        ]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $book = fn (string $time) => $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapistUser->id,
            'session_date' => now()->addDay()->toDateString(),
            'session_time' => $time, 'medium' => 'meet',
        ]);
        $book('10:00')->assertCreated()->assertJsonPath('session.payment_status', 'free'); // free initial
        $sessionId = $book('11:00')->assertCreated()->assertJsonPath('session.payment_status', 'pending')->json('session.id');

        $res = $this->postJson("/api/v1/sessions/{$sessionId}/proof", [
            'proof' => UploadedFile::fake()->image('pay.png'),
        ])->assertStatus(202)->assertJsonPath('payment.status', 'pending');

        $paymentId = $res->json('payment.id');
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId, 'therapy_session_id' => $sessionId, 'subscription_id' => null,
        ]);

        // Second proof while one is pending → rejected.
        $this->postJson("/api/v1/sessions/{$sessionId}/proof", [
            'proof' => UploadedFile::fake()->image('pay2.png'),
        ])->assertStatus(422);

        Sanctum::actingAs($this->finance, ['*'], 'api');
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('therapy_sessions', [
            'id' => $sessionId, 'payment_status' => 'paid', 'status' => 'confirmed',
        ]);
        // booked(2) + reviewed(1) + status-changed(1)
        $this->assertSame(4, $this->patientUser->notifications()->count());
    }

    public function test_patient_cannot_review_payments(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $paymentId = $this->subscribe()->assertCreated()->json('payment.id');

        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])->assertForbidden();
    }

    public function test_subscription_endpoints_require_patient_role(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks', 'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertForbidden();
        $this->getJson('/api/v1/subscriptions/current')->assertForbidden();
    }
}
