<?php

namespace App\Support\Providers;

use App\Contracts\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Domyślny kanał powiadomień — e-mail. Zawsze zarejestrowany, więc instalacja
 * bez modułu push czy SMS wciąż potrafi kogokolwiek powiadomić.
 *
 * Dostępny tylko wtedy, gdy poczta jest faktycznie skonfigurowana: sterownik
 * 'log' i 'array' to tryby deweloperskie, w których wiadomość nigdzie nie dolatuje.
 */
class MailNotificationChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'E-mail';
    }

    public function isAvailable(): bool
    {
        $mailer = config('mail.default');

        if (in_array($mailer, ['log', 'array', null], true)) {
            return false;
        }

        // SMTP bez hosta to konfiguracja niedokończona — lepiej zgłosić brak
        // gotowości niż próbować wysyłać i wywalać się przy każdym powiadomieniu.
        if ($mailer === 'smtp' && empty(config('mail.mailers.smtp.host'))) {
            return false;
        }

        return true;
    }

    public function canReach(User $user): bool
    {
        return !empty($user->email);
    }

    public function send(User $user, string $title, string $body, array $options = []): bool
    {
        if (!$this->canReach($user)) {
            return false;
        }

        try {
            $url = $options['url'] ?? null;

            Mail::raw(
                $body.($url ? "\n\n".$url : ''),
                fn ($message) => $message->to($user->email)->subject($title)
            );

            return true;
        } catch (\Throwable $e) {
            // Powiadomienie jest efektem ubocznym — nigdy nie wywraca operacji,
            // która je wywołała. Zostawiamy ślad w logu i zwracamy porażkę.
            Log::warning('Powiadomienie e-mail nie zostało wysłane', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }
}
