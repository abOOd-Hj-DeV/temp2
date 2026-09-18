<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * users.role is the single source of truth; the Spatie role consumed by the
 * `role:` middleware must always mirror it (F-11).
 */
class RoleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'U', 'email' => "{$role}@example.com", 'password' => Hash::make('Secret123!'),
            'whatsapp_number' => '+9639000'.random_int(10000, 99999), 'role' => $role,
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
    }

    public function test_spatie_role_is_derived_from_users_role_on_create_and_change(): void
    {
        $user = $this->makeUser('patient'); // no explicit assignRole()

        $this->assertTrue($user->hasRole('patient'));
        $this->assertSame(['patient'], $user->getRoleNames()->all());

        $user->update(['role' => UserRole::ADMIN->value]);
        $user->refresh();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('patient'));
        $this->assertSame(['admin'], $user->getRoleNames()->all());
    }

    public function test_middleware_follows_the_enum_role_not_a_stale_spatie_assignment(): void
    {
        $user = $this->makeUser('patient');

        // Simulate historical drift: Spatie says admin, users.role says patient.
        DB::table('model_has_roles')->where('model_id', $user->id)->delete();
        $user->unsetRelation('roles');
        $user->assignRole('admin');
        $this->assertTrue($user->fresh()->hasRole('admin'));

        // Even before reconcile, a drifted Spatie row must not grant access.
        Sanctum::actingAs($user->fresh(), ['*'], 'api');
        $this->getJson('/api/v1/admin/red-flags')->assertForbidden();
        $this->app['auth']->forgetGuards();

        // The reconcile step used by the migration restores agreement.
        $user->fresh()->syncSpatieRole();
        $this->assertSame(['patient'], $user->fresh()->getRoleNames()->all());

        Sanctum::actingAs($user->fresh(), ['*'], 'api');
        $this->getJson('/api/v1/admin/red-flags')->assertForbidden();
    }
}
