<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wzbogaca klientów w nowej bazie danymi ze starego planera.
 * NIE tworzy nowych — tylko wypełnia BRAKUJĄCE pola u istniejących.
 *
 * Match key (priorytet):
 *   1) NIP (znormalizowany)
 *   2) Email (jeśli match po NIP nie znalazł nic)
 *
 * Użycie:
 *   php artisan clients:enrich-from-legacy --dry-run        → pokaż co zmieni, bez zapisu
 *   php artisan clients:enrich-from-legacy                  → wykonaj
 *   php artisan clients:enrich-from-legacy --match=nip      → match TYLKO po NIP
 *   php artisan clients:enrich-from-legacy --verbose-diff   → drukuj każdą zmianę
 */
class EnrichClientsFromLegacy extends Command
{
    protected $signature = 'clients:enrich-from-legacy
                            {--dry-run : Tylko symulacja, bez zapisu}
                            {--match=both : Strategia dopasowania (nip|email|both)}
                            {--limit= : Limit klientów do przetworzenia (debug)}
                            {--verbose-diff : Drukuj każdą zmianę pola}
                            {--export-conflicts= : Ścieżka do CSV z listą konfliktów (różniące się wartości w nowej i starej bazie)}
                            {--export-unmatched= : Ścieżka do CSV z listą starych klientów których nie znaleziono w nowej bazie}';

    protected $description = 'Wzbogaca istniejących klientów danymi ze starej bazy planner.client (tylko brakujące pola)';

    /** Mapowanie kodów krajów na pełne nazwy */
    private array $countryMap = [
        'PL' => 'Polska', 'DE' => 'Niemcy', 'CZ' => 'Czechy', 'SK' => 'Słowacja',
        'LT' => 'Litwa',  'LV' => 'Łotwa',  'EE' => 'Estonia', 'UA' => 'Ukraina',
        'GB' => 'Wielka Brytania', 'US' => 'USA', 'FR' => 'Francja', 'IT' => 'Włochy',
        'ES' => 'Hiszpania', 'NL' => 'Holandia', 'BE' => 'Belgia',
    ];

    /** Statystyki */
    private array $stats = [
        'legacy_total'      => 0,
        'matched_by_nip'    => 0,
        'matched_by_email'  => 0,
        'no_match'          => 0,
        'no_changes'        => 0,
        'updated'           => 0,
        'conflicts'         => 0, // gdy nowa wartość różna od starej i niepusta — pomijamy, ale zliczamy
    ];

    /** Lista pól do wypełnienia: legacy_field => [new_field, transform_method?] */
    private array $fieldMap = [
        'mail'           => 'email',
        'phone'          => 'phone',
        'mobile_phone'   => 'phone2',
        'www'            => 'website',
        'firstname'      => null,        // budowane razem z lastname → contact_person
        'lastname'       => null,
        'street_name'    => 'street',
        // street_number → building_number + apartment_number (parser)
        'zip_code'       => 'postal_code',
        'city'           => 'city',
        'country'        => 'country',   // map kod → pełna nazwa
        'note'           => 'notes',
    ];

