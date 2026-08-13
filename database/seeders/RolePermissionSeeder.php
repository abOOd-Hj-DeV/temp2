<?php
// database/seeders/RolePermissionSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Enums\UserRole;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        // 1. إعادة تعيين ذاكرة التخزين المؤقت (ضرورية بعد كل عملية Seeding)
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. قائمة الصلاحيات
        $permissions = [
            'manage users', 'view reports', 'manage content', 'manage finances',
            'book appointments', 'view appointments' , 'manage appointments',
            'review documents', 'assign support'
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
                'guard_name' => 'api' // <--- تعيين حارس API للدور
            ]);

            // تعيين الصلاحيات حسب الدور
            if ($roleName === UserRole::SUPER_ADMIN->value) {
                // المدير الأعلى يحصل على جميع الصلاحيات
                $role->syncPermissions($allApiPermissions);

            } elseif ($roleName === UserRole::PATIENT->value) {
                // العميل (Patient)
                $role->syncPermissions(['book appointments', 'view appointments']);

            } elseif ($roleName === UserRole::THERAPIST->value) {
                // المعالج (Therapist)
                $role->syncPermissions(['view reports', 'manage appointments', 'view appointments']);

            } elseif ($roleName === UserRole::CLINICAL_SUPERVISOR->value) {
                // المشرف السريري (Clinical Supervisor)
                $role->syncPermissions(['manage appointments', 'review documents', 'view reports']);

            } elseif ($roleName === UserRole::FINANCE_PARTNER->value) {
                // شريك المالية (Finance Partner)
                $role->syncPermissions(['manage finances', 'view reports']);
            }
            // يمكن إضافة منطق لبقية الأدوار هنا (ADMIN, SUPPORT_AGENT, CONTENT_MANAGER)
        }
    }
}
