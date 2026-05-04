<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientMergeService;
use Illuminate\Console\Command;

/**
 * Scala dwóch klientów — pojedyncza operacja. Dla batch użyj clients:merge-auto.
 *
 * Użycie:
 *   php artisan clients:merge 123 456            → scali 456 → 123 (zachowa 123)
 *   php artisan clients:merge 123 456 --dry-run  → pokaże co się stanie bez zmian
 */
class MergeClients extends Command
{
    protected $signature = 'clients:merge
                            {keep : ID klienta do zachowania}
                            {duplicate : ID duplikatu do scalenia}
                            {--dry-run : Pokaż zmiany bez zapisu}';

    protected $description = 'Scala duplikat klienta do klienta głównego (zachowuje wszystkie kontakty i adresy)';

    public function handle(ClientMergeService $merger): int
    {
        $keepId = (int) $this->argument('keep');
        $dupId  = (int) $this->argument('duplicate');
        $dryRun = (bool) $this->option('dry-run');

        if ($keepId === $dupId) {
            $this->error('keep i duplicate to ten sam ID — nic do scalania.');
            return self::FAILURE;
        }

        // withTrashed: chcemy móc zmergować DO soft-deleted klienta (np. przywrócić historię)
        // oraz zmergować Z soft-deleted (usunąć zduplikowanego, nawet jeśli już jest usunięty)
        $keep = Client::withTrashed()->find($keepId);
        $dup  = Client::withTrashed()->find($dupId);

        if ($keep?->trashed()) {
            $this->warn("Keep ID {$keepId} jest soft-deleted — zostanie przywrócony.");
            if (!$this->option('dry-run')) {
                $keep->restore();
            }
        }

        if (!$keep) {
            $this->error("Klient keep (ID {$keepId}) nie istnieje.");
            return self::FAILURE;
        }
        if (!$dup) {
            $this->error("Klient duplicate (ID {$dupId}) nie istnieje.");
            return self::FAILURE;
        }

        $this->info("SCALANIE:");
        $this->line("  ZACHOWAĆ (keep):     [{$keep->id}] {$keep->name} (NIP: {$keep->nip})");
        $this->line("  USUNĄĆ (duplicate):  [{$dup->id}] {$dup->name} (NIP: {$dup->nip})");
        $this->line('');

        $plan = $merger->plan($keep, $dup);

        if (empty($plan['relations'])) {
            $this->line('  → brak relacji do przepięcia');
        } else {
            foreach ($plan['relations'] as $table => $cnt) {
                $this->line("  → {$table}: {$cnt} rekordów do przepięcia");
            }
        }

        foreach ($plan['patches'] as $field => $val) {
            $this->line("  → keep.{$field}: '{$val}'");
        }
        foreach ($plan['overflow'] as $line) {
            $this->line("  → do notes: {$line}");
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — nic nie zapisuję. Uruchom bez --dry-run aby wykonać scalanie.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Kontynuować scalanie?', false)) {
            $this->info('Anulowano.');
            return self::SUCCESS;
        }

        $moved = $merger->execute($keep, $dup);

        $this->info("✓ Scalanie zakończone. Przepięto {$moved} relacji. Duplikat [{$dupId}] usunięty (soft-delete).");
        return self::SUCCESS;
    }
}
