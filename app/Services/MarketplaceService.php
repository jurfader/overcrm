<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Marketplace = agregator 3 zrodel modulow:
 *  1. installed   — z tabeli modules (DB), z metadanymi z manifestow
 *  2. localAvailable — moduly w katalogu modules/ ktorych jeszcze nie ma w DB
 *                     (typowo: ZIP wypakowany recznie, albo discover jeszcze nie odpalil)
 *  3. remote      — z license servera (/plugins?product=overcrm), te nie sa
 *                   pobrane lokalnie; klikajac "Zainstaluj" pobieramy ZIP i instalujemy
 *
 * Instalacja remote modulu:
 *  1. POST /plugins/{id}/download → signed downloadUrl
 *  2. fetch ZIP do tmp
 *  3. ModuleService::installFromZip()
 *  4. Module::find($name)->update(['is_active' => true])
 */
class MarketplaceService
{
    public function __construct(
        protected LicenseService $license,
        protected ModuleService $moduleService,
    ) {}

    /**
     * Zwraca pelen stan marketplace (3 sekcje) dla UI.
     */
    public function snapshot(): array
    {
        // Auto-discover — wypelnia DB z katalogu modules/ jezeli czegos brakuje
        $this->moduleService->discoverModules();

        $remoteList = collect($this->license->listMarketplacePlugins());
        // slug => remote version (do wykrycia update'ow)
        $remoteVersionBySlug = $remoteList->mapWithKeys(fn ($p) => [
            strtolower($p['id'] ?? '') => $p['version'] ?? null,
        ])->all();

        // Pokazujemy WYŁĄCZNIE moduły mające folder na dysku.
        //
        // Odpada przez to dwie grupy rekordów, których i tak nie da się obsłużyć
        // z tego ekranu: zombie po `rm -rf modules/X` (od tego jest przycisk
        // „Wyczyść stale") oraz pseudo-moduły rdzenia (core, users, clients,
        // planner, calendar) — wpisy bez folderu, bez manifestu i bez menu,
        // istniejące tylko na potrzeby uprawnień. Wcześniej te drugie przechodziły
        // przez `is_core` i wyświetlały się jako pozycje marketplace, których
        // administrator nie mógł ani włączyć, ani skonfigurować.
        //
        // Ustawienia odfiltrowanych rekordów zostają w bazie — po ponownej
        // instalacji modułu konfiguracja wraca.
        $installed = Module::orderBy('order')->orderBy('display_name')->get()
            ->filter(fn (Module $m) => $m->existsOnDisk())
            ->values()
            ->map(function (Module $m) use ($remoteVersionBySlug) {
                $remoteVersion = $remoteVersionBySlug[$m->name] ?? null;
                $updateAvailable = $remoteVersion
                    && version_compare($remoteVersion, $m->version ?? '0.0.0', '>');

                return [
                    'id'             => $m->id,
                    'name'           => $m->name,
                    'display_name'   => $m->display_name,
                    'description'    => $m->description,
                    'version'        => $m->version,
                    'author'         => $m->author,
                    'icon'           => $m->icon,
                    'is_active'      => $m->is_active,
                    'is_core'        => $m->is_core,
                    'dependencies'   => $m->dependencies,
                    'exists_on_disk' => $m->existsOnDisk(),
                    'config_route'   => $m->getConfigRoute(),
                    // Opis zdolności (module.json v2) — UI grupuje po kategorii
                    // i pokazuje, czego moduł potrzebuje, zanim admin kliknie
                    // „Włącz" i dostanie odmowę.
                    'category'       => $m->category,
                    'category_label' => self::CATEGORY_LABELS[$m->category ?? ''] ?? null,
                    'vendor'         => $m->vendor,
                    'provides'       => $m->provides ?: [],
                    'requires'       => $m->requires ?: [],
                    'bundle'         => $m->bundle,
                    'bundle_label'   => self::BUNDLE_LABELS[$m->bundle ?? ''] ?? null,
                    'bundle_owned'   => $this->license->hasBundle($m->bundle),
                    'requirements'   => $m->checkRequirements(),
                    // Moduły, których włączenie odblokowałoby ten — pusta lista
                    // znaczy „nie da się załatwić lokalnie", więc UI nie pokazuje
                    // przycisku, który i tak skończyłby się odmową.
                    'resolvable'     => $m->is_active
                        ? []
                        : $m->resolvableRequirements()->pluck('display_name')->all(),
                    // Wykryta nowsza wersja w marketplace — UI pokazuje
                    // "Aktualizuj do vX.Y.Z" button.
                    'remote_version'    => $remoteVersion,
                    'update_available'  => $updateAvailable,
                ];
            })->all();

        $remote = $remoteList->map(function ($plugin) use ($installed) {
            $slug = strtolower($plugin['id'] ?? '');
            $isInstalled = !empty(array_filter($installed, fn ($m) => $m['name'] === $slug));
            return [
                'id'           => $plugin['id'] ?? null,
                'name'         => $slug,
                'display_name' => $plugin['name'] ?? $slug,
                'description'  => $plugin['description'] ?? null,
                'version'      => $plugin['version'] ?? null,
                'author'       => $plugin['author'] ?? 'OVERMEDIA',
                'icon'         => $plugin['icon'] ?? 'puzzle',
                'price'        => $plugin['price'] ?? 0,
                'currency'     => $plugin['currency'] ?? 'PLN',
                'required_plan' => $plugin['requiredPlan'] ?? null,
                'downloads'    => $plugin['downloads'] ?? 0,
                'installed'    => $isInstalled,
            ];
        })->values()->all();

        return [
            'installed'  => $installed,
            'remote'     => $remote,
            'categories' => $this->categoriesOf($installed),
        ];
    }

