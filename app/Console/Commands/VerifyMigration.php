<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\Status;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyMigration extends Command
{
    protected $signature = 'migrate:verify
                            {--legacy-only : Pokaż tylko statystyki starej bazy}
                            {--new-only : Pokaż tylko statystyki nowej bazy}';

    protected $description = 'Weryfikacja migracji – porównanie danych starej i nowej bazy';

    public function handle(): int
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  Weryfikacja migracji danych');
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        $legacyOk = false;
        $newOk = false;

        // === STARA BAZA (legacy) ===
        if (!$this->option('new-only')) {
            try {
                DB::connection('legacy')->getPdo();
                $legacyOk = true;
                $this->verifyLegacy();
            } catch (\Throwable $e) {
                $this->error('Stara baza (legacy): ' . $e->getMessage());
                $this->line('  Upewnij się, że LEGACY_DB_* są ustawione w .env');
            }
        }

        // === NOWA BAZA (default) ===
        if (!$this->option('legacy-only')) {
            try {
                DB::connection()->getPdo();
                $newOk = true;
                $this->verifyNew();
            } catch (\Throwable $e) {
                $this->error('Nowa baza: ' . $e->getMessage());
            }
        }

        // === PORÓWNANIE ===
        if ($legacyOk && $newOk && !$this->option('legacy-only') && !$this->option('new-only')) {
            $this->newLine();
            $this->info('--- Porównanie ---');
            $this->compareData();
        }

        $this->newLine();
        return self::SUCCESS;
    }

    private function verifyLegacy(): void
    {
        $this->info('--- Stara baza (legacy) ---');

        $tables = [
            'user' => 'Użytkownicy',
            'client' => 'Klienci',
            'status' => 'Statusy',
            'planner' => 'Wizyty (planner)',
            'mail_account' => 'Konta mail',
            'mail_template' => 'Szablony mail',
        ];

        foreach ($tables as $table => $label) {
            try {
                $count = DB::connection('legacy')->table($table)->count();
                $this->line("  {$label} ({$table}): <info>{$count}</info>");
            } catch (\Throwable $e) {
                $this->line("  {$label}: <comment>brak tabeli</comment>");
            }
        }

        // Szczegóły planner
        try {
            $active = DB::connection('legacy')->table('planner')->where('trash', 0)->count();
            $trashed = DB::connection('legacy')->table('planner')->where('trash', 1)->count();
            $noUser = DB::connection('legacy')->table('planner')->where('trash', 0)->where(function ($q) {
                $q->whereNull('id_user')->orWhere('id_user', 0);
            })->count();
            $noClient = DB::connection('legacy')->table('planner')->where('trash', 0)->where(function ($q) {
                $q->whereNull('id_client')->orWhere('id_client', 0);
            })->count();
            $this->line("  planner aktywne: {$active}, w koszu: {$trashed}, bez user: {$noUser}, bez client: {$noClient}");
        } catch (\Throwable $e) {
            // ignore
        }

        $this->newLine();
    }

    private function verifyNew(): void
    {
        $this->info('--- Nowa baza ---');

        $this->line('  Użytkownicy (users): ' . User::count());
        $this->line('  Klienci (clients): ' . Client::count());
        $this->line('  Statusy (statuses): ' . Status::count());
        $this->line('  Wizyty (client_visits): ' . ClientVisit::count());

        // Problematyczne rekordy
        $noUser = ClientVisit::whereNull('user_id')->count();
        $noClient = ClientVisit::whereNull('client_id')->count();
        $noStatus = ClientVisit::whereNull('status_id')->count();

        if ($noUser > 0 || $noClient > 0 || $noStatus > 0) {
            $this->newLine();
            $this->warn('  Wizyty z brakującymi danymi:');
            if ($noUser > 0) {
                $this->line("    bez user_id: {$noUser} (uruchom: php artisan migrate:fix-orphaned-visits)");
            }
            if ($noClient > 0) {
                $this->line("    bez client_id: {$noClient}");
            }
            if ($noStatus > 0) {
                $this->line("    bez status_id: {$noStatus}");
            }
        }

        $this->newLine();
    }

    private function compareData(): void
    {
        $legacyUsers = DB::connection('legacy')->table('user')->count();
        $legacyClients = DB::connection('legacy')->table('client')->count();
        $legacyStatuses = DB::connection('legacy')->table('status')->count();
        $legacyPlannerActive = DB::connection('legacy')->table('planner')->where('trash', 0)->count();

        $newUsers = User::count();
        $newClients = Client::count();
        $newStatuses = Status::count();
        $newVisits = ClientVisit::count();

        $checks = [
            ['Użytkownicy', $legacyUsers, $newUsers],
            ['Klienci', $legacyClients, $newClients],
            ['Statusy', $legacyStatuses, $newStatuses],
            ['Wizyty (aktywne)', $legacyPlannerActive, $newVisits],
        ];

        $allOk = true;
        foreach ($checks as [$label, $old, $new]) {
            $status = $old === $new ? '✓' : '✗';
            $color = $old === $new ? 'info' : 'error';
            $this->line("  {$label}: stara={$old}, nowa={$new} [<{$color}>{$status}</{$color}>]");
            if ($old !== $new) {
                $allOk = false;
            }
        }

        if (!$allOk) {
            $this->newLine();
            $this->warn('Różnice wykryte. Możliwe przyczyny:');
            $this->line('  - Migracja nie została uruchomiona (php artisan migrate:legacy)');
            $this->line('  - Część rekordów pominięto (duplikaty, błędne dane)');
            $this->line('  - Wizyty bez user_id – uruchom: php artisan migrate:fix-orphaned-visits');
        } else {
            $this->newLine();
            $this->info('Wszystkie liczby się zgadzają.');
        }
    }
}
