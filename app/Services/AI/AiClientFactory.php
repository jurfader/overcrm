<?php

namespace App\Services\AI;

use App\Models\Setting;

/**
 * Factory wybierający odpowiedniego klienta AI na podstawie Settings (`module=core`, `group=ai`):
 *   - ai_provider:    'gemini' | 'openai-compat'
 *   - ai_base_url:    np. https://llm.chickenking.co/v1
 *   - ai_api_key:     token (puste dla LM Studio)
 *   - ai_model:       np. google/gemma-4-e4b
 *   - ai_audio_model: model do transcription
 *
 * Use:
 *   $ai = app(AiClientFactory::class)->make();
 *   $text = $ai->chat($prompt);
 */
class AiClientFactory
{
    public function make(array $overrides = []): AiClient
    {
        $provider = $overrides['provider'] ?? Setting::get('ai_provider', 'gemini', 'core');

        if ($provider === 'openai-compat') {
            return new OpenAICompatClient(
                baseUrl:    $overrides['base_url']    ?? (string) Setting::get('ai_base_url', '', 'core'),
                apiKey:     $overrides['api_key']     ?? (string) Setting::get('ai_api_key', '', 'core'),
                model:      $overrides['model']       ?? (string) Setting::get('ai_model', 'gpt-4o-mini', 'core'),
                audioModel: $overrides['audio_model'] ?? (string) Setting::get('ai_audio_model', 'whisper-1', 'core'),
            );
        }

        // Gemini — klucz może być w Setting lub env (fallback dla istniejących configów)
        $apiKey = $overrides['api_key']
            ?? (string) Setting::get('ai_api_key', '', 'core');
        if ($apiKey === '') {
            $apiKey = (string) (config('gemini.api_key') ?: env('GEMINI_API_KEY', ''));
        }

        return new GeminiClient(
            apiKey:     $apiKey,
            model:      $overrides['model']       ?? (string) Setting::get('ai_model', 'gemini-2.5-flash', 'core'),
            audioModel: $overrides['audio_model'] ?? (string) Setting::get('ai_audio_model', 'gemini-2.5-flash', 'core'),
        );
    }

    /**
     * Klient AI specjalnie do AUDIO transcription. Może być inny niż text-AI
     * (np. LM Studio Gemma do tekstu + Whisper.cpp do audio).
     *
     * Setting `ai_audio_provider`:
     *   - 'same-as-text' (default) → make() — używa głównego providera
     *   - 'gemini' → wymusza Gemini (audio multimodal natywnie)
     *   - 'openai-compat' → osobny URL/key dla audio (whisper-1 endpoint)
     *   - 'whisper-cpp' → whisper.cpp server (POST /inference, nie OpenAI-compat)
     */
    public function makeForAudio(): AiClient
    {
        $audioProvider = (string) Setting::get('ai_audio_provider', 'same-as-text', 'core');

        if ($audioProvider === 'same-as-text') {
            return $this->make();
        }

        if ($audioProvider === 'gemini') {
            // Klucz Gemini z osobnego ai_audio_api_key, jeśli nie ma — fallback na główny
            $apiKey = (string) Setting::get('ai_audio_api_key', '', 'core');
            if ($apiKey === '') {
                $apiKey = (string) Setting::get('ai_api_key', '', 'core');
            }
            if ($apiKey === '') {
                $apiKey = (string) (config('gemini.api_key') ?: env('GEMINI_API_KEY', ''));
            }

            return new GeminiClient(
                apiKey: $apiKey,
                model: (string) Setting::get('ai_audio_model', 'gemini-2.5-flash', 'core'),
                audioModel: (string) Setting::get('ai_audio_model', 'gemini-2.5-flash', 'core'),
            );
        }

        if ($audioProvider === 'whisper-cpp') {
            $baseUrl = (string) Setting::get('ai_audio_base_url', '', 'core');
            if ($baseUrl === '') {
                $baseUrl = (string) Setting::get('ai_base_url', '', 'core');
            }
            $apiKey = (string) Setting::get('ai_audio_api_key', '', 'core');

            return new WhisperCppClient(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                audioModel: (string) Setting::get('ai_audio_model', 'whisper-large-v3', 'core'),
            );
        }

        // 'openai-compat' — osobny serwer (np. whisper-1 OpenAI-compat)
        $baseUrl = (string) Setting::get('ai_audio_base_url', '', 'core');
        if ($baseUrl === '') {
            $baseUrl = (string) Setting::get('ai_base_url', '', 'core');
        }
        $apiKey = (string) Setting::get('ai_audio_api_key', '', 'core');
        if ($apiKey === '') {
            $apiKey = (string) Setting::get('ai_api_key', '', 'core');
        }

        return new OpenAICompatClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            model: (string) Setting::get('ai_model', 'whisper-1', 'core'),
            audioModel: (string) Setting::get('ai_audio_model', 'whisper-1', 'core'),
        );
    }
}
