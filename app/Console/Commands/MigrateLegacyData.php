<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Models\UserMailConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class MigrateLegacyData extends Command
{
    protected $signature = 'migrate:legacy 
                            {--entity= : Migruj konkretną encję (statuses,users,clients,planner,mail-accounts,mail-templates,config,logs,user-rights,all)}
                            {--dry-run : Tylko symulacja, bez zapisu}
                            {--fresh : Wyczyść istniejące dane przed migracją}
                            {--force : Pomiń potwierdzenie}
                            {--include-trashed : Migruj również usunięte wpisy z planera}';

    protected $description = 'Migracja danych ze starego planera (Zend Framework) do nowego systemu';

    private int $migrated = 0;
    private int $skipped = 0;
    private array $errors = [];
    private array $warnings = [];
    private array $userIdMap = [];    // old_id => new_id
    private array $clientIdMap = [];  // old_id => new_id
    private array $statusIdMap = [];  // old_id => new_id

    /**
     * Mapowanie 2-literowych kodów krajów na pełne nazwy
     */
    private array $countryMap = [
        'PL' => 'Polska',
        'DE' => 'Niemcy',
        'CZ' => 'Czechy',
        'SK' => 'Słowacja',
        'LT' => 'Litwa',
        'LV' => 'Łotwa',
        'EE' => 'Estonia',
        'UA' => 'Ukraina',
        'GB' => 'Wielka Brytania',
        'FR' => 'Francja',
        'IT' => 'Włochy',
        'ES' => 'Hiszpania',
        'NL' => 'Holandia',
        'BE' => 'Belgia',
        'AT' => 'Austria',
        'CH' => 'Szwajcaria',
        'SE' => 'Szwecja',
        'NO' => 'Norwegia',
        'DK' => 'Dania',
        'FI' => 'Finlandia',
        'HU' => 'Węgry',
        'RO' => 'Rumunia',
        'BG' => 'Bułgaria',
        'HR' => 'Chorwacja',
        'SI' => 'Słowenia',
        'US' => 'Stany Zjednoczone',
        'CA' => 'Kanada',
        'RU' => 'Rosja',
        'BY' => 'Białoruś',
        'IE' => 'Irlandia',
        'PT' => 'Portugalia',
    ];

    /**
     * Daty-sentinel które traktujemy jako NULL (nie są prawdziwymi datami)
     */
    private array $invalidBirthdays = [
        '0000-00-00',
        '0001-11-30',
        '1970-01-01', // Unix epoch - prawdopodobnie default
    ];

    public function handle(): int
    {
        $entity = $this->option('entity') ?: 'all';
        $isDryRun = $this->option('dry-run');
        $isFresh = $this->option('fresh');

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║  MIGRACJA DANYCH ZE STAREGO PLANERA             ║');
        $this->info('╚══════════════════════════════════════════════════╝');

        if ($isDryRun) {
            $this->warn('=== TRYB SYMULACJI — żadne dane nie zostaną zapisane ===');
        }

        if ($isFresh && !$isDryRun && !$this->option('force')) {
            if (!$this->confirm('UWAGA: Flaga --fresh wyczyści istniejące dane w nowej bazie. Kontynuować?')) {
                $this->info('Anulowano.');
                return self::SUCCESS;
            }
        }

        if ($this->option('include-trashed')) {
            $this->warn('Uwaga: Migrowane będą również usunięte wpisy z planera (trash=1)');
        }

        // Wyłącz ochronę mass-assignment aby ustawić created_at/updated_at z oryginalnych dat
        Model::unguard();

        // Sprawdź połączenie z bazą legacy
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Połączono z bazą legacy: ' . config('database.connections.legacy.database'));
        } catch (\Exception $e) {
            $this->error('Nie można połączyć się z bazą legacy: ' . $e->getMessage());
            $this->newLine();
            $this->line('Upewnij się, że w .env masz:');
            $this->line('  LEGACY_DB_HOST=127.0.0.1');
            $this->line('  LEGACY_DB_DATABASE=planner_old');
            $this->line('  LEGACY_DB_USERNAME=root');
            $this->line('  LEGACY_DB_PASSWORD=');
            return self::FAILURE;
        }

        // Wyświetl statystyki starej bazy
        if ($isDryRun) {
            $this->showLegacyStats();
        }

        if ($isFresh && !$isDryRun) {
            $this->freshClearData($entity);
        }

        $entities = $entity === 'all'
            ? ['config', 'statuses', 'users', 'clients', 'planner', 'mail-accounts', 'mail-templates', 'logs', 'user-rights']
            : [trim($entity)];

        foreach ($entities as $e) {
            $this->migrated = 0;
            $this->skipped = 0;
            $this->errors = [];
            $this->warnings = [];

            $this->newLine();
            $this->info("========================================");
            $this->info(" Migracja: " . strtoupper($e));
            $this->info("========================================");

            match ($e) {
                'config' => $this->migrateConfig($isDryRun),
                'statuses' => $this->migrateStatuses($isDryRun, $isFresh),
                'users' => $this->migrateUsers($isDryRun, $isFresh),
                'clients' => $this->migrateClients($isDryRun, $isFresh),
                'planner' => $this->migratePlanner($isDryRun, $isFresh),
                'mail-accounts' => $this->migrateMailAccounts($isDryRun, $isFresh),
                'mail-templates' => $this->migrateMailTemplates($isDryRun, $isFresh),
                'logs' => $this->migrateActivityLogs($isDryRun, $isFresh),
                'user-rights' => $this->migrateUserRights($isDryRun, $isFresh),
                default => $this->error("Nieznana encja: {$e}"),
            };

            $this->newLine();
            $this->info("Zmigrowano: {$this->migrated}");
            if ($this->skipped > 0) {
                $this->warn("Pominięto: {$this->skipped}");
            }
            if (count($this->warnings) > 0) {
                $this->warn("Ostrzeżenia: " . count($this->warnings));
                foreach (array_slice($this->warnings, 0, 5) as $w) {
                    $this->line("  ⚠ {$w}");
                }
                if (count($this->warnings) > 5) {
                    $this->line("  ... i " . (count($this->warnings) - 5) . " więcej");
                }
            }
            if (count($this->errors) > 0) {
                $this->error("Błędy: " . count($this->errors));
                foreach (array_slice($this->errors, 0, 10) as $err) {
                    $this->line("  ✗ {$err}");
                }
                if (count($this->errors) > 10) {
                    $this->line("  ... i " . (count($this->errors) - 10) . " więcej");
                }
            }
        }

        Model::reguard();

        // Podsumowanie po pełnej migracji
        if (!$isDryRun && $entity === 'all') {
            $this->newLine();
            $this->info("╔══════════════════════════════════════════════════╗");
            $this->info("║  PODSUMOWANIE MIGRACJI                          ║");
            $this->info("╚══════════════════════════════════════════════════╝");
            $this->table(
                ['Tabela', 'Rekordów'],
                [
                    ['Statusy', Status::count()],
                    ['Użytkownicy', User::count()],
                    ['Klienci', Client::count()],
                    ['Wizyty (planner)', ClientVisit::count()],
                    ['Konta mailowe', UserMailConfig::count()],
                    ['Szablony email', EmailTemplate::count()],
                    ['Logi aktywności', DB::table('activity_log')->count()],
                    ['Uprawnienia użytkowników', DB::table('user_permissions')->count()],
                ]
            );

            $this->newLine();
            $this->warn("═══ WAŻNE CZYNNOŚCI PO MIGRACJI ═══");
            $this->line("1. Użytkownicy muszą zmienić hasło (tymczasowe: ZmienHaslo123!)");
            $this->line("2. Konta mailowe wymagają ponownej weryfikacji (hasła przeniesione zaszyfrowane)");
            $this->line("3. Sprawdź ustawienia integracji Apilo i Fakturownia");
            $this->line("4. Zweryfikuj dane klientów (adresy, telefony)");
        }

        return self::SUCCESS;
    }

    /**
     * Wyświetl statystyki starej bazy przed migracją
     */
    private function showLegacyStats(): void
    {
        $this->newLine();
        $this->info("--- Statystyki starej bazy ---");

        $tables = ['status', 'user', 'client', 'planner', 'mail_account', 'mail_template', 'base_config', 'base_log', 'user_rights'];

        foreach ($tables as $table) {
            try {
                $count = DB::connection('legacy')->table($table)->count();
                $this->line("  {$table}: {$count} rekordów");
            } catch (\Exception $e) {
                $this->line("  {$table}: [niedostępna]");
            }
        }

        // Szczegóły planer
        try {
            $active = DB::connection('legacy')->table('planner')->where('trash', 0)->count();
            $trashed = DB::connection('legacy')->table('planner')->where('trash', 1)->count();
            $noClient = DB::connection('legacy')->table('planner')->where('trash', 0)->where('id_client', 0)->count();
            $this->line("  planner aktywne: {$active}, usunięte: {$trashed}, bez klienta: {$noClient}");
        } catch (\Exception $e) {
            // ignore
        }

        // Szczegóły klienci
        try {
            $zeroBirthday = DB::connection('legacy')->table('client')
                ->whereIn('birthday', ['0000-00-00', '0001-11-30'])
                ->count();
            $this->line("  client z nieprawidłową datą urodzin: {$zeroBirthday}");
        } catch (\Exception $e) {
            // ignore
        }

        $this->newLine();
    }

    /**
     * Wyczyść wszystkie tabele przed migracją — centralne czyszczenie z FK CHECKS=0
     */
    private function freshClearData(string $entity): void
    {
        $this->warn('Czyszczenie danych przed migracją (--fresh)...');

        $allTables = [
            'sent_emails',
            'task_comments',
            'tasks',
            'user_permissions',
            'client_visits',
            'clients',
            'users',
            'statuses',
            'user_mail_configs',
            'email_templates',
            'activity_log',
        ];

        $entityTableMap = [
            'statuses' => ['tasks', 'task_comments', 'statuses'],
            'users' => ['sent_emails', 'task_comments', 'tasks', 'user_permissions', 'user_mail_configs', 'users'],
            'clients' => ['client_visits', 'clients'],
            'planner' => ['client_visits'],
            'mail-accounts' => ['user_mail_configs'],
            'mail-templates' => ['email_templates'],
            'logs' => ['activity_log'],
            'user-rights' => ['user_permissions'],
        ];

        $tablesToClear = ($entity === 'all')
            ? $allTables
            : ($entityTableMap[$entity] ?? []);

        if (empty($tablesToClear)) {
            return;
        }

        // Jedna transakcja z wyłączonymi FK checks — gwarantuje tę samą sesję PDO
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        $this->line('  SET FOREIGN_KEY_CHECKS=0');

        foreach ($tablesToClear as $table) {
            try {
                DB::unprepared("TRUNCATE TABLE `{$table}`");
                $this->line("  TRUNCATE TABLE {$table} — OK");
            } catch (\Exception $e) {
                $this->warn("  TRUNCATE TABLE {$table} — pominięto ({$e->getMessage()})");
            }
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
        $this->line('  SET FOREIGN_KEY_CHECKS=1');
        $this->info('Czyszczenie zakończone.');
    }

    // ==================== KONFIGURACJA ====================

    private function migrateConfig(bool $dryRun): void
    {
        $oldConfig = DB::connection('legacy')->table('base_config')->get();
        $this->line("Znaleziono {$oldConfig->count()} ustawień w starej bazie");

        // Mapowanie code => klucz w nowym systemie Setting
        $configMap = [
            'fakturownia_subdomain' => 'fakturownia_subdomain',
            'fakturownia_api_token' => 'fakturownia_api_token',
            'fakturownia_overdue_day' => 'fakturownia_overdue_days',
            'apilo_subdomain' => 'apilo_subdomain',
            'apilo_api_token' => 'apilo_access_token',
            'apilo_api_client_id' => 'apilo_client_id',
            'apilo_api_secret' => 'apilo_client_secret',
            'apilo_api_refresh_token' => 'apilo_refresh_token',
            'apilo_api_access_token_expire_at' => 'apilo_token_expires_at',
            'base_company_name' => 'company_name',
            'base_company_street' => 'company_street',
            'base_company_zip_code' => 'company_zip_code',
            'base_company_city' => 'company_city',
            'base_company_mail' => 'company_email',
            'base_company_phone' => 'company_phone',
            'client_statuses' => 'client_statuses',
        ];

        // Ustawienia które są wrażliwe (nie wyświetlać w logach)
        $sensitiveKeys = [
            'fakturownia_api_token', 'apilo_api_token', 'apilo_api_secret',
            'apilo_api_refresh_token',
        ];

        foreach ($oldConfig as $cfg) {
            $newKey = $configMap[$cfg->code] ?? null;

            if (!$newKey) {
                $this->skipped++;
                continue;
            }

            $value = $cfg->value;
            $displayValue = in_array($cfg->code, $sensitiveKeys)
                ? Str::limit($value, 20, '***')
                : $value;

            $this->line("  {$cfg->code} → {$newKey}: {$displayValue}");

            if (!$dryRun) {
                Setting::set($newKey, $value, 'core');
            }

            $this->migrated++;
        }
    }

    // ==================== STATUSY ====================

    private function migrateStatuses(bool $dryRun, bool $fresh): void
    {
        $oldStatuses = DB::connection('legacy')->table('status')->get();
        $this->line("Znaleziono {$oldStatuses->count()} statusów w starej bazie");

        $colorMap = [
            'default' => '#6B7280',
            'primary' => '#3B82F6',
            'danger' => '#EF4444',
            'success' => '#10B981',
            'info' => '#06B6D4',
            'warning' => '#F59E0B',
            'pink' => '#EC4899',
            'dark-green' => '#047857',
            'dark-yellow' => '#D97706',
            'sun-yellow' => '#FBBF24',
        ];

        $bar = $this->output->createProgressBar($oldStatuses->count());

        foreach ($oldStatuses as $old) {
            try {
                // Generuj unikalny slug
                $slug = Str::slug($old->name);
                if (!$dryRun && Status::where('slug', $slug)->exists()) {
                    $slug = $slug . '-' . $old->id;
                }

                $descParts = array_filter([
                    !empty($old->mail_to) ? "Mail do: {$old->mail_to}" : '',
                    !empty($old->mail_subject) ? "Temat: {$old->mail_subject}" : '',
                    !empty($old->mail_message) ? "Treść: " . Str::limit($old->mail_message, 500) : '',
                ]);
                $description = !empty($descParts) ? implode("\n", $descParts) : null;

                $data = [
                    'name' => $old->name,
                    'slug' => $slug,
                    'type' => $old->type ?: 'new',
                    'color' => $colorMap[$old->color] ?? '#6B7280',
                    'order' => $old->weight ?? 0,
                    'is_default' => (bool)$old->is_default,
                    'is_visible' => (bool)$old->is_show,
                    'is_final' => $old->type === 'done',
                    'description' => $description,
                    'created_at' => $old->create_date,
                    'updated_at' => $old->mod_date ?? now(),
                ];

                if (!$dryRun) {
                    $status = Status::create($data);
                    $this->statusIdMap[$old->id] = $status->id;
                } else {
                    $this->statusIdMap[$old->id] = $old->id;
                }

                $this->migrated++;
            } catch (\Exception $e) {
                $this->errors[] = "Status #{$old->id} ({$old->name}): {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
    }

    // ==================== UŻYTKOWNICY ====================

    private function migrateUsers(bool $dryRun, bool $fresh): void
    {
        $oldUsers = DB::connection('legacy')->table('user')->get();
        $this->line("Znaleziono {$oldUsers->count()} użytkowników w starej bazie");

        $bar = $this->output->createProgressBar($oldUsers->count());

        foreach ($oldUsers as $old) {
            try {
                // Sprawdź czy email jest prawidłowy
                $email = trim($old->mail ?? '');
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->warnings[] = "User #{$old->id}: nieprawidłowy email '{$old->mail}', ustawiam placeholder";
                    $email = 'user_' . $old->id . '@placeholder.local';
                }

                // Sprawdź duplikat emaila
                if (!$dryRun && User::where('email', $email)->exists()) {
                    $this->warnings[] = "User #{$old->id}: email '{$email}' już istnieje, pomijam";
                    $this->skipped++;
                    $bar->advance();
                    continue;
                }

                // Buduj imię i nazwisko
                $name = trim(($old->firstname ?? '') . ' ' . ($old->lastname ?? ''));
                if (empty($name)) {
                    $name = $old->name ?? $email;
                }

                $isAdmin = ($old->type == 2) || ($old->accept_admin == 1);

                $data = [
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make('ZmienHaslo123!'),
                    'phone' => $this->sanitizePhone($old->phone ?? ''),
                    'position' => $old->position,
                    'role' => $isAdmin ? 'admin' : 'user',
                    'status' => 'active',
                    'avatar' => ($old->avatar && strlen($old->avatar) <= 255) ? $old->avatar : null,
                    'email_verified_at' => now(),
                    'last_login_at' => $old->last_login_date,
                    'fakturownia_department_id' => $old->id_department_fakturownia ?: null,
                    'created_at' => $old->create_date ?? now(),
                    'updated_at' => $old->mod_date ?? now(),
                ];

                if (!$dryRun) {
                    $user = User::create($data);
                    $this->userIdMap[$old->id] = $user->id;
                } else {
                    $this->userIdMap[$old->id] = $old->id;
                }

                $this->migrated++;
            } catch (\Exception $e) {
                $this->errors[] = "User #{$old->id} ({$old->mail}): {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();

        if (!$dryRun) {
            $this->newLine();
            $this->warn("UWAGA: Wszystkim użytkownikom ustawiono tymczasowe hasło: ZmienHaslo123!");
            $this->warn("Stare hasła (MD5) nie mogą być przekonwertowane — użytkownicy muszą zmienić hasła.");
        }
    }

    // ==================== KLIENCI ====================

    private function migrateClients(bool $dryRun, bool $fresh): void
    {
        $count = DB::connection('legacy')->table('client')->count();
        $this->line("Znaleziono {$count} klientów w starej bazie");

        // Buduj mapę userIdMap jeśli jest pusta
        if (empty($this->userIdMap) && !$dryRun) {
            $this->buildUserIdMap();
        }

        // Statystyki problemów (do wyświetlenia)
        $stats = [
            'invalid_birthdays' => 0,
            'sentinel_birthdays' => 0,
            'invalid_country' => 0,
            'phone_truncated' => 0,
            'email_trimmed' => 0,
            'orphaned_sales_rep' => 0,
            'empty_names' => 0,
        ];

        $bar = $this->output->createProgressBar($count);

        // Przetwarzaj w porcjach po 500
        DB::connection('legacy')->table('client')->orderBy('id')->chunk(500, function ($clients) use ($dryRun, $bar, &$stats) {
            foreach ($clients as $old) {
                try {
                    // === BIRTHDAY: obsługa sentineli i nieprawidłowych dat ===
                    $birthday = null;
                    if ($old->birthday) {
                        if (in_array($old->birthday, $this->invalidBirthdays)) {
                            $stats['invalid_birthdays']++;
                            // Zostaw jako null
                        } elseif (preg_match('/^2000-\d{2}-\d{2}$/', $old->birthday)) {
                            // Daty 2000-XX-XX to prawdopodobnie placeholder
                            $stats['sentinel_birthdays']++;
                            // Zostaw jako null
                        } else {
                            // Waliduj datę
                            $parsed = date_parse($old->birthday);
                            if ($parsed['error_count'] === 0 && $parsed['year'] > 1900 && $parsed['year'] < 2025) {
                                $birthday = $old->birthday;
                            } else {
                                $stats['invalid_birthdays']++;
                            }
                        }
                    }

                    // === COUNTRY: mapowanie kodów 2-literowych ===
                    $country = 'Polska'; // domyślnie
                    if (!empty($old->country)) {
                        $code = strtoupper(trim($old->country));
                        if ($code === 'PO') {
                            // Znany błąd w danych — PO zamiast PL
                            $country = 'Polska';
                            $stats['invalid_country']++;
                        } elseif (isset($this->countryMap[$code])) {
                            $country = $this->countryMap[$code];
                        } elseif (strlen($code) > 2) {
                            // Może pełna nazwa — użyj jak jest
                            $country = $old->country;
                        } else {
                            $country = $this->countryMap['PL']; // fallback
                            $stats['invalid_country']++;
                        }
                    }

                    // === PHONE: sanityzacja i obcinanie do 20 znaków ===
                    $phone = $this->sanitizePhone($old->phone ?? '');
                    $phone2 = $this->sanitizePhone($old->mobile_phone ?? '');
                    if ($phone && strlen($phone) > 20) {
                        $stats['phone_truncated']++;
                    }
                    if ($phone2 && strlen($phone2) > 20) {
                        $stats['phone_truncated']++;
                    }

                    // === EMAIL: trimming białych znaków ===
                    $email = trim($old->mail ?? '');
                    if ($email !== ($old->mail ?? '')) {
                        $stats['email_trimmed']++;
                    }
                    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->warnings[] = "Client #{$old->id}: nieprawidłowy email '{$email}'";
                        // Zachowaj mimo wszystko — użytkownik może poprawić
                    }

                    // === NAME: budowa nazwy ===
                    $contactPerson = trim(($old->firstname ?? '') . ' ' . ($old->lastname ?? ''));
                    $name = $old->name ?: $contactPerson;
                    if (empty(trim($name))) {
                        $name = 'Klient #' . $old->id;
                        $stats['empty_names']++;
                    }

                    // === SALES REP: mapowanie ===
                    $createdBy = null;
                    if ($old->id_sales_representative) {
                        $createdBy = $this->userIdMap[$old->id_sales_representative] ?? null;
                        if (!$createdBy && $old->id_sales_representative > 0) {
                            $stats['orphaned_sales_rep']++;
                        }
                    }

                    // === TYPE: walidacja ===
                    $type = in_array($old->type, ['company', 'person']) ? $old->type : 'company';

                    // === BUILDING / APARTMENT: rozdziel "5/3" na building=5, apartment=3 ===
                    $buildingNumber = null;
                    $apartmentNumber = null;
                    $streetNumber = trim($old->street_number ?? '');
                    if (!empty($streetNumber)) {
                        if (preg_match('/^(\d+[a-zA-Z]?)\s*[\/\\\\]\s*(\d+[a-zA-Z]?)$/', $streetNumber, $m)) {
                            $buildingNumber = $m[1];
                            $apartmentNumber = $m[2];
                        } else {
                            $buildingNumber = $streetNumber;
                        }
                    }

                    // === CLIENT STATUS: mapowanie starego statusu na nowy enum ===
                    $clientStatus = 'active';
                    $oldStatus = strtolower(trim($old->status ?? ''));
                    if (in_array($oldStatus, ['inactive', 'nieaktywny', 'disabled', 'zamknięty'])) {
                        $clientStatus = 'inactive';
                    } elseif (in_array($oldStatus, ['potential', 'potencjalny', 'lead', 'nowy', 'prospect'])) {
                        $clientStatus = 'potential';
                    }

                    // === REGION: zachowaj w profilu lub notatkach ===
                    $notes = $old->note ?: '';
                    if (!empty($old->region)) {
                        $notes = trim($notes . "\nRegion: " . $old->region);
                    }

                    // === DELIVERY ADDRESS: zachowaj WSZYSTKIE pola w notatkach ===
                    $deliveryParts = array_filter([
                        !empty($old->delivery_name) ? "Nazwa: {$old->delivery_name}" : '',
                        !empty($old->delivery_address) ? "Adres: {$old->delivery_address}" : '',
                        trim(($old->delivery_street_name ?? '') . ' ' . ($old->delivery_street_number ?? ''))
                            ? "Ulica: " . trim(($old->delivery_street_name ?? '') . ' ' . ($old->delivery_street_number ?? ''))
                            : '',
                        !empty($old->delivery_zip_code) || !empty($old->delivery_city)
                            ? "Miasto: " . trim(($old->delivery_zip_code ?? '') . ' ' . ($old->delivery_city ?? ''))
                            : '',
                        !empty($old->delivery_country) ? "Kraj: " . ($this->countryMap[strtoupper($old->delivery_country)] ?? $old->delivery_country) : '',
                        !empty($old->delivery_phone) ? "Tel: {$old->delivery_phone}" : '',
                        !empty($old->delivery_mail) ? "Email: {$old->delivery_mail}" : '',
                        !empty($old->delivery_parcel_name) ? "Kurier: {$old->delivery_parcel_name}" : '',
                        !empty($old->delivery_parcel_id_external) ? "Nr przesyłki: {$old->delivery_parcel_id_external}" : '',
                    ]);
                    if (!empty($deliveryParts)) {
                        $deliveryBlock = "\n\n--- Adres dostawy (ze starego systemu) ---\n" . implode("\n", $deliveryParts);
                        $notes = trim($notes . $deliveryBlock);
                    }

                    $data = [
                        'type' => $type,
                        'name' => Str::limit($name, 255, ''),
                        'short_name' => $old->name_brand ?: null,
                        'nip' => $this->sanitizeNip($old->vatin ?? ''),
                        'email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'phone2' => $phone2 ?: null,
                        'website' => $old->www ?: null,
                        'street' => $old->street_name ?: $old->address ?: null,
                        'building_number' => $buildingNumber,
                        'apartment_number' => $apartmentNumber,
                        'postal_code' => $old->zip_code ?: null,
                        'city' => $old->city ?: null,
                        'country' => $country,
                        'contact_person' => $contactPerson ?: null,
                        'contact_email' => trim($old->delivery_mail ?? '') ?: null,
                        'contact_phone' => $this->sanitizePhone($old->delivery_phone ?? '') ?: null,
                        'status' => $clientStatus,
                        'notes' => $notes ?: null,
                        'birthday' => $birthday,
                        'created_by' => $createdBy,
                        'created_at' => $old->create_date ?? now(),
                        'updated_at' => $old->mod_date ?? now(),
                    ];

                    if (!$dryRun) {
                        $client = Client::create($data);
                        $this->clientIdMap[$old->id] = $client->id;
                    } else {
                        $this->clientIdMap[$old->id] = $old->id;
                    }

                    $this->migrated++;
                } catch (\Exception $e) {
                    $this->errors[] = "Client #{$old->id} ({$old->name}): " . Str::limit($e->getMessage(), 100);
                    $this->skipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();

        // Wyświetl statystyki problemów
        $this->newLine();
        if ($stats['invalid_birthdays'] > 0) {
            $this->line("  Nieprawidłowe daty urodzin (0000-00-00, 0001-11-30): {$stats['invalid_birthdays']} → ustawiono NULL");
        }
        if ($stats['sentinel_birthdays'] > 0) {
            $this->line("  Daty-sentinel (2000-XX-XX): {$stats['sentinel_birthdays']} → ustawiono NULL");
        }
        if ($stats['invalid_country'] > 0) {
            $this->line("  Nieprawidłowe kody krajów (np. 'PO'): {$stats['invalid_country']} → ustawiono 'Polska'");
        }
        if ($stats['phone_truncated'] > 0) {
            $this->line("  Numery telefonów skrócone: {$stats['phone_truncated']}");
        }
        if ($stats['email_trimmed'] > 0) {
            $this->line("  Emaile z obciętymi spacjami: {$stats['email_trimmed']}");
        }
        if ($stats['orphaned_sales_rep'] > 0) {
            $this->line("  Osierocone referencje handlowca: {$stats['orphaned_sales_rep']} → ustawiono NULL");
        }
        if ($stats['empty_names'] > 0) {
            $this->line("  Klienci bez nazwy: {$stats['empty_names']} → ustawiono 'Klient #ID'");
        }
    }

    // ==================== PLANNER → CLIENT_VISITS ====================

    private function migratePlanner(bool $dryRun, bool $fresh): void
    {
        $includeTrashed = $this->option('include-trashed');

        $query = DB::connection('legacy')->table('planner');
        if (!$includeTrashed) {
            $query->where('trash', 0);
        }
        $count = $query->count();

        $activeCount = DB::connection('legacy')->table('planner')->where('trash', 0)->count();
        $trashedCount = DB::connection('legacy')->table('planner')->where('trash', 1)->count();
        $this->line("Znaleziono {$activeCount} aktywnych wpisów (+{$trashedCount} w koszu)");
        $this->line($includeTrashed
            ? "Migrowane: {$count} (aktywne + kosz)"
            : "Migrowane: {$count} (tylko aktywne)");

        // Buduj mapy ID jeśli puste
        if (empty($this->userIdMap) && !$dryRun) {
            $this->buildUserIdMap();
        }
        if (empty($this->clientIdMap) && !$dryRun) {
            $this->buildClientIdMap();
        }
        if (empty($this->statusIdMap) && !$dryRun) {
            $this->buildStatusIdMap();
        }

        // Pre-cache stare statusy aby nie odpytywać bazy per-rekord
        $oldStatusCache = DB::connection('legacy')->table('status')
            ->get()
            ->keyBy('id');

        // Statystyki
        $stats = [
            'no_client' => 0,
            'no_user' => 0,
            'no_status' => 0,
            'no_date' => 0,
            'orphaned_user' => 0,
            'orphaned_client' => 0,
            'trashed_migrated' => 0,
        ];

        $bar = $this->output->createProgressBar($count);

        $plannerQuery = DB::connection('legacy')->table('planner');
        if (!$includeTrashed) {
            $plannerQuery->where('trash', 0);
        }

        $plannerQuery
            ->orderBy('id')
            ->chunk(500, function ($entries) use ($dryRun, $bar, &$stats, $includeTrashed, $oldStatusCache) {
                foreach ($entries as $old) {
                    try {
                        // === CLIENT: nullable ===
                        $clientId = null;
                        if ($old->id_client && $old->id_client > 0) {
                            $clientId = $this->clientIdMap[$old->id_client] ?? null;
                            if (!$clientId && !$dryRun) {
                                $stats['orphaned_client']++;
                            }
                        } else {
                            $stats['no_client']++;
                        }

                        // === USER: nullable w schemacie, fallback do pierwszego admina gdy brak ===
                        $userId = null;
                        if ($old->id_user) {
                            $userId = $this->userIdMap[$old->id_user] ?? null;
                            if (!$userId) {
                                $stats['orphaned_user']++;
                            }
                        } else {
                            $stats['no_user']++;
                        }
                        // Fallback: wizyty bez użytkownika przypisz do pierwszego admina (żeby były widoczne w kalendarzu)
                        if (!$userId && !$dryRun) {
                            $userId = User::where('role', 'admin')->orderBy('id')->value('id')
                                ?? User::orderBy('id')->value('id');
                        }

                        // === STATUS: nullable od migracji ===
                        $statusId = null;
                        if ($old->id_status && $old->id_status > 0) {
                            $statusId = $this->statusIdMap[$old->id_status] ?? null;
                            if (!$statusId) {
                                $stats['no_status']++;
                            }
                        } else {
                            $stats['no_status']++;
                        }

                        // === DATE: completion_date (termin w kalendarzu) ma pierwszeństwo, potem submit_date, create_date ===
                        $visitDate = null;
                        $visitTime = null;
                        if ($old->completion_date) {
                            $timestamp = strtotime($old->completion_date);
                            if ($timestamp && $timestamp > 0) {
                                $visitDate = date('Y-m-d', $timestamp);
                                $visitTime = date('H:i:s', $timestamp);
                            }
                        }
                        if (!$visitDate && $old->submit_date) {
                            $timestamp = strtotime($old->submit_date);
                            if ($timestamp && $timestamp > 0) {
                                $visitDate = date('Y-m-d', $timestamp);
                                $visitTime = date('H:i:s', $timestamp);
                            }
                        }
                        if (!$visitDate && $old->create_date) {
                            $visitDate = date('Y-m-d', strtotime($old->create_date));
                            $visitTime = date('H:i:s', strtotime($old->create_date));
                        }
                        if (!$visitDate) {
                            $visitDate = now()->format('Y-m-d');
                            $stats['no_date']++;
                        }

                        // === DEADLINE: walidacja completion_date ===
                        $deadline = null;
                        if ($old->completion_date) {
                            $ts = strtotime($old->completion_date);
                            if ($ts && $ts > 0) {
                                $deadline = date('Y-m-d H:i:s', $ts);
                            }
                        }

                        // === STATUS STRING: na podstawie trash, typu statusu i daty ===
                        $statusStr = 'planned';
                        if ($old->trash == 1) {
                            $statusStr = 'cancelled';
                            $stats['trashed_migrated']++;
                        } elseif ($old->id_status && isset($oldStatusCache[$old->id_status])) {
                            $statusType = $oldStatusCache[$old->id_status]->type ?? '';
                            if ($statusType === 'done' || $statusType === 'cancelled') {
                                $statusStr = 'completed';
                            } elseif ($statusType === 'in_progress') {
                                $statusStr = 'confirmed';
                            } else {
                                $statusStr = $visitDate && $visitDate < date('Y-m-d') ? 'completed' : 'planned';
                            }
                        } elseif ($visitDate && $visitDate < date('Y-m-d')) {
                            $statusStr = 'completed';
                        }

                        // === DESCRIPTION: oczyszczanie HTML ===
                        $description = $old->description ?: null;
                        // Zamień encje HTML na UTF-8 (np. &Oacute; → Ó)
                        if ($description) {
                            $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }

                        $data = [
                            'client_id' => $clientId,
                            'user_id' => $userId,
                            'status_id' => $statusId,
                            'visit_date' => $visitDate,
                            'visit_time' => $visitTime,
                            'title' => Str::limit($old->summary ?: '', 255),
                            'description' => $description,
                            'notes' => $old->notes ?: null,
                            'link' => $old->social_link ? Str::limit($old->social_link, 255, '') : null,
                            'status' => $statusStr,
                            'deadline' => $deadline,
                            'created_at' => $old->create_date ?? now(),
                            'updated_at' => $old->mod_date ?? now(),
                        ];

                        if (!$dryRun) {
                            ClientVisit::create($data);
                        }

                        $this->migrated++;
                    } catch (\Exception $e) {
                        $this->errors[] = "Planner #{$old->id}: " . Str::limit($e->getMessage(), 100);
                        $this->skipped++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();

        // Statystyki
        $this->newLine();
        $this->line("  Bez przypisanego klienta (id_client=0): {$stats['no_client']}");
        if ($stats['orphaned_client'] > 0) {
            $this->line("  Osierocone referencje klientów: {$stats['orphaned_client']} → ustawiono NULL");
        }
        if ($stats['orphaned_user'] > 0) {
            $this->line("  Osierocone referencje użytkowników: {$stats['orphaned_user']} → ustawiono NULL");
        }
        if ($stats['no_status'] > 0) {
            $this->line("  Bez statusu lub z id_status=0: {$stats['no_status']} → ustawiono NULL");
        }
        if ($stats['no_date'] > 0) {
            $this->line("  Bez daty wizyty: {$stats['no_date']} → użyto daty dzisiejszej");
        }
        if ($stats['trashed_migrated'] > 0) {
            $this->line("  Usunięte wpisy (trash=1): {$stats['trashed_migrated']} → status='cancelled'");
        }
    }

    // ==================== KONTA MAILOWE ====================

    private function migrateMailAccounts(bool $dryRun, bool $fresh): void
    {
        $oldAccounts = DB::connection('legacy')->table('mail_account')->get();
        $this->line("Znaleziono {$oldAccounts->count()} kont mailowych w starej bazie");

        // Buduj mapę user → mail_account
        if (empty($this->userIdMap) && !$dryRun) {
            $this->buildUserIdMap();
        }

        $oldUsers = DB::connection('legacy')->table('user')->get();
        $userMailMap = []; // mail_account_id => [user_ids]
        foreach ($oldUsers as $u) {
            if ($u->id_mail > 0) {
                $userMailMap[$u->id_mail][] = $u->id;
            }
        }

        // Deduplikacja kont - sprawdź po username/host
        $seenAccounts = []; // "username@host" => true
        $stats = [
            'duplicates_skipped' => 0,
            'passwords_migrated' => 0,
            'no_password' => 0,
            'unassigned' => 0,
        ];

        $bar = $this->output->createProgressBar($oldAccounts->count());

        foreach ($oldAccounts as $old) {
            try {
                // Deduplikacja
                $accountKey = trim($old->username ?? '') . '@' . trim($old->host ?? '');
                if (isset($seenAccounts[$accountKey])) {
                    $stats['duplicates_skipped']++;
                    $this->skipped++;
                    $bar->advance();
                    continue;
                }
                $seenAccounts[$accountKey] = true;

                // Znajdź użytkowników przypisanych do tego konta
                $assignedUserIds = $userMailMap[$old->id] ?? [];

                if (empty($assignedUserIds)) {
                    $stats['unassigned']++;
                    $this->skipped++;
                    $bar->advance();
                    continue;
                }

                foreach ($assignedUserIds as $oldUserId) {
                    $newUserId = $this->userIdMap[$oldUserId] ?? null;
                    if (!$newUserId && !$dryRun) continue;

                    // === HASŁO: przenieś plaintext (model zaszyfruje automatycznie) ===
                    $password = 'ZMIEN_HASLO';
                    if (!empty($old->password) && trim($old->password) !== '') {
                        // Model UserMailConfig ma mutator setMailPasswordAttribute
                        // który automatycznie szyfruje — wystarczy podać plaintext
                        $password = trim($old->password);
                        $stats['passwords_migrated']++;
                    } else {
                        $stats['no_password']++;
                    }

                    $port = intval($old->port ?: 587);

                    $data = [
                        'user_id' => $newUserId ?? 1,
                        'name' => trim($old->from_name ?: 'Konto #' . $old->id),
                        'mail_host' => trim($old->host ?: 'smtp.gmail.com'),
                        'mail_port' => $port,
                        'mail_username' => trim($old->username ?: ''),
                        'mail_password' => $password,
                        'mail_encryption' => $port == 465 ? 'ssl' : 'tls',
                        'mail_from_address' => trim($old->from_address ?: $old->username ?: ''),
                        'mail_from_name' => trim($old->from_name ?: ''),
                        'is_default' => true,
                        'is_verified' => false, // Trzeba ponownie zweryfikować po migracji
                        'created_at' => $old->create_date ?? now(),
                        'updated_at' => $old->mod_date ?? now(),
                    ];

                    if (!$dryRun) {
                        UserMailConfig::create($data);
                    }

                    $this->migrated++;
                }
            } catch (\Exception $e) {
                $this->errors[] = "MailAccount #{$old->id}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();

        // Statystyki
        $this->newLine();
        if ($stats['passwords_migrated'] > 0) {
            $this->line("  Hasła zaszyfrowane i przeniesione: {$stats['passwords_migrated']}");
        }
        if ($stats['no_password'] > 0) {
            $this->line("  Konta bez hasła: {$stats['no_password']}");
        }
        if ($stats['duplicates_skipped'] > 0) {
            $this->line("  Duplikaty pominięte: {$stats['duplicates_skipped']}");
        }
        if ($stats['unassigned'] > 0) {
            $this->line("  Konta bez przypisanego użytkownika: {$stats['unassigned']}");
        }

        if (!$dryRun) {
            $this->newLine();
            $this->warn("UWAGA: Hasła do kont mailowych zostały zaszyfrowane i przeniesione.");
            $this->warn("Jednak konta wymagają ponownej weryfikacji połączenia (is_verified=false).");
            $this->warn("Użytkownicy powinni przetestować wysyłkę w ustawieniach.");
        }
    }

    // ==================== SZABLONY EMAIL ====================

    private function migrateMailTemplates(bool $dryRun, bool $fresh): void
    {
        $oldTemplates = DB::connection('legacy')->table('mail_template')->get();
        $this->line("Znaleziono {$oldTemplates->count()} szablonów email w starej bazie");

        $bar = $this->output->createProgressBar($oldTemplates->count());

        foreach ($oldTemplates as $old) {
            try {
                // Generuj unikalny slug
                $slug = Str::slug($old->name ?: 'szablon-' . $old->id);
                if (!$dryRun && EmailTemplate::where('slug', $slug)->exists()) {
                    $slug = $slug . '-' . $old->id;
                }

                // Oczyszczanie HTML - zamień encje na UTF-8
                $htmlContent = $old->template ?: '';
                if ($htmlContent) {
                    $htmlContent = html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                $description = null;
                if (!empty($old->attachment)) {
                    $description = "Załącznik (stary system): {$old->attachment}";
                }

                $data = [
                    'name' => $old->name ?: 'Szablon #' . $old->id,
                    'slug' => $slug,
                    'subject' => $old->name ?: 'Bez tematu',
                    'description' => $description,
                    'html_content' => $htmlContent,
                    'category' => 'marketing',
                    'is_active' => true,
                    'variables' => [],
                    'created_at' => $old->create_date ?? now(),
                    'updated_at' => $old->mod_date ?? now(),
                ];

                if (!$dryRun) {
                    EmailTemplate::create($data);
                }

                $this->migrated++;
            } catch (\Exception $e) {
                $this->errors[] = "Template #{$old->id} ({$old->name}): {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
    }

    // ==================== LOGI AKTYWNOŚCI ====================

    private function migrateActivityLogs(bool $dryRun, bool $fresh): void
    {
        $count = DB::connection('legacy')->table('base_log')->count();
        $this->line("Znaleziono {$count} logów w starej bazie");

        if ($count === 0) {
            $this->line("Brak logów do migracji");
            return;
        }

        if (empty($this->userIdMap) && !$dryRun) {
            $this->buildUserIdMap();
        }

        $actionMap = [
            'login' => 'login',
            'logout' => 'logout',
            'create' => 'create',
            'add' => 'create',
            'update' => 'update',
            'edit' => 'update',
            'delete' => 'delete',
            'remove' => 'delete',
            'restore' => 'restore',
        ];

        $bar = $this->output->createProgressBar($count);

        DB::connection('legacy')->table('base_log')
            ->orderBy('id')
            ->chunk(1000, function ($logs) use ($dryRun, $bar, $actionMap) {
                $batch = [];

                foreach ($logs as $old) {
                    try {
                        $userId = null;
                        if ($old->id_user) {
                            $userId = $this->userIdMap[$old->id_user] ?? null;
                        }

                        $action = $actionMap[strtolower(trim($old->type ?? ''))] ?? ($old->type ?: 'other');

                        $oldValues = null;
                        if ($old->desc_data) {
                            $decoded = json_decode($old->desc_data, true);
                            $oldValues = $decoded ?: null;
                        }

                        $row = [
                            'user_id' => $userId,
                            'action' => Str::limit($action, 50, ''),
                            'model_type' => $old->obj ?: null,
                            'model_id' => null,
                            'description' => $old->desc ? Str::limit($old->desc, 65000, '') : null,
                            'old_values' => $oldValues ? json_encode($oldValues) : null,
                            'new_values' => null,
                            'ip_address' => $old->ip ?: null,
                            'user_agent' => null,
                            'created_at' => $old->date ?? now(),
                            'updated_at' => $old->date ?? now(),
                        ];

                        if (!$dryRun) {
                            $batch[] = $row;
                        }

                        $this->migrated++;
                    } catch (\Exception $e) {
                        $this->errors[] = "Log #{$old->id}: " . Str::limit($e->getMessage(), 100);
                        $this->skipped++;
                    }

                    $bar->advance();
                }

                if (!$dryRun && !empty($batch)) {
                    DB::table('activity_log')->insert($batch);
                }
            });

        $bar->finish();
    }

    // ==================== UPRAWNIENIA UŻYTKOWNIKÓW ====================

    private function migrateUserRights(bool $dryRun, bool $fresh): void
    {
        $count = DB::connection('legacy')->table('user_rights')->count();
        $this->line("Znaleziono {$count} wpisów user_rights w starej bazie");

        if ($count === 0) {
            $this->line("Brak uprawnień do migracji");
            return;
        }

        if (empty($this->userIdMap) && !$dryRun) {
            $this->buildUserIdMap();
        }

        $newPermissions = DB::table('permissions')->get();
        if ($newPermissions->isEmpty() && !$dryRun) {
            $this->warn("Brak uprawnień w tabeli permissions — uruchom seeder: php artisan db:seed --class=PermissionSeeder");
            return;
        }

        $permCodeMap = $newPermissions->keyBy('code');

        $codeMapping = [
            'client' => ['clients_view', 'clients_manage'],
            'clients' => ['clients_view', 'clients_manage'],
            'client_view' => ['clients_view'],
            'client_edit' => ['clients_manage'],
            'client_manage' => ['clients_manage'],
            'planner' => ['tasks_view', 'tasks_manage'],
            'task' => ['tasks_view', 'tasks_manage'],
            'tasks' => ['tasks_view', 'tasks_manage'],
            'task_view' => ['tasks_view'],
            'task_edit' => ['tasks_manage'],
            'task_manage' => ['tasks_manage'],
            'user' => ['users_view', 'users_manage'],
            'users' => ['users_view', 'users_manage'],
            'user_view' => ['users_view'],
            'user_edit' => ['users_manage'],
            'user_manage' => ['users_manage'],
            'settings' => ['settings_manage'],
            'config' => ['settings_manage'],
            'status' => ['statuses_view', 'statuses_manage'],
            'statuses' => ['statuses_view', 'statuses_manage'],
            'report' => ['reports_view'],
            'reports' => ['reports_view'],
            'mail' => ['tasks_manage'],
            'mail_template' => ['settings_manage'],
        ];

        $assigned = [];
        $stats = ['mapped' => 0, 'unmapped' => 0, 'duplicates' => 0];

        $bar = $this->output->createProgressBar($count);

        $oldRights = DB::connection('legacy')->table('user_rights')->get();

        foreach ($oldRights as $old) {
            try {
                $oldUserId = $old->id_user;
                $newUserId = $this->userIdMap[$oldUserId] ?? null;
                if (!$newUserId && !$dryRun) {
                    $this->skipped++;
                    $bar->advance();
                    continue;
                }

                $code = strtolower(trim($old->code ?? ''));
                $value = strtolower(trim($old->value ?? ''));

                $specificKey = $code . '_' . $value;
                $permCodes = $codeMapping[$specificKey] ?? $codeMapping[$code] ?? null;

                if (!$permCodes) {
                    $stats['unmapped']++;
                    $this->warnings[] = "UserRight #{$old->id}: niezmapowany code='{$old->code}', value='{$old->value}'";
                    $bar->advance();
                    continue;
                }

                foreach ($permCodes as $permCode) {
                    $perm = $permCodeMap[$permCode] ?? null;
                    if (!$perm) continue;

                    $key = ($newUserId ?? $oldUserId) . ':' . $perm->id;
                    if (isset($assigned[$key])) {
                        $stats['duplicates']++;
                        continue;
                    }
                    $assigned[$key] = true;

                    if (!$dryRun) {
                        DB::table('user_permissions')->insert([
                            'user_id' => $newUserId,
                            'permission_id' => $perm->id,
                            'created_at' => $old->create_date ?? now(),
                            'updated_at' => $old->create_date ?? now(),
                        ]);
                    }

                    $stats['mapped']++;
                    $this->migrated++;
                }
            } catch (\Exception $e) {
                $this->errors[] = "UserRight #{$old->id}: " . Str::limit($e->getMessage(), 100);
                $this->skipped++;
            }

            $bar->advance();
        }

        $bar->finish();

        $this->newLine();
        $this->line("  Zmapowane uprawnienia: {$stats['mapped']}");
        if ($stats['unmapped'] > 0) {
            $this->line("  Niezmapowane kody: {$stats['unmapped']}");
        }
        if ($stats['duplicates'] > 0) {
            $this->line("  Duplikaty pominięte: {$stats['duplicates']}");
        }
    }

    // ==================== HELPERY ====================

    /**
     * Sanityzacja numeru telefonu — usuń spacje, myślniki, ogranicz do 20 znaków
     */
    private function sanitizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if (empty($phone) || $phone === '-') {
            return null;
        }

        // Usuń niepotrzebne znaki ale zachowaj + na początku
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Jeśli po czyszczeniu mamy pustego stringa, zwróć null
        if (empty($cleaned)) {
            return null;
        }

        // Ogranicz do 20 znaków
        return Str::limit($cleaned, 20, '');
    }

    /**
     * Sanityzacja NIP — usuń myślniki i spacje, ogranicz do 15 znaków
     */
    private function sanitizeNip(string $nip): ?string
    {
        $nip = trim($nip);
        if (empty($nip)) {
            return null;
        }

        // Usuń myślniki i spacje
        $cleaned = preg_replace('/[\s\-]/', '', $nip);

        if (empty($cleaned)) {
            return null;
        }

        // Ogranicz do 15 znaków (pole w bazie)
        return Str::limit($cleaned, 15, '');
    }

    /**
     * Buduj mapę old user ID → new user ID: email, potem nazwa (elastyczne dopasowanie)
     */
    private function buildUserIdMap(): void
    {
        $oldUsers = DB::connection('legacy')->table('user')->get();
        $newUsers = User::all();

        foreach ($oldUsers as $old) {
            $email = trim($old->mail ?? '');
            $oldName = $this->normalizeUserName($old->name ?? '');

            // 1. Mapuj po emailu
            $match = $newUsers->first(function ($user) use ($email) {
                return $email && strcasecmp(trim($user->email ?? ''), $email) === 0;
            });

            // 2. Fallback: mapuj po nazwie (elastyczne dopasowanie)
            if (!$match && $oldName) {
                $match = $newUsers->first(function ($user) use ($oldName) {
                    $newName = $this->normalizeUserName($user->name ?? '');
                    return $newName && $this->userNamesMatch($oldName, $newName);
                });
            }

            if ($match) {
                $this->userIdMap[$old->id] = $match->id;
            }
        }

        $this->line("Zmapowano " . count($this->userIdMap) . " użytkowników");
    }

    private function normalizeUserName(?string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim($name ?? ''));
        return $name;
    }

    private function userNamesMatch(string $oldName, string $newName): bool
    {
        if (strcasecmp($oldName, $newName) === 0) {
            return true;
        }
        // "Imię Nazwisko" vs "Nazwisko Imię"
        $oldParts = array_filter(explode(' ', $oldName));
        $newParts = array_filter(explode(' ', $newName));
        if (count($oldParts) >= 2 && count($newParts) >= 2) {
            $oldReversed = implode(' ', array_reverse($oldParts));
            if (strcasecmp($oldReversed, $newName) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Buduj mapę old client ID → new client ID na podstawie NIP lub nazwy
     */
    private function buildClientIdMap(): void
    {
        $this->line("Buduję mapę klientów (to może chwilę potrwać)...");

        // Mapuj po NIP (najdokładniejsze)
        $oldClients = DB::connection('legacy')->table('client')
            ->select('id', 'vatin', 'name')
            ->get();

        $newClients = Client::select('id', 'nip', 'name')->get();

        foreach ($oldClients as $old) {
            // Najpierw po NIP
            $nip = $this->sanitizeNip($old->vatin ?? '');
            if (!empty($nip)) {
                $match = $newClients->first(function ($client) use ($nip) {
                    return $client->nip && strcasecmp($client->nip, $nip) === 0;
                });
                if ($match) {
                    $this->clientIdMap[$old->id] = $match->id;
                    continue;
                }
            }

            // Potem po nazwie (case-insensitive)
            if (!empty($old->name)) {
                $match = $newClients->first(function ($client) use ($old) {
                    return strcasecmp($client->name, $old->name) === 0;
                });
                if ($match) {
                    $this->clientIdMap[$old->id] = $match->id;
                }
            }
        }

        $this->line("Zmapowano " . count($this->clientIdMap) . " klientów");
    }

    /**
     * Buduj mapę old status ID → new status ID na podstawie nazwy
     */
    private function buildStatusIdMap(): void
    {
        $oldStatuses = DB::connection('legacy')->table('status')->get();
        $newStatuses = Status::all();

        foreach ($oldStatuses as $old) {
            $match = $newStatuses->first(function ($status) use ($old) {
                return strcasecmp($status->name, $old->name) === 0;
            });
            if ($match) {
                $this->statusIdMap[$old->id] = $match->id;
            }
        }

        $this->line("Zmapowano " . count($this->statusIdMap) . " statusów");
    }
}
