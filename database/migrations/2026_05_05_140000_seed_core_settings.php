<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings')) return;

        $now = now();
        $rows = [
            // ============== Ogólne ==============
            ['key' => 'app_timezone',          'group' => 'general', 'type' => 'select',   'label' => 'Strefa czasowa', 'description' => 'Strefa używana do dat w kalendarzu i powiadomieniach', 'value' => 'Europe/Warsaw', 'options' => json_encode(['Europe/Warsaw' => 'Europa/Warszawa', 'Europe/London' => 'Europa/Londyn', 'UTC' => 'UTC']), 'order' => 10],
            ['key' => 'app_locale',            'group' => 'general', 'type' => 'select',   'label' => 'Język interfejsu', 'description' => 'Domyślny język aplikacji', 'value' => 'pl', 'options' => json_encode(['pl' => 'Polski', 'en' => 'English']), 'order' => 20],
            ['key' => 'default_calendar_view', 'group' => 'general', 'type' => 'select',   'label' => 'Domyślny widok kalendarza', 'description' => 'Co użytkownik zobaczy po wejściu w Kalendarz', 'value' => 'month', 'options' => json_encode(['month' => 'Miesiąc', 'week' => 'Tydzień', 'day' => 'Dzień']), 'order' => 30],
            ['key' => 'items_per_page',        'group' => 'general', 'type' => 'integer',  'label' => 'Wyników na stronę', 'description' => 'Domyślna liczba wierszy w tabelach klientów / zadań', 'value' => '25', 'options' => null, 'order' => 40],
            ['key' => 'week_starts_monday',    'group' => 'general', 'type' => 'boolean',  'label' => 'Tydzień zaczyna się od poniedziałku', 'description' => 'Wyłącz aby tydzień zaczynał się od niedzieli', 'value' => '1', 'options' => null, 'order' => 50],

            // ============== Dane firmy ==============
            ['key' => 'company_nip',     'group' => 'company', 'type' => 'string',   'label' => 'NIP', 'description' => '10 cyfr, bez kresek', 'value' => null, 'options' => null, 'order' => 10],
            ['key' => 'company_regon',   'group' => 'company', 'type' => 'string',   'label' => 'REGON', 'description' => '9 lub 14 cyfr', 'value' => null, 'options' => null, 'order' => 20],
            ['key' => 'company_address', 'group' => 'company', 'type' => 'string',   'label' => 'Adres (ulica, numer)', 'description' => 'np. ul. Marszałkowska 1/100', 'value' => null, 'options' => null, 'order' => 30],
            ['key' => 'company_city',    'group' => 'company', 'type' => 'string',   'label' => 'Miasto', 'description' => null, 'value' => null, 'options' => null, 'order' => 40],
            ['key' => 'company_postal',  'group' => 'company', 'type' => 'string',   'label' => 'Kod pocztowy', 'description' => 'Format 00-000', 'value' => null, 'options' => null, 'order' => 50],
            ['key' => 'company_phone',   'group' => 'company', 'type' => 'string',   'label' => 'Telefon firmowy', 'description' => null, 'value' => null, 'options' => null, 'order' => 60],
            ['key' => 'company_email',   'group' => 'company', 'type' => 'string',   'label' => 'E-mail firmowy', 'description' => null, 'value' => null, 'options' => null, 'order' => 70],
            ['key' => 'company_bank_account', 'group' => 'company', 'type' => 'string', 'label' => 'Numer konta bankowego', 'description' => 'IBAN, np. PL 12 3456 7890 …', 'value' => null, 'options' => null, 'order' => 80],

            // ============== Poczta ==============
            ['key' => 'mail_from_address', 'group' => 'mail', 'type' => 'string',   'label' => 'Domyślny nadawca (e-mail)', 'description' => 'Używany gdy user nie ma własnego SMTP', 'value' => null, 'options' => null, 'order' => 10],
            ['key' => 'mail_from_name',    'group' => 'mail', 'type' => 'string',   'label' => 'Domyślny nadawca (nazwa)', 'description' => 'Imię/nazwa firmy widoczne w polu „Od"', 'value' => null, 'options' => null, 'order' => 20],
            ['key' => 'mail_signature',    'group' => 'mail', 'type' => 'textarea', 'label' => 'Podpis HTML', 'description' => 'Doklejany do każdego maila wysyłanego z aplikacji', 'value' => null, 'options' => null, 'order' => 30],
        ];

        foreach ($rows as $row) {
            // Idempotentne — nie nadpisuje istniejących wartości
            $exists = DB::table('settings')->where('module', 'core')->where('key', $row['key'])->exists();
            if ($exists) continue;

            DB::table('settings')->insert(array_merge($row, [
                'module'     => 'core',
                'is_public'  => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) return;

        $keys = [
            'app_timezone', 'app_locale', 'default_calendar_view', 'items_per_page', 'week_starts_monday',
            'company_nip', 'company_regon', 'company_address', 'company_city', 'company_postal',
            'company_phone', 'company_email', 'company_bank_account',
            'mail_from_address', 'mail_from_name', 'mail_signature',
        ];
        DB::table('settings')->where('module', 'core')->whereIn('key', $keys)->delete();
    }
};
