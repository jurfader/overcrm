<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Dodaje opcję 'whisper-cpp' do dropdownu ai_audio_provider w Admin → Moduły → Core → AI.
 * Whisper.cpp używa natywnego endpointu /inference (form-data), nie /v1/audio/transcriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::where('module', 'core')->where('key', 'ai_audio_provider')->first();
        if (!$setting) return;

        $setting->options = [
            'same-as-text' => 'Taki sam jak dla tekstu (ai_provider)',
            'gemini' => 'Google Gemini (multimodal audio)',
            'openai-compat' => 'OpenAI-compat (whisper-1 endpoint)',
            'whisper-cpp' => 'Whisper.cpp server (/inference)',
        ];
        $setting->save();
    }

    public function down(): void
    {
        $setting = Setting::where('module', 'core')->where('key', 'ai_audio_provider')->first();
        if (!$setting) return;

        $setting->options = [
            'same-as-text' => 'Taki sam jak dla tekstu (ai_provider)',
            'gemini' => 'Google Gemini (multimodal audio)',
            'openai-compat' => 'Osobny serwer OpenAI-compat (np. whisper w LM Studio)',
        ];
        $setting->save();
    }
};
