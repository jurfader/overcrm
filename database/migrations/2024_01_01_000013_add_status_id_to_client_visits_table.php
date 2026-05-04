<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('statuses')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');
        });
    }
};
