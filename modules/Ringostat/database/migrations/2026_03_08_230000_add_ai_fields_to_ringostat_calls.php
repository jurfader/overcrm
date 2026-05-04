<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->string('ai_customer_mood')->nullable()->after('ai_summary');
            $table->string('ai_employee_mood')->nullable()->after('ai_customer_mood');
            $table->string('ai_overall_mood')->nullable()->after('ai_employee_mood');
            $table->text('ai_recommendations')->nullable()->after('ai_overall_mood');
            $table->string('ai_transcript_url')->nullable()->after('ai_recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->dropColumn([
                'ai_customer_mood',
                'ai_employee_mood',
                'ai_overall_mood',
                'ai_recommendations',
                'ai_transcript_url',
            ]);
        });
    }
};
