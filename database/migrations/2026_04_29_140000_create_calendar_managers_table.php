<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela m2m: który user może oglądać/edytować kalendarz innego usera.
 *
 *   manager_id        — handlowiec albo admin który dostaje dropdown przełączania
 *   calendar_user_id  — user, do którego kalendarza ma dostęp (np. kalendarz1/2/3)
 *
 * Brak rekordu = brak dostępu (tylko admin global widzi wszystko).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('calendar_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['manager_id', 'calendar_user_id']);
            $table->index('calendar_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_managers');
    }
};
