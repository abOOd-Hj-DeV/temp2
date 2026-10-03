<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Events\Auth\CredentialsRevoked;
use App\Http\Middleware\EnsureAuthCaptcha;
use App\Models\AccountInvitation;
use App\Models\RefreshToken;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Account\StaffAccountService;
use App\Services\Auth\AuthService;
use App\Services\Auth\CaptchaVerifier;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\Therapist\TherapistService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthRepairSecurityTest extends TestCase
{
    use DatabaseMigrations;

    private FakeWhatsAppSender $sender;

    private int $identity = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        config(['app.key' => 'synthetic-auth-repair-test-key', 'auth_security.captcha.enabled' => false]);
        Cache::flush();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->sender = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->sender);
    }

    private function user(string $role = 'patient'): User
    {
        $id = ++$this->identity;

        return User::create([
            'name' => 'Synthetic User', 'email' => "synthetic{$id}@example.test",
            'password' => Hash::make('OldSecret1!'), 'role' => $role,
            'whatsapp_number' => '+96390000'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
    }

    private function otp(): string
    {
        preg_match('/verification code is: (\d{6})/', end($this->sender->messages)['message'], $matches);

        return $matches[1];
    }

    private function login(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => $user->whatsapp_number, 'password' => 'OldSecret1!',
        ])->assertOk()->json();
    }

    private function verify(User $user, string $challenge, string $otp): TestResponse
    {
        return $this->postJson('/api/v1/auth/login/2fa', [
            'whatsapp_number' => $user->whatsapp_number, 'login_challenge' => $challenge, 'otp' => $otp,
        ]);
    }

    private function payload(array $changes = []): array
    {
        return array_merge([
            'name' => 'Original Patient', 'email' => 'original@example.test',
            'password' => 'Original1!', 'password_confirmation' => 'Original1!',
            'whatsapp_number' => '+963911111111', 'timezone' => 'Asia/Damascus',
            'privacy_accepted' => true,
        ], $changes);
    }

    public function test_anonymous_staff_resend_and_verification_require_password_challenge(): void
    {
        $user = $this->user('admin');
        $this->postJson('/api/v1/auth/login/2fa/resend', ['whatsapp_number' => $user->whatsapp_number])
            ->assertUnprocessable()->assertJsonValidationErrorFor('login_challenge');
        $this->verify($user, str_repeat('x', 64), '123456')->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $user->whatsapp_number, 'password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertCount(0, $this->sender->messages);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertDatabaseCount('auth_login_challenges', 0);
    }

    public function test_staff_challenge_is_bound_to_user_single_use_and_returns_real_tokens(): void
    {
        $user = $this->user('admin');
        $other = $this->user('admin');
        $body = $this->login($user);
        $code = $this->otp();
        $this->assertTrue($body['requires_2fa']);
        $this->assertArrayNotHasKey('access_token', $body);
        $this->assertDatabaseHas('auth_login_challenges', ['token_hash' => hash('sha256', $body['login_challenge'])]);
        $this->verify($other, $body['login_challenge'], $code)->assertUnprocessable();
        $tokens = $this->verify($user, $body['login_challenge'], $code)->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/users', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->verify($user, $body['login_challenge'], $code)->assertUnprocessable();
        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertDatabaseCount('auth_login_challenges', 0);
    }

    public function test_challenge_expires_at_the_exact_boundary_without_resend_extension(): void
    {
        $user = $this->user('admin');
        $body = $this->login($user);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login/2fa/resend', [
            'whatsapp_number' => $user->whatsapp_number, 'login_challenge' => $body['login_challenge'],
        ])->assertOk();
        $code = $this->otp();
        $this->travelTo(Carbon::parse($body['challenge_expires_at']));
        $this->verify($user, $body['login_challenge'], $code)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login/2fa/resend', [
            'whatsapp_number' => $user->whatsapp_number, 'login_challenge' => $body['login_challenge'],
        ])->assertUnprocessable();
        $this->assertCount(2, $this->sender->messages);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_resend_rotates_code_but_does_not_reset_password_proof(): void
    {
        $user = $this->user('admin');
        $body = $this->login($user);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login/2fa/resend', [
            'whatsapp_number' => $user->whatsapp_number, 'login_challenge' => $body['login_challenge'],
        ])->assertOk();
        $this->verify($user, $body['login_challenge'], $this->otp())->assertOk();
    }

    public function test_new_password_proof_supersedes_the_previous_challenge(): void
    {
        $user = $this->user('admin');
        $first = $this->login($user);
        $this->travel(61)->seconds();
        $second = $this->login($user);
        $code = $this->otp();
        $this->verify($user, $first['login_challenge'], $code)->assertUnprocessable();
        $this->verify($user, $second['login_challenge'], $code)->assertOk();
    }

    public function test_bad_codes_exhaust_the_otp_without_minting_tokens(): void
    {
        $user = $this->user('admin');
        $body = $this->login($user);
        $code = $this->otp();
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->verify($user, $body['login_challenge'], $wrong)->assertUnprocessable();
        }
        $this->verify($user, $body['login_challenge'], $code)->assertUnprocessable();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_delivery_failure_cannot_create_a_login_challenge(): void
    {
        $user = $this->user('admin');
        $this->sender->fail = true;
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $user->whatsapp_number, 'password' => 'OldSecret1!'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('auth_login_challenges', 0);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_second_registration_preserves_all_original_pending_credentials(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $pending = User::where('whatsapp_number', '+963911111111')->firstOrFail();
        $original = $pending->only(['name', 'email', 'password', 'timezone', 'privacy_policy_version', 'privacy_accepted_at']);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/register', $this->payload([
            'name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'Stranger1!',
            'password_confirmation' => 'Stranger1!', 'timezone' => 'UTC',
        ]))->assertCreated()->assertJsonPath('user_id', $pending->id);
        $this->assertEquals($original, $pending->refresh()->only(array_keys($original)));
        $this->postJson('/api/v1/auth/otp/verify', ['whatsapp_number' => $pending->whatsapp_number, 'otp' => $this->otp()])->assertOk();
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $pending->whatsapp_number, 'password' => 'Stranger1!'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $pending->whatsapp_number, 'password' => 'Original1!'])->assertOk();
    }

    public function test_reset_invalidates_previously_password_proven_staff_login(): void
    {
        $user = $this->user('admin');
        $body = $this->login($user);
        $code = $this->otp();
        app(AuthService::class)->forgotPassword($user->whatsapp_number);
        app(AuthService::class)->resetPassword($user->whatsapp_number, $this->otp(), 'NewSecret1!');
        $this->verify($user, $body['login_challenge'], $code)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login/2fa/resend', ['whatsapp_number' => $user->whatsapp_number, 'login_challenge' => $body['login_challenge']])->assertUnprocessable();
        $this->assertDatabaseCount('auth_login_challenges', 0);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertGreaterThan(1, $user->refresh()->credential_version);
        $this->travel(61)->seconds();
        $fresh = $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $user->whatsapp_number, 'password' => 'NewSecret1!'])->assertOk()->json();
        $this->verify($user, $fresh['login_challenge'], $this->otp())->assertOk();
    }

    public function test_password_change_invalidates_challenges_and_every_refresh_but_preserves_current_access(): void
    {
        $user = $this->user('admin');
        $first = $this->login($user);
        $tokens = $this->verify($user, $first['login_challenge'], $this->otp())->assertOk()->json();
        $this->travel(61)->seconds();
        $pending = $this->login($user);
        $code = $this->otp();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/auth/user/password', ['current_password' => 'OldSecret1!', 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!'], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->verify($user, $pending['login_challenge'], $code)->assertUnprocessable();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
    }

    private function resetAfterSuccessfulPasswordCheck(User $user): void
    {
        $hasher = new class extends BcryptHasher
        {
            public ?\Closure $afterCheck = null;

            public function check($value, $hashedValue, array $options = [])
            {
                $valid = parent::check($value, $hashedValue, $options);
                if ($valid && $this->afterCheck) {
                    $callback = $this->afterCheck;
                    $this->afterCheck = null;
                    $callback();
                }

                return $valid;
            }
        };
        $hasher->afterCheck = function () use ($user): void {
            app(AuthService::class)->forgotPassword($user->whatsapp_number);
            app(AuthService::class)->resetPassword($user->whatsapp_number, $this->otp(), 'NewSecret1!');
        };
        Hash::swap($hasher);
    }

    public function test_patient_login_rejects_reset_between_password_check_and_token_mint(): void
    {
        $user = $this->user();
        $this->resetAfterSuccessfulPasswordCheck($user);
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $user->whatsapp_number, 'password' => 'OldSecret1!'])->assertUnprocessable();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertTrue(Hash::check('NewSecret1!', $user->refresh()->password));
    }

    public function test_staff_login_cannot_create_challenge_after_password_check_reset_race(): void
    {
        $user = $this->user('admin');
        $this->resetAfterSuccessfulPasswordCheck($user);
        $this->postJson('/api/v1/auth/login', ['whatsapp_number' => $user->whatsapp_number, 'password' => 'OldSecret1!'])->assertUnprocessable();
        $this->assertDatabaseCount('auth_login_challenges', 0);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_password_change_rejects_reset_between_current_password_check_and_update(): void
    {
        $user = $this->user();
        $tokens = $this->login($user);
        $this->app['auth']->forgetGuards();
        $this->resetAfterSuccessfulPasswordCheck($user);
        $this->putJson('/api/v1/auth/user/password', [
            'current_password' => 'OldSecret1!', 'password' => 'Competing1!', 'password_confirmation' => 'Competing1!',
        ], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertUnprocessable();
        $this->assertTrue(Hash::check('NewSecret1!', $user->refresh()->password));
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_refresh_rejects_credential_version_mismatch_even_when_token_not_revoked(): void
    {
        $user = $this->user();
        $tokens = $this->login($user);
        DB::table('users')->where('id', $user->id)->increment('credential_version');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnprocessable();
        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertSame(0, RefreshToken::whereNotNull('used_at')->count());
    }

    public function test_refresh_rechecks_credentials_after_loading_user_snapshot(): void
    {
        $user = $this->user();
        $tokens = $this->login($user);
        $ran = false;
        User::retrieved(function (User $snapshot) use ($user, &$ran): void {
            if ($snapshot->id === $user->id && ! $ran) {
                $ran = true;
                app(AuthService::class)->forgotPassword($user->whatsapp_number);
                app(AuthService::class)->resetPassword($user->whatsapp_number, $this->otp(), 'NewSecret1!');
            }
        });
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnprocessable();
        $this->assertTrue($ran);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_privacy_requests_do_not_consume_register_or_reset_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/auth/privacy-policy')->assertOk();
        }
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $this->postJson('/api/v1/auth/forgot-password', ['whatsapp_number' => '+963922222222'])->assertOk();
    }

    public function test_resend_aliases_share_phone_budget_across_ips(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$i}"])->postJson('/api/v1/auth/otp/resend', ['whatsapp_number' => '+963922222222'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/api/v1/auth/otp/send', ['whatsapp_number' => '00963922222222'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/api/v1/auth/otp/send', ['whatsapp_number' => '+963933333333'])->assertOk();
    }

    public function test_finance_permission_revocation_is_enforced_under_role_ceiling(): void
    {
        $finance = $this->user('finance_partner');
        $token = $finance->createToken('synthetic')->plainTextToken;
        $this->getJson('/api/v1/admin/payments', ['Authorization' => 'Bearer '.$token])->assertOk();
        Role::findByName('finance_partner', 'api')->revokePermissionTo('manage finances');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/payments', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $finance->givePermissionTo('manage finances');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/payments', ['Authorization' => 'Bearer '.$token])->assertOk();
        $patient = $this->user();
        $patient->givePermissionTo('manage finances');
        $patient->assignRole('super_admin');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/payments', ['Authorization' => 'Bearer '.$patient->createToken('synthetic')->plainTextToken])->assertForbidden();
    }

    public function test_every_admin_route_has_permission_and_role_gates_with_seeded_allowed_roles(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $roles = array_values(array_filter($middleware, fn ($m) => str_starts_with($m, 'role:')));
            $permissions = array_values(array_filter($middleware, fn ($m) => str_starts_with($m, PermissionMiddleware::class.':')));
            $this->assertCount(1, $roles, $route->uri());
            $this->assertCount(1, $permissions, $route->uri());
            [$permission] = explode(',', substr($permissions[0], strlen(PermissionMiddleware::class) + 1));
            foreach (explode(',', substr($roles[0], 5)) as $role) {
                $this->assertTrue(Role::findByName($role, 'api')->hasPermissionTo($permission, 'api'), "{$role} missing {$permission} on {$route->uri()}");
            }
        }
    }

    public function test_supervisor_invitation_requires_license_review_before_therapist_operations(): void
    {
        $supervisor = $this->user('clinical_supervisor');
        $invitation = app(StaffAccountService::class)->invite($supervisor, [
            'name' => 'Synthetic Therapist', 'email' => 'therapist@example.test', 'whatsapp_number' => '+963944444444', 'role' => 'therapist',
            'therapist' => ['specialty' => 'cbt', 'country' => 'JO'],
        ]);
        $therapist = Therapist::findOrFail($invitation['user']->id);
        $this->assertSame(ApprovalStatus::PENDING, $therapist->approval_status);
        $this->assertNull($therapist->license_file_path);
        preg_match('/activation code ([A-Z0-9]{8})/', end($this->sender->messages)['message'], $matches);
        $tokens = $this->postJson('/api/v1/auth/activate', ['whatsapp_number' => '+963944444444', 'code' => $matches[1], 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!'])->assertOk()->json();
        $this->getJson('/api/v1/therapists/me/dashboard', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertForbidden();
        $this->expectException(ValidationException::class);
        app(TherapistService::class)->decideApproval($therapist->user_id, ApprovalStatus::APPROVED, $this->user('admin'));
    }

    public function test_credentials_revoked_event_is_after_commit_and_not_emitted_on_rollback(): void
    {
        $user = $this->user();
        $this->login($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function (CredentialsRevoked $event) use (&$seen): void {
            $seen[] = [$event->userId, DB::transactionLevel(), PersonalAccessToken::count()];
        });
        DB::beginTransaction();
        $user->revokeAllTokens();
        $this->assertSame([], $seen);
        DB::rollBack();
        $this->assertSame([], $seen);
        $this->assertSame(1, PersonalAccessToken::count());
        DB::transaction(function () use ($user, &$seen): void {
            $user->revokeAllTokens();
            $this->assertSame([], $seen);
        });
        $this->assertSame([[$user->id, 0, 0]], $seen);
    }

    public function test_revoke_all_synchronizes_model_version_before_later_password_save(): void
    {
        $user = $this->user();
        $user->revokeAllTokens();
        $this->assertSame(2, $user->credential_version);
        $user->forceFill(['password' => Hash::make('NewSecret1!')])->save();
        $this->assertSame(3, $user->refresh()->credential_version);
    }

    public function test_activation_failed_attempts_persist_despite_validation_errors_and_throttling(): void
    {
        $invitation = app(StaffAccountService::class)->invite($this->user('super_admin'), [
            'name' => 'Synthetic Finance', 'email' => 'finance@example.test', 'whatsapp_number' => '+963944444444', 'role' => 'finance_partner',
        ]);
        preg_match('/activation code ([A-Z0-9]{8})/', end($this->sender->messages)['message'], $matches);
        $activate = fn (string $code) => $this->postJson('/api/v1/auth/activate', [
            'whatsapp_number' => '+963944444444', 'code' => $code, 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!',
        ]);
        for ($i = 0; $i < 5; $i++) {
            $activate('WRONGCD'.$i)->assertUnprocessable();
        }
        $stored = AccountInvitation::where('user_id', $invitation['user']->id)->firstOrFail();
        $this->assertSame(5, $stored->attempts);
        $this->assertNotNull($stored->revoked_at);
        $activate($matches[1])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $activate($matches[1])->assertUnprocessable();
        $this->assertNull($invitation['user']->refresh()->phone_verified_at);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_logout_and_password_change_emit_committed_revocation_events(): void
    {
        $user = $this->user();
        $tokens = $this->login($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function (CredentialsRevoked $event) use (&$seen): void {
            $seen[] = [$event->userId, DB::transactionLevel()];
        });
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
        $this->assertSame([[$user->id, 0]], $seen);
        $this->app['auth']->forgetGuards();
        $tokens = $this->login($user);
        $this->putJson('/api/v1/auth/user/password', ['current_password' => 'OldSecret1!', 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!'], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk();
        $this->assertSame([[$user->id, 0], [$user->id, 0]], $seen);
    }

    public function test_production_captcha_fails_closed_even_if_disabled(): void
    {
        $this->app['env'] = 'production';
        config(['auth_security.captcha.enabled' => false, 'auth_security.captcha.verifier' => null]);
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->assertCount(0, $this->sender->messages);
        $this->getJson('/api/v1/auth/privacy-policy')->assertOk();
        $this->app['env'] = 'testing';
    }

    public function test_configured_captcha_rejects_missing_bad_or_error_and_allows_legitimate_fake(): void
    {
        $fake = new AuthRepairFakeCaptcha;
        config(['auth_security.captcha.enabled' => true, 'auth_security.captcha.verifier' => AuthRepairFakeCaptcha::class]);
        $this->app->instance(AuthRepairFakeCaptcha::class, $fake);
        $this->postJson('/api/v1/auth/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrorFor('captcha_token');
        $this->postJson('/api/v1/auth/register', $this->payload(['captcha_token' => 'invalid']))->assertUnprocessable();
        $fake->throws = true;
        $this->postJson('/api/v1/auth/register', $this->payload(['captcha_token' => 'synthetic-valid']))->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $fake->throws = false;
        $this->postJson('/api/v1/auth/register', $this->payload(['captcha_token' => 'synthetic-valid']))->assertCreated();
        $this->assertSame('auth.register', end($fake->actions));
        $this->assertCount(1, $this->sender->messages);
    }

    public function test_all_sensitive_public_auth_actions_require_captcha_middleware(): void
    {
        $actions = ['register', 'otp.verify', 'otp.resend', 'otp.send', 'login', 'login.2fa', 'login.2fa.resend', 'password.forgot', 'password.reset', 'activate'];
        foreach ($actions as $action) {
            $route = app('router')->getRoutes()->getByName('auth.'.$action);
            $this->assertContains(EnsureAuthCaptcha::class, $route->gatherMiddleware());
        }
    }
}

class AuthRepairFakeCaptcha implements CaptchaVerifier
{
    public bool $throws = false;

    public array $actions = [];

    public function verify(string $token, string $ip, string $action): bool
    {
        $this->actions[] = $action;
        if ($this->throws) {
            throw new \RuntimeException('Synthetic verifier outage');
        }

        return $token === 'synthetic-valid';
    }
}
