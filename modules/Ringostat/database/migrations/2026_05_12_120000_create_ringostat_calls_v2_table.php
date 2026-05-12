<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela polaczen z Ringostat.net API (nowy modul, niezalezna od PlayCentrala
 * ktora ma swoja `ringostat_calls`).
 *
 * Wypelniana przez webhook /ringostat/webhook po zakonczeniu kazdego calla.
 * Pola caller/callee normalizowane do match'owania z Client + User.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ringostat_calls_v2', function (Blueprint $table) {
            $table->id();

            $table->string('ringostat_call_id')->unique();
            $table->string('direction', 10)->index();        // 'in' | 'out'
            $table->string('caller', 64)->nullable()->index();
            $table->string('callee', 64)->nullable()->index();
            $table->string('sip', 64)->nullable();           // SIP account ID

            $table->timestamp('started_at')->nullable()->index();
            $table->integer('duration')->default(0);         // sekundy (waiting + speaking)
            $table->integer('billsec')->default(0);          // sekundy rozmowy

            $table->string('status', 32)->nullable()->index(); // answered/missed/busy/failed
            $table->string('recording_url', 500)->nullable();

            $table->foreignId('client_id')->nullable()->index()->constrained('clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();

            $table->json('webhook_payload')->nullable();     // raw payload z Ringostat

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ringostat_calls_v2');
    }
};
