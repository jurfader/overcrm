<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient kompatybilny z OpenAI API.
 * Działa z: LM Studio, OpenAI, OpenRouter, dowolnym providerem implementującym /v1/chat/completions.
 */
class OpenAICompatClient implements AiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private string $audioModel = 'whisper-large-v3',
        private int $timeout = 120,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getProviderName(): string
    {
        if (str_contains($this->baseUrl, 'openai.com')) return 'OpenAI';
        if (str_contains($this->baseUrl, 'llm.chickenking') || str_contains($this->baseUrl, 'localhost') || str_contains($this->baseUrl, '127.0.0.1')) {
            return 'LM Studio';
        }
        if (str_contains($this->baseUrl, 'openrouter')) return 'OpenRouter';
        return 'OpenAI-compatible';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isReady(): bool
    {
        return $this->baseUrl !== '' && $this->model !== '';
    }

    public function chat(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $messages = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chatMessages($messages, $options);
    }

    public function chatMessages(array $messages, array $options = []): string
    {
        // Opcjonalny system_prompt jako shortcut — wstaw na początek jeśli brak system role
        if (!empty($options['system_prompt'])) {
            $hasSystem = false;
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (!$hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $options['system_prompt']]);
            }
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            // Reasoning models (Gemma 3 thinking, DeepSeek-R1) wymagają znacznie więcej tokens
            // bo CoT zjada kilkaset—kilka tysięcy. Default 4096 z marginesem.
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        $response = $this->httpClient()->post($this->baseUrl . '/chat/completions', $body);

        if (!$response->successful()) {
            Log::warning('OpenAI-compat chat error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
                'url' => $this->baseUrl,
                'model' => $this->model,
            ]);
            throw new AiClientException(
                "AI provider error ({$this->getProviderName()}, HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? [];
        $text = $message['content'] ?? '';
        $reasoning = $message['reasoning_content'] ?? '';
        $finishReason = $data['choices'][0]['finish_reason'] ?? '';

        // Reasoning models (Gemma 3 thinking) odkładają chain-of-thought w 'reasoning_content',
        // a faktyczną odpowiedź w 'content'. Gdy max_tokens za małe, model nie zdąży skończyć
        // thinking i 'content' zostaje puste.
        if ($text === '' && $reasoning !== '' && $finishReason === 'length') {
            throw new AiClientException(
                "Model {$this->getModel()} wyczerpał limit tokenów w trakcie 'myślenia' (reasoning chain-of-thought). " .
                "Zwiększ max_tokens (obecnie " . ($options['max_tokens'] ?? 4096) . ") lub przełącz na model bez thinking mode."
            );
        }

        if ($text === '' && $reasoning !== '') {
            // Model skończył reasoning ale nie wygenerował odpowiedzi w content. Zwróć reasoning jako fallback
            // (bywa że Gemma w finish_reason='stop' też tak robi przy krótkich promptach).
            return trim($reasoning);
        }

        if ($text === '') {
            throw new AiClientException(
                "AI provider zwrócił pustą odpowiedź (finish_reason: {$finishReason}). " .
                "Response: " . substr(json_encode($data), 0, 300)
            );
        }

        return $text;
    }

    public function chatJson(string $prompt, ?string $systemPrompt = null, array $options = []): array
    {
        $messages = [];
        $jsonInstruction = "\n\nZawsze odpowiadaj WYŁĄCZNIE jako poprawny JSON, bez markdown, bez wyjaśnień.";
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt . $jsonInstruction];
        } else {
            $messages[] = ['role' => 'system', 'content' => trim($jsonInstruction)];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->httpClient()->post($this->baseUrl . '/chat/completions', $body);

        // Niektóre lokalne modele (Gemma) nie obsługują response_format → spróbuj bez
        if (!$response->successful() && str_contains($response->body(), 'response_format')) {
            unset($body['response_format']);
            $response = $this->httpClient()->post($this->baseUrl . '/chat/completions', $body);
        }

        if (!$response->successful()) {
            throw new AiClientException(
                "AI JSON error ({$this->getProviderName()}, HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? [];
        $text = $message['content'] ?? '';
        $reasoning = $message['reasoning_content'] ?? '';
        $finishReason = $data['choices'][0]['finish_reason'] ?? '';

        // Reasoning models (Gemma 3, DeepSeek-R1) — gdy content puste, JSON może siedzieć w reasoning_content
        if ($text === '' && $reasoning !== '') {
            $text = $reasoning;
        }

        $json = $this->extractJson($text);
        if ($json === null) {
            // Spróbuj jeszcze raz w reasoning_content jeśli content nie zawierał poprawnego JSON
            if ($reasoning !== '' && $text !== $reasoning) {
                $json = $this->extractJson($reasoning);
            }
        }

        if ($json === null) {
            $debug = $text === '' ? "(content empty, reasoning len: " . strlen($reasoning) . ")" : substr($text, 0, 300);
            throw new AiClientException(
                "AI zwrócił niepoprawny JSON (finish_reason: {$finishReason}): " . $debug
            );
        }

        return $json;
    }

    public function transcribe(string $audioPath, ?string $language = null, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new AiClientException("Plik audio nie istnieje: {$audioPath}");
        }

        $request = Http::timeout($this->timeout * 2)
            ->connectTimeout(15)
            ->attach('file', file_get_contents($audioPath), basename($audioPath));

        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $params = [
            'model' => $options['model'] ?? $this->audioModel,
            'response_format' => 'text',
        ];
        if ($language !== null) {
            $params['language'] = $language;
        }

        $response = $request->post($this->baseUrl . '/audio/transcriptions', $params);

        if (!$response->successful()) {
            throw new AiClientException(
                "AI transcription error ({$this->getProviderName()}, HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $text = trim($response->body());

        // Niektóre serwery zwracają JSON nawet z response_format=text
        if (str_starts_with($text, '{')) {
            $json = json_decode($text, true);
            if (is_array($json) && isset($json['text'])) {
                return trim($json['text']);
            }
        }

        return $text;
    }

    private function httpClient()
    {
        $http = Http::timeout($this->timeout)
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json']);

        if ($this->apiKey !== '') {
            $http = $http->withToken($this->apiKey);
        }

        return $http;
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/```(?:json)?\s*(\{.+?\}|\[.+?\])\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }

        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }
}
