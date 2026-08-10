<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Kanał powiadomień. RÓŻNI SIĘ od pozostałych kategorii: nie wybiera się
 * jednego kanału, tylko włącza kilka naraz (mail + push + SMS + WhatsApp),
 * a nadawca wysyła powiadomienie „czymkolwiek, co jest włączone".
 *
 * Kanały: mail (rdzeń, zawsze dostępny), web push, WhatsApp, SMS.
 *
 * Wysyłką steruje App\Support\Notifications\Notifier — on pyta rejestr
 * o włączone kanały i próbuje po kolei, więc żaden moduł nie musi wiedzieć,
 * jakie kanały ma dana instalacja.
 */
interface NotificationChannel
{
    public function key(): string;

    public function label(): string;

    /** Czy kanał ma komplet konfiguracji (klucze VAPID, token bramki, SMTP…). */
    public function isAvailable(): bool;

    /**
     * Czy kanał da się użyć DLA TEGO użytkownika. Kanał może być poprawnie
     * skonfigurowany globalnie, a mimo to nieosiągalny dla konkretnej osoby:
     * brak numeru telefonu przy SMS, brak subskrypcji przeglądarki przy push.
     */
    public function canReach(User $user): bool;

    /**
     * Wysyła powiadomienie. Zwraca false zamiast rzucać wyjątkiem, gdy wysyłka
     * się nie powiodła — powiadomienie jest efektem ubocznym i nigdy nie może
     * wywrócić operacji biznesowej, która je wywołała.
     *
     * $options: ['url' => link docelowy, 'tag' => grupowanie, 'priority' => 'normal'|'high']
     *
     * @param array<string, mixed> $options
     */
    public function send(User $user, string $title, string $body, array $options = []): bool;
}
