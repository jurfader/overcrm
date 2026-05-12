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

        // Zarejestruj PSR-4 autoload modulu dynamicznie (zeby nie trzeba bylo
        // composer dump-autoload po marketplace install). Mapuje Modules\Foo\
        // -> modules/Foo/ + modules/Foo/src/.
        $this->registerModulePsr4($folderName, $modulePath);

        // Załaduj plik ServiceProvider (wymagane oddzielnie — jest w root modulu,
        // nie w src/, a ServiceProvider class musi byc dostepny przed register()).
        require_once $providerFile;

        // Zarejestruj provider jeśli klasa istnieje
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Rejestruje PSR-4 prefix dla modulu w runtime'owym Composer ClassLoaderze.
     * Daje to modulom mozliwosc autoladowania klas (Controllers, Models, Services)
     * bez modyfikacji composer.json.
     */
    protected function registerModulePsr4(string $folderName, string $modulePath): void
    {
        $loader = $this->getComposerLoader();
        if (!$loader) return;

        $loader->addPsr4("Modules\\{$folderName}\\", [
            $modulePath . '/',
            $modulePath . '/src/',
        ]);
    }

    /**
     * Znajduje Composer ClassLoader z istniejacych spl_autoload_functions
     * (cached). require vendor/autoload.php drugi raz zwraca true, nie loader,
     * wiec musimy fishingowac po istniejacych callbackach.
     */
    protected ?\Composer\Autoload\ClassLoader $cachedLoader = null;
    protected function getComposerLoader(): ?\Composer\Autoload\ClassLoader
    {
        if ($this->cachedLoader) return $this->cachedLoader;

        foreach (spl_autoload_functions() as $fn) {
            if (is_array($fn) && isset($fn[0]) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
                return $this->cachedLoader = $fn[0];
            }
        }
        return null;
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
