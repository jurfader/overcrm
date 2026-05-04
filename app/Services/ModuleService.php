<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Collection;

class ModuleService
{
    /**
     * Skanuj katalog modules/ i zsynchronizuj z bazą danych
     */
    public function discoverModules(): Collection
    {
        $modulesPath = base_path('modules');
        $discovered = collect();

        if (!File::exists($modulesPath)) {
            File::makeDirectory($modulesPath, 0755, true);
        }

        $directories = File::directories($modulesPath);

        foreach ($directories as $dir) {
            $manifestPath = $dir . '/module.json';

            if (!File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true);

            if (!$manifest) {
                continue;
            }

            $moduleName = strtolower(basename($dir));

            // Znajdź lub utwórz moduł w bazie
            $module = Module::updateOrCreate(
                ['name' => $moduleName],
                [
                    'display_name' => $manifest['display_name'] ?? ucfirst($moduleName),
                    'description' => $manifest['description'] ?? null,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'author' => $manifest['author'] ?? null,
                    'icon' => $manifest['icon'] ?? 'puzzle',
                    'is_core' => $manifest['is_core'] ?? false,
                    'order' => $manifest['order'] ?? 0,
                    'dependencies' => $manifest['dependencies'] ?? [],
                    'permissions' => $manifest['permissions'] ?? [],
                ]
            );

            // Zarejestruj ustawienia modułu
            if (isset($manifest['settings'])) {
                $this->registerModuleSettings($moduleName, $manifest['settings']);
            }

            $discovered->push($module);
        }

        return $discovered;
    }

    /**
     * Zarejestruj ustawienia modułu.
     *
     * KRYTYCZNE: gdy setting już istnieje w bazie, NIE nadpisujemy 'value'
     * (to może być wartość wpisana przez admina — np. klucze API Play Centrali).
     * Aktualizujemy tylko metadane (label, description, type, options) z manifestu.
     */
    protected function registerModuleSettings(string $moduleName, array $settings): void
    {
        $order = 0;

        foreach ($settings as $group => $groupSettings) {
            foreach ($groupSettings as $key => $config) {
                $metadata = [
                    'group' => $group,
                    'type' => $config['type'] ?? 'string',
                    'label' => $config['label'] ?? $key,
                    'description' => $config['description'] ?? null,
                    'options' => $config['options'] ?? null,
                    'is_public' => $config['public'] ?? false,
                    'order' => $order++,
                ];

                $setting = Setting::firstOrCreate(
                    ['module' => $moduleName, 'key' => $key],
                    array_merge($metadata, ['value' => $config['default'] ?? null])
                );

                // Jeśli istniał — zaktualizuj TYLKO metadane, value zostaje (admin wpisał klucze).
                if (!$setting->wasRecentlyCreated) {
                    $setting->update($metadata);
                }
            }
        }
    }

