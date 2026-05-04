<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClientVisit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyReportController extends Controller
{
    public function index(Request $request): Response
    {
        $date = $request->get('date', now()->toDateString());
        $userId = $request->get('user_id');

        $users = User::orderBy('name')->get(['id', 'name', 'role']);

        $activities = [];
        $visitsSummary = [];

        if ($userId) {
            $dayStart = Carbon::parse($date)->startOfDay();
            $dayEnd = Carbon::parse($date)->endOfDay();

            // Pobierz wszystkie logi aktywności tego użytkownika w tym dniu
            $activities = ActivityLog::with('user')
                ->where('user_id', $userId)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->latest()
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'action_label' => $log->action_label,
                        'action_color' => $log->action_color,
                        'model_type' => $log->model_type,
                        'model_id' => $log->model_id,
                        'model_name' => $log->model_name,
                        'description' => $log->description,
                        'old_values' => $log->old_values,
                        'new_values' => $log->new_values,
                        'ip_address' => $log->ip_address,
                        'created_at' => $log->created_at->toISOString(),
                        'time' => $log->created_at->format('H:i:s'),
                    ];
                });

            // Pobierz wizyty tego użytkownika na ten dzień (aktualny stan)
            $visits = ClientVisit::with(['client'])
                ->where('user_id', $userId)
                ->whereDate('visit_date', $date)
                ->orderBy('visit_time')
                ->get();

            // Pobierz statusy do mapowania
            $statusMap = \App\Models\Status::all()->keyBy('id');

            $visitsSummary = $visits->map(function ($visit) use ($statusMap) {
                $statusObj = $visit->status_id ? ($statusMap[$visit->status_id] ?? null) : null;
                return [
                    'id' => $visit->id,
                    'client_name' => $visit->client?->name ?? '—',
                    'client_id' => $visit->client_id,
                    'title' => $visit->title,
                    'description' => $visit->description,
                    'notes' => $visit->notes,
                    'visit_time' => $visit->visit_time?->format('H:i'),
                    'status_name' => $statusObj?->name,
                    'status_color' => $statusObj?->color,
                    'order_value' => $visit->order_value,
                    'updated_at' => $visit->updated_at?->toISOString(),
                ];
            });
        }

        $selectedUser = $userId ? User::find($userId, ['id', 'name', 'role']) : null;

        return Inertia::render('Admin/DailyReport/Index', [
            'users' => $users,
            'activities' => $activities,
            'visitsSummary' => $visitsSummary,
            'selectedUser' => $selectedUser,
            'filters' => [
                'date' => $date,
                'user_id' => $userId ?? '',
            ],
        ]);
    }
}