    public function handle(): int
    {
        $isDry = (bool) $this->option('dry-run');
        $matchStrategy = $this->option('match');
        if (!in_array($matchStrategy, ['nip', 'email', 'both'], true)) {
            $this->error("Niepoprawna strategia --match: {$matchStrategy} (dozwolone: nip, email, both)");
            return self::FAILURE;
        }
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $verbose = (bool) $this->option('verbose-diff');

        // 1. Test połączenia z legacy
        try {
            $count = DB::connection('legacy')->table('client')->count();
        } catch (\Throwable $e) {
            $this->error("Nie udało się połączyć z bazą legacy: " . $e->getMessage());
            $this->line('Sprawdź .env: LEGACY_DB_HOST, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME, LEGACY_DB_PASSWORD');
            return self::FAILURE;
        }

        if ($limit) {
            $count = min($count, $limit);
        }
        $this->stats['legacy_total'] = $count;

        $this->info(sprintf(
            'Wzbogacanie klientów ze starej bazy: %d rekordów · strategia=%s · %s',
            $count,
            $matchStrategy,
            $isDry ? 'DRY-RUN' : 'ZAPIS'
        ));
        $this->newLine();

        if (!$isDry && !$this->confirm('Kontynuować?', true)) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $diffs = [];                // do raportu na końcu
        $conflictRows = [];         // do CSV: [new_id, new_name, legacy_id, field, current, legacy]
        $unmatchedRows = [];        // do CSV: [legacy_id, vatin, name, email, phone, city]

        $exportConflicts = $this->option('export-conflicts');
        $exportUnmatched = $this->option('export-unmatched');

        $query = DB::connection('legacy')->table('client')->orderBy('id');
        if ($limit) {
            $query->limit($limit);
        }

        $query->chunk(500, function ($legacyClients) use ($isDry, $matchStrategy, $verbose, $bar, &$diffs, &$conflictRows, &$unmatchedRows, $exportConflicts, $exportUnmatched) {
            foreach ($legacyClients as $old) {
                $bar->advance();
                $client = $this->matchClient($old, $matchStrategy);
                if (!$client) {
                    $this->stats['no_match']++;
                    if ($exportUnmatched) {
                        $unmatchedRows[] = [
                            'legacy_id' => $old->id,
                            'vatin'     => $old->vatin ?? '',
                            'name'      => $old->name ?? '',
                            'email'     => $old->mail ?? '',
                            'phone'     => $old->phone ?? '',
                            'city'      => $old->city ?? '',
                        ];
                    }
                    continue;
                }

                $diff = $this->computeDiff($client, $old);
                if (empty($diff['fillable']) && empty($diff['conflicts'])) {
                    $this->stats['no_changes']++;
                    continue;
                }

                if (!empty($diff['conflicts'])) {
                    $this->stats['conflicts'] += count($diff['conflicts']);
                    if ($exportConflicts) {
                        foreach ($diff['conflicts'] as $field => $vals) {
                            $conflictRows[] = [
                                'new_id'    => $client->id,
                                'new_name'  => $client->name,
                                'legacy_id' => $old->id,
                                'field'     => $field,
                                'current'   => $vals['new'],
                                'legacy'    => $vals['legacy'],
                            ];
                        }
                    }
                }

                if (!empty($diff['fillable'])) {
                    if ($verbose) {
                        $this->newLine();
                        $this->line("→ Klient #{$client->id} ({$client->name}) ← legacy #{$old->id}");
                        foreach ($diff['fillable'] as $field => $val) {
                            $this->line("    + {$field}: " . $this->shortVal($val));
                        }
                    }

                    if (!$isDry) {
                        $client->fill($diff['fillable']);
                        $client->save();
                    }
                    $this->stats['updated']++;
                    $diffs[] = [
                        'new_id'    => $client->id,
                        'new_name'  => $client->name,
                        'legacy_id' => $old->id,
                        'fields'    => array_keys($diff['fillable']),
                    ];
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Eksporty CSV
        if ($exportConflicts && !empty($conflictRows)) {
            $this->writeCsv($exportConflicts, ['new_id', 'new_name', 'legacy_id', 'field', 'current', 'legacy'], $conflictRows);
            $this->info("✓ Konflikty wyeksportowane: {$exportConflicts} (".count($conflictRows)." wierszy)");
        } elseif ($exportConflicts) {
            $this->line("Brak konfliktów do eksportu — plik nie został utworzony.");
        }

        if ($exportUnmatched && !empty($unmatchedRows)) {
            $this->writeCsv($exportUnmatched, ['legacy_id', 'vatin', 'name', 'email', 'phone', 'city'], $unmatchedRows);
            $this->info("✓ Niezdopasowani wyeksportowani: {$exportUnmatched} (".count($unmatchedRows)." wierszy)");
        } elseif ($exportUnmatched) {
            $this->line("Wszyscy starzy klienci zostali zdopasowani — plik nie został utworzony.");
        }

        // Raport
        $this->printSummary($isDry, $diffs);

        return self::SUCCESS;
    }

    /**
     * Zapisz dane do CSV (separator ; — kompatybilność z Excelem PL, BOM dla UTF-8).
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fh = fopen($path, 'w');
        if (!$fh) {
            $this->error("Nie udało się otworzyć pliku do zapisu: {$path}");
            return;
        }
        // BOM dla Excela
        fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fh, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row), ';');
        }
        fclose($fh);
    }

    /**
     * Znajdź klienta w nowej bazie po NIP lub email.
     * Match po NIP (znormalizowanym) → email → null
     */
    private function matchClient($old, string $strategy): ?Client
    {
        $client = null;

        if ($strategy === 'nip' || $strategy === 'both') {
            $nip = $this->sanitizeNip($old->vatin ?? '');
            // NIP musi mieć minimum 7 znaków żeby był sensowny match
            if ($nip && strlen($nip) >= 7) {
                $client = Client::query()
                    ->whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$nip])
                    ->first();
                if ($client) {
                    $this->stats['matched_by_nip']++;
                    return $client;
                }
            }
        }

        if ($strategy === 'email' || $strategy === 'both') {
            $email = trim($old->mail ?? '');
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $client = Client::query()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                    ->first();
                if ($client) {
                    $this->stats['matched_by_email']++;
                    return $client;
                }
            }
        }

        return null;
    }

    /**
     * Oblicz co warto wypełnić w nowym kliencie:
     *   - fillable: pola PUSTE w nowym, ale niepuste w starym → kandydaci do uzupełnienia
     *   - conflicts: pola NIEPUSTE w nowym, różne od starego → pomijane (nie nadpisujemy)
     */
    private function computeDiff(Client $client, $old): array
    {
        $fillable = [];
        $conflicts = [];

        // Email
        $oldEmail = trim($old->mail ?? '');
        if ($oldEmail && filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
            $this->considerField($client, 'email', $oldEmail, $fillable, $conflicts);
        }

        // Telefony
        $phone  = $this->sanitizePhone($old->phone ?? '');
        $phone2 = $this->sanitizePhone($old->mobile_phone ?? '');
        if ($phone) {
            $this->considerField($client, 'phone', $phone, $fillable, $conflicts);
        }
        if ($phone2) {
            $this->considerField($client, 'phone2', $phone2, $fillable, $conflicts);
        }

        // WWW
        if (!empty($old->www)) {
            $this->considerField($client, 'website', trim($old->www), $fillable, $conflicts);
        }

        // NIP — wypełnij gdy nowy klient nie ma NIP (matching mógł być po email)
        $nip = $this->sanitizeNip($old->vatin ?? '');
        if ($nip) {
            $this->considerField($client, 'nip', $nip, $fillable, $conflicts);
        }

        // Adres
        $streetName   = trim($old->street_name ?? '');
        $streetNumber = trim($old->street_number ?? '');
        if ($streetName) {
            $this->considerField($client, 'street', $streetName, $fillable, $conflicts);
        }
        if ($streetNumber) {
            // Rozdziel "5/3" na building + apartment
            if (preg_match('/^(\d+[a-zA-Z]?)\s*[\/\\\\]\s*(\d+[a-zA-Z]?)$/', $streetNumber, $m)) {
                $this->considerField($client, 'building_number', $m[1], $fillable, $conflicts);
                $this->considerField($client, 'apartment_number', $m[2], $fillable, $conflicts);
            } else {
                $this->considerField($client, 'building_number', $streetNumber, $fillable, $conflicts);
            }
        }
        if (!empty($old->zip_code)) {
            $this->considerField($client, 'postal_code', trim($old->zip_code), $fillable, $conflicts);
        }
        if (!empty($old->city)) {
            $this->considerField($client, 'city', trim($old->city), $fillable, $conflicts);
        }
        if (!empty($old->country)) {
            $code = strtoupper(trim($old->country));
            $country = $this->countryMap[$code] ?? ($code === 'PO' ? 'Polska' : $old->country);
            $this->considerField($client, 'country', $country, $fillable, $conflicts);
        }

        // Contact person (firstname + lastname)
        $contactPerson = trim(($old->firstname ?? '') . ' ' . ($old->lastname ?? ''));
        if ($contactPerson !== '') {
            $this->considerField($client, 'contact_person', $contactPerson, $fillable, $conflicts);
        }

        // Notes — to specjalny przypadek: jeśli notatka starego nie jest już w nowej, dopisz na końcu (nie konflikt)
        $oldNote = trim($old->note ?? '');
        if ($oldNote !== '') {
            $existingNotes = trim($client->notes ?? '');
            if ($existingNotes === '') {
                $fillable['notes'] = $oldNote;
            } elseif (!Str::contains($existingNotes, $oldNote)) {
                // Dopisz starą notatkę na końcu z separatorem
                $fillable['notes'] = $existingNotes . "\n\n--- Z poprzedniego planera ---\n" . $oldNote;
            }
        }

        return ['fillable' => $fillable, 'conflicts' => $conflicts];
    }

    /**
     * Decyzja: jeśli pole w nowej bazie jest puste, dodaj do fillable.
     * Jeśli niepuste i różne od starej wartości — konflikt (pomijamy).
     */
    private function considerField(Client $client, string $field, $newValue, array &$fillable, array &$conflicts): void
    {
        $current = $client->{$field};
        $currentTrim = is_string($current) ? trim($current) : $current;

        if ($currentTrim === null || $currentTrim === '' || $currentTrim === '0') {
            $fillable[$field] = $newValue;
            return;
        }

        // Specjalny przypadek dla NIP: porównuj po normalizacji
        if ($field === 'nip') {
            $a = preg_replace('/[^0-9]/', '', (string) $current);
            $b = preg_replace('/[^0-9]/', '', (string) $newValue);
            if ($a === $b) {
                return; // identyczne po normalizacji — nic nie rób
            }
        }

        if (is_string($current) && mb_strtolower(trim($current)) !== mb_strtolower(trim((string) $newValue))) {
            $conflicts[$field] = ['new' => $current, 'legacy' => $newValue];
        }
    }

    /**
     * Sanityzacja telefonu — zostaw cyfry i +, max 20 znaków.
     */
    private function sanitizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '' || $phone === '-') {
            return null;
        }
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        if ($cleaned === '' || $cleaned === '+') {
            return null;
        }
        return Str::limit($cleaned, 20, '');
    }

    /**
     * Sanityzacja NIP — same cyfry, max 15 znaków.
     */
    private function sanitizeNip(string $nip): ?string
    {
        $nip = trim($nip);
        if ($nip === '') {
            return null;
        }
        // Usuń przedrostek "PL" (case-insensitive) jeśli na początku
        $nip = preg_replace('/^PL\s*/i', '', $nip);
        $cleaned = preg_replace('/[^\d]/', '', $nip);
        if ($cleaned === '') {
            return null;
        }
        return Str::limit($cleaned, 15, '');
    }

    /**
     * Skróć wartość do podglądu w terminalu.
     */
    private function shortVal($val): string
    {
        $s = is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE);
        return mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
    }

    private function printSummary(bool $isDry, array $diffs): void
    {
        $this->info('=== PODSUMOWANIE ===');
        $rows = [
            ['Klientów w starej bazie',     $this->stats['legacy_total']],
            ['Dopasowanych po NIP',         $this->stats['matched_by_nip']],
            ['Dopasowanych po email',       $this->stats['matched_by_email']],
            ['Nie znaleziono w nowej',      $this->stats['no_match']],
            ['Bez zmian (wszystko OK)',     $this->stats['no_changes']],
            ['Wzbogacono '.($isDry ? '(symulacja)' : '(zapisano)'), $this->stats['updated']],
            ['Konflikty (pomięte)',         $this->stats['conflicts']],
        ];
        $this->table(['Metryka', 'Liczba'], $rows);

        // Top 20 zmian
        if (count($diffs) > 0) {
            $this->newLine();
            $this->info('Pierwsze 20 wzbogaconych klientów:');
            $sample = array_slice($diffs, 0, 20);
            $tableRows = array_map(fn ($d) => [
                $d['new_id'],
                $d['new_name'],
                $d['legacy_id'],
                implode(', ', $d['fields']),
            ], $sample);
            $this->table(['ID nowy', 'Nazwa', 'ID stary', 'Wzbogacone pola'], $tableRows);
            if (count($diffs) > 20) {
                $this->line('... i ' . (count($diffs) - 20) . ' więcej');
            }
        }

        if ($isDry) {
            $this->newLine();
            $this->warn('DRY-RUN — żadnej zmiany nie zapisano. Uruchom bez --dry-run, aby zatwierdzić.');
        }
    }
}
