<?php

namespace Modules\PlayCentrala\Commands;

use Illuminate\Console\Command;
use Modules\PlayCentrala\Models\RingostatCall;
use Modules\PlayCentrala\Services\RingostatService;
use Illuminate\Support\Facades\Log;

class SyncRingostatCalls extends Command
{
    protected $signature = 'ringostat:sync-calls
        {--hours=24 : Ile godzin wstecz synchronizować}
        {--rematch : Ponownie dopasuj client_id i user_id dla wszystkich połączeń}';

    protected $description = 'Synchronizuj połączenia z Play Wirtualna Centralka API';

    public function handle(RingostatService $service): int
    {
        if (!$service->isConfigured()) {
            $this->warn('Play API nie jest skonfigurowane. Ustaw Client ID i Client Secret w ustawieniach.');
            return self::SUCCESS;
        }

        $hours = (int) $this->option('hours');
        $from  = now()->subHours($hours)->format('Y-m-d H:i:s');
        $to    = now()->format('Y-m-d H:i:s');

        $this->info("Pobieranie połączeń z ostatnich {$hours}h ({$from} — {$to})...");

        try {
            $calls = $service->getAllCalls($from, $to);
        } catch (\Exception $e) {
            $this->error('Błąd pobierania z Play API: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (empty($calls)) {
            $this->info('Brak nowych połączeń.');
            return self::SUCCESS;
        }

        $this->info('Pobrano ' . count($calls) . ' połączeń. Synchronizacja...');

        $bar    = $this->output->createProgressBar(count($calls));
        $synced = 0;
        $errors = 0;

        foreach ($calls as $callData) {
            $callId = $callData['globalSessionId'] ?? $callData['callSessionId'] ?? null;
            if (!$callId) {
                $bar->advance();
                continue;
            }

            try {
                $call = RingostatCall::updateOrCreate(
                    ['call_id' => $callId],
                    RingostatService::mapCallData($callData)
                );
                if (!$call->client_id) $call->matchClient();
                if (!$call->user_id)   $call->matchUser();
                $synced++;
            } catch (\Exception $e) {
                $errors++;
                Log::error('Play sync error', ['call_id' => $callId, 'error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($this->option('rematch')) {
            $this->info('Ponowne dopasowanie klientów i pracowników...');
            RingostatCall::chunk(100, function ($chunk) {
                foreach ($chunk as $call) {
                    $call->matchClient();
                    $call->matchUser();
                }
            });
            $this->info('Dopasowanie zakończone.');
        }

        $this->info("Zsynchronizowano: {$synced}" . ($errors > 0 ? ", błędy: {$errors}" : ''));

        return self::SUCCESS;
    }
}
