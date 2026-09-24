<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INF-05: four clearly-labelled placeholder packages, idempotent, admin edits preserved. */
class PackageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_four_published_placeholders_idempotently(): void
    {
        $this->seed(PackageSeeder::class);
        $this->seed(PackageSeeder::class);

        $this->assertSame(6, Package::count());
        $this->assertSame(4, Package::published()->count());
        $this->assertSame(2, Package::whereIn('code', PackageSeeder::LEGACY_CODES)->where('is_published', false)->count());

        Package::published()->get()->each(function (Package $package) {
            $this->assertStringStartsWith('placeholder_', $package->code);
            $this->assertStringStartsWith('[Placeholder]', $package->name);
            $this->assertSame(PackageSeeder::PLACEHOLDER_NOTE, $package->description);
            $this->assertGreaterThan(0, $package->number_of_sessions);
            $this->assertGreaterThan(0, $package->duration_days);
            $this->assertGreaterThanOrEqual(1, $package->daily_sessions_quota);
        });

        $edited = Package::where('code', 'placeholder_starter_4')->firstOrFail();
        $edited->update(['name' => 'Starter (final)', 'price' => 120, 'is_published' => false]);

        $this->seed(PackageSeeder::class);

        $edited->refresh();
        $this->assertSame('Starter (final)', $edited->name);
        $this->assertEqualsWithDelta(120, (float) $edited->price, 0.001);
        $this->assertFalse($edited->is_published);
        $this->assertSame(6, Package::count());
    }

    public function test_legacy_plan_republished_by_admin_is_left_alone(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x',
            'whatsapp_number' => '+963900000930', 'role' => 'super_admin', 'is_active' => true,
            'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
        Package::where('code', '4_weeks')->update(['published_by' => $admin->id, 'published_at' => now()]);

        $this->seed(PackageSeeder::class);

        $this->assertTrue(Package::where('code', '4_weeks')->firstOrFail()->is_published);
        $this->assertFalse(Package::where('code', '8_weeks')->firstOrFail()->is_published);
    }

    public function test_placeholders_appear_in_patient_catalogue(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PackageSeeder::class);

        $patient = User::create([
            'name' => 'P', 'email' => 'p@example.com', 'password' => 'x',
            'whatsapp_number' => '+963900000931', 'role' => 'patient', 'is_active' => true,
            'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
        $patient->assignRole('patient');
        Patient::create(['user_id' => $patient->id, 'full_name' => 'P', 'age' => 25, 'gender' => 'female', 'language' => 'en']);

        $this->actingAs($patient, 'api')
            ->getJson('/api/v1/subscriptions/packages')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.code', 'placeholder_starter_4');
    }
}
