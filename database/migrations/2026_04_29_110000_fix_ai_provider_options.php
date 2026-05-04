<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix dla migracji 2026_04_29_100000: 'options' było zapisane jako json_encode(string),
 * ale Setting model castuje 'options' => 'array', więc Eloquent drugi raz to enkodował
 * → w bazie sat string '\"{\\\"gemini\\\":\\\"Google...\\\"}\"' i UI renderował literę-po-literze.
 *
 * Naprawia istniejący rekord wpisując options bezpośrednio jako array (Eloquent
 * sam zrobi json_encode przy zapisie).
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::where('module', 'core')->where('key', 'ai_provider')->first();
        if ($setting) {
            $setting->options = [
                'gemini' => 'Google Gemini (chmura)',
                'openai-compat' => 'OpenAI-compatible (LM Studio, OpenAI, własny)',
            ];
            $setting->save();
        }

        // Wyczyść cache settingu, żeby UI od razu zobaczył poprawne options
        \Illuminate\Support\Facades\Cache::forget('setting.core.ai_provider');
    }

    public function down(): void
    {
        // brak — to jest fix, nie ma sensu odwracać
    }
};
