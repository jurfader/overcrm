<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\Task;
use App\Models\User;
use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetRegistry::class);
    }

    public function boot(WidgetRegistry $registry): void
    {
        $this->registerCoreWidgets($registry);
    }

    protected function registerCoreWidgets(WidgetRegistry $registry): void
    {
        $registry->register(new Widget(
            key: 'core.kpi-tiles',
            title: 'Kluczowe wskaźniki',
            icon: 'dashboard',
            component: 'KpiTiles',
            defaultWidth: 12,
            minWidth: 6,
            description: '5 kafli KPI: zadania, klienci, użytkownicy',
            handler: fn (?User $user) => [
                'tasks'        => Task::incomplete()->count(),
                'todayTasks'   => Task::today()->incomplete()->count(),
                'overdueTasks' => Task::overdue()->count(),
                'clients'      => Client::active()->count(),
                'users'        => User::active()->count(),
            ],
        ));

        $registry->register(new Widget(
            key: 'core.tasks-today',
            title: 'Zadania na dziś',
            icon: 'tasks',
            component: 'TasksToday',
            defaultWidth: 6,
            minWidth: 4,
            description: 'Twoje zadania z terminem na dziś',
            handler: fn (?User $user) => $user
                ? Task::with(['status', 'client', 'assignee'])
                    ->today()
                    ->incomplete()
                    ->when(!$user->hasAdminRights(), fn($q) => $q->assignedTo($user->id))
                    ->orderBy('priority', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
                : [],
        ));

        $registry->register(new Widget(
            key: 'core.tasks-overdue',
            title: 'Przeterminowane zadania',
            icon: 'alert',
            component: 'TasksOverdue',
            defaultWidth: 6,
            minWidth: 4,
            description: 'Zadania po terminie wymagające reakcji',
            handler: fn (?User $user) => $user
                ? Task::with(['status', 'client', 'assignee'])
                    ->overdue()
                    ->when(!$user->hasAdminRights(), fn($q) => $q->assignedTo($user->id))
                    ->orderBy('due_date')
                    ->limit(5)
                    ->get()
                    ->toArray()
                : [],
        ));

        $registry->register(new Widget(
            key: 'core.recent-clients',
            title: 'Ostatnio dodani klienci',
            icon: 'clients',
            component: 'RecentClients',
            defaultWidth: 6,
            minWidth: 3,
            description: '5 najnowszych klientów w systemie',
            handler: fn (?User $user) => Client::latest()->limit(5)->get()->toArray(),
        ));

        $registry->register(new Widget(
            key: 'core.upcoming-visits',
            title: 'Najbliższe wizyty',
            icon: 'calendar',
            component: 'UpcomingVisits',
            defaultWidth: 6,
            minWidth: 4,
            description: 'Wizyty z kalendarza zaplanowane na najbliższe dni',
            handler: function (?User $user) {
                if (!$user || !Schema::hasTable('client_visits')) return [];

                return ClientVisit::with(['client', 'user'])
                    ->whereDate('visit_date', '>=', now()->startOfDay())
                    ->when(!$user->hasAdminRights(), fn($q) => $q->where('user_id', $user->id))
                    ->orderBy('visit_date')
                    ->orderBy('visit_time')
                    ->limit(8)
                    ->get()
                    ->map(fn($v) => [
                        'id'         => $v->id,
                        'title'      => $v->title,
                        'visit_date' => $v->visit_date?->format('Y-m-d'),
                        'visit_time' => $v->visit_time,
                        'client'     => $v->client ? [
                            'id'   => $v->client->id,
                            'name' => $v->client->short_name ?: $v->client->name,
                        ] : null,
                        'user' => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name] : null,
                        'color' => $v->color,
                    ])
                    ->toArray();
            },
        ));

        $registry->register(new Widget(
            key: 'core.venue-birthdays',
            title: 'Urodziny lokali',
            icon: 'cake',
            component: 'VenueBirthdays',
            defaultWidth: 6,
            minWidth: 3,
            description: 'Lokale z rocznicą otwarcia w najbliższych 30 dniach',
            handler: function (?User $user) {
                $today = now()->startOfDay();
                $endDate = $today->copy()->addDays(30);
                $upcoming = [];

                $clients = Client::active()->whereNotNull('profile')->get();
                foreach ($clients as $client) {
                    $birthday = $client->profile['venue']['venue_birthday'] ?? null;
                    if (!$birthday || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) continue;

                    $parsed = \Carbon\Carbon::parse($birthday);
                    $thisYear = $today->copy()->setMonth($parsed->month)->setDay($parsed->day)->startOfDay();
                    if ($thisYear->lt($today)) $thisYear->addYear();

                    if ($thisYear->gte($today) && $thisYear->lte($endDate)) {
                        $upcoming[] = [
                            'id'             => $client->id,
                            'name'           => $client->short_name ?: $client->name,
                            'venue_birthday' => $birthday,
                            'date'           => $thisYear->format('Y-m-d'),
                            'days_until'     => (int) $today->diffInDays($thisYear, false),
                        ];
                    }
                }

                usort($upcoming, fn($a, $b) => $a['date'] <=> $b['date']);
                return $upcoming;
            },
        ));
    }
}
