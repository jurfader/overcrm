<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Abstrakcja telefonii firmowej. Rdzeń NIE zna żadnej konkretnej centrali —
 * moduły telefonii rejestrują się w kategorii 'telephony' i to one wiedzą,
 * skąd biorą połączenia i nagrania.
 *
 * Moduły: Play Wirtualna Centrala, Ringostat, 3CX, Twilio.
 *
 * Dzięki temu kontraktowi moduł analizy rozmów (AI) deklaruje
 * `"requires": ["capability:telephony"]` i działa z każdą z tych central
 * bez zmiany choćby jednej linii swojego kodu.
 *
 * Kategoria jednoaktywna — w danej instalacji działa jedna centrala.
 */
interface TelephonyProvider
{
    public function key(): string;

    public function label(): string;

    /** Czy provider jest skonfigurowany (klucze API, konto) i gotowy do użycia. */
    public function isAvailable(): bool;

    /**
     * Klasa modelu Eloquent trzymającego połączenia tego providera.
     *
     * Każdy moduł ma własną tabelę (ringostat_calls, ringostat_calls_v2, …),
     * a raporty i widgety muszą umieć zapytać o połączenia, nie wiedząc która
     * centrala jest zainstalowana. Model MUSI wystawiać kolumny opisane
     * w `callColumns()`.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    public function callModel(): string;

    /**
     * Mapa znaczenie → nazwa kolumny w modelu połączeń. Pozwala raportom
     * pisać zapytania bez znajomości schematu konkretnego modułu.
     *
     * Wymagane klucze: id, date, direction, duration, caller, destination,
     * user_id, client_id, recording_url, answered.
     *
     * @return array<string, string>
     */
    public function callColumns(): array;

    /** Czy centrala udostępnia nagrania rozmów (warunek działania analizy AI). */
    public function supportsRecordings(): bool;

    /**
     * Pobiera nagranie rozmowy i zwraca ścieżkę do pliku w katalogu tymczasowym.
     * Provider odpowiada za autoryzację i ewentualne odszyfrowanie.
     *
     * Zwraca null, gdy nagrania nie ma albo pobranie się nie powiodło —
     * caller nie może zakładać, że plik istnieje.
     */
    public function downloadRecording(string $callId): ?string;

    /** Czy centrala potrafi zainicjować połączenie z aplikacji. */
    public function supportsClickToCall(): bool;

    /**
     * Dzwoni do `$number` z aparatu/konta użytkownika. Zwraca false, gdy
     * użytkownik nie ma skonfigurowanego numeru wewnętrznego w tej centrali.
     */
    public function click2call(User $user, string $number): bool;

    /**
     * Synchronizuje połączenia od podanego momentu. Zwraca liczbę zapisanych
     * rekordów. Wołane z harmonogramu — musi być idempotentne.
     */
    public function syncSince(\DateTimeInterface $since): int;
}
