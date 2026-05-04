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
            $this->registerModule(ucfirst($moduleName));
        }
    }

    /**
     * Zarejestruj pojedynczy moduł
     */
    protected function registerModule(string $moduleName): void
    {
        $modulePath = base_path("modules/{$moduleName}");
        $providerClass = "Modules\\{$moduleName}\\{$moduleName}ServiceProvider";
        $providerFile = "{$modulePath}/{$moduleName}ServiceProvider.php";

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
     * Załaduj routy wszystkich aktywnych modułów
     */
    protected function loadModuleRoutes(): void
    {
        $modulesPath = base_path('modules');

        if (!File::exists($modulesPath)) {
            return;
        }

        foreach ($this->activeModuleNames as $activeModule) {
            $moduleName = ucfirst($activeModule);
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
