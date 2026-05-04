<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient dla whisper.cpp server (whisper-server z repo ggerganov/whisper.cpp).
 * Natywny endpoint POST /inference (form-data: file, language, temperature, response_format).
 * Nie obsługuje OpenAI-compat /v1/audio/transcriptions.
 *
 * Klasa implementuje AiClient ale TYLKO transcribe() działa — pozostałe metody
 * rzucają wyjątek (whisper.cpp to model audio, nie LLM).
 */
class WhisperCppClient implements AiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey = '',
        private string $audioModel = 'whisper-large-v3',
        private int $timeout = 240,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getProviderName(): string
    {
        return 'Whisper.cpp';
    }

    public function getModel(): string
    {
        return $this->audioModel;
    }

    public function isReady(): bool
    {
        return $this->baseUrl !== '';
    }

    public function chat(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        throw new AiClientException('Whisper.cpp obsługuje tylko transkrypcję audio, nie chat.');
    }

    public function chatMessages(array $messages, array $options = []): string
    {
        throw new AiClientException('Whisper.cpp obsługuje tylko transkrypcję audio, nie chat.');
    }

    public function chatJson(string $prompt, ?string $systemPrompt = null, array $options = []): array
    {
        throw new AiClientException('Whisper.cpp obsługuje tylko transkrypcję audio, nie chat.');
    }

    public function transcribe(string $audioPath, ?string $language = null, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new AiClientException("Plik audio nie istnieje: {$audioPath}");
        }

        $request = Http::timeout($this->timeout)
            ->connectTimeout(15)
            ->attach('file', file_get_contents($audioPath), basename($audioPath));

        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $params = [
            'response_format' => 'json',
            'temperature' => (string) ($options['temperature'] ?? 0.0),
        ];
        if ($language !== null) {
            $params['language'] = $language;
        }

        $response = $request->post($this->baseUrl . '/inference', $params);

        if (!$response->successful()) {
            Log::warning('Whisper.cpp transcription error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
                'url' => $this->baseUrl,
            ]);
            throw new AiClientException(
                "Whisper.cpp transcription error (HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $body = trim($response->body());

        if (str_starts_with($body, '{')) {
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['text'])) {
                return trim($json['text']);
            }
        }

        return $body;
    }
}
