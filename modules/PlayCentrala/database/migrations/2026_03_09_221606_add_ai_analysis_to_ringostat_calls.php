<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->json('ai_analysis')->nullable()->after('ai_recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->dropColumn('ai_analysis');
        });
    }
};
