<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Support\Brand;
use Database\Seeders\ClientSeeder;
use Database\Seeders\ClientVisitSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Kreator pierwszego uruchomienia (/setup).
 *
 * Świeża instalacja z install.sh ma tylko migracje — bez statusów zadań,
 * uprawnień i rekordów modułów core (seedery NIE są uruchamiane na produkcji,
 * bo zawierają dane przykładowe). Kreator uzupełnia ten baseline i zbiera
 * konfigurację firmy, brandingu, preferencji oraz modułów.
 *
 * Stan trzymany w Settings:
 *   - setup_completed    '1' gdy kreator zakończony (czyta EnsureSetupComplete)
 *   - setup_completed_at ISO8601 daty zakończenia
 *   - setup_progress     JSON { krok => 'done'|'skipped' }
 */
class SetupService
{
    /**
     * Kroki kreatora. 'optional' => można pominąć przyciskiem "Pomiń".
     * Kolejność ma znaczenie — frontend renderuje po niej stepper.
     */
    public const STEPS = [
        ['key' => 'license',     'title' => 'Licencja',      'description' => 'Aktywacja klucza OVERMEDIA',                'icon' => 'lock',            'optional' => false],
        ['key' => 'company',     'title' => 'Dane firmy',    'description' => 'NIP, adres i dane kontaktowe',              'icon' => 'building-office', 'optional' => true],
        ['key' => 'branding',    'title' => 'Wygląd',        'description' => 'Logo, kolory i motyw',                      'icon' => 'sparkles',        'optional' => true],
        ['key' => 'baseline',    'title' => 'Dane startowe', 'description' => 'Statusy zadań, uprawnienia, moduły core',   'icon' => 'statuses',        'optional' => false],
        ['key' => 'preferences', 'title' => 'Preferencje',   'description' => 'Ustawienia regionalne i poczta',            'icon' => 'settings',        'optional' => true],
        ['key' => 'modules',     'title' => 'Moduły',        'description' => 'Rozszerzenia z marketplace',                'icon' => 'puzzle',          'optional' => true],
    ];

    /**
     * Presety statusów zadań. Klient wybiera jeden w kroku "Dane startowe" —
     * później edytowalne w Ustawienia → Statusy.
     */
    public const STATUS_PRESETS = [
        'sales' => [
            'label' => 'Sprzedaż B2B',
            'description' => 'Klasyczny obieg zadań handlowych',
            'statuses' => [
                ['name' => 'Nowe',        'slug' => 'new',         'type' => 'new',         'color' => '#3B82F6', 'is_default' => true,  'is_final' => false],
                ['name' => 'W trakcie',   'slug' => 'in_progress', 'type' => 'in_progress', 'color' => '#F59E0B', 'is_default' => false, 'is_final' => false],
                ['name' => 'Oczekujące',  'slug' => 'pending',     'type' => 'in_progress', 'color' => '#8B5CF6', 'is_default' => false, 'is_final' => false],
                ['name' => 'Wykonane',    'slug' => 'done',        'type' => 'done',        'color' => '#10B981', 'is_default' => false, 'is_final' => true],
                ['name' => 'Anulowane',   'slug' => 'cancelled',   'type' => 'cancelled',   'color' => '#6B7280', 'is_default' => false, 'is_final' => true],
            ],
        ],
        'service' => [
            'label' => 'Serwis / wsparcie',
            'description' => 'Obsługa zgłoszeń od klientów',
            'statuses' => [
                ['name' => 'Zgłoszenie',          'slug' => 'new',            'type' => 'new',         'color' => '#3B82F6', 'is_default' => true,  'is_final' => false],
                ['name' => 'W realizacji',        'slug' => 'in_progress',    'type' => 'in_progress', 'color' => '#F59E0B', 'is_default' => false, 'is_final' => false],
                ['name' => 'Czeka na klienta',    'slug' => 'waiting_client', 'type' => 'in_progress', 'color' => '#8B5CF6', 'is_default' => false, 'is_final' => false],
                ['name' => 'Rozwiązane',          'slug' => 'done',           'type' => 'done',        'color' => '#10B981', 'is_default' => false, 'is_final' => true],
                ['name' => 'Odrzucone',           'slug' => 'cancelled',      'type' => 'cancelled',   'color' => '#6B7280', 'is_default' => false, 'is_final' => true],
            ],
        ],
        'minimal' => [
            'label' => 'Minimalny',
            'description' => 'Trzy statusy — do rozbudowy później',
            'statuses' => [
                ['name' => 'Do zrobienia', 'slug' => 'new',         'type' => 'new',         'color' => '#3B82F6', 'is_default' => true,  'is_final' => false],
                ['name' => 'W trakcie',    'slug' => 'in_progress', 'type' => 'in_progress', 'color' => '#F59E0B', 'is_default' => false, 'is_final' => false],
                ['name' => 'Zrobione',     'slug' => 'done',        'type' => 'done',        'color' => '#10B981', 'is_default' => false, 'is_final' => true],
            ],
        ],
    ];

