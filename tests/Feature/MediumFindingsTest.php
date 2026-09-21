<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit follow-ups M2–M8: package revenue split across a therapist change,
 * one switch per package, reschedule re-validation, approved-profile lock,
 * staff session cancellation and payout-details encryption.
 */
class MediumFindingsTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        config(['sakina.platform_commission_rate' => 0.2]);

        $this->patientUser = $this->makeUser('patient', '+963900000180');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'MF Patient',
            'age' => 30, 'gender' => 'male', 'language' => 'en',
        ]);
        $this->therapistUser = $this->makeUser('therapist', '+963900000181');
        $this->makeTherapist($this->therapistUser);
        $this->admin = $this->makeUser('admin', '+963900000182');
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

    private function makeTherapist(User $user, string $status = 'approved'): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id, 'full_name' => "Dr {$user->id}",
            'specialty' => 'cbt', 'country' => 'JO',
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']),
            'approval_status' => $status,
        ]);
    }

    private function approvedPackage(int $sessions = 4, float $price = 200): Subscription
    {
        return Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'sessions_total' => $sessions, 'daily_sessions_quota' => 2,
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => $price, 'verification_status' => 'approved', 'therapist_id' => $this->therapistUser->id,
        ]);
    }

    private function book(User $therapist, string $time, int $daysAhead): TestResponse
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        return $this->postJson('/api/v1/sessions/book', [
            'therapist_id' => $therapist->id,
            'session_date' => now()->addDays($daysAhead)->toDateString(),
            'session_time' => $time, 'medium' => 'meet',
        ]);
    }

    private function deliver(string $sessionId): void
    {
        TherapySession::whereKey($sessionId)->update([
            'status' => 'completed', 'attendance_confirmed_at' => now(),
        ]);
    }

    private function switchTo(User $target): TestResponse
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        return $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $target->id, 'reason' => 'Prefer a different approach.',
        ]);
    }

    private function approveSwitch(string $switchId, User $target): void
    {
        Sanctum::actingAs($target, ['*'], 'api');
        $this->postJson("/api/v1/therapists/me/switch-requests/{$switchId}/decide", ['action' => 'accept'])->assertOk();
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => 'approve'])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_package_revenue_is_split_by_delivered_sessions_across_a_therapist_change(): void
    {
        $package = $this->approvedPackage(4, 200); // 50 per session, 40 net at 20% commission
        $first = $this->book($this->therapistUser, '09:00', 3)->assertCreated()->json('session.id');
        $this->deliver($first);

        $targetUser = $this->makeUser('therapist', '+963900000183');
        $this->makeTherapist($targetUser);
        $switchId = $this->switchTo($targetUser)->assertStatus(202)->json('data.id');
        $this->approveSwitch($switchId, $targetUser);

        $this->assertSame($targetUser->id, $package->refresh()->therapist_id);
        $this->assertSame(1, Therapist::find($targetUser->id)->clients_count);

        $second = $this->book($targetUser, '10:00', 4)->assertCreated()
            ->assertJsonPath('session.subscription_id', $package->id)->json('session.id');
        $this->deliver($second);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('earned', 40)
            ->assertJsonPath('pending_earnings', 0)
            ->assertJsonPath('earned_package_sessions', 1);

        Sanctum::actingAs($targetUser, ['*'], 'api');
        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('earned', 40)
            ->assertJsonPath('pending_earnings', 80); // two undelivered slices remain with the current therapist

        // A second switch on the same package is refused.
        $third = $this->makeUser('therapist', '+963900000184');
        $this->makeTherapist($third);
        $this->switchTo($third)->assertUnprocessable()->assertJsonValidationErrorFor('therapist');
        $this->assertSame(1, TherapistSwitch::count());
    }

    public function test_cancelled_package_has_no_pending_earnings(): void
    {
        $this->approvedPackage(4, 200);
        $first = $this->book($this->therapistUser, '09:00', 3)->assertCreated()->json('session.id');
        $this->deliver($first);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('earned', 40)->assertJsonPath('pending_earnings', 120);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/subscriptions/'.Subscription::first()->id.'/cancel')->assertOk();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('earned', 40)->assertJsonPath('pending_earnings', 0);
    }

    public function test_reschedule_approval_revalidates_availability_and_package_window(): void
    {
        $package = $this->approvedPackage();
        $id = $this->book($this->therapistUser, '09:00', 3)->assertCreated()->json('session.id');

        // Outside the therapist's 09:00-12:00 window: refused at request time.
        $this->postJson("/api/v1/sessions/{$id}/reschedule", [
            'session_date' => now()->addDays(4)->toDateString(), 'session_time' => '15:00',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('session_time');

        $this->postJson("/api/v1/sessions/{$id}/reschedule", [
            'session_date' => now()->addDays(4)->toDateString(), 'session_time' => '10:00',
        ])->assertStatus(202);

        // Availability shrinks before the therapist decides: approval is refused.
        Therapist::whereKey($this->therapistUser->id)->update([
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-09:30']),
        ]);
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('session_time');
        $this->assertNotNull(TherapySession::find($id)->reschedule_date);

        // Package ended in the meantime: approval is refused too.
        Therapist::whereKey($this->therapistUser->id)->update([
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']),
        ]);
        $package->update(['end_date' => now()->addDays(3)->toDateString()]);
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('session_date');

        $package->update(['end_date' => now()->addWeeks(4)->toDateString()]);
        $this->postJson("/api/v1/sessions/{$id}/reschedule/decide", ['action' => 'approve'])->assertOk();
        $this->assertSame('10:00', substr((string) TherapySession::find($id)->session_time, 0, 5));
    }

    public function test_approved_therapist_cannot_resubmit_for_approval(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        Therapist::whereKey($this->therapistUser->id)->update(['license_file_path' => 'licenses/x.pdf']);

        $this->postJson('/api/v1/therapists/me/approval')->assertStatus(409);
        $this->assertSame('approved', Therapist::find($this->therapistUser->id)->approval_status->value);
    }

    public function test_clinical_staff_can_cancel_a_session_with_an_audited_reason(): void
    {
        $id = $this->book($this->therapistUser, '09:00', 1)->assertCreated()->json('session.id');

        Sanctum::actingAs($this->makeUser('finance_partner', '+963900000185'), ['*'], 'api');
        $this->postJson("/api/v1/admin/sessions/{$id}/cancel", ['reason' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/sessions/{$id}/cancel")->assertUnprocessable();
        $this->postJson("/api/v1/admin/sessions/{$id}/cancel", ['reason' => 'Therapist unavailable (illness).'])
            ->assertOk()->assertJsonPath('session.status', 'cancelled');
        $this->postJson("/api/v1/admin/sessions/{$id}/cancel", ['reason' => 'again'])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::SESSION_CANCELLED_BY_STAFF, 'entity_id' => $id]);
    }

    public function test_payout_details_are_encrypted_at_rest(): void
    {
        $withdrawal = WalletWithdrawal::create([
            'therapist_id' => $this->therapistUser->id, 'amount' => 50,
            'status' => WalletWithdrawal::STATUS_PENDING,
            'payout_details' => ['method' => 'bank', 'iban' => 'JO94CBJO0010000000000131000302'],
        ]);

        $raw = DB::table('wallet_withdrawals')->where('id', $withdrawal->id)->value('payout_details');

        $this->assertStringNotContainsString('JO94CBJO', $raw);
        $this->assertSame('JO94CBJO0010000000000131000302', $withdrawal->fresh()->payout_details['iban']);
    }
}
