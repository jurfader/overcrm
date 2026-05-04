<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\ModuleLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Client;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $modulesCount = 0;
        $recentLogs = collect();

        // Sprawdź czy tabele istnieją (dla pierwszego uruchomienia)
        if (Schema::hasTable('modules')) {
            $modulesCount = Module::active()->count();
        }
        
        if (Schema::hasTable('module_logs')) {
            $recentLogs = ModuleLog::with('module', 'user')
                ->latest()
                ->take(10)
                ->get();
        }

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => User::count(),
                'clients' => Client::count(),
                'tasks' => Task::count(),
                'modules' => $modulesCount,
            ],
            'recentLogs' => $recentLogs,
        ]);
    }
}