    /**
     * Kanoniczna lista uprawnień core. Źródło prawdy także dla PermissionSeeder
     * (demo/dev) — zmiana w jednym miejscu wystarczy.
     */
    public const PERMISSIONS = [
        ['name' => 'Podgląd zadań',              'code' => 'tasks_view',      'module' => 'tasks'],
        ['name' => 'Zarządzanie zadaniami',      'code' => 'tasks_manage',    'module' => 'tasks'],
        ['name' => 'Podgląd klientów',           'code' => 'clients_view',    'module' => 'clients'],
        ['name' => 'Zarządzanie klientami',      'code' => 'clients_manage',  'module' => 'clients'],
        ['name' => 'Podgląd użytkowników',       'code' => 'users_view',      'module' => 'users'],
        ['name' => 'Zarządzanie użytkownikami',  'code' => 'users_manage',    'module' => 'users'],
        ['name' => 'Podgląd statusów',           'code' => 'statuses_view',   'module' => 'statuses'],
        ['name' => 'Zarządzanie statusami',      'code' => 'statuses_manage', 'module' => 'statuses'],
        ['name' => 'Zarządzanie ustawieniami',   'code' => 'settings_manage', 'module' => 'settings'],
        ['name' => 'Podgląd raportów',           'code' => 'reports_view',    'module' => 'reports'],
    ];

    /** Moduły systemowe widoczne w /admin/marketplace jako "wbudowane". */
    public const CORE_MODULES = [
        ['name' => 'core',     'display_name' => 'System',       'description' => 'Podstawowy moduł systemowy',                  'icon' => 'settings', 'is_core' => true,  'order' => 1],
        ['name' => 'users',    'display_name' => 'Użytkownicy',  'description' => 'Zarządzanie użytkownikami i uprawnieniami',   'icon' => 'users',    'is_core' => true,  'order' => 2],
        ['name' => 'clients',  'display_name' => 'Klienci',      'description' => 'Baza klientów i kontrahentów',                'icon' => 'clients',  'is_core' => true,  'order' => 3],
        ['name' => 'planner',  'display_name' => 'Planner',      'description' => 'Zadania i planowanie pracy',                  'icon' => 'tasks',    'is_core' => true,  'order' => 4],
        ['name' => 'calendar', 'display_name' => 'Kalendarz',    'description' => 'Kalendarz wizyt klientów',                    'icon' => 'calendar', 'is_core' => false, 'order' => 5],
    ];

    /** Klucze ustawień zbierane w kroku "Dane firmy". */
    public const COMPANY_KEYS = [
        'company_name', 'company_nip', 'company_regon', 'company_address',
        'company_city', 'company_postal', 'company_phone', 'company_email',
        'company_bank_account',
    ];

    /** Klucze ustawień zbierane w kroku "Preferencje". */
    public const PREFERENCE_KEYS = [
        'app_timezone', 'default_calendar_view', 'items_per_page', 'week_starts_monday',
        'mail_from_address', 'mail_from_name', 'mail_signature',
    ];

    public function __construct(
        protected LicenseService $license,
        protected ModuleService $modules,
    ) {}

    // ===================================================================
    // Stan kreatora
    // ===================================================================

    public function isComplete(): bool
    {
        return (bool) Setting::get('setup_completed', false);
    }

