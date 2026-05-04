<?php

namespace App\Console\Commands;

use App\Models\ClientVisit;
use App\Services\VisitPhoneExtractor;
use Illuminate\Console\Command;

/**
 * Dla każdej wizyty z pustym `phones` przepuszcza description przez VisitPhoneExtractor
 * i zapisuje wyciągnięte numery w phones + phones_normalized.
 *
 *   php artisan visits:extract-phones --dry-run
 *   php artisan visits:extract-phones
 *   php artisan visits:extract-phones --all   (nadpisze też wizyty które już mają phones)
 *   php artisan visits:extract-phones --sync  (po wyciągnięciu — sync do klienta)
 */
class ExtractVisitPhones extends Command
{
    protected $signature = 'visits:extract-phones
                            {--dry-run : Pokaż co zostanie zaktualizowane bez zapisu}
                            {--all : Przetwarzaj też wizyty które już mają phones}
                            {--sync : Po wyciągnięciu sync numerów do klienta}';

    protected $description = 'Wyciąga numery telefonów z opisu wizyt (regex) i zapisuje w pole phones';

    public function handle(VisitPhoneExtractor $extractor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $all    = (bool) $this->option('all');
        $sync   = (bool) $this->option('sync');

        $query = ClientVisit::query()
            ->whereNotNull('description')
            ->where('description', '!=', '');

        if (!$all) {
            $query->where(function ($q) {
                $q->whereNull('phones')
                  ->orWhereRaw("JSON_LENGTH(phones) = 0");
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Brak wizyt do przetworzenia.');
            return self::SUCCESS;
        }

        $this->info("Do przetworzenia: {$total} wizyt" . ($dryRun ? ' (DRY-RUN)' : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = ['updated' => 0, 'phones_found' => 0, 'no_phones' => 0, 'synced_to_client' => 0];

        $query->chunkById(200, function ($visits) use (&$stats, $extractor, $dryRun, $sync, $bar) {
            foreach ($visits as $visit) {
                $existing = is_array($visit->phones) ? $visit->phones : [];
                $extracted = $extractor->extract($visit->description ?? '');

                // Dedup po znormalizowanym numerze
                $seen = [];
                foreach ($existing as $p) {
                    $seen[ClientVisit::normalizePhone($p)] = true;
                }
                $merged = $existing;
                foreach ($extracted as $phone) {
                    $key = ClientVisit::normalizePhone($phone);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $merged[] = $phone;
                }

                if (empty($merged)) {
                    $stats['no_phones']++;
                    $bar->advance();
                    continue;
                }

                if (count($merged) === count($existing)) {
                    // Nic nowego, tylko rebuild phones_normalized jeśli puste
                    if (!$dryRun && empty($visit->phones_normalized)) {
                        $visit->update([
                            'phones_normalized' => ClientVisit::buildNormalizedPhones($merged),
                        ]);
                    }
                    $bar->advance();
                    continue;
                }

                $stats['updated']++;
                $stats['phones_found'] += count($merged) - count($existing);

                if (!$dryRun) {
                    $visit->update([
                        'phones' => $merged,
                        'phones_normalized' => ClientVisit::buildNormalizedPhones($merged),
                    ]);

                    if ($sync && $visit->client_id) {
                        app(\App\Http\Controllers\CalendarController::class);
                        // Wywołanie prywatnej metody przez refleksję byłoby hackiem —
                        // zamiast tego zrobię prostą inline logikę sync
                        $this->syncToClient($visit->client_id, $merged);
                        $stats['synced_to_client']++;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line("\n");
        $this->info('=== PODSUMOWANIE ===');
        $this->line("  Wizyty zaktualizowane:      {$stats['updated']}");
        $this->line("  Nowe numery wyciągnięte:    {$stats['phones_found']}");
        $this->line("  Wizyty bez wykrytych num.:  {$stats['no_phones']}");
        if ($sync) {
            $this->line("  Sync do klienta:            {$stats['synced_to_client']}");
        }

        if ($dryRun) {
            $this->line('');
            $this->warn('DRY-RUN — nic nie zostało zapisane. Uruchom bez --dry-run aby wykonać.');
        } else {
            $this->line('');
            $this->info('Teraz uruchom: php artisan ringostat:match-visits — żeby dopasować połączenia do tych wizyt.');
        }

        return self::SUCCESS;
    }

    private function syncToClient(int $clientId, array $phones): void
    {
        $client = \App\Models\Client::find($clientId);
        if (!$client) return;

        $existing = [];
        foreach (['phone', 'phone2', 'contact_phone'] as $slot) {
            if (!empty($client->$slot)) {
                $existing[ClientVisit::normalizePhone($client->$slot)] = true;
            }
        }

        $patches = [];
        foreach ($phones as $phone) {
            $key = ClientVisit::normalizePhone($phone);
            if (isset($existing[$key])) continue;

            foreach (['phone', 'phone2', 'contact_phone'] as $slot) {
                if (empty($client->$slot) && !isset($patches[$slot])) {
                    $patches[$slot] = $phone;
                    $existing[$key] = true;
                    break;
                }
            }
        }

        if (!empty($patches)) {
            $client->update($patches);
        }
    }
}
