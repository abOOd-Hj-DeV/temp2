<?php

namespace Tests\Feature;

use App\Events\Auth\CredentialsRevoked;
use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class LogoutAllAuditTest extends TestCase
{
    use CommittedDatabase;

    private int $identity = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function credentials(): array
    {
        $identity = ++$this->identity;
        $user = User::create([
            'name' => 'Synthetic logout user', 'email' => "logout{$identity}@example.test",
            'password' => Hash::make('SyntheticPassword1!'), 'role' => 'patient',
            'whatsapp_number' => '+15559000'.str_pad((string) $identity, 4, '0', STR_PAD_LEFT),
            'is_active' => true, 'phone_verified_at' => now(),
        ])->refresh();
        $access = [];
        foreach (range(1, 2) as $device) {
            $token = $user->createToken('synthetic device', ['*'], now()->addHour());
            $access[] = $token->plainTextToken;
            (new RefreshToken)->forceFill([
                'user_id' => $user->id, 'family_id' => (string) Str::uuid(),
                'access_token_id' => $token->accessToken->getKey(),
                'token_hash' => hash('sha256', "synthetic-refresh-{$identity}-{$device}"),
                'expires_at' => now()->addDay(), 'credential_version' => $user->credential_version,
            ])->save();
        }
        DB::table('auth_login_challenges')->insert([
            'token_hash' => hash('sha256', "synthetic-challenge-{$identity}"),
            'user_id' => $user->id, 'credential_version' => $user->credential_version,
            'expires_at' => now()->addMinutes(5),
        ]);
        foreach ($this->otpKeys($user) as $key) {
            Cache::put($key, 'synthetic-existing-cache-value', now()->addMinutes(5));
        }

        return [$user, $access];
    }

    private function otpKeys(User $user): array
    {
        $phone = preg_replace('/\D+/', '', $user->whatsapp_number);
        $keys = [];
        foreach ([OtpService::PURPOSE_LOGIN_2FA, OtpService::PURPOSE_PASSWORD_RESET] as $purpose) {
            $keys[] = "otp:{$purpose}:{$phone}";
            $keys[] = "otp_attempts:{$purpose}:{$phone}";
        }

        return $keys;
    }

    private function snapshot(User $user): array
    {
        return [
            'version' => $user->fresh()->credential_version,
            'access' => $user->tokens()->orderBy('id')->get(['id', 'tokenable_type', 'tokenable_id', 'name', 'abilities', 'expires_at', 'created_at'])->toArray(),
            'refresh' => $user->refreshTokens()->orderBy('id')->get()->toArray(),
            'challenges' => DB::table('auth_login_challenges')->where('user_id', $user->id)->get()->map(fn ($row) => (array) $row)->all(),
            'audit' => AuditLog::where('user_id', $user->id)->count(),
        ];
    }

    private function rejectAuditInsert(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_logout_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = 'auth.logout_all' THEN RAISE EXCEPTION 'Synthetic mandatory audit failure' USING ERRCODE = '23514'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER reject_logout_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_logout_audit()');
        } else {
            DB::unprepared("CREATE TRIGGER reject_logout_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'auth.logout_all' BEGIN SELECT RAISE(ABORT, 'Synthetic mandatory audit failure'); END");
        }
    }

    private function allowAuditInsert(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER reject_logout_audit ON audit_logs');
            DB::unprepared('DROP FUNCTION reject_logout_audit()');
        } else {
            DB::unprepared('DROP TRIGGER reject_logout_audit');
        }
    }

    public function test_failed_mandatory_database_audit_preserves_credentials_and_emits_no_revocation(): void
    {
        [$user, $access] = $this->credentials();
        $before = $this->snapshot($user);
        $cache = array_map(fn ($key) => Cache::get($key), $this->otpKeys($user));
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        $this->rejectAuditInsert();
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$access[0]]);
            $this->fail('Mandatory audit insertion must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic mandatory audit failure', $exception->getMessage());
        } finally {
            $this->allowAuditInsert();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame($cache, array_map(fn ($key) => Cache::get($key), $this->otpKeys($user)));
        $this->assertSame([], $seen);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$access[0]])->assertOk();
    }

    public function test_success_commits_audit_and_revocation_before_notification_and_preserves_other_users(): void
    {
        [$user, $access] = $this->credentials();
        [$other, $otherAccess] = $this->credentials();
        $otherBefore = $this->snapshot($other);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen, $user): void {
            $seen[] = [$event->userId, DB::transactionLevel(), $this->snapshot($user)];
        });
        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$access[0]])
            ->assertOk()->assertExactJson(['message' => __('Logged out from all devices.')]);
        $after = $this->snapshot($user);
        $this->assertSame(2, $after['version']);
        $this->assertSame([], $after['access']);
        $this->assertSame([], $after['challenges']);
        $this->assertCount(2, $after['refresh']);
        foreach ($after['refresh'] as $refresh) {
            $this->assertSame(now()->toISOString(), $refresh['revoked_at']);
        }
        $this->assertSame(1, $after['audit']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id, 'action' => AuditLogService::LOGOUT_ALL, 'entity_id' => $user->id,
        ]);
        $this->assertSame([[$user->id, 0, $after]], $seen);
        foreach ($this->otpKeys($user) as $key) {
            $this->assertNull(Cache::get($key));
        }
        $this->assertSame($otherBefore, $this->snapshot($other));
        foreach ($this->otpKeys($other) as $key) {
            $this->assertSame('synthetic-existing-cache-value', Cache::get($key));
        }
        foreach ($access as $token) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$otherAccess[0]])->assertOk();
    }

    public function test_revocation_database_failure_rolls_back_the_mandatory_audit_and_database_credentials(): void
    {
        [$user] = $this->credentials();
        $before = $this->snapshot($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_logout_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic revocation failure' USING ERRCODE = '23514'; RETURN OLD; END; \$\$");
            DB::unprepared('CREATE TRIGGER reject_logout_delete BEFORE DELETE ON personal_access_tokens FOR EACH ROW EXECUTE FUNCTION reject_logout_delete()');
        } else {
            DB::unprepared("CREATE TRIGGER reject_logout_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'Synthetic revocation failure'); END");
        }
        try {
            app(AuthService::class)->logoutAll($user);
            $this->fail('Database revocation must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic revocation failure', $exception->getMessage());
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP TRIGGER reject_logout_delete ON personal_access_tokens');
                DB::unprepared('DROP FUNCTION reject_logout_delete()');
            } else {
                DB::unprepared('DROP TRIGGER reject_logout_delete');
            }
        }
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_enclosing_transaction_defers_notification_until_the_audit_is_committed(): void
    {
        [$user] = $this->credentials();
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = [$event->userId, DB::transactionLevel(), AuditLog::where('action', AuditLogService::LOGOUT_ALL)->count()];
        });
        DB::beginTransaction();
        app(AuthService::class)->logoutAll($user);
        $this->assertSame([], $seen);
        $this->assertSame(1, AuditLog::where('action', AuditLogService::LOGOUT_ALL)->count());
        DB::commit();
        $this->assertSame([[$user->id, 0, 1]], $seen);
    }

    public function test_enclosing_rollback_restores_database_revocation_and_discards_notification(): void
    {
        [$user] = $this->credentials();
        $before = $this->snapshot($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        DB::beginTransaction();
        app(AuthService::class)->logoutAll($user);
        $this->assertSame([], $seen);
        DB::rollBack();
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
    }

    public function test_stale_credential_proof_cannot_revoke_a_newer_authenticated_session(): void
    {
        [$stale] = $this->credentials();
        $fresh = $stale->fresh();
        $fresh->revokeAllTokens();
        $fresh->createToken('newer session');
        $before = $this->snapshot($fresh);
        $this->assertRejectedWithoutMutation($stale, $before, 'whatsapp_number');
    }

    public function test_current_access_token_deleted_after_authentication_cannot_revoke_other_devices(): void
    {
        [$user] = $this->credentials();
        $token = $user->tokens()->firstOrFail();
        $user->withAccessToken($token);
        $token->delete();
        $this->assertRejectedWithoutMutation($user, $this->snapshot($user), 'whatsapp_number');
    }

    public function test_changed_account_status_is_rechecked_before_audit_or_revocation(): void
    {
        foreach ([['is_active' => false], ['phone_verified_at' => null], ['anonymized_at' => now()]] as $change) {
            [$stale] = $this->credentials();
            DB::table('users')->where('id', $stale->id)->update($change);
            $this->assertRejectedWithoutMutation($stale, $this->snapshot($stale), 'whatsapp_number');
        }
    }

    public function test_logout_all_keeps_caller_model_version_synchronized(): void
    {
        [$user] = $this->credentials();
        app(AuthService::class)->logoutAll($user);
        $this->assertSame(2, $user->credential_version);
        $user->forceFill(['password' => Hash::make('ChangedSyntheticPassword1!')])->save();
        $this->assertSame(3, $user->fresh()->credential_version);
    }

    private function assertRejectedWithoutMutation(User $user, array $before, string $field): void
    {
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        try {
            app(AuthService::class)->logoutAll($user);
            $this->fail('Stale authenticated state must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
    }
}
