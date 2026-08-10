<?php

namespace App\Support\Notifications;

use App\Contracts\NotificationChannel;
use App\Models\User;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\Collection;

/**
 * Jedyny punkt wysyłki powiadomień w systemie. Kod domenowy woła:
 *
 *   Notify::send($user, 'Zadanie na dziś', 'Telefon do klienta X', ['url' => …]);
 *
 * i nie wie ani ile kanałów ma instalacja, ani które są włączone. Dzięki temu
 * przypomnienia o zadaniach działają tak samo u klienta, który ma tylko pocztę,
 * jak u tego, który dokupił push, SMS i WhatsApp.
 *
 * Strategie:
 *  - 'all'   — wysyła wszystkimi włączonymi kanałami (domyślna dla ważnych rzeczy)
 *  - 'first' — pierwszy kanał, który zadziała; reszta pomijana (oszczędza SMS-y)
 */
class Notifier
{
    public function __construct(protected ProviderRegistry $registry) {}

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool> klucz kanału → czy wysyłka się powiodła
     */
    public function send(User $user, string $title, string $body, array $options = []): array
    {
        $strategy = $options['strategy'] ?? 'all';
        $only = $options['channels'] ?? null;

        $results = [];

        foreach ($this->channels() as $channel) {
            if (is_array($only) && !in_array($channel->key(), $only, true)) {
                continue;
            }

            if (!$channel->canReach($user)) {
                continue;
            }

            $results[$channel->key()] = $channel->send($user, $title, $body, $options);

            if ($strategy === 'first' && $results[$channel->key()]) {
                break;
            }
        }

        return $results;
    }

    /**
     * Wysyłka do wielu odbiorców. Zwraca mapę user_id → wynik per kanał.
     *
     * @param iterable<User> $users
     * @param array<string, mixed> $options
     * @return array<int, array<string, bool>>
     */
    public function sendToMany(iterable $users, string $title, string $body, array $options = []): array
    {
        $out = [];

        foreach ($users as $user) {
            $out[$user->id] = $this->send($user, $title, $body, $options);
        }

        return $out;
    }

    /**
     * Włączone i gotowe kanały.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function channels(): Collection
    {
        return collect($this->registry->activeAll('notification'))
            ->filter(fn ($c) => $c instanceof NotificationChannel)
            ->values();
    }

    /** Czy cokolwiek jest w stanie dostarczyć powiadomienie do tego użytkownika. */
    public function canReach(User $user): bool
    {
        return $this->channels()->contains(fn (NotificationChannel $c) => $c->canReach($user));
    }
}
