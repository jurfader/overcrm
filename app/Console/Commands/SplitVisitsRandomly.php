<?php

namespace App\Console\Commands;

use App\Models\ClientVisit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rozdziela losowo wizyty source-usera między 3+ targetów.
 * Loguje mapowanie do storage/app/visit_split_<timestamp>.json (revert).
 *
 * Użycie:
 *   php artisan visits:split-randomly --source=3 --targets=13,14,15 --dry-run
 *   php artisan visits:split-randomly --source=3 --targets=13,14,15 --execute
 *   php artisan visits:revert-split --log=visit_split_2026-04-29_15-00-00.json
 */
class SplitVisitsRandomly extends Command
{
    protected $signature = 'visits:split-randomly
        {--source= : ID użytkownika źródłowego}
        {--targets= : ID userów docelowych, przecinkami (min. 2)}
        {--dry-run : Tylko pokazuje rozkład, nic nie zapisuje}
        {--execute : Wykonaj UPDATE w transakcji}
        {--seed=20260429 : Seed dla deterministycznego shuffle}
        {--include-trashed : Uwzględnij soft-deleted wizyty}';

    protected $description = 'Losowo rozdziela wizyty jednego usera między wielu docelowych — z logiem do revertu';

    public function handle(): int
    {
        $sourceId = (int) $this->option('source');
        $targetsRaw = (string) $this->option('targets');
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $seed = (int) $this->option('seed');
        $includeTrashed = (bool) $this->option('include-trashed');

        if (!$sourceId || !$targetsRaw) {
            $this->error('Podaj --source=ID i --targets=ID1,ID2,ID3');
            return self::FAILURE;
        }
        if (!$dryRun && !$execute) {
            $this->error('Wybierz --dry-run lub --execute');
            return self::FAILURE;
        }

        $targets = array_values(array_filter(array_map('intval', explode(',', $targetsRaw))));
        if (count($targets) < 2) {
            $this->error('Min. 2 targety wymagane');
            return self::FAILURE;
        }

        $source = User::find($sourceId);
        if (!$source) {
            $this->error("Source user {$sourceId} nie istnieje");
            return self::FAILURE;
        }
        foreach ($targets as $tid) {
            if (!User::find($tid)) {
                $this->error("Target user {$tid} nie istnieje");
                return self::FAILURE;
            }
        }
        if (in_array($sourceId, $targets, true)) {
            $this->error('Source nie może być na liście targetów');
            return self::FAILURE;
        }

        $query = ClientVisit::where('user_id', $sourceId);
        if ($includeTrashed) {
            $query->withTrashed();
        }
        $visitIds = $query->orderBy('id')->pluck('id')->all();
        $total = count($visitIds);

        if ($total === 0) {
            $this->warn("User {$sourceId} ({$source->name}) nie ma wizyt do rozdziału");
            return self::SUCCESS;
        }

        // Deterministyczny shuffle
        mt_srand($seed);
        for ($i = $total - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$visitIds[$i], $visitIds[$j]] = [$visitIds[$j], $visitIds[$i]];
        }

        // Round-robin po targetach: [v0→t0, v1→t1, v2→t2, v3→t0, ...]
        $assignment = [];
        foreach ($visitIds as $idx => $vid) {
            $assignment[$vid] = $targets[$idx % count($targets)];
        }

        $perTarget = array_count_values($assignment);
        $this->info("Source: #{$sourceId} ({$source->name}) — {$total} wizyt" . ($includeTrashed ? ' (+ trashed)' : ''));
        $this->newLine();
        $this->table(
            ['target_id', 'name', 'email', 'liczba'],
            array_map(function ($tid) use ($perTarget) {
                $u = User::find($tid);
                return [$tid, $u->name, $u->email, $perTarget[$tid] ?? 0];
            }, $targets)
        );

        if ($dryRun) {
            $this->info('DRY-RUN — zero zmian. Aby wykonać: --execute');
            return self::SUCCESS;
        }

        // Zapisz log PRZED zapisem (jeśli coś padnie, mamy mapowanie)
        $logName = 'visit_split_' . now()->format('Y-m-d_H-i-s') . '.json';
        $logPayload = [
            'source_id' => $sourceId,
            'targets' => $targets,
            'seed' => $seed,
            'include_trashed' => $includeTrashed,
            'created_at' => now()->toIso8601String(),
            'mapping' => array_map(
                fn($vid) => ['visit_id' => $vid, 'old_user_id' => $sourceId, 'new_user_id' => $assignment[$vid]],
                $visitIds
            ),
        ];
        Storage::disk('local')->put($logName, json_encode($logPayload, JSON_PRETTY_PRINT));
        $this->info("Log zapisany: storage/app/{$logName}");

        if (!$this->confirm("Wykonać UPDATE na {$total} wizytach?", false)) {
            $this->warn('Anulowane');
            return self::FAILURE;
        }

        DB::transaction(function () use ($assignment, $includeTrashed) {
            // Grupowanie po new_user_id → batch UPDATE WHERE id IN (...)
            $byTarget = [];
            foreach ($assignment as $vid => $newUid) {
                $byTarget[$newUid][] = $vid;
            }
            foreach ($byTarget as $newUid => $vids) {
                $q = ClientVisit::whereIn('id', $vids);
                if ($includeTrashed) $q->withTrashed();
                $q->update(['user_id' => $newUid]);
            }
        });

        $this->info("✓ Update wykonany. Aby cofnąć: php artisan visits:revert-split --log={$logName}");
        return self::SUCCESS;
    }
}
