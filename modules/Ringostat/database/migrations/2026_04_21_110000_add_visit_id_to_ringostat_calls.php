<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ringostat_calls', 'visit_id')) {
            return;
        }
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->after('client_id')->constrained('client_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id');
        });
    }
};
