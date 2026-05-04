<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient Google Gemini API.
 * Endpoint: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 */
class GeminiClient implements AiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.5-flash',
        private string $audioModel = 'gemini-2.5-flash',
        private int $timeout = 120,
    ) {}

    public function getProviderName(): string { return 'Google Gemini'; }
    public function getModel(): string { return $this->model; }
    public function isReady(): bool { return $this->apiKey !== ''; }

    public function chat(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        return $this->chatMessages(
            [['role' => 'user', 'content' => $prompt]],
            array_merge($options, ['system_prompt' => $systemPrompt])
        );
    }

    public function chatMessages(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $url = self::BASE_URL . "/models/{$model}:generateContent?key=" . urlencode($this->apiKey);

        // Konwertuj messages OpenAI format → Gemini contents format
        $systemPrompt = $options['system_prompt'] ?? null;
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                // Gemini ma osobny systemInstruction — przekaż przez params
                $systemPrompt = ($systemPrompt ? $systemPrompt . "\n" : '') . ($m['content'] ?? '');
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content'] ?? '']],
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 2048,
            ],
        ];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $response = Http::timeout($this->timeout)->connectTimeout(10)->acceptJson()->post($url, $body);

        if (!$response->successful()) {
            Log::warning('Gemini chat error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
                'model' => $model,
            ]);
            throw new AiClientException(
                "Gemini error (HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ($text === '') {
            throw new AiClientException("Gemini zwrócił pustą odpowiedź. Response: " . substr(json_encode($data), 0, 300));
        }

        return $text;
    }

    public function chatJson(string $prompt, ?string $systemPrompt = null, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $url = self::BASE_URL . "/models/{$model}:generateContent?key=" . urlencode($this->apiKey);

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.3,
                'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                'responseMimeType' => 'application/json',
            ],
        ];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $response = Http::timeout($this->timeout)->connectTimeout(10)->acceptJson()->post($url, $body);

        if (!$response->successful()) {
            throw new AiClientException(
                "Gemini JSON error (HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            if (preg_match('/```(?:json)?\s*(\{.+?\}|\[.+?\])\s*```/s', $text, $m)) {
                $decoded = json_decode($m[1], true);
            } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (!is_array($decoded)) {
            throw new AiClientException("Gemini zwrócił niepoprawny JSON: " . substr($text, 0, 300));
        }

        return $decoded;
    }

    public function transcribe(string $audioPath, ?string $language = null, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new AiClientException("Plik audio nie istnieje: {$audioPath}");
        }

        $model = $options['model'] ?? $this->audioModel;
        $url = self::BASE_URL . "/models/{$model}:generateContent?key=" . urlencode($this->apiKey);

        $audioBase64 = base64_encode(file_get_contents($audioPath));
        $mimeType = $this->detectMimeType($audioPath);

        $instruction = 'Sczytaj treść tej rozmowy telefonicznej i zwróć tylko tekst (bez timestampów, bez dodatkowego komentarza).';
        if ($language === 'pl') {
            $instruction = 'Sczytaj treść tej rozmowy telefonicznej w języku polskim i zwróć tylko transkrypcję — bez timestampów, bez komentarzy.';
        }

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $instruction],
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => $audioBase64]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => $options['max_tokens'] ?? 8192,
            ],
        ];

        $response = Http::timeout($this->timeout * 2)->connectTimeout(10)->acceptJson()->post($url, $body);

        if (!$response->successful()) {
            throw new AiClientException(
                "Gemini transcription error (HTTP {$response->status()}): " . substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();
        return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    private function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3' => 'audio/mp3',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }
}