    /**
     * Kategorie występujące wśród zainstalowanych modułów, w ustalonej kolejności.
     * UI renderuje z tego nagłówki sekcji zamiast jednej płaskiej listy —
     * przy kilkunastu modułach i kilku dostawcach tej samej rzeczy płaska lista
     * przestaje być czytelna.
     *
     * @param array<int, array<string, mixed>> $installed
     * @return array<int, array{key: string, label: string, count: int}>
     */
    protected function categoriesOf(array $installed): array
    {
        $counts = [];

        foreach ($installed as $module) {
            $key = $module['category'] ?: 'other';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $out = [];

        // 'other' jest ostatnim wpisem w CATEGORY_LABELS, więc pętla załatwia
        // też moduły bez kategorii — nie trzeba ich doklejać osobno.
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            if (!empty($counts[$key])) {
                $out[] = ['key' => $key, 'label' => self::CATEGORY_LABELS[$key], 'count' => $counts[$key]];
            }
        }

        return $out;
    }

    /**
     * Nazwy kategorii w kolejności wyświetlania. Kolejność jest celowa:
     * najpierw to, czego klient szuka najczęściej.
     */
    /**
     * Pakiety licencyjne. Uprawnienia nadaje serwer licencji (pole `bundles`
     * w podpisanej odpowiedzi) — tutaj trzymamy tylko nazwy do pokazania.
     */
    public const BUNDLE_LABELS = [
        'overcrm-core'      => 'W licencji podstawowej',
        'overcrm-ai'        => 'Pakiet AI',
        'overcrm-telefonia' => 'Pakiet Telefonia',
        'overcrm-analityka' => 'Pakiet Analityka',
        'overcrm-sprzedaz'  => 'Pakiet Sprzedaż',
        'overcrm-pliki'     => 'Pakiet Pliki',
        'overcrm-wdrozenie' => 'Pakiet Wdrożenie',
    ];

    public const CATEGORY_LABELS = [
        'invoice'       => 'Fakturowanie',
        'order'         => 'Zamówienia',
        'telephony'     => 'Telefonia',
        'shipping'      => 'Wysyłka',
        'communication' => 'Komunikacja',
        'sales'         => 'Sprzedaż',
        'analytics'     => 'Analityka',
        'tasks'         => 'Zadania',
        'ai'            => 'Sztuczna inteligencja',
        'storage'       => 'Pliki',
        'other'         => 'Pozostałe',
    ];

