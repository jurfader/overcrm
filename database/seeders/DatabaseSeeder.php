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
            UserSeeder::class,
            PermissionSeeder::class,
            StatusSeeder::class,
            ClientSeeder::class,
            TaskSeeder::class,
            ClientVisitSeeder::class,
            ModuleSeeder::class,
        ]);
    }
}
