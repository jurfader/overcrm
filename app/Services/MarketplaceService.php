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

        $installed = Module::orderBy('order')->orderBy('display_name')->get()->map(function (Module $m) {
            return [
                'id'           => $m->id,
                'name'         => $m->name,
                'display_name' => $m->display_name,
                'description'  => $m->description,
                'version'      => $m->version,
                'author'       => $m->author,
                'icon'         => $m->icon,
                'is_active'    => $m->is_active,
                'is_core'      => $m->is_core,
                'dependencies' => $m->dependencies,
                'exists_on_disk' => $m->existsOnDisk(),
            ];
        })->all();

        $remote = collect($this->license->listMarketplacePlugins())->map(function ($plugin) use ($installed) {
            $name = strtolower($plugin['name'] ?? '');
            $isInstalled = !empty(array_filter($installed, fn ($m) => $m['name'] === $name));
            return [
                'id'           => $plugin['id'] ?? null,
                'name'         => $name,
                'display_name' => $plugin['name'] ?? $name,
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
}
