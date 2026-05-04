<?php

namespace App\Services\AI;

/**
 * Wspólny interfejs klienta AI — abstrakcja nad różnymi providerami.
 * Provider wybierany przez Settings (`core.ai_provider`):
 *   - 'gemini'         → GeminiClient
 *   - 'openai-compat'  → OpenAICompatClient (LM Studio, OpenAI, OpenRouter)
 */
interface AiClient
{
    /**
     * Generuj tekst odpowiedzi na prompt.
     *
     * @param array $options 'temperature' (float), 'max_tokens' (int), 'model' (override default)
     * @throws AiClientException
     */
    public function chat(string $prompt, ?string $systemPrompt = null, array $options = []): string;

    /**
     * Multi-turn chat — pełna historia konwersacji.
     *
     * @param array $messages Lista w formacie OpenAI: [['role' => 'user'|'assistant'|'system', 'content' => '...'], ...]
     * @param array $options  Jak w chat(). Plus 'system_prompt' jako shortcut.
     */
    public function chatMessages(array $messages, array $options = []): string;

    /**
     * Generuj odpowiedź jako JSON (structured output). Implementacja parsuje JSON z response.
     *
     * @throws AiClientException
     */
    public function chatJson(string $prompt, ?string $systemPrompt = null, array $options = []): array;

    /**
     * Transcrybuj audio na tekst.
     *
     * @param string|null $language Kod języka ('pl', 'en'). null = auto-detect
     * @throws AiClientException
     */
    public function transcribe(string $audioPath, ?string $language = null, array $options = []): string;

    public function isReady(): bool;
    public function getProviderName(): string;
    public function getModel(): string;
}
