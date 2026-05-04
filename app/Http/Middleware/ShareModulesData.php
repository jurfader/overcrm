<?php

namespace App\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ShareModulesData
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Współdziel aktywne moduły z frontendem
        Inertia::share('activeModules', function () {
            $modules = $this->getActiveModulesWithMenu();
            // Moduł Raporty widoczny tylko dla adminów
            $user = auth()->user();
            if ($user && !$user->hasAdminRights()) {
                $modules = array_values(array_filter($modules, fn($m) => $m['name'] !== 'reports'));
            }
            return $modules;
        });

        return $next($request);
    }

    /**
     * Pobierz aktywne moduły wraz z ich konfiguracją menu
     */
    protected function getActiveModulesWithMenu(): array
    {
        $modules = [];
        $modulesPath = base_path('modules');

        if (!File::exists($modulesPath)) {
            return $modules;
        }

        // Pobierz aktywne moduły z bazy.
        // $dbAvailable = mamy tabelę `modules`. Wtedy filtrujemy po `is_active`.
        // Bez tabeli (świeża instalacja) — fall-through, pokazujemy wszystko z manifestu.
        $activeNames = [];
        $dbAvailable = false;
        try {
            if (Schema::hasTable('modules')) {
                $dbAvailable = true;
                $activeNames = Module::where('is_active', true)
                    ->pluck('name')
                    ->toArray();
            }
        } catch (\Exception $e) {
            $dbAvailable = false;
        }

        foreach (File::directories($modulesPath) as $modulePath) {
            $moduleName = basename($modulePath);
            $lowerName = strtolower($moduleName);

            // Gdy baza dostępna — pokaż TYLKO is_active=true. Pusta lista = pusta nawigacja.
            if ($dbAvailable && !in_array($lowerName, $activeNames)) {
                continue;
            }

            $manifestPath = $modulePath . '/module.json';
            if (!File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true);
            if (!$manifest) {
                continue;
            }

            $modules[] = [
                'name' => $lowerName,
                'display_name' => $manifest['display_name'] ?? ucfirst($moduleName),
                'icon' => $manifest['icon'] ?? 'puzzle',
                'menu' => $manifest['menu'] ?? [],
            ];
        }

        return $modules;
    }
}