    /**
     * Pobiera moduł z license servera i instaluje przez ModuleService.
     */
    public function installFromMarketplace(string $pluginId): array
    {
        $download = $this->license->downloadPlugin($pluginId);
        if (!($download['success'] ?? false)) {
            return $download; // przekazuje message + code
        }

        $url = $download['download_url'];
        $tmpPath = tempnam(sys_get_temp_dir(), 'plugin-') . '.zip';

        try {
            $response = Http::timeout(60)->get($url);
            if (!$response->successful()) {
                @unlink($tmpPath);
                return ['success' => false, 'message' => "Nie udalo sie pobrac modulu (HTTP {$response->status()})"];
            }
            File::put($tmpPath, $response->body());

            $result = $this->moduleService->installFromZip($tmpPath);
            @unlink($tmpPath);

            // Po instalacji moduł jest w katalogu + DB. Domyślnie is_active=false —
            // aktywujemy automatycznie zeby user mogl od razu uzywac.
            if ($result['success'] ?? false) {
                $module = $result['module'] ?? null;
                if ($module && !$module->is_core) {
                    $this->moduleService->activate($module);
                }
                $this->license->forgetMarketplaceCache();
                $this->clearLaravelCaches();
            }

            return $result;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            Log::warning('Marketplace install exception', ['plugin_id' => $pluginId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Czysci route + view cache po install/update modulu. Bez tego nowe route'y
     * z routes/web.php modulu nie sa widoczne (cached file ma stara liste),
     * a Inertia czasem keszuje stare manifesty menu.
     */
    protected function clearLaravelCaches(): void
    {
        try {
            \Artisan::call('route:clear');
            \Artisan::call('view:clear');
        } catch (\Throwable $e) {
            Log::warning('Cache clear after marketplace op failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Aktualizuje zainstalowany modul do najnowszej wersji z marketplace.
     * Pobiera ZIP, usuwa stare pliki, wypakowuje nowe, uruchamia migracje.
     * Settings + is_active zachowane (przez DB row Module).
     */
    public function updateFromMarketplace(string $pluginId): array
    {
        $slug = strtolower($pluginId);
        $module = Module::where('name', $slug)->first();
        if (!$module) {
            return ['success' => false, 'message' => 'Modul nie jest zainstalowany'];
        }

        $download = $this->license->downloadPlugin($pluginId);
        if (!($download['success'] ?? false)) {
            return $download;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'plugin-') . '.zip';
        try {
            $response = Http::timeout(60)->get($download['download_url']);
            if (!$response->successful()) {
                @unlink($tmpPath);
                return ['success' => false, 'message' => "Nie udalo sie pobrac (HTTP {$response->status()})"];
            }
            File::put($tmpPath, $response->body());

            // Zachowaj sciezke przed usunieciem (Module::getPath skanuje filesystem)
            $oldPath = $module->getPath();
            $wasActive = $module->is_active;
            $oldVersion = $module->version;

            // Usun stare pliki — Setting + Module row zostaja (settings przetrwa
            // przez registerModuleSettings, value nie nadpisywane gdy istnieje).
            if (File::exists($oldPath)) {
                File::deleteDirectory($oldPath);
            }

            $result = $this->moduleService->installFromZip($tmpPath);
            @unlink($tmpPath);

            if ($result['success'] ?? false) {
                $fresh = Module::where('name', $slug)->first();
                if ($fresh) {
                    $fresh->logAction('updated', [
                        'from_version' => $oldVersion,
                        'to_version'   => $fresh->version,
                    ]);
                    if ($wasActive && !$fresh->is_active) {
                        $fresh->update(['is_active' => true]);
                    }
                }
                $result['message'] = "Zaktualizowano {$oldVersion} → " . ($fresh->version ?? '?');
                $this->license->forgetMarketplaceCache();
                $this->clearLaravelCaches();
            }

            return $result;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            Log::warning('Marketplace update exception', ['plugin_id' => $pluginId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