    /**
     * Zainstaluj nowy moduł z pliku ZIP
     */
    public function installFromZip(string $zipPath): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'Nie można otworzyć pliku ZIP'];
        }

        // Znajdź module.json w archiwum
        $manifestIndex = $zip->locateName('module.json', \ZipArchive::FL_NODIR);

        if ($manifestIndex === false) {
            $zip->close();
            return ['success' => false, 'message' => 'Brak pliku module.json w archiwum'];
        }

        $manifestContent = $zip->getFromIndex($manifestIndex);
        $manifest = json_decode($manifestContent, true);

        if (!$manifest || !isset($manifest['name'])) {
            $zip->close();
            return ['success' => false, 'message' => 'Nieprawidłowy plik module.json'];
        }

        $moduleName = strtolower($manifest['name']);
        $modulePath = base_path('modules/' . ucfirst($moduleName));

        // Sprawdź czy moduł już istnieje
        if (File::exists($modulePath)) {
            $zip->close();
            return ['success' => false, 'message' => 'Moduł już istnieje'];
        }

        // Wypakuj moduł
        File::makeDirectory($modulePath, 0755, true);
        $zip->extractTo($modulePath);
        $zip->close();

        // Zarejestruj moduł
        $this->discoverModules();

        $module = Module::where('name', $moduleName)->first();

        if ($module) {
            $module->logAction('installed');
        }

        return [
            'success' => true,
            'message' => 'Moduł został zainstalowany',
            'module' => $module,
        ];
    }

    /**
     * Odinstaluj moduł
     */
    public function uninstall(Module $module): array
    {
        if ($module->is_core) {
            return ['success' => false, 'message' => 'Nie można odinstalować modułu systemowego'];
        }

        if ($module->is_active) {
            return ['success' => false, 'message' => 'Najpierw dezaktywuj moduł'];
        }

        // Sprawdź zależności
        $dependents = Module::active()
            ->get()
            ->filter(fn($m) => in_array($module->name, $m->dependencies ?? []));

        if ($dependents->isNotEmpty()) {
            return [
                'success' => false,
                'message' => 'Inne moduły zależą od tego modułu: ' . $dependents->pluck('display_name')->join(', '),
            ];
        }

        // Usuń pliki modułu
        $modulePath = $module->getPath();

        if (File::exists($modulePath)) {
            File::deleteDirectory($modulePath);
        }

        // Usuń ustawienia modułu
        Setting::where('module', $module->name)->delete();

        // Zaloguj i usuń moduł
        $module->logAction('uninstalled');
        $module->delete();

        return ['success' => true, 'message' => 'Moduł został odinstalowany'];
    }

    /**
     * Aktywuj moduł
     */
    public function activate(Module $module): array
    {
        $missing = $module->checkDependencies();

        if (!empty($missing)) {
            return [
                'success' => false,
                'message' => 'Brakujące zależności: ' . implode(', ', $missing),
            ];
        }

        if ($module->activate()) {
            return ['success' => true, 'message' => 'Moduł został aktywowany'];
        }

        return ['success' => false, 'message' => 'Nie można aktywować modułu'];
    }

    /**
     * Dezaktywuj moduł
     */
    public function deactivate(Module $module): array
    {
        if ($module->is_core) {
            return ['success' => false, 'message' => 'Nie można dezaktywować modułu systemowego'];
        }

        // Sprawdź czy inne moduły nie zależą od tego
        $dependents = Module::active()
            ->where('id', '!=', $module->id)
            ->get()
            ->filter(fn($m) => in_array($module->name, $m->dependencies ?? []));

        if ($dependents->isNotEmpty()) {
            return [
                'success' => false,
                'message' => 'Inne aktywne moduły zależą od tego modułu: ' . $dependents->pluck('display_name')->join(', '),
            ];
        }

        if ($module->deactivate()) {
            return ['success' => true, 'message' => 'Moduł został dezaktywowany'];
        }

        return ['success' => false, 'message' => 'Nie można dezaktywować modułu'];
    }

    /**
     * Pobierz listę dostępnych modułów z marketplace (stub)
     */
    public function getMarketplaceModules(): array
    {
        // W przyszłości można połączyć z zewnętrznym API
        return [
            [
                'name' => 'crm',
                'display_name' => 'CRM',
                'description' => 'Zaawansowane zarządzanie relacjami z klientami',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'users',
                'price' => 'Darmowy',
            ],
            [
                'name' => 'invoices',
                'display_name' => 'Faktury',
                'description' => 'Wystawianie i zarządzanie fakturami',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'document-text',
                'price' => 'Darmowy',
            ],
            [
                'name' => 'reports',
                'display_name' => 'Raporty',
                'description' => 'Zaawansowane raporty i statystyki',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'chart-bar',
                'price' => 'Darmowy',
            ],
        ];
    }

    /**
     * Generuj szablon nowego modułu
     */
    public function generateModule(string $name, array $options = []): array
    {
        $moduleName = ucfirst(strtolower($name));
        $modulePath = base_path('modules/' . $moduleName);

        if (File::exists($modulePath)) {
            return ['success' => false, 'message' => 'Moduł o tej nazwie już istnieje'];
        }

        // Utwórz strukturę katalogów
        $directories = [
            '',
            '/config',
            '/database/migrations',
            '/database/seeders',
            '/src/Controllers',
            '/src/Models',
            '/src/Services',
            '/resources/js/Pages',
            '/resources/js/Components',
            '/routes',
        ];

        foreach ($directories as $dir) {
            File::makeDirectory($modulePath . $dir, 0755, true);
        }

        // Utwórz plik module.json
        $manifest = [
            'name' => strtolower($name),
            'display_name' => $options['display_name'] ?? $moduleName,
            'description' => $options['description'] ?? 'Opis modułu ' . $moduleName,
            'version' => '1.0.0',
            'author' => $options['author'] ?? 'CHICKENKING',
            'icon' => $options['icon'] ?? 'puzzle',
            'is_core' => false,
            'order' => 100,
            'dependencies' => [],
            'permissions' => [
                strtolower($name) . '_view' => 'Podgląd modułu ' . $moduleName,
                strtolower($name) . '_manage' => 'Zarządzanie modułem ' . $moduleName,
            ],
            'settings' => [
                'general' => [
                    'enabled' => [
                        'type' => 'boolean',
                        'label' => 'Włączony',
                        'default' => true,
                        'description' => 'Czy moduł jest włączony',
                    ],
                ],
            ],
            'routes' => [
                'web' => 'routes/web.php',
                'api' => 'routes/api.php',
            ],
            'menu' => [
                [
                    'label' => $moduleName,
                    'route' => strtolower($name) . '.index',
                    'icon' => $options['icon'] ?? 'puzzle',
                    'permission' => strtolower($name) . '_view',
                ],
            ],
        ];

        File::put($modulePath . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Utwórz ServiceProvider
        $this->createServiceProvider($modulePath, $moduleName);

        // Utwórz podstawowy kontroler
        $this->createController($modulePath, $moduleName);

        // Utwórz routes
        $this->createRoutes($modulePath, $moduleName);

        // Utwórz widok Vue
        $this->createVueComponent($modulePath, $moduleName);

        // Zarejestruj moduł w bazie
        $this->discoverModules();

        return [
            'success' => true,
            'message' => 'Moduł został wygenerowany',
            'path' => $modulePath,
        ];
    }

    protected function createServiceProvider(string $path, string $name): void
    {
        $content = <<<PHP
<?php

namespace Modules\\{$name};

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rejestracja serwisów modułu
    }

    public function boot(): void
    {
        // Ładowanie routes
        \$this->loadRoutes();
        
        // Ładowanie widoków
        \$this->loadViewsFrom(__DIR__ . '/resources/views', strtolower('{$name}'));
        
        // Ładowanie migracji
        \$this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    protected function loadRoutes(): void
    {
        Route::middleware('web')
            ->prefix(strtolower('{$name}'))
            ->name(strtolower('{$name}') . '.')
            ->group(__DIR__ . '/routes/web.php');
    }
}
PHP;

        File::put($path . '/' . $name . 'ServiceProvider.php', $content);
    }

    protected function createController(string $path, string $name): void
    {
        $lowerName = strtolower($name);
        $content = <<<PHP
<?php

namespace Modules\\{$name}\\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class {$name}Controller extends Controller
{
    public function index()
    {
        return Inertia::render('{$name}/Index', [
            'title' => '{$name}',
        ]);
    }
}
PHP;

        File::put($path . '/src/Controllers/' . $name . 'Controller.php', $content);
    }

    protected function createRoutes(string $path, string $name): void
    {
        $lowerName = strtolower($name);
        $content = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use Modules\\{$name}\\Controllers\\{$name}Controller;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [{$name}Controller::class, 'index'])->name('index');
});
PHP;

        File::put($path . '/routes/web.php', $content);
        File::put($path . '/routes/api.php', "<?php\n\n// API routes for {$name} module\n");
    }

    protected function createVueComponent(string $path, string $name): void
    {
        $content = <<<VUE
<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    title: String,
});
</script>

<template>
    <Head :title="title" />
    
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ title }}</h1>
        
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600">
                Witaj w module {$name}! Edytuj ten plik, aby dostosować moduł.
            </p>
        </div>
    </div>
</template>
VUE;

        File::put($path . '/resources/js/Pages/Index.vue', $content);
    }
}
