<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolumna play_phone w users — numer telefonu użytkownika w Play Wirtualna Centralka
        if (!Schema::hasColumn('users', 'play_phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('play_phone', 30)->nullable()->after('phone')
                    ->comment('Numer w Play Wirtualna Centralka (format 48XXXXXXXXX)');
            });
        }

        // Nowe kolumny w ringostat_calls
        Schema::table('ringostat_calls', function (Blueprint $table) {
            if (!Schema::hasColumn('ringostat_calls', 'answered_by_number')) {
                $table->string('answered_by_number', 30)->nullable()
                    ->after('destination')
                    ->comment('Numer który odebrał połączenie po przekierowaniu (Play)');
            }
            if (!Schema::hasColumn('ringostat_calls', 'encryption_key_name')) {
                $table->string('encryption_key_name', 100)->nullable()
                    ->after('recording_wav_url')
                    ->comment('Nazwa klucza szyfrowania nagrania (Play)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('play_phone');
        });

        Schema::table('ringostat_calls', function (Blueprint $table) {
            $table->dropColumn(['answered_by_number', 'encryption_key_name']);
        });
    }
};
