<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Błąd warstwy AI: brak konfiguracji, odrzucenie przez API, nieparsowalna
 * odpowiedź modelu, przekroczony limit.
 *
 * Wołający MUSI to łapać — analiza AI jest wzbogaceniem, nigdy warunkiem
 * powodzenia operacji biznesowej. Nieudana analiza rozmowy nie może wywrócić
 * synchronizacji połączeń, a nieudane podsumowanie wizyty nie może zablokować
 * jej zapisania.
 */
class AiException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("Provider AI '{$provider}' nie jest skonfigurowany.");
    }

    public static function badResponse(string $provider, string $detail): self
    {
        return new self("Provider AI '{$provider}' zwrócił nieoczekiwaną odpowiedź: {$detail}");
    }

    public static function audioUnsupported(string $provider): self
    {
        return new self("Provider AI '{$provider}' nie obsługuje transkrypcji audio.");
    }
}
