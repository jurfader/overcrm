<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('link')->nullable()->after('notes');
            $table->datetime('deadline')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->dropColumn(['description', 'link', 'deadline']);
        });
    }
};
