<?php

// Globalne helpery dostępne w każdym namespace.
// Plik ładowany przez composer autoload.files (composer.json).

use App\Support\Brand;

if (!function_exists('brand')) {
    /**
     * Pobierz wartość brandu (Settings → config/brand.php fallback).
     * Bez argumentu zwraca cały array brandu.
     */
    function brand(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? Brand::all() : Brand::get($key, $default);
    }
}
