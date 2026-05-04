<?php

namespace App\Console\Commands;

use App\Models\ClientVisit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cofa rozdział wykonany przez visits:split-randomly z logu.
 * Idempotentne: jeśli wizyta już ma user_id != new_user_id z logu — pomija (nie nadpisuje cudzych zmian).
 */
class RevertVisitSplit extends Command
{
    protected $signature = 'visits:revert-split {--log= : Nazwa pliku logu w storage/app/}';
    protected $description = 'Cofa wcześniejszy split wizyt na podstawie zapisanego logu';

    public function handle(): int
    {
        $logName = (string) $this->option('log');
        if (!$logName) {
            $this->error('--log=visit_split_*.json wymagane');
            return self::FAILURE;
        }
        if (!Storage::disk('local')->exists($logName)) {
            $this->error("Log {$logName} nie istnieje");
            return self::FAILURE;
        }

        $payload = json_decode(Storage::disk('local')->get($logName), true);
        $sourceId = $payload['source_id'];
        $mapping = $payload['mapping'] ?? [];
        $includeTrashed = $payload['include_trashed'] ?? false;
        $total = count($mapping);

        $this->info("Revert: {$total} wizyt → user #{$sourceId}");
        if (!$this->confirm('Wykonać?', false)) {
            return self::FAILURE;
        }

        $reverted = 0;
        $skipped = 0;
        DB::transaction(function () use ($mapping, $sourceId, $includeTrashed, &$reverted, &$skipped) {
            foreach ($mapping as $entry) {
                $vid = $entry['visit_id'];
                $expectedUid = $entry['new_user_id'];

                $q = ClientVisit::where('id', $vid);
                if ($includeTrashed) $q->withTrashed();
                $visit = $q->first();
                if (!$visit) { $skipped++; continue; }
                if ((int) $visit->user_id !== (int) $expectedUid) {
                    // ktoś już zmienił przypisanie — nie ruszaj
                    $skipped++;
                    continue;
                }
                $visit->update(['user_id' => $sourceId]);
                $reverted++;
            }
        });

        $this->info("✓ Reverted: {$reverted}, Skipped (zmienione w międzyczasie): {$skipped}");
        return self::SUCCESS;
    }
}
