<?php

namespace Modules\Ringostat\Commands;

use Illuminate\Console\Command;
use Modules\Ringostat\Models\RingostatCall;

/**
 * Retroaktywnie matchuje istniejące ringostat_calls z wizytami (client_visits.phones_normalized).
 * Uzupełnia visit_id dla połączeń które miały numer pasujący do wizyty w kalendarzu.
 *
 *   php artisan ringostat:match-visits --dry-run
 *   php artisan ringostat:match-visits
 *   php artisan ringostat:match-visits --limit=100
 *   php artisan ringostat:match-visits --all   (nawet te co mają już visit_id — ponowne matchowanie)
 */
class MatchVisits extends Command
{
    protected $signature = 'ringostat:match-visits
                            {--dry-run : Pokaż co zostanie zaktualizowane bez zapisu}
                            {--limit=0 : Maksymalna liczba połączeń do przetworzenia (0 = wszystkie)}
                            {--all : Przetwarzaj też połączenia które już mają visit_id}';

    protected $description = 'Retroaktywny match ringostat_calls z wizytami po numerze telefonu';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');
        $all    = (bool) $this->option('all');

        $query = RingostatCall::query()
            ->where(function ($q) {
                $q->whereNotNull('caller')->where('caller', '!=', '')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('destination')->where('destination', '!=', '');
                  });
            });

        if (!$all) {
            $query->whereNull('visit_id');
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Brak połączeń do przetworzenia.');
            return self::SUCCESS;
        }

        $this->info("Do przetworzenia: {$total} połączeń" . ($dryRun ? ' (DRY-RUN)' : ''));

        if ($limit > 0) $query->limit($limit);

        $bar = $this->output->createProgressBar(min($total, $limit ?: $total));
        $bar->start();

        $stats = ['matched' => 0, 'no_match' => 0, 'client_updated' => 0];

        $query->chunkById(200, function ($calls) use (&$stats, $dryRun, $bar) {
            foreach ($calls as $call) {
                $visit = $call->matchVisit();
                if ($visit) {
                    $stats['matched']++;
                    if (!$dryRun) {
                        $updates = ['visit_id' => $visit->id];
                        if (!$call->client_id && $visit->client_id) {
                            $updates['client_id'] = $visit->client_id;
                            $stats['client_updated']++;
                        }
                        $call->update($updates);
                    }
                } else {
                    $stats['no_match']++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->line("\n");
        $this->info('=== PODSUMOWANIE ===');
        $this->line("  Dopasowane do wizyty:  {$stats['matched']}");
        $this->line("  Brak dopasowania:      {$stats['no_match']}");
        $this->line("  + client_id ustawiony: {$stats['client_updated']}");

        if ($dryRun) {
            $this->line('');
            $this->warn('DRY-RUN — nic nie zostało zapisane. Uruchom bez --dry-run aby wykonać.');
        }

        return self::SUCCESS;
    }
}