    /** @return array<string, string>  krok => 'done'|'skipped' */
    public function progress(): array
    {
        $raw = Setting::get('setup_progress', null);

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function markStep(string $key, string $status = 'done'): void
    {
        $progress = $this->progress();
        $progress[$key] = $status;

        Setting::set('setup_progress', $progress);
    }

    /**
     * Pełny stan dla frontendu kreatora. Marketplace opakowany w try/catch —
     * brak połączenia z license serverem nie może wywalić całego kreatora.
     */
    public function state(): array
    {
        $progress = $this->progress();

        return [
            'steps' => array_map(
                fn (array $step) => $step + ['status' => $progress[$step['key']] ?? null],
                self::STEPS,
            ),
            'completed' => $this->isComplete(),
            'license' => $this->license->status(),
            'domain' => parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url'),
            'company' => $this->settingsSubset(self::COMPANY_KEYS),
            'gusAvailable' => $this->gusAvailable(),
            'brand' => Brand::all(),
            'brandDefaults' => [
                'primary_color' => config('brand.primary_color'),
                'secondary_color' => config('brand.secondary_color'),
            ],
            'baseline' => $this->baselineState(),
            'preferences' => $this->settingsSubset(self::PREFERENCE_KEYS),
            // marketplace celowo NIE tutaj — to zapytanie HTTP do license servera,
            // ładowane osobno (Inertia::optional) w kroku "Moduły".
            'installedModules' => $this->installedModuleNames(),
        ];
    }

    protected function settingsSubset(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = Setting::get($key, null);
        }

        return $out;
    }

