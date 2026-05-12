<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class Module extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'version',
        'author',
        'icon',
        'is_active',
        'is_core',
        'order',
        'dependencies',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_core' => 'boolean',
            'dependencies' => 'array',
            'permissions' => 'array',
        ];
    }

    // ==================== RELACJE ====================

    public function logs(): HasMany
    {
        return $this->hasMany(ModuleLog::class);
    }

    public function settings(): HasMany
    {
        return Setting::where('module', $this->name)->get();
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCore($query)
    {
        return $query->where('is_core', true);
    }

    // ==================== METODY ====================

    /**
     * Pobierz ścieżkę do modułu. Lookup case-insensitive — slug 'dailyreport'
     * pasuje do folderu 'DailyReport' (PSR-4 wymaga CamelCase na Linux).
     */
    public function getPath(): string
    {
        $modulesPath = base_path('modules');
        $target = strtolower($this->name);

        if (File::exists($modulesPath)) {
            foreach (File::directories($modulesPath) as $dir) {
                if (strtolower(basename($dir)) === $target) {
                    return $dir;
                }
            }
        }
        // Fallback do ucfirst gdy folder nie istnieje (np. modul w DB, ale
        // jeszcze nie zainstalowany na disku) — getPath caller dostanie path
        // ktorego existsOnDisk() i tak zwroci false.
        return $modulesPath . '/' . ucfirst($this->name);
    }

    /**
     * Sprawdź czy moduł istnieje na dysku
     */
    public function existsOnDisk(): bool
    {
        return File::exists($this->getPath() . '/module.json');
    }

    /**
     * Pobierz manifest modułu (module.json)
     */
    public function getManifest(): ?array
    {
        $path = $this->getPath() . '/module.json';

        if (!File::exists($path)) {
            return null;
        }

        return json_decode(File::get($path), true);
    }

    /**
     * Zwraca nazwe route'u dla custom config page modulu (np. 'infakt.config').
     * Czytane z manifest['config_route']. null gdy modul nie ma wlasnej strony
     * konfiguracji — wtedy caller powinien uzyc generic admin.modules.show.
     */
    public function getConfigRoute(): ?string
    {
        $manifest = $this->getManifest();
        return $manifest['config_route'] ?? null;
    }

    /**
     * Sprawdź czy wszystkie zależności są spełnione
     */
    public function checkDependencies(): array
    {
        $missing = [];
        
        if (!$this->dependencies) {
            return $missing;
        }

        foreach ($this->dependencies as $dep) {
            $depModule = self::where('name', $dep)->first();
            
            if (!$depModule || !$depModule->is_active) {
                $missing[] = $dep;
            }
        }

        return $missing;
    }

    /**
     * Aktywuj moduł
     */
    public function activate(): bool
    {
        $missing = $this->checkDependencies();
        
        if (!empty($missing)) {
            return false;
        }

        $this->is_active = true;
        $this->save();

        // Uruchom migracje modułu
        $this->runMigrations();

        // Zaloguj akcję
        $this->logAction('activated');

        return true;
    }

    /**
     * Dezaktywuj moduł
     */
    public function deactivate(): bool
    {
        // Sprawdź czy inne moduły nie zależą od tego
        $dependents = self::active()
            ->get()
            ->filter(fn($m) => in_array($this->name, $m->dependencies ?? []));

        if ($dependents->isNotEmpty()) {
            return false;
        }

        $this->is_active = false;
        $this->save();

        $this->logAction('deactivated');

        return true;
    }

    /**
     * Uruchom migracje modułu
     */
    public function runMigrations(): void
    {
        $migrationsPath = $this->getPath() . '/database/migrations';
        
        if (File::exists($migrationsPath)) {
            Artisan::call('migrate', [
                '--path' => 'modules/' . ucfirst($this->name) . '/database/migrations',
                '--force' => true,
            ]);
        }
    }

    /**
     * Zaloguj akcję
     */
    public function logAction(string $action, array $details = []): void
    {
        ModuleLog::create([
            'module_id' => $this->id,
            'action' => $action,
            'version' => $this->version,
            'user_id' => auth()->id(),
            'details' => $details,
        ]);
    }

    /**
     * Pobierz konfigurację modułu
     */
    public function getConfig(): array
    {
        return Setting::where('module', $this->name)
            ->orderBy('group')
            ->orderBy('order')
            ->get()
            ->groupBy('group')
            ->toArray();
    }

    /**
     * Zapisz konfigurację modułu
     */
    public function saveConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            Setting::updateOrCreate(
                ['module' => $this->name, 'key' => $key],
                ['value' => is_array($value) ? json_encode($value) : $value]
            );
        }
    }
}
