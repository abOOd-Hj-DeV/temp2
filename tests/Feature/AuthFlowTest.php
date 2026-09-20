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
        ], $overrides);
    }

    private function lastOtp(): string
    {
        preg_match('/(\d{6})/', end($this->sender->messages)['message'], $m);

        return $m[1];
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
