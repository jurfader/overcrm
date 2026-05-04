<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Dodaje globalne ustawienia AI provider (Gemini / OpenAI-compatible).
 * Po dodaniu admin może w UI Admin → Moduły → Core → AI ustawić:
 *   - ai_provider: 'gemini' lub 'openai-compat'
 *   - ai_base_url: np. https://llm.chickenking.co/v1 (LM Studio) albo https://api.openai.com/v1
 *   - ai_api_key: token (LM Studio może zostawić puste)
 *   - ai_model: nazwa modelu
 *   - ai_audio_model: model do transcription
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_provider',
                'value' => 'gemini',
                'type' => 'select',
                'label' => 'Provider AI',
                'description' => 'Który serwer AI ma być używany do analiz, scoringu, podsumowań',
                'options' => [
                    'gemini' => 'Google Gemini (chmura)',
                    'openai-compat' => 'OpenAI-compatible (LM Studio, OpenAI, własny)',
                ],
                'is_public' => false,
                'order' => 10,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_base_url',
                'value' => '',
                'type' => 'string',
                'label' => 'Base URL (dla OpenAI-compat)',
                'description' => 'np. https://llm.chickenking.co/v1 lub https://api.openai.com/v1. Zostaw puste dla Gemini.',
                'is_public' => false,
                'order' => 11,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_api_key',
                'value' => '',
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Klucz API. Dla LM Studio zostaw puste. Dla OpenAI sk-...',
                'is_public' => false,
                'order' => 12,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_model',
                'value' => 'gemini-2.5-flash',
                'type' => 'string',
                'label' => 'Model — czat / analiza',
                'description' => 'Nazwa modelu (np. gemini-2.5-flash, google/gemma-4-e4b, gpt-4o-mini, llama-3.1-8b-instruct)',
                'is_public' => false,
                'order' => 13,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_audio_model',
                'value' => 'gemini-2.5-flash',
                'type' => 'string',
                'label' => 'Model — transcription audio',
                'description' => 'Model do transcribowania nagrań Play Centrali. Gemini Flash obsługuje audio bezpośrednio. Dla LM Studio: whisper-large-v3.',
                'is_public' => false,
                'order' => 14,
            ],
        ];

        foreach ($settings as $cfg) {
            Setting::firstOrCreate(
                ['module' => $cfg['module'], 'key' => $cfg['key']],
                $cfg
            );
        }
    }

    public function down(): void
    {
        Setting::where('module', 'core')
            ->whereIn('key', ['ai_provider', 'ai_base_url', 'ai_api_key', 'ai_model', 'ai_audio_model'])
            ->delete();
    }
};
