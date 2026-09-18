<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::create([
            'name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Secret123!'),
            'role' => 'patient', 'whatsapp_number' => '+963900000010',
            'is_active' => true, 'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
    }

    private function login(): array
    {
        return $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => '+963900000010', 'password' => 'Secret123!',
        ])->assertOk()->json();
    }

    private function bearer(string $token): array
    {
        // The request guard memoises the resolved user; reset it so every
        // request authenticates from scratch like a real client would.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_login_issues_short_lived_access_and_long_lived_refresh_token(): void
    {
        $body = $this->login();

        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertSame(64, strlen($body['refresh_token']));
        $this->assertTrue(now()->addMinutes(121)->gte($body['expires_at']));
        $this->assertTrue(now()->addDays(29)->lte($body['refresh_expires_at']));

        // Only a digest is persisted.
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $body['refresh_token']]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => hash('sha256', $body['refresh_token'])]);
    }

    public function test_refresh_rotates_both_tokens_and_kills_the_old_access_token(): void
    {
        $first = $this->login();

        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);

        $this->getJson('/api/v1/auth/user', $this->bearer($first['access_token']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/user', $this->bearer($second['access_token']))->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());

        // Same family across the rotation chain.
        $this->assertSame(1, RefreshToken::distinct()->count('family_id'));
    }

    public function test_replaying_a_used_refresh_token_revokes_every_session(): void
    {
        $first = $this->login();
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json();

        // Attacker replays the consumed token.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertUnprocessable();

        // Legitimate holder is also logged out — the family is burned.
        $this->getJson('/api/v1/auth/user', $this->bearer($second['access_token']))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertUnprocessable();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->whereNull('used_at')->count());
    }

    public function test_expired_or_unknown_refresh_tokens_are_rejected(): void
    {
        $body = $this->login();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('x', 64)])->assertUnprocessable();

        $this->travel(31)->days();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $body['refresh_token']])->assertUnprocessable();
    }

    public function test_deactivated_user_cannot_refresh(): void
    {
        $body = $this->login();
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $body['refresh_token']])->assertUnprocessable();
    }

    public function test_logout_all_revokes_every_access_and_refresh_token(): void
    {
        $a = $this->login();
        $this->travel(1)->minute();
        $b = $this->login();
        $this->assertSame(2, PersonalAccessToken::count());

        $this->postJson('/api/v1/auth/logout-all', [], $this->bearer($a['access_token']))->assertOk();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->getJson('/api/v1/auth/user', $this->bearer($b['access_token']))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $a['refresh_token']])->assertUnprocessable();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $b['refresh_token']])->assertUnprocessable();
    }

    public function test_single_logout_revokes_only_that_sessions_refresh_token(): void
    {
        $a = $this->login();
        $this->travel(1)->minute();
        $b = $this->login();

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($a['access_token']))->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $a['refresh_token']])->assertUnprocessable();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $b['refresh_token']])->assertOk();
    }
}
