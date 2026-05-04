<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();

        DB::table('tasks')->insert([
            'title' => 'Spotkanie z klientem ABC',
            'description' => 'Omówienie nowego projektu i ustalenie harmonogramu prac.',
            'status_id' => 2, // W trakcie
            'client_id' => 1,
            'assigned_to' => 2,
            'created_by' => 1,
            'submit_date' => $today->copy()->subDays(3),
            'due_date' => $today,
            'priority' => 'high',
            'estimated_hours' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'title' => 'Przygotowanie oferty handlowej',
            'description' => 'Przygotować szczegółową ofertę na dostawę produktów dla XYZ Industries.',
            'status_id' => 1, // Nowe
            'client_id' => 2,
            'assigned_to' => 3,
            'created_by' => 1,
            'submit_date' => $today,
            'due_date' => $today->copy()->addDays(2),
            'priority' => 'medium',
            'estimated_hours' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'title' => 'Raport miesięczny - styczeń',
            'description' => 'Przygotować raport sprzedażowy za miesiąc styczeń 2026.',
            'status_id' => 1, // Nowe
            'assigned_to' => 2,
            'created_by' => 1,
            'submit_date' => $today->copy()->subDays(1),
            'due_date' => $today->copy()->addDays(3),
            'priority' => 'low',
            'estimated_hours' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'title' => 'Wysyłka zamówienia #1234',
            'description' => 'Przygotować i wysłać paczkę z zamówieniem dla Tech Solutions.',
            'status_id' => 4, // Wykonane
            'client_id' => 3,
            'assigned_to' => 3,
            'created_by' => 1,
            'submit_date' => $today->copy()->subDays(5),
            'due_date' => $today->copy()->subDays(2),
            'completed_at' => $today->copy()->subDays(2),
            'priority' => 'high',
            'estimated_hours' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'title' => 'Rozmowa telefoniczna z klientem',
            'description' => 'Follow-up po ostatnim spotkaniu, omówienie dalszych kroków.',
            'status_id' => 2, // W trakcie
            'client_id' => 4,
            'assigned_to' => 3,
            'created_by' => 1,
            'submit_date' => $today,
            'due_date' => $today,
            'priority' => 'medium',
            'estimated_hours' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'title' => 'Aktualizacja cennika',
            'description' => 'Zaktualizować cennik produktów na stronie internetowej.',
            'status_id' => 3, // Oczekujące
            'assigned_to' => 2,
            'created_by' => 1,
            'submit_date' => $today->copy()->subDays(2),
            'due_date' => $today->copy()->addDays(5),
            'priority' => 'low',
            'estimated_hours' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
