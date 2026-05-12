<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ringostat_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->unique()->comment('Ringostat uniqueid');
            $table->string('caller', 50)->nullable()->comment('Numer dzwoniącego');
            $table->string('destination', 50)->nullable()->comment('Numer docelowy');
            $table->string('call_type', 20)->default('in')->comment('in/out/callback');
            $table->string('disposition', 30)->default('NO ANSWER')->comment('ANSWERED/NO ANSWER/BUSY/FAILED');
            $table->dateTime('call_date')->index();
            $table->integer('duration')->default(0)->comment('Czas trwania połączenia (sek)');
            $table->integer('wait_time')->default(0)->comment('Czas oczekiwania (sek)');
            $table->integer('billsec')->default(0)->comment('Czas rozmowy (sek)');
            $table->text('recording_url')->nullable();
            $table->text('recording_wav_url')->nullable();
            $table->string('employee_id', 50)->nullable()->comment('Ringostat employee ID');
            $table->string('employee_name')->nullable()->comment('Ringostat employee name');
            $table->string('department')->nullable()->comment('Ringostat department');
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->text('landing_page')->nullable();
            $table->text('referrer')->nullable();
            $table->text('call_card_url')->nullable();
            $table->string('scheme_name')->nullable()->comment('Schemat przekierowania');
            $table->string('missing_reason')->nullable()->comment('Powód nieodebrania');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ai_transcript')->nullable()->comment('Transkrypcja AI (przyszłość)');
            $table->text('ai_summary')->nullable()->comment('Podsumowanie AI (przyszłość)');
            $table->timestamps();

            $table->index(['caller']);
            $table->index(['destination']);
            $table->index(['call_type']);
            $table->index(['disposition']);
            $table->index(['user_id', 'call_date']);
            $table->index(['client_id', 'call_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ringostat_calls');
    }
};
