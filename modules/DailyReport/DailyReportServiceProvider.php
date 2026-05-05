<?php

namespace Modules\DailyReport;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class DailyReportServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(WidgetRegistry $widgets): void
    {
        // Dashboard widget — moja aktywność dziś (logi z ActivityLog)
        $widgets->register(new Widget(
            key:           'dailyreport.my-activity-today',
            title:         'Moja aktywność dziś',
            icon:          'document-text',
            component:     'DailyReportMyActivity',  // resolved from modules/*/resources/js/Widgets/*.vue
            defaultWidth:  6,
            minWidth:      4,
            description:   'Liczba akcji dziś + 5 ostatnich (z modułu Raport dzienny)',
            module:        'dailyreport',
            handler:       fn (?User $user) => $this->fetchMyActivity($user),
        ));
    }

    protected function fetchMyActivity(?User $user): array
    {
        if (!$user || !Schema::hasTable('activity_logs')) {
            return ['count' => 0, 'recent' => []];
        }

        $today = now()->startOfDay();
        $tomorrow = now()->endOfDay();

        $base = ActivityLog::where('user_id', $user->id)
            ->whereBetween('created_at', [$today, $tomorrow]);

        $count = (clone $base)->count();
        $recent = (clone $base)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'label'       => $log->action_label ?? $log->action,
                'color'       => $log->action_color ?? '#888',
                'description' => $log->description,
                'time'        => $log->created_at->format('H:i'),
            ])
            ->toArray();

        return ['count' => $count, 'recent' => $recent];
    }
}