    protected function gusAvailable(): bool
    {
        try {
            return app(GusService::class)->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Liczniki danych startowych + presety do wyboru. */
    public function baselineState(): array
    {
        return [
            'presets' => array_map(
                fn (string $key, array $preset) => [
                    'key' => $key,
                    'label' => $preset['label'],
                    'description' => $preset['description'],
                    'statuses' => array_map(
                        fn (array $s) => ['name' => $s['name'], 'color' => $s['color']],
                        $preset['statuses'],
                    ),
                ],
                array_keys(self::STATUS_PRESETS),
                self::STATUS_PRESETS,
            ),
            'counts' => [
                'statuses' => $this->safeCount('statuses'),
                'permissions' => $this->safeCount('permissions'),
                'modules' => $this->safeCount('modules'),
                'clients' => $this->safeCount('clients'),
                'tasks' => $this->safeCount('tasks'),
                'users' => $this->safeCount('users'),
            ],
        ];
    }

    protected function safeCount(string $table): int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Nazwy zainstalowanych modułów niesystemowych — do podsumowania kreatora. */
    protected function installedModuleNames(): array
    {
        try {
            return Module::where('is_core', false)
                ->orderBy('display_name')
                ->pluck('display_name')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Pełny stan marketplace (HTTP do license servera) — ładowany leniwie. */
    public function marketplaceState(): array
    {
        try {
            return app(MarketplaceService::class)->snapshot();
        } catch (\Throwable $e) {
            Log::warning('Setup: marketplace snapshot failed', ['error' => $e->getMessage()]);

            return ['installed' => [], 'remote' => [], 'error' => 'Nie udało się pobrać listy modułów z serwera OVERMEDIA.'];
        }
    }

    // ===================================================================
    // Zapis poszczególnych kroków
    // ===================================================================

    public function saveCompany(array $data): void
    {
        foreach (self::COMPANY_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            Setting::set($key, $data[$key], 'core');
        }

        // Nazwa firmy jest też częścią brandu (stopki maili, PDF-y, tytuł).
        if (! empty($data['company_name'])) {
            app(BrandingService::class)->update(['company_name' => $data['company_name']]);
        }

        Cache::flush();
        $this->markStep('company');
    }

    public function savePreferences(array $data): void
    {
        foreach (self::PREFERENCE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Setting::set($key, $value, 'core');
        }

        Cache::flush();
        $this->markStep('preferences');
    }

    /**
     * Uzupełnia dane, bez których świeża instalacja jest bezużyteczna:
     * statusy zadań (Kanban/Planner), wiersze uprawnień (nadawanie w profilu
     * użytkownika) i rekordy modułów core. W pełni idempotentne — ponowne
     * uruchomienie kreatora nie duplikuje wierszy.
     *
     * @return array<string, int> liczba utworzonych rekordów per kategoria
     */
    public function applyBaseline(string $preset = 'sales', bool $withSampleData = false): array
    {
        $created = [
            'statuses' => $this->seedStatuses($preset),
            'permissions' => $this->seedPermissions(),
            'modules' => $this->seedCoreModules(),
            'sample' => 0,
        ];

        // Wykryj moduły leżące w modules/ (np. wgrane ręcznie przed setupem)
        try {
            $this->modules->discoverModules();
        } catch (\Throwable $e) {
            Log::warning('Setup: discoverModules failed', ['error' => $e->getMessage()]);
        }

        if ($withSampleData) {
            $created['sample'] = $this->seedSampleData();
        }

        Cache::flush();
        $this->markStep('baseline');

        return $created;
    }

    /**
     * Statusy z wybranego presetu. Gdy jakiekolwiek statusy już istnieją,
     * nie ruszamy ich (klient mógł je edytować) — dokładamy tylko brakujące slugi.
     */
    public function seedStatuses(string $preset = 'sales'): int
    {
        $definition = self::STATUS_PRESETS[$preset] ?? self::STATUS_PRESETS['sales'];
        // Pytamy WYŁĄCZNIE o kontekst kalendarza. Statusy zadań istnieją od
        // migracji na każdej instalacji, więc sprawdzanie całej tabeli zawsze
        // dawałoby „coś już jest" i preset nigdy nie ustawiłby statusu domyślnego
        // dla spotkań — nowe wizyty zostawałyby bez statusu.
        $hasAny = Status::context(Status::CONTEXT_CALENDAR)->exists();
        $created = 0;

        foreach ($definition['statuses'] as $index => $status) {
            $exists = Status::where('slug', $status['slug'])->exists();
            if ($exists) {
                continue;
            }

            Status::create([
                'name' => $status['name'],
                'slug' => $status['slug'],
                'type' => $status['type'],
                // Presety opisują obieg SPOTKAŃ (kolory i grupy w kalendarzu).
                // Statusy zadań są osobnym, stałym zestawem zakładanym migracją,
                // więc nie zależą od wybranego przez klienta presetu.
                'context' => Status::CONTEXT_CALENDAR,
                'color' => $status['color'],
                'order' => $index + 1,
                // Domyślny status ustawiamy tylko gdy tabela była pusta —
                // inaczej nadpisalibyśmy wybór klienta.
                'is_default' => $hasAny ? false : $status['is_default'],
                'is_visible' => true,
                'is_final' => $status['is_final'],
            ]);
            $created++;
        }

        return $created;
    }

    public function seedPermissions(): int
    {
        $created = 0;

        foreach (self::PERMISSIONS as $permission) {
            $model = Permission::firstOrCreate(
                ['code' => $permission['code']],
                ['name' => $permission['name'], 'module' => $permission['module']],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function seedCoreModules(): int
    {
        $created = 0;

        foreach (self::CORE_MODULES as $module) {
            $model = Module::firstOrCreate(
                ['name' => $module['name']],
                $module + ['version' => '1.0.0', 'author' => 'OVERMEDIA', 'is_active' => true],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Dane przykładowe (klienci + zadania + wizyty) — do obejrzenia jak CRM
     * wygląda z danymi. Uruchamiane tylko gdy baza klientów jest pusta,
     * żeby nie zaśmiecić produkcji przy ponownym uruchomieniu kreatora.
     */
    protected function seedSampleData(): int
    {
        if (Client::query()->exists()) {
            return 0;
        }

        try {
            foreach ([
                ClientSeeder::class,
                TaskSeeder::class,
                ClientVisitSeeder::class,
            ] as $seeder) {
                (new $seeder)->run();
            }

            return Client::query()->count();
        } catch (\Throwable $e) {
            Log::warning('Setup: sample data seeding failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    // ===================================================================
    // Zakończenie / ponowne uruchomienie
    // ===================================================================

    public function complete(): void
    {
        Setting::set('setup_completed', '1');
        Setting::set('setup_completed_at', now()->toIso8601String());

        Cache::flush();
    }

    /** Ponowne przejście kreatora (Ustawienia → Ogólne). Danych nie kasuje. */
    public function restart(): void
    {
        Setting::set('setup_completed', '0');
        Setting::set('setup_progress', []);

        Cache::flush();
    }

    /**
     * Czy w systemie jest ktokolwiek poza kontem, na którym trwa setup —
     * używane w podsumowaniu jako podpowiedź "dodaj użytkowników".
     */
    public function hasOtherUsers(): bool
    {
        return User::query()->count() > 1;
    }
}
