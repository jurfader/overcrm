<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Znajduje potencjalne duplikaty klientów — grupuje po znormalizowanym NIP
 * (usuwa myślniki/spacje/kropki, leading zeros) oraz opcjonalnie po znormalizowanej nazwie.
 * Nie modyfikuje bazy. Tylko wyświetla listę do ręcznego scalania przez clients:merge.
 */
class FindDuplicateClients extends Command
{
    protected $signature = 'clients:find-duplicates {--by=nip : Kryterium: nip|name}';
    protected $description = 'Wypisuje grupy zduplikowanych klientów (po NIP lub nazwie)';

    public function handle(): int
    {
        $by = $this->option('by');

        if ($by === 'nip') {
            return $this->findByNip();
        }

        if ($by === 'name') {
            return $this->findByName();
        }

        $this->error("Nieznane kryterium: {$by}. Użyj --by=nip lub --by=name");
        return self::FAILURE;
    }

    private function findByNip(): int
    {
        $normalizeExpr = "REPLACE(REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', ''), '\t', '')";

        $groups = Client::selectRaw("$normalizeExpr AS norm_nip, COUNT(*) as c, GROUP_CONCAT(id ORDER BY id) as ids")
            ->whereNotNull('nip')
            ->where('nip', '!=', '')
            ->groupBy(DB::raw($normalizeExpr))
            ->havingRaw('c > 1')
            ->orderByDesc('c')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('Brak duplikatów klientów po NIP.');
            return self::SUCCESS;
        }

        $totalDuplicates = 0;
        $this->info("Znaleziono {$groups->count()} grup duplikatów po NIP:\n");

        foreach ($groups as $group) {
            $ids = explode(',', $group->ids);
            $clients = Client::whereIn('id', $ids)
                ->withCount(['clientVisits'])
                ->orderBy('id')
                ->get();

            $this->line("<fg=yellow>NIP: {$group->norm_nip}</> ({$group->c} kopii)");

            $rows = $clients->map(fn ($c) => [
                'ID'       => $c->id,
                'Nazwa'    => mb_strimwidth($c->name, 0, 40, '...'),
                'Telefon'  => $c->phone ?: '—',
                'Email'    => $c->email ? mb_strimwidth($c->email, 0, 25, '...') : '—',
                'Wizyty'   => $c->client_visits_count ?? 0,
                'Status'   => $c->status,
                'Utw.'     => $c->created_at?->format('Y-m-d'),
            ])->toArray();

            $this->table(['ID', 'Nazwa', 'Telefon', 'Email', 'Wizyty', 'Status', 'Utw.'], $rows);

            $keepId = $clients->first()->id;
            $mergeIds = $clients->skip(1)->pluck('id')->implode(',');
            $this->line("  <fg=cyan>Sugerowane scalenie (zachowaj najstarszego):</>");
            foreach (explode(',', $mergeIds) as $dupId) {
                $this->line("    php artisan clients:merge {$keepId} {$dupId}");
            }
            $this->line('');

            $totalDuplicates += $group->c - 1;
        }

        $this->info("Łącznie {$totalDuplicates} duplikatów do scalenia.");
        return self::SUCCESS;
    }

    private function findByName(): int
    {
        $normalizeExpr = "LOWER(REPLACE(REPLACE(name, ' ', ''), 'sp. z o.o.', ''))";

        $groups = Client::selectRaw("$normalizeExpr AS norm_name, COUNT(*) as c, GROUP_CONCAT(id ORDER BY id) as ids")
            ->whereNotNull('name')
            ->groupBy(DB::raw($normalizeExpr))
            ->havingRaw('c > 1')
            ->orderByDesc('c')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('Brak duplikatów klientów po znormalizowanej nazwie.');
            return self::SUCCESS;
        }

        $this->info("Znaleziono {$groups->count()} grup duplikatów po nazwie:\n");
        $this->warn('UWAGA: dopasowanie po nazwie jest mniej pewne niż po NIP — przed scaleniem zweryfikuj ręcznie.');

        foreach ($groups as $group) {
            $ids = explode(',', $group->ids);
            $clients = Client::whereIn('id', $ids)->orderBy('id')->get();

            $this->line("<fg=yellow>Nazwa znormalizowana: {$group->norm_name}</> ({$group->c} kopii)");

            $rows = $clients->map(fn ($c) => [
                'ID'    => $c->id,
                'Nazwa' => mb_strimwidth($c->name, 0, 40, '...'),
                'NIP'   => $c->nip ?: '—',
                'Miasto' => $c->city ?: '—',
                'Status' => $c->status,
            ])->toArray();

            $this->table(['ID', 'Nazwa', 'NIP', 'Miasto', 'Status'], $rows);
            $this->line('');
        }

        return self::SUCCESS;
    }
}
