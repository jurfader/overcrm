<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Hybrid mode AI: tekstowe analizy przez jednego providera (np. LM Studio Gemma),
 * audio transcription przez innego (np. Gemini, bo LM Studio nie ma whispera).
 *
 * Nowe settings:
 *   - ai_audio_provider: 'same-as-text' | 'gemini' | 'openai-compat'
 *   - ai_audio_base_url: URL dla openai-compat audio (jeśli różny od głównego)
 *   - ai_audio_api_key: klucz dla audio (zwykle Gemini key)
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_audio_provider',
                'value' => 'same-as-text',
                'type' => 'select',
                'label' => 'Provider AI — audio (transcription)',
                'description' => 'Osobny provider dla transkrypcji rozmów. Gemma/Llama nie obsługują audio. Wybierz "Gemini" jeśli LM Studio nie ma whispera.',
                'options' => [
                    'same-as-text' => 'Taki sam jak dla tekstu (ai_provider)',
                    'gemini' => 'Google Gemini (multimodal audio)',
                    'openai-compat' => 'Osobny serwer OpenAI-compat (np. whisper w LM Studio)',
                ],
                'is_public' => false,
                'order' => 15,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_audio_base_url',
                'value' => '',
                'type' => 'string',
                'label' => 'Audio Base URL (opcjonalne)',
                'description' => 'Jeśli audio przez osobny serwer OpenAI-compat. Inaczej zostaw puste — użyje ai_base_url.',
                'is_public' => false,
                'order' => 16,
            ],
            [
                'module' => 'core',
                'group' => 'ai',
                'key' => 'ai_audio_api_key',
                'value' => '',
                'type' => 'password',
                'label' => 'Audio API Key (opcjonalne)',
                'description' => 'Klucz API dla audio (np. Gemini API Key). Jeśli puste, użyje ai_api_key.',
                'is_public' => false,
                'order' => 17,
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
            ->whereIn('key', ['ai_audio_provider', 'ai_audio_base_url', 'ai_audio_api_key'])
            ->delete();
    }
};
