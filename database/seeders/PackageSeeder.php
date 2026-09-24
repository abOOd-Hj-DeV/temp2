<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

/**
 * Placeholder catalogue (INF-05). Names, prices and durations are stand-ins
 * so the booking/payment flow is exercisable end-to-end; the Head-Master
 * replaces them through the admin packages API. Existing rows with the same
 * code are left untouched so admin edits survive re-seeding. The two legacy
 * plans inserted by the packages migration are unpublished (never deleted:
 * existing subscriptions reference them) unless an admin already published
 * them explicitly.
 */
class PackageSeeder extends Seeder
{
    public const PLACEHOLDER_NOTE = 'PLACEHOLDER — pricing and scope to be set by the Head-Master before launch.';

    /** @var list<array<string, int|float|string>> */
    public const PACKAGES = [
        ['code' => 'placeholder_starter_4', 'name' => '[Placeholder] Starter — 4 sessions', 'price' => 100.00, 'number_of_sessions' => 4, 'duration_days' => 30, 'daily_sessions_quota' => 1],
        ['code' => 'placeholder_standard_8', 'name' => '[Placeholder] Standard — 8 sessions', 'price' => 190.00, 'number_of_sessions' => 8, 'duration_days' => 60, 'daily_sessions_quota' => 1],
        ['code' => 'placeholder_extended_12', 'name' => '[Placeholder] Extended — 12 sessions', 'price' => 270.00, 'number_of_sessions' => 12, 'duration_days' => 90, 'daily_sessions_quota' => 1],
        ['code' => 'placeholder_intensive_16', 'name' => '[Placeholder] Intensive — 16 sessions', 'price' => 340.00, 'number_of_sessions' => 16, 'duration_days' => 90, 'daily_sessions_quota' => 2],
    ];

    public const LEGACY_CODES = ['4_weeks', '8_weeks'];

    public function run(): void
    {
        Package::whereIn('code', self::LEGACY_CODES)
            ->whereNull('published_by')
            ->update(['is_published' => false]);

        foreach (self::PACKAGES as $attributes) {
            Package::firstOrCreate(
                ['code' => $attributes['code']],
                $attributes + [
                    'description' => self::PLACEHOLDER_NOTE,
                    'is_published' => true,
                    'published_at' => now(),
                ],
            );
        }
    }
}
