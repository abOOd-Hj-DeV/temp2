<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach ([
                'access patient care' => ['patient'],
                'manage therapist care' => ['therapist'],
                'access care chat' => ['patient', 'therapist'],
            ] as $name => $roles) {
                // An existing permission may already have deliberate deployment-specific revocations.
                if (Permission::where('name', $name)->where('guard_name', 'api')->exists()) {
                    continue;
                }
                $permission = Permission::create(['name' => $name, 'guard_name' => 'api']);
                foreach (Role::whereIn('name', $roles)->where('guard_name', 'api')->get() as $role) {
                    $role->givePermissionTo($permission);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep operator-managed grants and revocations on rollback.
    }
};
