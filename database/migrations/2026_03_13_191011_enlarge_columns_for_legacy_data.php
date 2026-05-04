<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });

        Schema::table('client_visits', function (Blueprint $table) {
            $table->text('link')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });

        Schema::table('client_visits', function (Blueprint $table) {
            $table->string('link')->nullable()->change();
        });
    }
};
