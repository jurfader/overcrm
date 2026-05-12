<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->string('caller', 255)->nullable()->change();
            $table->string('destination', 255)->nullable()->change();
            $table->string('employee_id', 100)->nullable()->change();
            $table->string('employee_name', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->string('caller', 50)->nullable()->change();
            $table->string('destination', 50)->nullable()->change();
            $table->string('employee_id', 50)->nullable()->change();
            $table->string('employee_name')->nullable()->change();
        });
    }
};
