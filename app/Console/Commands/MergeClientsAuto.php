<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Masowe scalanie duplikatów klientów po znormalizowanym NIP.
 *
 * Dla każdej grupy duplikatów o tym samym NIP:
 *   - wybiera "keep" = klient z największą liczbą wizyt (lub najstarszy przy remisie)
 *   - pozostałe scala z keep (zachowując wszystkie kontakty/adresy jak w clients:merge)
 *
 * Użycie:
 *   php artisan clients:merge-auto --dry-run    → tylko podgląd (zawsze rób to pierwsze!)
 *   php artisan clients:merge-auto              → wykona wszystko po potwierdzeniu
 */
class MergeClientsAuto extends Command
{
    protected $signature = 'clients:merge-auto
                            {--dry-run : Pokaż co zostanie zrobione bez zapisu}
                            {--limit=0 : Maksymalna liczba grup do przetworzenia (0 = wszystkie)}';

    protected $description = 'Masowo scala wszystkie duplikaty klientów po NIP (najlepszy keep = najwięcej wizyt)';

    public function handle(ClientMergeService $merger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Znajdź grupy duplikatów po znormalizowanym NIP
        $normalizeExpr = "REPLACE(REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', ''), '\t', '')";

        $groupsQuery = Client::selectRaw("$normalizeExpr AS norm_nip, COUNT(*) as c, GROUP_CONCAT(id ORDER BY id) as ids")
            ->whereNotNull('nip')
            ->where('nip', '!=', '')
            ->groupBy(DB::raw($normalizeExpr))
            ->havingRaw('c > 1')
            ->orderByDesc('c');

        if ($limit > 0) {
            $groupsQuery->limit($limit);
        }

        $groups = $groupsQuery->get();

        if ($groups->isEmpty()) {
            $this->info('Brak duplikatów do scalenia.');
            return self::SUCCESS;
        }

        $totalDups = $groups->sum(fn ($g) => $g->c - 1);
        $this->info("Znaleziono {$groups->count()} grup, łącznie {$totalDups} duplikatów do scalenia.");
        $this->line('');

        if (!$dryRun) {
            $this->warn('TO JEST OPERACJA MASOWA — zmodyfikuje setki rekordów.');
            if (!$this->confirm("Kontynuować masowe scalanie {$totalDups} duplikatów?", false)) {
                $this->info('Anulowano.');
                return self::SUCCESS;
            }
        }

        $bar = $this->output->createProgressBar($groups->count());
        $bar->start();

        $stats = [
            'groups_processed' => 0,
            'merges_done'      => 0,
            'merges_failed'    => 0,
            'relations_moved'  => 0,
            'overflow_lines'   => 0,
        ];

        foreach ($groups as $group) {
            $ids = array_map('intval', explode(',', $group->ids));

            // keep = klient z największą liczbą wizyt (przy remisie najstarszy)
            $candidates = Client::whereIn('id', $ids)
                ->withCount(['clientVisits'])
                ->orderByDesc('client_visits_count')
                ->orderBy('id')
                ->get();

            $keep = $candidates->first();
            $duplicates = $candidates->skip(1);

            foreach ($duplicates as $dup) {
                try {
                    $plan = $merger->plan($keep, $dup);
                    $stats['overflow_lines'] += count($plan['overflow']);

                    if (!$dryRun) {
                        $moved = $merger->execute($keep->fresh(), $dup);
                        $stats['relations_moved'] += $moved;
                    } else {
                        $stats['relations_moved'] += array_sum($plan['relations']);
                    }

                    $stats['merges_done']++;
                } catch (\Throwable $e) {
                    $stats['merges_failed']++;
                    $this->line("\n  <fg=red>BŁĄD merge {$keep->id} ← {$dup->id}: {$e->getMessage()}</>");
                }
            }

            $stats['groups_processed']++;
            $bar->advance();
        }

        $bar->finish();
        $this->line("\n");

        $this->info('=== PODSUMOWANIE ===');
        $this->line("  Grupy przetworzone:     {$stats['groups_processed']}");
        $this->line("  Scalenia wykonane:      {$stats['merges_done']}");
        $this->line("  Błędy scalania:         {$stats['merges_failed']}");
        $this->line("  Relacje przepięte:      {$stats['relations_moved']}");
        $this->line("  Nadmiarowe kontakty → notatki: {$stats['overflow_lines']}");

        if ($dryRun) {
            $this->line('');
            $this->warn('DRY-RUN — nic nie zostało zapisane. Uruchom bez --dry-run żeby wykonać.');
        }

        return self::SUCCESS;
    }
}
