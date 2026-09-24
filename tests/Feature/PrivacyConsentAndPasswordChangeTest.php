<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivacyConsentAndPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Consenting Patient',
            'email' => 'consent@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'whatsapp_number' => '+963900000901',
            'privacy_accepted' => true,
        ], $overrides);
    }

    public function test_privacy_policy_is_public_and_versioned(): void
    {
        $this->getJson('/api/v1/auth/privacy-policy')
            ->assertOk()
            ->assertJsonPath('version', config('sakina.privacy_policy.version'))
            ->assertJsonStructure(['version', 'url', 'summary']);
    }

    public function test_registration_requires_explicit_privacy_consent_and_persists_it(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['privacy_accepted' => false]))
            ->assertStatus(422)->assertJsonValidationErrorFor('privacy_accepted');

        $without = $this->payload();
        unset($without['privacy_accepted']);
        $this->postJson('/api/v1/auth/register', $without)
            ->assertStatus(422)->assertJsonValidationErrorFor('privacy_accepted');

        $this->assertDatabaseMissing('users', ['whatsapp_number' => '+963900000901']);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('whatsapp_number', '+963900000901')->firstOrFail();
        $this->assertSame(config('sakina.privacy_policy.version'), $user->privacy_policy_version);
        $this->assertNotNull($user->privacy_accepted_at);
    }

    public function test_authenticated_user_can_change_password_with_current_password(): void
    {
        $user = User::create([
            'name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('OldSecret1!'),
            'role' => 'patient', 'whatsapp_number' => '+963900000902',
            'is_active' => true, 'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
        $user->assignRole('patient');

        $keep = $user->createToken('api')->plainTextToken;
        $other = $user->createToken('api')->plainTextToken;

        $this->withToken($keep)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'wrong', 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!',
        ])->assertStatus(422)->assertJsonValidationErrorFor('current_password');

        $this->withToken($keep)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'OldSecret1!', 'password' => 'OldSecret1!', 'password_confirmation' => 'OldSecret1!',
        ])->assertStatus(422)->assertJsonValidationErrorFor('password');

        $this->withToken($keep)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'OldSecret1!', 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret1!', $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame(1, $user->tokens()->count());

        // The device that changed the password stays signed in; every other device is out.
        $this->app['auth']->forgetGuards();
        $this->withToken($keep)->getJson('/api/v1/auth/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/user')->assertUnauthorized();

        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', AuditLogService::PASSWORD_CHANGED)->exists());
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->putJson('/api/v1/auth/user/password', [
            'current_password' => 'x', 'password' => 'NewSecret1!', 'password_confirmation' => 'NewSecret1!',
        ])->assertUnauthorized();
    }
}
