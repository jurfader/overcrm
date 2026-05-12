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

        // Filtruj zombie — Module rekordy bez folderu (po rm -rf modules/X
        // albo uninstall). is_core zwolnione (core/clients/users nie maja
        // folderu w modules/). Settings tych zombie zostaja — admin moze
        // reinstall przez marketplace i odzyskac konfiguracje.
        $installed = Module::orderBy('order')->orderBy('display_name')->get()
            ->filter(fn (Module $m) => $m->is_core || $m->existsOnDisk())
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
            'installed' => $installed,
            'remote'    => $remote,
        ];
    }

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
            }

            return $result;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            Log::warning('Marketplace install exception', ['plugin_id' => $pluginId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
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
                    // Po update'cie modul moze byc nieaktywny (installFromZip
                    // ustawia is_active=false default). Przywrocenie stanu.
                    if ($wasActive && !$fresh->is_active) {
                        $fresh->update(['is_active' => true]);
                    }
                }
                $result['message'] = "Zaktualizowano {$oldVersion} → " . ($fresh->version ?? '?');
            }

            return $result;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            Log::warning('Marketplace update exception', ['plugin_id' => $pluginId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
