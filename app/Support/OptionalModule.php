<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Bezpieczne sięganie po serwisy modułów z core.
 *
 * Moduły są instalowane z marketplace i NIE ma ich w repo — na świeżej
 * instalacji katalog modules/ jest pusty. Type-hint na klasę modułu w
 * konstruktorze/metodzie kontrolera powoduje wtedy BindingResolutionException
 * ("Target class does not exist") i całe 500 na ekranie, który z modułem
 * nie ma nic wspólnego.
 *
 * Zamiast tego: OptionalModule::resolve(FooService::class) → instancja albo null,
 * a wywołujący degraduje się z sensownym komunikatem.
 */
class OptionalModule
{
    /** Czy klasa serwisu modułu jest w ogóle załadowana (moduł zainstalowany i aktywny). */
    public static function available(string $class): bool
    {
        return class_exists($class);
    }

    /**
     * Instancja serwisu modułu albo null, gdy moduł nie jest zainstalowany
     * lub jego konstruktor rzuca (np. brak konfiguracji API).
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public static function resolve(string $class): ?object
    {
        if (! self::available($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable $e) {
            Log::debug('Optional module service unavailable', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
