<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'name' => 'Administrator',
                'email' => 'admin@example.com',
                'password' => Hash::make('admin123'),
                'phone' => '+48 500 000 001',
                'position' => 'Administrator systemu',
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Jan Kowalski',
                'email' => 'jan@example.com',
                'password' => Hash::make('test123'),
                'phone' => '+48 500 000 002',
                'position' => 'Manager sprzedaży',
                'role' => 'manager',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Anna Nowak',
                'email' => 'anna@example.com',
                'password' => Hash::make('test123'),
                'phone' => '+48 500 000 003',
                'position' => 'Specjalista ds. klientów',
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Piotr Wiśniewski',
                'email' => 'piotr@example.com',
                'password' => Hash::make('test123'),
                'phone' => '+48 500 000 004',
                'position' => 'Handlowiec',
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
