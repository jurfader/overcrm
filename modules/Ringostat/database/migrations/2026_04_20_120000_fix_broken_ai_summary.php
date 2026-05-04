<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Czyści rekordy ringostat_calls gdzie ai_summary zawiera surowy JSON
     * (bug: wcześniej gdy Gemini zwrócił nie-JSON, cała odpowiedź lądowała w summary).
     * Te rekordy są wyczyszczone — użytkownik może ponownie uruchomić analizę.
     */
    public function up(): void
    {
        DB::table('ringostat_calls')
            ->where(function ($q) {
                $q->where('ai_summary', 'LIKE', '{%"summary"%}%')
                  ->orWhere('ai_summary', 'LIKE', '{%"scores"%}%')
                  ->orWhere('ai_summary', 'LIKE', '{%"key_moments"%}%')
                  ->orWhere('ai_summary', 'LIKE', '{%"recommendations"%}%');
            })
            ->update([
                'ai_summary'      => null,
                'ai_transcript'   => null,
                'ai_analysis'     => null,
                'ai_customer_mood' => null,
                'ai_employee_mood' => null,
                'ai_overall_mood'  => null,
                'ai_recommendations' => null,
            ]);
    }

    public function down(): void
    {
        // Nie do odzyskania — analizy AI zostały wyczyszczone.
    }
};
