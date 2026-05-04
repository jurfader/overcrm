<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationLogController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->get('tab', 'integration');

        $query = IntegrationLog::with('user')->latest();

        if ($service = $request->get('service')) {
            $query->where('service', $service);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(25)->withQueryString();

        $stats = [
            'total' => IntegrationLog::count(),
            'today' => IntegrationLog::whereDate('created_at', today())->count(),
            'errors_today' => IntegrationLog::whereDate('created_at', today())->where('status', 'error')->count(),
            'avg_duration' => (int) IntegrationLog::whereDate('created_at', today())->avg('duration_ms'),
        ];

        $appLogs = [];
        $appLogStats = ['total' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0];

        if ($tab === 'app') {
            $levelFilter = $request->get('level', '');
            $appSearch = $request->get('search', '');
            $page = max(1, (int) $request->get('page', 1));
            $parsed = $this->parseAppLogs($levelFilter, $appSearch, $page, 50);
            $appLogs = $parsed['entries'];
            $appLogStats = $parsed['stats'];
        }

        return Inertia::render('Admin/IntegrationLogs/Index', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => [
                'service' => $request->get('service', ''),
                'status' => $request->get('status', ''),
                'search' => $request->get('search', ''),
                'tab' => $tab,
                'level' => $request->get('level', ''),
            ],
            'services' => [
                'fakturownia' => 'Fakturownia',
                'apilo' => 'Apilo',
                'gus' => 'GUS',
                'ringostat' => 'Ringostat',
            ],
            'appLogs' => $appLogs,
            'appLogStats' => $appLogStats,
        ]);
    }

    private function parseAppLogs(string $levelFilter = '', string $search = '', int $page = 1, int $perPage = 50): array
    {
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            return ['entries' => [], 'stats' => ['total' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0]];
        }

        $content = file_get_contents($logFile);
        $pattern = '/\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s(\w+)\.(\w+):\s(.*?)(?=\n\[\d{4}-|\z)/s';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $entries = [];
        $stats = ['total' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0];

        foreach ($matches as $match) {
            $level = strtolower($match[3]);
            $stats['total']++;

            if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                $stats['errors']++;
            } elseif ($level === 'warning') {
                $stats['warnings']++;
            } else {
                $stats['info']++;
            }

            if ($levelFilter && $level !== strtolower($levelFilter)) {
                continue;
            }

            $message = trim($match[4]);
            if ($search && stripos($message, $search) === false) {
                continue;
            }

            $stackTrace = null;
            $messageLine = $message;
            if (str_contains($message, "\n")) {
                $parts = explode("\n", $message, 2);
                $messageLine = $parts[0];
                $stackTrace = trim($parts[1]);
                if (mb_strlen($stackTrace) > 2000) {
                    $stackTrace = mb_substr($stackTrace, 0, 2000) . "\n... (obcięto)";
                }
            }

            $entries[] = [
                'timestamp' => $match[1],
                'environment' => $match[2],
                'level' => strtoupper($match[3]),
                'message' => mb_strlen($messageLine) > 500 ? mb_substr($messageLine, 0, 500) . '...' : $messageLine,
                'stack_trace' => $stackTrace,
            ];
        }

        $entries = array_reverse($entries);
        $total = count($entries);
        $entries = array_slice($entries, ($page - 1) * $perPage, $perPage);

        $stats['filtered_total'] = $total;
        $stats['page'] = $page;
        $stats['per_page'] = $perPage;
        $stats['last_page'] = max(1, ceil($total / $perPage));

        return ['entries' => $entries, 'stats' => $stats];
    }
}
