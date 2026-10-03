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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class RefreshReplayAuditTest extends TestCase
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
        $password = 'SyntheticReplayPassword1!';
        $user = User::create([
            'name' => 'Synthetic replay user', 'email' => "replay{$identity}@example.test",
            'password' => Hash::make($password), 'role' => 'patient',
            'whatsapp_number' => '+15559100'.str_pad((string) $identity, 4, '0', STR_PAD_LEFT),
            'is_active' => true, 'phone_verified_at' => now(),
        ])->refresh();
        $auth = app(AuthService::class);
        $first = $auth->login(['whatsapp_number' => $user->whatsapp_number, 'password' => $password]);
        $current = $auth->refresh($first['refresh_token']);
        $otherDevice = $auth->login(['whatsapp_number' => $user->whatsapp_number, 'password' => $password]);
        DB::table('auth_login_challenges')->insert([
            'token_hash' => hash('sha256', "synthetic-replay-challenge-{$identity}"),
            'user_id' => $user->id, 'credential_version' => $user->credential_version,
            'expires_at' => now()->addMinutes(5),
        ]);
        Cache::put($this->otpKey($user), 'synthetic-existing-reset-otp', now()->addMinutes(5));

        return [$user, $first['refresh_token'], [$current, $otherDevice]];
    }

    private function otpKey(User $user): string
    {
        return 'otp:'.OtpService::PURPOSE_PASSWORD_RESET.':'.preg_replace('/\D+/', '', $user->whatsapp_number);
    }

    private function snapshot(User $user): array
    {
        return [
            'version' => $user->fresh()->credential_version,
            'access' => $user->tokens()->orderBy('id')->get(['id', 'tokenable_id', 'name', 'abilities', 'expires_at'])->toArray(),
            'refresh' => $user->refreshTokens()->orderBy('id')->get()->toArray(),
            'challenges' => DB::table('auth_login_challenges')->where('user_id', $user->id)->get()->map(fn ($row) => (array) $row)->all(),
            'replay_audits' => AuditLog::where('user_id', $user->id)->where('action', AuditLogService::REFRESH_TOKEN_REPLAYED)->count(),
        ];
    }

    private function rejectAudit(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_replay_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = 'auth.refresh_token_replayed' THEN RAISE EXCEPTION 'Synthetic replay audit failure' USING ERRCODE = '23514'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER reject_replay_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_replay_audit()');
        } else {
            DB::unprepared("CREATE TRIGGER reject_replay_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'auth.refresh_token_replayed' BEGIN SELECT RAISE(ABORT, 'Synthetic replay audit failure'); END");
        }
    }

    private function allowAudit(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER reject_replay_audit ON audit_logs');
            DB::unprepared('DROP FUNCTION reject_replay_audit()');
        } else {
            DB::unprepared('DROP TRIGGER reject_replay_audit');
        }
    }

    public function test_mandatory_replay_audit_failure_preserves_credentials_and_emits_no_revocation(): void
    {
        [$user, $replay, $current] = $this->credentials();
        $before = $this->snapshot($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        $this->rejectAudit();
        try {
            app(AuthService::class)->refresh($replay);
            $this->fail('Mandatory replay audit must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic replay audit failure', $exception->getMessage());
        } finally {
            $this->allowAudit();
        }
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('synthetic-existing-reset-otp', Cache::get($this->otpKey($user)));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$current[0]['access_token']])->assertOk();
    }

    public function test_replay_commits_mandatory_audit_and_all_revocation_before_the_422_rejection(): void
    {
        [$user, $replay, $current] = $this->credentials();
        [$other, , $otherCurrent] = $this->credentials();
        $otherBefore = $this->snapshot($other);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen, $user): void {
            $seen[] = [$event->userId, DB::transactionLevel(), $this->snapshot($user)];
        });
        Log::spy();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $replay])
            ->assertUnprocessable()->assertJsonValidationErrors('refresh_token');
        $after = $this->snapshot($user);
        $this->assertSame(2, $after['version']);
        $this->assertSame([], $after['access']);
        $this->assertSame([], $after['challenges']);
        $this->assertSame(1, $after['replay_audits']);
        $this->assertCount(3, $after['refresh']);
        foreach ($after['refresh'] as $refresh) {
            $this->assertSame(now()->toISOString(), $refresh['revoked_at']);
        }
        $this->assertSame([[$user->id, 0, $after]], $seen);
        $audit = AuditLog::where('user_id', $user->id)->where('action', AuditLogService::REFRESH_TOKEN_REPLAYED)->sole();
        $family = RefreshToken::where('token_hash', hash('sha256', $replay))->value('family_id');
        $this->assertSame(['family_id' => $family], $audit->details);
        $this->assertSame($user->id, $audit->entity_id);
        Log::shouldHaveReceived('warning')->once()->with('Refresh token replay detected; revoking all sessions', [
            'user_id' => $user->id, 'family_id' => $family,
        ]);
        foreach (array_merge([$replay], array_column($current, 'refresh_token')) as $plain) {
            $this->assertStringNotContainsString($plain, $audit->toJson());
            $this->assertStringNotContainsString(hash('sha256', $plain), $audit->toJson());
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $plain])->assertUnprocessable();
        }
        $this->assertSame($after, $this->snapshot($user));
        $this->assertCount(1, $seen);
        $this->assertNull(Cache::get($this->otpKey($user)));
        foreach ($current as $tokens) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertUnauthorized();
        }
        $this->assertSame($otherBefore, $this->snapshot($other));
        $this->assertSame('synthetic-existing-reset-otp', Cache::get($this->otpKey($other)));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user', ['Authorization' => 'Bearer '.$otherCurrent[0]['access_token']])->assertOk();
    }

    public function test_database_revocation_failure_rolls_back_replay_audit_and_credentials_without_notification(): void
    {
        [$user, $replay] = $this->credentials();
        $before = $this->snapshot($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_replay_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic replay revocation failure' USING ERRCODE = '23514'; RETURN OLD; END; \$\$");
            DB::unprepared('CREATE TRIGGER reject_replay_delete BEFORE DELETE ON personal_access_tokens FOR EACH ROW EXECUTE FUNCTION reject_replay_delete()');
        } else {
            DB::unprepared("CREATE TRIGGER reject_replay_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'Synthetic replay revocation failure'); END");
        }
        try {
            app(AuthService::class)->refresh($replay);
            $this->fail('Database revocation must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic replay revocation failure', $exception->getMessage());
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP TRIGGER reject_replay_delete ON personal_access_tokens');
                DB::unprepared('DROP FUNCTION reject_replay_delete()');
            } else {
                DB::unprepared('DROP TRIGGER reject_replay_delete');
            }
        }
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_replay_notification_waits_for_the_enclosing_transaction_commit(): void
    {
        [$user, $replay] = $this->credentials();
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = [$event->userId, DB::transactionLevel(), AuditLog::where('action', AuditLogService::REFRESH_TOKEN_REPLAYED)->count()];
        });
        DB::beginTransaction();
        $this->assertInvalidRefresh($replay);
        $this->assertSame([], $seen);
        $this->assertSame(2, $user->fresh()->credential_version);
        DB::commit();
        $this->assertSame([[$user->id, 0, 1]], $seen);
    }

    public function test_enclosing_rollback_restores_database_credentials_and_discards_replay_notification(): void
    {
        [$user, $replay] = $this->credentials();
        $before = $this->snapshot($user);
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        DB::beginTransaction();
        $this->assertInvalidRefresh($replay);
        $this->assertSame([], $seen);
        DB::rollBack();
        $this->assertSame($before, $this->snapshot($user));
        $this->assertSame([], $seen);
    }

    public function test_stale_version_used_token_cannot_revoke_newer_credentials(): void
    {
        [$user, $replay] = $this->credentials();
        DB::table('users')->where('id', $user->id)->update(['credential_version' => 2]);
        $before = $this->snapshot($user);
        $this->assertInvalidRefresh($replay);
        $this->assertSame($before, $this->snapshot($user));
    }

    public function test_refresh_row_is_rechecked_after_initial_read_before_replay_mutation(): void
    {
        foreach (['revoked_at', 'used_at', 'deleted', 'token_hash', 'credential_version', 'user_id'] as $change) {
            [$user, $replay] = $this->credentials();
            $otherUserId = null;
            if ($change === 'user_id') {
                [$other] = $this->credentials();
                $otherUserId = $other->id;
            }
            $changed = false;
            $expected = null;
            $hash = hash('sha256', $replay);
            Event::listen('eloquent.retrieved: '.RefreshToken::class, function ($token) use (&$changed, &$expected, $change, $hash, $user, $otherUserId): void {
                if ($changed || $token->token_hash !== $hash) {
                    return;
                }
                $changed = true;
                $row = DB::table('refresh_tokens')->where('id', $token->id);
                match ($change) {
                    'deleted' => $row->delete(),
                    'revoked_at' => $row->update(['revoked_at' => now()]),
                    'used_at' => $row->update(['used_at' => null]),
                    'token_hash' => $row->update(['token_hash' => hash('sha256', Str::random(64))]),
                    'credential_version' => $row->update(['credential_version' => 0]),
                    'user_id' => $row->update(['user_id' => $otherUserId]),
                };
                $expected = $this->snapshot($user);
            });
            $this->assertInvalidRefresh($replay);
            $this->assertTrue($changed);
            $this->assertSame($expected, $this->snapshot($user));
        }
    }

    public function test_user_version_is_reloaded_after_the_initial_relationship_read(): void
    {
        [$user, $replay] = $this->credentials();
        $changed = false;
        $expected = null;
        $seen = [];
        Event::listen(CredentialsRevoked::class, function ($event) use (&$seen): void {
            $seen[] = $event->userId;
        });
        Event::listen('eloquent.retrieved: '.User::class, function ($loaded) use ($user, &$changed, &$expected): void {
            if ($changed || $loaded->id !== $user->id) {
                return;
            }
            $changed = true;
            DB::table('users')->where('id', $user->id)->update(['credential_version' => 2]);
            $expected = $this->snapshot($user);
        });
        $this->assertInvalidRefresh($replay);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot($user));
        $this->assertSame([], $seen);
    }

    public function test_expired_used_token_still_revokes_current_sessions_as_before(): void
    {
        [$user, $replay] = $this->credentials();
        RefreshToken::where('token_hash', hash('sha256', $replay))->update(['expires_at' => now()->subSecond()]);
        $this->assertInvalidRefresh($replay);
        $this->assertSame(2, $user->fresh()->credential_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', AuditLogService::REFRESH_TOKEN_REPLAYED)->count());
    }

    private function assertInvalidRefresh(string $plain): void
    {
        try {
            app(AuthService::class)->refresh($plain);
            $this->fail('Replay must not issue credentials.');
        } catch (ValidationException $exception) {
            $this->assertSame(['refresh_token' => [__('Invalid or expired refresh token.')]], $exception->errors());
        }
    }
}
