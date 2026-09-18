<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Make every user's Spatie role match users.role (the source of truth), so
 * the `role:` middleware and enum-based checks agree for existing accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        User::query()->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $user->syncSpatieRole();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: roles derived from users.role remain valid.
    }
};
