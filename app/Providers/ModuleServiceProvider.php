<?php

namespace App\Providers;

use App\Models\Module;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    protected array $activeModuleNames = [];

    public function register(): void {}

    public function boot(): void
    {
        $this->resolveActiveModules();
        $this->registerActiveModules();
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();
    }

    protected function resolveActiveModules(): void
    {
        $modulesPath = base_path('modules');

        if (!File::exists($modulesPath)) {
            return;
        }

        try {
            if (Schema::hasTable('modules')) {
                $this->activeModuleNames = Module::where('is_active', true)
                    ->pluck('name')
                    ->toArray();
            }
        } catch (\Exception $e) {
            $this->activeModuleNames = [];
        }

        if (empty($this->activeModuleNames)) {
            $this->activeModuleNames = collect(File::directories($modulesPath))
                ->map(fn($dir) => strtolower(basename($dir)))
                ->toArray();
        }
    }

    protected function registerActiveModules(): void
    {
        foreach ($this->activeModuleNames as $moduleName) {
            $this->registerModule($moduleName);
        }
    }

    /**
     * Zarejestruj pojedynczy moduł. Folder szukany case-insensitive (Linux
     * filesystem rozni 'DailyReport' od 'Dailyreport') żeby zachować CamelCase
     * z composer psr-4 niezależnie od slugu w DB.
     */
    protected function registerModule(string $moduleName): void
    {
        $folderName = $this->resolveModuleFolderName($moduleName);
        if (!$folderName) return;

        $modulePath = base_path("modules/{$folderName}");
        $providerClass = "Modules\\{$folderName}\\{$folderName}ServiceProvider";
        $providerFile = "{$modulePath}/{$folderName}ServiceProvider.php";

        if (!File::exists($providerFile)) {
            return;
        }

        // Załaduj plik ServiceProvider
        require_once $providerFile;

        // Zarejestruj provider jeśli klasa istnieje
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Znajduje rzeczywisty folder dla slug'u modułu (case-insensitive match).
     * Cache per-request żeby nie scandirować przy każdym module.
     */
    protected ?array $folderMap = null;
    protected function resolveModuleFolderName(string $slug): ?string
    {
        if ($this->folderMap === null) {
            $this->folderMap = [];
            $modulesPath = base_path('modules');
            if (File::exists($modulesPath)) {
                foreach (File::directories($modulesPath) as $dir) {
                    $base = basename($dir);
                    $this->folderMap[strtolower($base)] = $base;
                }
            }
        }
        return $this->folderMap[strtolower($slug)] ?? null;
    }

    /**
     * Załaduj routy wszystkich aktywnych modułów
     */
    protected function loadModuleRoutes(): void
    {
        $modulesPath = base_path('modules');

        if (!File::exists($modulesPath)) {
            return;
        }

        foreach ($this->activeModuleNames as $activeModule) {
            $moduleName = $this->resolveModuleFolderName($activeModule);
            if (!$moduleName) continue;
            $modulePath = $modulesPath . '/' . $moduleName;

            if (!File::exists($modulePath . '/module.json')) {
                continue;
            }

            $webRoutes = $modulePath . '/routes/web.php';
            if (File::exists($webRoutes)) {
                Route::middleware(['web', 'auth', 'verified'])
                    ->prefix(strtolower($moduleName))
                    ->name(strtolower($moduleName) . '.')
                    ->group($webRoutes);
            }

            $apiRoutes = $modulePath . '/routes/api.php';
            if (File::exists($apiRoutes)) {
                Route::middleware('api')
                    ->prefix('api/' . strtolower($moduleName))
                    ->name('api.' . strtolower($moduleName) . '.')
                    ->group($apiRoutes);
            }
        }
    }

    /**
     * Załaduj migracje modułów
     */
    protected function loadModuleMigrations(): void
    {
        $modulesPath = base_path('modules');

        if (!File::exists($modulesPath)) {
            return;
        }

        foreach (File::directories($modulesPath) as $modulePath) {
            $migrationsPath = $modulePath . '/database/migrations';
            
            if (File::exists($migrationsPath)) {
                $this->loadMigrationsFrom($migrationsPath);
            }
        }
    }
}
