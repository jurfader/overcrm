<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ClientVisitSeeder extends Seeder
{
    public function run(): void
    {
        $clients = Client::all();
        $users = User::all();
        
        if ($clients->isEmpty() || $users->isEmpty()) {
            return;
        }

        $colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6B7280'];
        
        $visits = [
            // Dzisiejsze wizyty
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->format('Y-m-d'),
                'visit_time' => '09:00',
                'title' => 'Spotkanie handlowe',
                'notes' => 'Omówienie nowej oferty produktowej',
                'color' => '#3B82F6',
                'status' => 'confirmed',
            ],
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->format('Y-m-d'),
                'visit_time' => '14:30',
                'title' => 'Dostawa towaru',
                'notes' => 'Standardowa dostawa zamówienia',
                'color' => '#10B981',
                'status' => 'planned',
                'order_value' => 1250.00,
            ],
            // Wizyty w tym tygodniu
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->addDays(1)->format('Y-m-d'),
                'visit_time' => '10:00',
                'notes' => 'Prezentacja nowych produktów',
                'color' => '#F59E0B',
                'status' => 'planned',
            ],
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->addDays(2)->format('Y-m-d'),
                'visit_time' => '11:30',
                'notes' => 'Negocjacje cenowe',
                'color' => '#8B5CF6',
                'status' => 'planned',
            ],
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->addDays(3)->format('Y-m-d'),
                'visit_time' => '08:00',
                'title' => 'Odbiór zamówienia',
                'color' => '#EC4899',
                'status' => 'confirmed',
                'order_value' => 3500.00,
            ],
            // Wizyty z przeszłości
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->subDays(2)->format('Y-m-d'),
                'visit_time' => '09:30',
                'notes' => 'Dostawa zrealizowana bez problemów',
                'color' => '#10B981',
                'status' => 'completed',
                'order_value' => 2100.00,
            ],
            [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => now()->subDays(5)->format('Y-m-d'),
                'visit_time' => '15:00',
                'notes' => 'Reklamacja rozpatrzona pozytywnie',
                'color' => '#EF4444',
                'status' => 'completed',
            ],
        ];

        // Dodaj więcej losowych wizyt na cały miesiąc
        for ($i = 0; $i < 20; $i++) {
            $date = now()->startOfMonth()->addDays(rand(0, 27));
            
            $visits[] = [
                'client_id' => $clients->random()->id,
                'user_id' => $users->random()->id,
                'visit_date' => $date->format('Y-m-d'),
                'visit_time' => sprintf('%02d:%02d', rand(7, 17), rand(0, 1) * 30),
                'title' => null,
                'notes' => null,
                'color' => $colors[array_rand($colors)],
                'status' => ['planned', 'confirmed', 'completed'][rand(0, 2)],
                'order_value' => rand(0, 1) ? rand(500, 5000) : null,
            ];
        }

        foreach ($visits as $visit) {
            ClientVisit::create($visit);
        }
    }
}
