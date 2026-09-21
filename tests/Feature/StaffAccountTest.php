<?php

namespace Tests\Feature;

use App\Jobs\CleanupUnverifiedUsersJob;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppSender $whatsApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->whatsApp = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->whatsApp);
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

    private function lastCode(): string
    {
        $message = end($this->whatsApp->messages)['message'];
        $this->assertMatchesRegularExpression('/activation code ([A-Z0-9]{8})/', $message);
        preg_match('/activation code ([A-Z0-9]{8})/', $message, $m);

        return $m[1];
    }

    private function therapistPayload(string $whatsapp = '+963900000502'): array
    {
        return [
            'name' => 'Dr. New', 'email' => 'Dr.New@Example.com', 'whatsapp_number' => $whatsapp, 'role' => 'therapist',
            'therapist' => ['specialty' => 'cbt', 'country' => 'JO', 'languages' => ['ar', 'en'], 'clients_limit' => 15],
        ];
    }

    public function test_bootstrap_command_creates_first_super_admin_once(): void
    {
        $this->artisan('sakina:bootstrap-super-admin', ['--name' => 'Root', '--email' => 'root@example.com', '--whatsapp' => '00962790000001'])
            ->assertSuccessful();

        $root = User::where('email', 'root@example.com')->firstOrFail();
        $this->assertSame('super_admin', $root->role->value);
        $this->assertSame('+962790000001', $root->whatsapp_number);
        $this->assertFalse($root->is_active);
        $this->assertNull($root->phone_verified_at);
        $this->assertTrue($root->hasRole('super_admin'));
        $this->assertCount(1, $this->whatsApp->messages);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::STAFF_ACCOUNT_CREATED, 'entity_id' => $root->id]);

        // No password yet: login is refused until the code is redeemed.
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => '+962790000001', 'password' => 'whatever1'])
            ->assertStatus(422);

        $code = $this->lastCode();
        $this->postJson('/api/v1/auth/activate', [
            'whatsapp_number' => '962790000001', 'code' => strtolower($code),
            'password' => 'Str0ngPassw0rd!', 'password_confirmation' => 'Str0ngPassw0rd!',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'user']);

        $root->refresh();
        $this->assertTrue($root->is_active);
        $this->assertNotNull($root->phone_verified_at);

        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => '+962790000001', 'password' => 'Str0ngPassw0rd!'])
            ->assertOk();

        // Second run is refused outright.
        $this->artisan('sakina:bootstrap-super-admin', ['--name' => 'Evil', '--email' => 'evil@example.com', '--whatsapp' => '+962790000002'])
            ->assertFailed();
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    public function test_activation_code_is_single_use_expiring_and_attempt_limited(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->makeUser('super_admin', '+963900000501');
        Sanctum::actingAs($admin, ['*'], 'api');

        $response = $this->postJson('/api/v1/admin/users', $this->therapistPayload())->assertCreated();
        $userId = $response->json('user.id');
        $code = $this->lastCode();

        $activate = fn (string $c) => $this->postJson('/api/v1/auth/activate', [
            'whatsapp_number' => '+963900000502', 'code' => $c, 'password' => 'Str0ngPassw0rd!', 'password_confirmation' => 'Str0ngPassw0rd!',
        ]);

        // Wrong codes burn attempts; the fifth wrong attempt revokes the invitation.
        for ($i = 0; $i < 4; $i++) {
            $activate('WRONGCD'.$i)->assertStatus(422);
        }
        $this->assertSame(4, AccountInvitation::where('user_id', $userId)->first()->attempts);
        $activate('WRONGCD9')->assertStatus(422);
        $this->assertNotNull(AccountInvitation::where('user_id', $userId)->first()->revoked_at);

        // Even the right code is now useless.
        $activate($code)->assertStatus(422);
        $this->assertNull(User::find($userId)->phone_verified_at);

        // Resend issues a fresh code (after the cooldown) and revokes the old one.
        $this->travel(61)->seconds();
        $this->postJson("/api/v1/admin/users/{$userId}/invitation/resend")->assertOk();
        $fresh = $this->lastCode();
        $this->assertNotSame($code, $fresh);

        // Expired codes are rejected.
        $this->travel(25)->hours();
        $activate($fresh)->assertStatus(422);

        $this->postJson("/api/v1/admin/users/{$userId}/invitation/resend")->assertOk();
        $activate($this->lastCode())->assertOk();

        // Single use: replaying the code fails once activated.
        $activate($this->lastCode())->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$userId}/invitation/resend")->assertStatus(422);

        $therapist = User::find($userId);
        $this->assertSame('dr.new@example.com', $therapist->email);
        $this->assertSame('approved', $therapist->therapist->approval_status->value);
        $this->assertSame(15, $therapist->therapist->clients_limit);
        $this->assertTrue($therapist->hasRole('therapist'));
    }

    public function test_role_matrix_is_enforced(): void
    {
        $admin = $this->makeUser('admin', '+963900000511');
        $head = $this->makeUser('clinical_supervisor', '+963900000512');
        $finance = $this->makeUser('finance_partner', '+963900000513');
        $therapist = $this->makeUser('therapist', '+963900000514');

        $staff = fn (string $role, string $wa) => ['name' => 'X', 'email' => "{$role}{$wa}@x.com", 'whatsapp_number' => $wa, 'role' => $role];

        Sanctum::actingAs($therapist, ['*'], 'api');
        $this->postJson('/api/v1/admin/users', $staff('therapist', '+963900000520'))->assertForbidden();

        Sanctum::actingAs($finance, ['*'], 'api');
        $this->postJson('/api/v1/admin/users', $staff('therapist', '+963900000520'))->assertForbidden();

        Sanctum::actingAs($head, ['*'], 'api');
        $this->postJson('/api/v1/admin/users', $staff('admin', '+963900000520'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $staff('finance_partner', '+963900000520'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $this->therapistPayload('+963900000520'))->assertCreated();

        Sanctum::actingAs($admin, ['*'], 'api');
        $this->postJson('/api/v1/admin/users', $staff('super_admin', '+963900000521'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $staff('admin', '+963900000521'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $staff('patient', '+963900000521'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $staff('finance_partner', '+963900000521'))->assertCreated();

        // Therapist role requires a profile.
        $this->postJson('/api/v1/admin/users', $staff('therapist', '+963900000522'))->assertStatus(422);

        // Duplicate identities are refused.
        $this->postJson('/api/v1/admin/users', $staff('finance_partner', '+963900000521'))->assertStatus(422);
        $this->postJson('/api/v1/admin/users', $staff('finance_partner', '+963900000511'))->assertStatus(422);

        // Listing excludes patients; the therapist cannot list.
        $this->makeUser('patient', '+963900000530');
        $roles = collect($this->getJson('/api/v1/admin/users?per_page=50')->assertOk()->json('data'))->pluck('role');
        $this->assertNotContains('patient', $roles);
        $this->assertContains('finance_partner', $roles);
        $this->assertSame(2, count($this->getJson('/api/v1/admin/users?pending_activation=1')->json('data')));
    }

    public function test_deactivation_revokes_tokens_and_respects_hierarchy(): void
    {
        $superAdmin = $this->makeUser('super_admin', '+963900000541');
        $admin = $this->makeUser('admin', '+963900000542');
        $therapist = $this->makeUser('therapist', '+963900000543');
        $patient = $this->makeUser('patient', '+963900000544');

        $therapistToken = $therapist->createToken('api')->plainTextToken;
        $this->withToken($therapistToken)->getJson('/api/v1/auth/user')->assertOk();

        Sanctum::actingAs($admin, ['*'], 'api');
        $this->patchJson("/api/v1/admin/users/{$superAdmin->id}/active", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$admin->id}/active", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$patient->id}/active", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$therapist->id}/active", ['is_active' => false])->assertOk();

        $this->assertFalse($therapist->refresh()->is_active);
        $this->assertSame(0, $therapist->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::STAFF_ACCOUNT_DEACTIVATED, 'entity_id' => $therapist->id]);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($therapistToken)->getJson('/api/v1/auth/user')->assertUnauthorized();

        Sanctum::actingAs($superAdmin, ['*'], 'api');
        $this->patchJson("/api/v1/admin/users/{$admin->id}/active", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/admin/users/{$therapist->id}/active", ['is_active' => true])->assertOk();
        $this->assertTrue($therapist->refresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', AuditLogService::STAFF_ACCOUNT_REACTIVATED)->count());
    }

    public function test_invited_accounts_cannot_be_hijacked_via_patient_registration_or_otp(): void
    {
        $admin = $this->makeUser('super_admin', '+963900000551');
        Sanctum::actingAs($admin, ['*'], 'api');
        $userId = $this->postJson('/api/v1/admin/users', [
            'name' => 'Fin', 'email' => 'fin@example.com', 'whatsapp_number' => '+963900000552', 'role' => 'finance_partner',
        ])->assertCreated()->json('user.id');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // Registering the same number must not turn the pending staff row into a patient login.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Attacker', 'email' => 'attacker@example.com', 'whatsapp_number' => '+963900000552',
            'password' => 'Attacker123!', 'password_confirmation' => 'Attacker123!',
        ])->assertStatus(422);

        // Resend answers generically (no enumeration) but sends nothing for a staff row.
        $this->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963900000552'])->assertOk();
        $this->assertCount(1, $this->whatsApp->messages);

        $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000552', 'otp' => '123456'])->assertStatus(422);

        $user = User::find($userId);
        $this->assertSame('finance_partner', $user->role->value);
        $this->assertSame('fin@example.com', $user->email);
        $this->assertNull($user->phone_verified_at);

        // The unverified-account sweeper leaves invited staff alone.
        $this->travel(3)->days();
        (new CleanupUnverifiedUsersJob(24))->handle();
        $this->assertNotNull(User::find($userId));
    }

    public function test_failed_whatsapp_delivery_rolls_back_the_account(): void
    {
        $admin = $this->makeUser('super_admin', '+963900000561');
        Sanctum::actingAs($admin, ['*'], 'api');
        $this->whatsApp->fail = true;

        $this->postJson('/api/v1/admin/users', $this->therapistPayload('+963900000562'))->assertStatus(422);

        $this->assertDatabaseMissing('users', ['whatsapp_number' => '+963900000562']);
        $this->assertSame(0, AccountInvitation::count());
    }
}
