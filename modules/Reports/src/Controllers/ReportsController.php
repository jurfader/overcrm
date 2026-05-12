<?php

namespace Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\Task;
use Modules\Fakturownia\Services\FakturowniaService;
use App\Services\Reports\MarginReportExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period', 'month');
        $dateRange = $this->getDateRange($period);
        
        return Inertia::render('Reports/Index', [
            'period' => $period,
            'dateRange' => $dateRange,
            'statistics' => $this->getStatistics($dateRange),
            'chartData' => $this->getChartData($dateRange),
        ]);
    }

    public function margin(Request $request, FakturowniaService $fakturownia)
    {
        $user = auth()->user();
        $isAdmin = $user->hasAdminRights();

        $period = $request->get('period', 'month');
        $departmentId = $request->get('department_id');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        if (!$isAdmin) {
            $departmentId = $user->fakturownia_department_id;
        }

        $marginStats = $fakturownia->getMarginStats($period, $departmentId, $dateFrom, $dateTo);
        $rawDepartments = $fakturownia->getDepartments();

        if ($period === 'custom' && $dateFrom && $dateTo) {
            $dateRange = [
                'start' => $dateFrom,
                'end' => $dateTo,
                'label' => Carbon::parse($dateFrom)->format('d.m.Y') . ' - ' . Carbon::parse($dateTo)->format('d.m.Y'),
            ];
        } else {
            $dateRange = $this->getDateRange($period);
        }

        $departments = array_map(function ($dept) {
            return [
                'id' => $dept['id'] ?? 0,
                'name' => $dept['shortcut'] ?: ($dept['name'] ?? 'Dział #' . ($dept['id'] ?? 0)),
            ];
        }, $rawDepartments);

        $productStats = $isAdmin ? $fakturownia->getProductStats($period, $departmentId, $dateFrom, $dateTo) : null;

        return Inertia::render('Reports/Margin', [
            'period' => $period,
            'dateRange' => $dateRange,
            'marginStats' => $marginStats,
            'departments' => $departments,
            'selectedDepartment' => $departmentId,
            'hasFakturownia' => $fakturownia->isConfigured(),
            'isAdmin' => $isAdmin,
            'productStats' => $productStats,
            'customDateFrom' => $dateFrom,
            'customDateTo' => $dateTo,
        ]);
    }

    /**
     * Eksport profesjonalnego raportu Excel z wykresami (5 arkuszy).
     * Używa tych samych filtrów co widok Margin (period, department_id, custom range).
     */
    public function export(Request $request, FakturowniaService $fakturownia, MarginReportExporter $exporter)
    {
        $validated = $request->validate([
            'period'        => 'nullable|in:day,week,month,quarter,year,custom',
            'department_id' => 'nullable|integer',
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date',
        ]);

        $period = $validated['period'] ?? 'month';
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        $deptId = $validated['department_id'] ?? null;

        // Rozwiń period preset → konkretne daty
        if ($period !== 'custom' || !$dateFrom || !$dateTo) {
            $range = $this->getDateRange($period);
            $dateFrom = $range['start'];
            $dateTo = $range['end'];
        }

        // Pre-flight: sprawdź czy Fakturownia jest skonfigurowana
        if (!$fakturownia->isConfigured()) {
            return response()->json([
                'error' => 'Fakturownia nie jest skonfigurowana. Sprawdź ustawienia w Admin → Moduły → Core.',
            ], 422);
        }

        // Etykieta handlowca (do nagłówka raportu)
        $deptLabel = 'Wszyscy handlowcy';
        if ($deptId) {
            try {
                foreach ($fakturownia->getDepartments() as $d) {
                    if ((int) ($d['id'] ?? 0) === (int) $deptId) {
                        $deptLabel = (string) ($d['shortcut'] ?: ($d['name'] ?? ('Oddział #' . $deptId)));
                        break;
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Reports::export — getDepartments failed: ' . $e->getMessage());
            }
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        try {
            $tmpPath = $exporter->generate([
                'period'                  => 'custom',
                'date_from'               => $dateFrom,
                'date_to'                 => $dateTo,
                'department_ids'          => $deptId ? [(int) $deptId] : null,
                'department_filter_label' => $deptLabel,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Reports::export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'department_id' => $deptId,
            ]);
            return response()->json([
                'error' => 'Nie udało się wygenerować raportu: ' . $e->getMessage(),
            ], 500);
        }

        $filename = sprintf('Raport_marzowosci_%s_%s.xlsx', $dateFrom, $dateTo);

        return response()->streamDownload(function () use ($tmpPath) {
            readfile($tmpPath);
            @unlink($tmpPath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    protected function getDateRange(string $period): array
    {
        $end = Carbon::now();

        $start = match ($period) {
            'day'     => $end->copy()->startOfDay(),
            'week'    => $end->copy()->subWeek(),
            'month'   => $end->copy()->subMonth(),
            'quarter' => $end->copy()->subQuarter(),
            'year'    => $end->copy()->subYear(),
            default   => $end->copy()->subMonth(),
        };

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => $start->format('d.m.Y') . ' - ' . $end->format('d.m.Y'),
        ];
    }

    protected function getStatistics(array $dateRange): array
    {
        $start = Carbon::parse($dateRange['start']);
        $end = Carbon::parse($dateRange['end']);

        // Wizyty
        $visitsQuery = ClientVisit::whereBetween('visit_date', [$start, $end]);
        $totalVisits = $visitsQuery->count();
        $completedVisits = (clone $visitsQuery)->whereNotNull('status_id')->count();
        $totalOrderValue = (clone $visitsQuery)->sum('order_value');

        // Klienci
        $newClients = Client::whereBetween('created_at', [$start, $end])->count();
        $activeClients = Client::where('status', 'active')->count();

        // Zadania
        $tasksQuery = Task::whereBetween('created_at', [$start, $end]);
        $totalTasks = $tasksQuery->count();
        $completedTasks = Task::whereHas('status', fn($q) => $q->where('is_final', true))
            ->whereBetween('updated_at', [$start, $end])
            ->count();

        return [
            'visits' => [
                'total' => $totalVisits,
                'completed' => $completedVisits,
                'conversion' => $totalVisits > 0 ? round(($completedVisits / $totalVisits) * 100, 1) : 0,
            ],
            'revenue' => [
                'total' => $totalOrderValue,
                'average' => $totalVisits > 0 ? round($totalOrderValue / $totalVisits, 2) : 0,
            ],
            'clients' => [
                'new' => $newClients,
                'active' => $activeClients,
            ],
            'tasks' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'completion_rate' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0,
            ],
        ];
    }

    protected function getChartData(array $dateRange): array
    {
        $start = Carbon::parse($dateRange['start']);
        $end = Carbon::parse($dateRange['end']);

        // Wizyty per dzień/tydzień
        $visits = ClientVisit::selectRaw('DATE(visit_date) as date, COUNT(*) as count, SUM(order_value) as value')
            ->whereBetween('visit_date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'visits' => [
                'labels' => $visits->pluck('date')->map(fn($d) => Carbon::parse($d)->format('d.m'))->toArray(),
                'data' => $visits->pluck('count')->toArray(),
            ],
            'revenue' => [
                'labels' => $visits->pluck('date')->map(fn($d) => Carbon::parse($d)->format('d.m'))->toArray(),
                'data' => $visits->pluck('value')->map(fn($v) => (float) $v)->toArray(),
            ],
        ];
    }
}
