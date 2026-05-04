<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Zadania
            ['name' => 'Podgląd zadań', 'code' => 'tasks_view', 'module' => 'tasks'],
            ['name' => 'Zarządzanie zadaniami', 'code' => 'tasks_manage', 'module' => 'tasks'],
            
            // Klienci
            ['name' => 'Podgląd klientów', 'code' => 'clients_view', 'module' => 'clients'],
            ['name' => 'Zarządzanie klientami', 'code' => 'clients_manage', 'module' => 'clients'],
            
            // Użytkownicy
            ['name' => 'Podgląd użytkowników', 'code' => 'users_view', 'module' => 'users'],
            ['name' => 'Zarządzanie użytkownikami', 'code' => 'users_manage', 'module' => 'users'],
            
            // Statusy
            ['name' => 'Podgląd statusów', 'code' => 'statuses_view', 'module' => 'statuses'],
            ['name' => 'Zarządzanie statusami', 'code' => 'statuses_manage', 'module' => 'statuses'],
            
            // Ustawienia
            ['name' => 'Zarządzanie ustawieniami', 'code' => 'settings_manage', 'module' => 'settings'],
            
            // Raporty
            ['name' => 'Podgląd raportów', 'code' => 'reports_view', 'module' => 'reports'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insert([
                'name' => $permission['name'],
                'code' => $permission['code'],
                'module' => $permission['module'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
