<?php

namespace Modules\Ringostat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Ringostat\Models\RingostatCall;
use Modules\Ringostat\Services\CallAiAnalyzer;
use Modules\Ringostat\Services\GeminiCallAnalyzer;

class AnalyzeCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minut na job
    public int $tries = 1;    // nie ponawiaj (kosztowne API)

    public function __construct(
        public int $callId,
    ) {}

    public function handle(CallAiAnalyzer $analyzer): void
    {
        $call = RingostatCall::find($this->callId);

        if (!$call || !$call->has_recording || $call->ai_transcript) {
            return; // Już przeanalizowane lub brak nagrania
        }

        try {
            // GeminiCallAnalyzer ma DI (AiClientFactory) — provider wybierany globalnie
            // przez Settings (core.ai_provider), więc nie ma już potrzeby branchować Gemini/OpenAI.
            app(GeminiCallAnalyzer::class)->analyze($call);
            Log::info('Auto-analysis completed', ['call_id' => $call->call_id]);
        } catch (\Throwable $e) {
            Log::warning('Auto-analysis failed', [
                'call_id' => $call->call_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
