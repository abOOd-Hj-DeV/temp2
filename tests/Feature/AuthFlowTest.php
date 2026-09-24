<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->sender = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->sender);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Patient',
            'email' => 'patient@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'whatsapp_number' => '+963900000001',
            'privacy_accepted' => true,
        ], $overrides);
    }

    private function lastOtp(): string
    {
        preg_match('/(\d{6})/', end($this->sender->messages)['message'], $m);

        return $m[1];
    }

    private function makeStaff(string $role = 'admin'): User
    {
        $u = User::create([
            'name' => 'Staff', 'email' => 'staff@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => $role, 'whatsapp_number' => '+963900000777',
            'is_active' => true, 'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_staff_login_requires_whatsapp_2fa(): void
    {
        $staff = $this->makeStaff('admin');

        // Resend endpoint exists and is generic. (Called first: anonymous
        // throttles share one bucket per IP, capped by this route's 3/min.)
        $this->postJson('/api/v1/auth/login/2fa/resend', [
            'whatsapp_number' => '+963900000777',
        ])->assertOk();

        // Password alone gets no token — an OTP goes out instead.
        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000777',
            'password' => 'Secret123!',
        ])->assertOk()
            ->assertJsonPath('requires_2fa', true)
            ->assertJsonMissing(['access_token']);
        $this->assertNotEmpty($this->sender->messages);

        // Wrong code rejected.
        $this->postJson('/api/v1/auth/login/2fa', [
            'whatsapp_number' => '+963900000777',
            'otp' => '000000',
        ])->assertUnprocessable();

        // Right code mints a usable token.
        $this->postJson('/api/v1/auth/login/2fa', [
            'whatsapp_number' => '+963900000777',
            'otp' => $this->lastOtp(),
        ])->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token']);

        // Patient login stays one-factor (no 2FA).
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['whatsapp_number' => '+963900000002']));
        $this->postJson('/api/v1/auth/otp/verify', [
            'whatsapp_number' => '+963900000002', 'otp' => $this->lastOtp(),
        ]);
        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000002',
            'password' => 'Secret123!',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_register_creates_unverified_patient_and_sends_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertCreated()->assertJsonPath('user_id', fn ($id) => is_string($id));

        $user = User::where('whatsapp_number', '+963900000001')->firstOrFail();
        $this->assertSame(UserRole::PATIENT, $user->role);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->phone_verified_at);
        $this->assertTrue($user->hasRole('patient'));
        $this->assertCount(1, $this->sender->messages);
    }

    public function test_register_rolls_back_when_the_otp_cannot_be_delivered(): void
    {
        $sender = new class implements WhatsAppSenderInterface
        {
            public bool $fail = true;

            public function send(string $phoneNumber, string $message): bool
            {
                return ! $this->fail;
            }
        };
        $this->app->instance(WhatsAppSenderInterface::class, $sender);

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(422);
        $this->assertDatabaseMissing('users', ['whatsapp_number' => '+963900000001']);

        // Email is stored normalised, and re-used case-insensitively.
        $sender->fail = false;
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => '  Patient@Example.COM ']))->assertCreated();
        $this->assertDatabaseHas('users', ['whatsapp_number' => '+963900000001', 'email' => 'patient@example.com']);
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'PATIENT@example.com', 'whatsapp_number' => '+963900000002']))
            ->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_register_never_accepts_a_client_supplied_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'role' => 'super_admin',
        ]));

        $response->assertCreated();
        $this->assertSame(
            UserRole::PATIENT,
            User::where('whatsapp_number', '+963900000001')->firstOrFail()->role
        );
    }

    public function test_full_registration_otp_login_logout_flow(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $verify = $this->postJson('/api/v1/auth/otp/verify', [
            'whatsapp_number' => '+963900000001',
            'otp' => $this->lastOtp(),
        ]);

        $verify->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_at']);
        $token = $verify->json('access_token');

        $user = User::where('whatsapp_number', '+963900000001')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->phone_verified_at);

        $this->getJson('/api/v1/auth/user', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('user.email', 'patient@example.com');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // Token row must be gone — revoked tokens can never authenticate again.
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_wrong_otp_is_rejected_and_limited_to_five_attempts(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'whatsapp_number' => '+963900000001',
                'otp' => '000000',
            ])->assertUnprocessable();
        }

        // OTP invalidated after max attempts — even the real code no longer works.
        $this->postJson('/api/v1/auth/otp/verify', [
            'whatsapp_number' => '+963900000001',
            'otp' => $this->lastOtp(),
        ])->assertUnprocessable();
    }

    public function test_login_requires_verified_account(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000001',
            'password' => 'Secret123!',
        ])->assertUnprocessable();
    }

    public function test_login_lockout_after_five_failures(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $this->postJson('/api/v1/auth/otp/verify', [
            'whatsapp_number' => '+963900000001',
            'otp' => $this->lastOtp(),
        ])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'whatsapp_number' => '+963900000001',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        // Locked: even the correct password is rejected during lockout.
        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000001',
            'password' => 'Secret123!',
        ])->assertUnprocessable();
    }

    public function test_password_reset_flow(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $this->postJson('/api/v1/auth/otp/verify', [
            'whatsapp_number' => '+963900000001',
            'otp' => $this->lastOtp(),
        ])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', [
            'whatsapp_number' => '+963900000001',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'whatsapp_number' => '+963900000001',
            'otp' => $this->lastOtp(),
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000001',
            'password' => 'NewSecret123!',
        ])->assertOk();
    }

    public function test_otp_endpoints_do_not_reveal_whether_a_number_is_registered(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $otp = $this->lastOtp();
        $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000001', 'otp' => $otp])->assertOk();

        $unknown = $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000999', 'otp' => '123456'])->assertStatus(422)->json();
        $verified = $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000001', 'otp' => '123456'])->assertStatus(422)->json();
        $this->assertSame($unknown, $verified);

        $this->travel(61)->seconds(); // unauthenticated throttle counter is shared per IP
        $sentBefore = count($this->sender->messages);
        $unknown = $this->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963900000999'])->assertOk()->json();
        $verified = $this->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963900000001'])->assertOk()->json();
        $this->assertSame($unknown, $verified);
        $this->assertCount($sentBefore, $this->sender->messages);

        // Pending account on resend cooldown: still the same 200 body, no second code.
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['whatsapp_number' => '+963900000002', 'email' => 'p2@example.com']))->assertCreated();
        $this->travel(61)->seconds(); // clear the throttle window, then re-enter the OTP cooldown
        $this->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963900000002'])->assertOk();
        $sentBefore = count($this->sender->messages);
        $pending = $this->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963900000002'])->assertOk()->json();
        $this->assertSame($unknown, $pending);
        $this->assertCount($sentBefore, $this->sender->messages);
    }

    public function test_login_lockout_does_not_reveal_whether_a_number_is_registered(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000001', 'otp' => $this->lastOtp()])->assertOk();

        // Each number from its own client so the per-IP throttle stays out of the way.
        $attempt = fn (string $number, string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['whatsapp_number' => $number, 'password' => 'wrong-password'])
            ->assertUnprocessable()->json();

        $unknownFailures = [];
        for ($i = 0; $i < 6; $i++) {
            $unknownFailures[] = $attempt('+963900000999', '10.0.0.1');
        }

        $knownFailures = [];
        for ($i = 0; $i < 6; $i++) {
            $knownFailures[] = $attempt('+963900000001', '10.0.0.2');
        }

        // Same bodies attempt-for-attempt: five "invalid credentials", then lockout.
        $this->assertSame($knownFailures, $unknownFailures);
        $this->assertNotSame($unknownFailures[0], $unknownFailures[5]);
        $this->assertStringContainsString('Too many failed attempts', json_encode($unknownFailures[5]));
    }

    public function test_login_lockout_counts_attempts_per_normalised_number(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => '+963900000001', 'otp' => $this->lastOtp()])->assertOk();

        foreach (['+963900000001', '963900000001', '+963900000001', '963900000001', '+963900000001'] as $number) {
            $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $number, 'password' => 'wrong-password'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => '+963900000001', 'password' => 'Secret123!'])
            ->assertUnprocessable()->assertJsonPath('errors.whatsapp_number.0', fn ($m) => str_contains($m, 'Too many failed attempts'));
    }

    public function test_throttled_or_failed_reregistration_leaves_the_pending_account_untouched(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $pending = User::where('whatsapp_number', '+963900000001')->firstOrFail();
        $before = $pending->only(['name', 'email', 'password']);

        $hijack = $this->registerPayload([
            'name' => 'Someone Else', 'email' => 'else@example.com',
            'password' => 'Other123!', 'password_confirmation' => 'Other123!',
        ]);

        // OTP resend cooldown still active: rejected, nothing rewritten.
        $this->postJson('/api/v1/auth/register', $hijack)->assertUnprocessable()->assertJsonValidationErrorFor('otp');
        $this->assertSame($before, $pending->refresh()->only(['name', 'email', 'password']));
        $this->assertCount(1, $this->sender->messages);

        // Delivery failure: still nothing rewritten and the pending row survives.
        Cache::flush();
        $this->sender->fail = true;
        $this->postJson('/api/v1/auth/register', $hijack)->assertUnprocessable();
        $this->assertSame($before, $pending->refresh()->only(['name', 'email', 'password']));
        $this->assertDatabaseHas('users', ['id' => $pending->id]);
    }

    public function test_unverified_number_gets_fresh_otp_on_reregister(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        // Simulate expired cooldown by flushing the cache between calls.
        Cache::flush();

        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'name' => 'Changed Name',
        ]))->assertCreated();

        $this->assertSame(
            'Changed Name',
            User::where('whatsapp_number', '+963900000001')->firstOrFail()->name
        );
    }
}
