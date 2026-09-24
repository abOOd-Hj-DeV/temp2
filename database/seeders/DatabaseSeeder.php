<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // تأكد من أن RolePermissionSeeder مُستدعى هنا
            RolePermissionSeeder::class,
            PackageSeeder::class,
            // إذا كان لديك Seeders أخرى، ضعها هنا
        ]);
    }
}
