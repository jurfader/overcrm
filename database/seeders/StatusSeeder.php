<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('statuses')->insert([
            [
                'name' => 'Nowe',
                'slug' => 'new',
                'type' => 'new',
                'color' => '#3B82F6',
                'order' => 1,
                'is_default' => true,
                'is_visible' => true,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'W trakcie',
                'slug' => 'in_progress',
                'type' => 'in_progress',
                'color' => '#F59E0B',
                'order' => 2,
                'is_default' => false,
                'is_visible' => true,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Oczekujące',
                'slug' => 'pending',
                'type' => 'in_progress',
                'color' => '#8B5CF6',
                'order' => 3,
                'is_default' => false,
                'is_visible' => true,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Wykonane',
                'slug' => 'done',
                'type' => 'done',
                'color' => '#10B981',
                'order' => 4,
                'is_default' => false,
                'is_visible' => true,
                'is_final' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Anulowane',
                'slug' => 'cancelled',
                'type' => 'cancelled',
                'color' => '#6B7280',
                'order' => 5,
                'is_default' => false,
                'is_visible' => true,
                'is_final' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
