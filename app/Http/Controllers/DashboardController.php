<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Module;
use App\Models\Task;
use App\Models\User;
use App\Services\CallReminderService;
use App\Services\FakturowniaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    protected FakturowniaService $fakturowniaService;

    public function __construct(FakturowniaService $fakturowniaService)
    {
        $this->fakturowniaService = $fakturowniaService;
    }

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $period = $request->get('period', 'month');
        
        // Statystyki
        $tasksCount = Task::incomplete()->count();
        $todayTasksCount = Task::today()->incomplete()->count();
        $overdueTasksCount = Task::overdue()->count();
        $clientsCount = Client::active()->count();
        $usersCount = User::active()->count();
        
        // Zadania na dziś (przypisane do użytkownika lub wszystkie dla admina)
        $todayTasks = Task::with(['status', 'client', 'assignee'])
            ->today()
            ->incomplete()
            ->when(!$user->hasAdminRights(), fn($q) => $q->assignedTo($user->id))
            ->orderBy('priority', 'desc')
            ->limit(10)
            ->get();
        
        // Przeterminowane zadania
        $overdueTasks = Task::with(['status', 'client', 'assignee'])
            ->overdue()
            ->when(!$user->hasAdminRights(), fn($q) => $q->assignedTo($user->id))
            ->orderBy('due_date')
            ->limit(5)
            ->get();
        
        // Ostatnio dodani klienci
        $recentClients = Client::latest()
            ->limit(5)
            ->get();

        // Dane z Fakturowni - statystyki przychodów i marżowości
        $departmentId = $user->fakturownia_department_id;
        $revenueStats = $this->fakturowniaService->getRevenueStats($period, $departmentId);
        $marginStats = $this->fakturowniaService->getMarginStats($period, $departmentId);

        // Informacja o filtrze działu
        $departmentInfo = null;
        if ($departmentId) {
            // Pobierz nazwę działu z Fakturowni
            $departmentName = 'Dział #' . $departmentId;
            $departments = $this->fakturowniaService->getDepartments();
            foreach ($departments as $dept) {
                if (($dept['id'] ?? null) == $departmentId) {
                    $departmentName = $dept['shortcut'] ?? $dept['name'] ?? $departmentName;
                    break;
                }
            }
            
            $departmentInfo = [
                'id' => $departmentId,
                'name' => $departmentName,
            ];
        }

        // Ringostat - statystyki połączeń na dashboardzie (tylko gdy moduł aktywny)
        $callStats = null;
        $callTrend = [];
        $hasRingostatIntegration = false;
        $clientsToCall = [];
        $clientsAfterVisit = [];

        try {
            if (Schema::hasTable('modules')
                && Module::where('name', 'ringostat')->where('is_active', true)->exists()
                && Schema::hasTable('ringostat_calls')
                && class_exists(\Modules\Ringostat\Models\RingostatCall::class)
            ) {
                $ringostatCallClass = \Modules\Ringostat\Models\RingostatCall::class;

                // Statystyki połączeń tylko dla danego użytkownika
                $todayCallsTotal = $ringostatCallClass::whereDate('call_date', now())->where('user_id', $user->id)->count();
                $todayCallsAnswered = $ringostatCallClass::whereDate('call_date', now())->where('user_id', $user->id)->answered()->count();
                $todayCallsMissed = $todayCallsTotal - $todayCallsAnswered;
                $todayAvgDuration = $ringostatCallClass::whereDate('call_date', now())->where('user_id', $user->id)->answered()->avg('billsec') ?? 0;

                $callStats = [
                    'total' => $todayCallsTotal,
                    'answered' => $todayCallsAnswered,
                    'missed' => $todayCallsMissed,
                    'avg_duration' => round($todayAvgDuration),
                ];

                for ($i = 6; $i >= 0; $i--) {
                    $date = now()->subDays($i);
                    $dayTotal = $ringostatCallClass::whereDate('call_date', $date)->where('user_id', $user->id)->count();
                    $dayAnswered = $ringostatCallClass::whereDate('call_date', $date)->where('user_id', $user->id)->answered()->count();
                    $callTrend[] = [
                        'date' => $date->format('d.m'),
                        'total' => $dayTotal,
                        'answered' => $dayAnswered,
                    ];
                }

                $hasRingostatIntegration = true;

                // Klienci do oddzwonienia – ostatnie połączenie 7+ dni temu (tylko połączenia tego handlowca)
                $daysThreshold = 7;
                $cutoffDate = now()->subDays($daysThreshold);
                $lastCallsByClient = $ringostatCallClass::where('user_id', $user->id)
                    ->whereNotNull('client_id')
                    ->where('disposition', 'ANSWERED')
                    ->selectRaw('client_id, MAX(call_date) as last_call')
                    ->groupBy('client_id')
                    ->havingRaw('MAX(call_date) < ?', [$cutoffDate])
                    ->orderByRaw('MAX(call_date) ASC')
                    ->limit(15)
                    ->get();

                $clientIds = $lastCallsByClient->pluck('client_id')->filter()->unique()->values();
                $clients = Client::active()->whereIn('id', $clientIds)->get()->keyBy('id');
                foreach ($lastCallsByClient as $row) {
                    $client = $clients->get($row->client_id);
                    if ($client) {
                        $lastCall = \Carbon\Carbon::parse($row->last_call);
                        $clientsToCall[] = [
                            'id' => $client->id,
                            'name' => $client->short_name ?: $client->name,
                            'phone' => $client->phone ?: $client->phone2,
                            'last_call_at' => $lastCall->format('Y-m-d'),
                            'days_ago' => (int) $lastCall->startOfDay()->diffInDays(now()->startOfDay()),
                        ];
                    }
                }

                // Klienci po wizycie – wizyta 7+ dni temu, brak oddzwonienia od tego handlowca
                if (Schema::hasTable('client_visits')) {
                    $lastVisits = \App\Models\ClientVisit::where('user_id', $user->id)
                        ->where('visit_date', '<', $cutoffDate)
                        ->selectRaw('client_id, MAX(visit_date) as last_visit')
                        ->groupBy('client_id')
                        ->get();

                    foreach ($lastVisits as $row) {
                        $lastCall = $ringostatCallClass::where('user_id', $user->id)
                            ->where('client_id', $row->client_id)
                            ->where('disposition', 'ANSWERED')
                            ->max('call_date');
                        $lastVisitDate = \Carbon\Carbon::parse($row->last_visit);
                        if (!$lastCall || \Carbon\Carbon::parse($lastCall)->lt($lastVisitDate)) {
                            $client = Client::active()->find($row->client_id);
                            if ($client && !collect($clientsToCall)->contains('id', $client->id)) {
                                $clientsAfterVisit[] = [
                                    'id' => $client->id,
                                    'name' => $client->short_name ?: $client->name,
                                    'phone' => $client->phone ?: $client->phone2,
                                    'last_visit_at' => $lastVisitDate->format('Y-m-d'),
                                    'days_ago' => (int) $lastVisitDate->startOfDay()->diffInDays(now()->startOfDay()),
                                ];
                            }
                        }
                    }
                    $clientsAfterVisit = array_slice($clientsAfterVisit, 0, 5);
                }
            }
        } catch (\Exception $e) {
            // Moduł niedostępny - ignoruj
        }

        // Lokale z urodzinami w najbliższych 30 dniach (profile.venue.venue_birthday)
        $venueBirthdaysUpcoming = [];
        try {
            $today = now()->startOfDay();
            $endDate = $today->copy()->addDays(30);
            $clientsWithProfile = Client::active()->whereNotNull('profile')->get();
            foreach ($clientsWithProfile as $client) {
                $birthday = $client->profile['venue']['venue_birthday'] ?? null;
                if ($birthday && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
                    $parsed = \Carbon\Carbon::parse($birthday);
                    $thisYear = $today->copy()->setMonth($parsed->month)->setDay($parsed->day)->startOfDay();
                    if ($thisYear->lt($today)) {
                        $thisYear->addYear();
                    }
                    if ($thisYear->gte($today) && $thisYear->lte($endDate)) {
                        $venueBirthdaysUpcoming[] = [
                            'id' => $client->id,
                            'name' => $client->short_name ?: $client->name,
                            'venue_birthday' => $birthday,
                            'date' => $thisYear->format('Y-m-d'),
                            'days_until' => (int) $today->diffInDays($thisYear, false),
                        ];
                    }
                }
            }
            usort($venueBirthdaysUpcoming, fn($a, $b) => $a['date'] <=> $b['date']);
        } catch (\Exception $e) {
            // Ignoruj błędy
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'tasks' => $tasksCount,
                'todayTasks' => $todayTasksCount,
                'overdueTasks' => $overdueTasksCount,
                'clients' => $clientsCount,
                'users' => $usersCount,
            ],
            'todayTasks' => $todayTasks,
            'overdueTasks' => $overdueTasks,
            'recentClients' => $recentClients,
            'revenueStats' => $revenueStats,
            'marginStats' => $marginStats,
            'selectedPeriod' => $period,
            'departmentInfo' => $departmentInfo,
            'hasFakturowniaIntegration' => $this->fakturowniaService->isConfigured(),
            'hasRingostatIntegration' => $hasRingostatIntegration,
            'callStats' => $callStats,
            'callTrend' => $callTrend,
            'clientsToCall' => $clientsToCall,
            'clientsAfterVisit' => $clientsAfterVisit,
            'venueBirthdaysUpcoming' => $venueBirthdaysUpcoming,
        ]);
    }

    /**
     * AI: sugestia rozmówki przed połączeniem z klientem
     */
    public function callReminder(Client $client, CallReminderService $service)
    {
        try {
            $reminder = $service->generate($client);
            return response()->json([
                'success' => true,
                'reminder' => $reminder,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('CallReminderService error', ['client_id' => $client->id, 'message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Błąd generowania sugestii: ' . $e->getMessage(),
            ], 500);
        }
    }
}
