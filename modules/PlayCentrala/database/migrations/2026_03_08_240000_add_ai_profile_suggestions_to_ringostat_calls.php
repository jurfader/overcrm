<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->json('ai_profile_suggestions')->nullable()->after('ai_transcript_url');
        });
    }

    public function down(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->dropColumn('ai_profile_suggestions');
        });
    }
};
