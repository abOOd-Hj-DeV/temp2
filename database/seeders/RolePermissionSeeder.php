<?php

// database/seeders/RolePermissionSeeder.php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        // 1. إعادة تعيين ذاكرة التخزين المؤقت (ضرورية بعد كل عملية Seeding)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. قائمة الصلاحيات
        $permissions = [
            'manage users', 'view reports', 'manage content', 'manage finances',
            'book appointments', 'view appointments', 'manage appointments',
            'review documents', 'assign support',
            'access patient care', 'manage therapist care', 'access care chat',
        ];

        // 3. إنشاء الصلاحيات وتحديد الحارس 'api'
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // جلب جميع الصلاحيات التي تنتمي للحارس 'api'
        $allApiPermissions = Permission::where('guard_name', 'api')->get();

        // 4. إنشاء الأدوار وتعيين الحارس 'api' ثم تعيين الصلاحيات

        foreach (UserRole::cases() as $roleCase) {
            $roleName = $roleCase->value;

            // إنشاء الدور مع تحديد الحارس 'api' بشكل صريح
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api', // <--- تعيين حارس API للدور
            ]);

            // تعيين الصلاحيات حسب الدور
            if (in_array($roleName, [UserRole::SUPER_ADMIN->value, UserRole::ADMIN->value], true)) {
                // المدير الأعلى يحصل على جميع الصلاحيات
                $role->syncPermissions($allApiPermissions);

            } elseif ($roleName === UserRole::PATIENT->value) {
                // العميل (Patient)
                $role->syncPermissions(['book appointments', 'view appointments', 'access patient care', 'access care chat']);

            } elseif ($roleName === UserRole::THERAPIST->value) {
                // المعالج (Therapist)
                $role->syncPermissions(['view reports', 'manage appointments', 'view appointments', 'manage therapist care', 'access care chat']);

            } elseif ($roleName === UserRole::CLINICAL_SUPERVISOR->value) {
                // المشرف السريري (Clinical Supervisor)
                $role->syncPermissions(['manage users', 'manage appointments', 'review documents', 'view reports', 'manage content', 'assign support', 'view appointments']);

            } elseif ($roleName === UserRole::FINANCE_PARTNER->value) {
                // شريك المالية (Finance Partner)
                $role->syncPermissions(['manage finances', 'view reports']);
            } elseif ($roleName === UserRole::SUPPORT_AGENT->value) {
                $role->syncPermissions(['assign support']);
            } elseif ($roleName === UserRole::CONTENT_MANAGER->value) {
                $role->syncPermissions(['manage content']);
            }
        }
    }
}
