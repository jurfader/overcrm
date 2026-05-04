<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Ringostat\Models\RingostatCall;
use Modules\Ringostat\Services\CallAiAnalyzer;
use Modules\Ringostat\Services\RingostatService;

class RingostatSyncAndAnalyze extends Command
{
    protected $signature = 'ringostat:sync-analyze
        {--hours=6 : Ile godzin wstecz synchronizować}
        {--min-duration=60 : Minimalna długość rozmowy do analizy (sekundy)}
        {--limit=10 : Max rozmów do analizy w jednym uruchomieniu}
        {--sync-only : Tylko synchronizacja, bez analizy}';

    protected $description = 'Synchronizuj połączenia z Ringostat i automatycznie analizuj rozmowy AI';

    public function handle(): int
    {
        // 1. Synchronizacja połączeń
        $hours = (int) $this->option('hours');
        $from = now()->subHours($hours)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');

        $this->info("Synchronizacja połączeń z ostatnich {$hours}h...");

        try {
            $service = app(RingostatService::class);
            $calls = $service->getCalls($from, $to);
        } catch (\Throwable $e) {
            $this->error('Błąd pobierania połączeń: ' . $e->getMessage());
            return self::FAILURE;
        }

        $synced = 0;
        foreach ($calls as $callData) {
            $callId = $callData['globalSessionId'] ?? $callData['callSessionId'] ?? null;
            if (!$callId) continue;

            try {
                $callId = $callData['globalSessionId'] ?? $callData['callSessionId'] ?? $callId;
                $call = RingostatCall::updateOrCreate(
                    ['call_id' => $callId],
                    RingostatService::mapCallData($callData)
                );
                if (!$call->client_id) $call->matchClient();
                if (!$call->user_id) $call->matchUser();
                $synced++;
            } catch (\Throwable $e) {
                Log::warning('Ringostat sync error', ['call_id' => $callId, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Zsynchronizowano: {$synced} połączeń.");

        if ($this->option('sync-only')) {
            return self::SUCCESS;
        }

        // 2. Auto-analiza rozmów > min-duration z nagraniem, bez transkrypcji
        $minDuration = (int) $this->option('min-duration');
        $limit = (int) $this->option('limit');

        $toAnalyze = RingostatCall::whereNotNull('recording_url')
            ->whereNull('ai_transcript')
            ->where('billsec', '>=', $minDuration)
            ->orderBy('call_date', 'desc')
            ->limit($limit)
            ->get();

        if ($toAnalyze->isEmpty()) {
            $this->info('Brak rozmów do analizy.');
            return self::SUCCESS;
        }

        $this->info("Analiza {$toAnalyze->count()} rozmów (min {$minDuration}s)...");

        $analyzer = app(CallAiAnalyzer::class);
        $analyzed = 0;
        $failed = 0;

        foreach ($toAnalyze as $call) {
            $this->output->write("  [{$call->call_id}] {$call->employee_name} / {$call->billsec}s ... ");

            try {
                set_time_limit(300);
                $analyzer->analyze($call);
                $this->info('OK');
                $analyzed++;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                Log::warning('Auto-analysis failed', ['call_id' => $call->call_id, 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        $this->info("Przeanalizowano: {$analyzed}, błędy: {$failed}.");

        return self::SUCCESS;
    }
}
