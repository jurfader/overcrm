<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Naprawa rozjazdu ustawień modułu Play Wirtualna Centrala.
 *
 * PROBLEM: panel administracyjny zapisywał klucze API pod `module = 'playcentrala'`
 * (bo tak nazywa się moduł), a serwis czytał je spod `module = 'ringostat'`
 * (nazwa sprzed przemianowania modułu). W bazie powstały DWA komplety wierszy dla
 * tych samych kluczy. Efekt: administrator wpisywał klucze w panelu, serwis czytał
 * pusty string, `isConfigured()` zwracało false i cała integracja Play milczała —
 * bez żadnego komunikatu o błędzie.
 *
 * Ta migracja przenosi wypełnione wartości do właściwego kubełka i kasuje
 * osierocone wiersze. Kubełek 'ringostat' zawierał WYŁĄCZNIE klucze Play —
 * moduł Ringostat.net trzyma swoją konfigurację pod `module = 'core'`
 * (`ringostat_auth_key`, `ringostat_project_id`), więc nic mu nie zabieramy.
 */
return new class extends Migration
{
    /** Klucze, które zawsze należały do Play Centrali, mimo zapisu pod 'ringostat'. */
    private const PLAY_KEYS = [
        'play_client_id',
        'play_client_secret',
        'play_private_key',
        'play_webhook_login',
        'play_webhook_password',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        foreach (self::PLAY_KEYS as $key) {
            $stary = DB::table('settings')
                ->where('module', 'ringostat')
                ->where('key', $key)
                ->first();

            if (!$stary) {
                continue;
            }

            // Przenosimy tylko wartości niepuste i tylko gdy w docelowym kubełku
            // nic nie ma — konfiguracja wpisana już poprawnie przez panel jest
            // nowsza i ma pierwszeństwo przed osieroconym wierszem.
            if (!empty($stary->value)) {
                $docelowy = DB::table('settings')
                    ->where('module', 'playcentrala')
                    ->where('key', $key)
                    ->first();

                if ($docelowy && empty($docelowy->value)) {
                    DB::table('settings')
                        ->where('id', $docelowy->id)
                        ->update(['value' => $stary->value, 'updated_at' => now()]);
                } elseif (!$docelowy) {
                    DB::table('settings')->insert([
                        'module'     => 'playcentrala',
                        'group'      => $stary->group ?? 'integrations',
                        'key'        => $key,
                        'value'      => $stary->value,
                        'type'       => $stary->type ?? 'string',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('settings')->where('id', $stary->id)->delete();
        }

        // Cache ustawień trzyma klucze per moduł — bez tego serwis czytałby
        // stare, puste wartości aż do wygaśnięcia wpisów.
        foreach (self::PLAY_KEYS as $key) {
            cache()->forget("setting.ringostat.{$key}");
            cache()->forget("setting.playcentrala.{$key}");
        }
    }

    public function down(): void
    {
        // Świadomie nieodwracalna: przywrócenie wierszy pod 'ringostat' odtworzyłoby
        // dokładnie ten rozjazd, który ta migracja naprawia.
    }
};
