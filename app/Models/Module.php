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
        'category',
        'vendor',
        'is_active',
        'is_core',
        'order',
        'dependencies',
        'provides',
        'requires',
        'conflicts',
        'bundle',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_core' => 'boolean',
            'dependencies' => 'array',
            'provides' => 'array',
            'requires' => 'array',
            'conflicts' => 'array',
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
     * Pełne sprawdzenie warunków uruchomienia modułu — zdolności, moduły, konflikty.
     *
     * Wpisy w `requires` mają postać:
     *   "capability:telephony" — spełnione przez DOWOLNY moduł wnoszący telefonię
     *   "module:leads"         — spełnione tylko przez ten konkretny moduł
     *   "leads"                — skrót równoważny "module:leads" (zgodność z `dependencies`)
     *
     * Dzięki wariantowi z capability moduł analizy rozmów nie musi znać nazwy
     * centrali. To jest cała różnica między integracją a uniwersalnością.
     *
     * @return array{missing: array<int, string>, conflicts: array<int, string>}
     */
    public function checkRequirements(): array
    {
        $missing = [];
        $conflicts = [];

        // Zależności po nazwie modułu (stare pole) trafiają do tej samej listy.
        foreach ($this->checkDependencies() as $dep) {
            $missing[] = $this->describeRequirement('module:'.$dep);
        }

        foreach ((array) $this->requires as $req) {
            if (!is_string($req) || $req === '') {
                continue;
            }

            if (!$this->isRequirementMet($req)) {
                $missing[] = $this->describeRequirement($req);
            }
        }

        foreach ((array) $this->conflicts as $conflict) {
            if (!is_string($conflict) || $conflict === '') {
                continue;
            }

            foreach ($this->conflictingModules($conflict) as $other) {
                $conflicts[] = $other->display_name;
            }
        }

        return [
            'missing' => array_values(array_unique($missing)),
            'conflicts' => array_values(array_unique($conflicts)),
        ];
    }

    /** Czy pojedynczy warunek jest spełniony. */
    protected function isRequirementMet(string $requirement): bool
    {
        [$type, $value] = $this->parseRequirement($requirement);

        if ($type === 'capability') {
            // Dwa źródła prawdy, bo aktywowany moduł nie jest jeszcze zbootowany
            // i nie zdążył zarejestrować swojego providera w rejestrze.
            if (app(\App\Support\Providers\ProviderRegistry::class)->has($value)) {
                return true;
            }

            return self::active()
                ->get()
                ->contains(fn (self $m) => in_array($value, (array) $m->provides, true));
        }

        $module = self::where('name', $value)->first();

        return $module !== null && $module->is_active;
    }

    /**
     * Aktywne moduły kolidujące z danym wpisem `conflicts`.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    protected function conflictingModules(string $conflict): \Illuminate\Support\Collection
    {
        [$type, $value] = $this->parseRequirement($conflict);

        return self::active()
            ->where('id', '!=', $this->id ?? 0)
            ->get()
            ->filter(function (self $other) use ($type, $value) {
                return $type === 'capability'
                    ? in_array($value, (array) $other->provides, true)
                    : $other->name === $value;
            })
            ->values();
    }

    /** @return array{0: string, 1: string} [typ, wartość] */
    protected function parseRequirement(string $requirement): array
    {
        if (str_contains($requirement, ':')) {
            [$type, $value] = explode(':', $requirement, 2);

            return [$type === 'capability' ? 'capability' : 'module', $value];
        }

        return ['module', $requirement];
    }

    /**
     * Zainstalowane, ale wyłączone moduły, których włączenie spełniłoby
     * niespełnione warunki tego modułu.
     *
     * Zwraca pustą kolekcję, gdy któregoś warunku nie da się zaspokoić lokalnie
     * (brakującego modułu nie ma na dysku) — wtedy nie ma czego proponować
     * i administrator musi go najpierw pobrać ze sklepu.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function resolvableRequirements(): \Illuminate\Support\Collection
    {
        $kandydaci = collect();

        // Bez filtra na obecność plików — `activate()` też jej nie wymaga, a marketplace
        // i tak pokazuje wyłącznie moduły leżące na dysku, więc UI nie zaproponuje
        // włączenia czegoś, czego nie ma. Wcześniejszy filtr powodował, że metoda
        // odrzucała moduły, które sama aktywacja przyjęłaby bez problemu.
        $nieaktywne = self::where('is_active', false)
            ->where('id', '!=', $this->id ?? 0)
            ->get();

        $warunki = array_merge(
            array_map(fn ($d) => 'module:'.$d, (array) $this->dependencies),
            array_values(array_filter((array) $this->requires, 'is_string'))
        );

        foreach ($warunki as $warunek) {
            if ($this->isRequirementMet($warunek)) {
                continue;
            }

            [$typ, $wartosc] = $this->parseRequirement($warunek);

            $dostawca = $typ === 'capability'
                ? $nieaktywne->first(fn (self $m) => in_array($wartosc, (array) $m->provides, true))
                : $nieaktywne->firstWhere('name', $wartosc);

            // Któregoś warunku nie da się spełnić lokalnie — kaskada nie ma sensu,
            // bo i tak skończyłaby się odmową na ostatnim kroku.
            if (!$dostawca) {
                return collect();
            }

            $kandydaci->push($dostawca);
        }

        return $kandydaci->unique('id')->values();
    }

    /** Opis warunku dla administratora — bez żargonu z manifestu. */
    protected function describeRequirement(string $requirement): string
    {
        [$type, $value] = $this->parseRequirement($requirement);

        if ($type === 'capability') {
            return self::CAPABILITY_LABELS[$value] ?? $value;
        }

        return self::where('name', $value)->value('display_name') ?? $value;
    }

    /**
     * Aktywne moduły, które przestaną działać po wyłączeniu tego modułu —
     * bo zależą od niego po nazwie ALBO od zdolności, którą tylko on wnosi.
     *
     * Drugi przypadek jest ważniejszy: wyłączenie jedynej centrali telefonicznej
     * musi zablokować się tak samo, jak wyłączenie modułu wskazanego wprost,
     * inaczej moduł analizy rozmów zostaje bez źródła danych.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function dependents(): \Illuminate\Support\Collection
    {
        $others = self::active()->where('id', '!=', $this->id ?? 0)->get();

        // Zdolności, których po wyłączeniu tego modułu nikt inny nie dostarczy.
        $exclusive = array_values(array_filter(
            (array) $this->provides,
            fn ($cap) => !$others->contains(fn (self $m) => in_array($cap, (array) $m->provides, true))
        ));

        return $others->filter(function (self $other) use ($exclusive) {
            if (in_array($this->name, (array) $other->dependencies, true)) {
                return true;
            }

            foreach ((array) $other->requires as $req) {
                if (!is_string($req)) {
                    continue;
                }

                if ($req === 'module:'.$this->name || $req === $this->name) {
                    return true;
                }

                if (str_starts_with($req, 'capability:')
                    && in_array(substr($req, strlen('capability:')), $exclusive, true)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** Nazwy zdolności po polsku — używane w komunikatach dla admina. */
    public const CAPABILITY_LABELS = [
        'telephony'    => 'moduł telefonii',
        'ai'           => 'moduł AI',
        'ai_audio'     => 'moduł AI z transkrypcją audio',
        'invoice'      => 'moduł fakturowania',
        'order'        => 'moduł zamówień',
        'product'      => 'moduł produktów',
        'storage'      => 'moduł plików',
        'notification' => 'kanał powiadomień',
        'shipping'     => 'moduł wysyłki',
    ];

    /**
     * Aktywuj moduł
     */
    public function activate(): bool
    {
        $problems = $this->checkRequirements();

        if (!empty($problems['missing']) || !empty($problems['conflicts'])) {
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
        if ($this->dependents()->isNotEmpty()) {
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
