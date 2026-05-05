<?php

namespace App\Support\Providers;

use App\Models\Setting;

/**
 * Singleton rejestr providerów per kategoria. Core rejestruje swoje (LocalProductProvider,
 * LocalOrderProvider, NullInvoiceProvider) w ProviderServiceProvider::boot().
 * Moduły rejestrują swoje w swoich ServiceProviderach.
 *
 * Aktywny provider per kategoria zapisany w Setting (key 'provider_{category}').
 *
 * Przykład rejestracji z modułu (modules/Apilo/ApiloServiceProvider::boot):
 *   $registry = app(ProviderRegistry::class);
 *   $registry->register('product', 'apilo', ApiloProductProvider::class);
 *   $registry->register('order',   'apilo', ApiloOrderProvider::class);
 */
class ProviderRegistry
{
    /** @var array<string, array<string, class-string>>  category → key → class */
    protected array $providers = [
        'product' => [],
        'order'   => [],
        'invoice' => [],
    ];

    /** Klucz default per category (gdy admin jeszcze nic nie wybrał) */
    protected array $defaults = [
        'product' => 'local',
        'order'   => 'local',
        'invoice' => 'none',
    ];

    public function register(string $category, string $key, string $class): void
    {
        $this->ensureCategory($category);
        $this->providers[$category][$key] = $class;
    }

    /** @return array<string, class-string> */
    public function all(string $category): array
    {
        $this->ensureCategory($category);
        return $this->providers[$category];
    }

    /**
     * Lista metadata wszystkich providerów dostępnych w danej kategorii
     * (dla UI selectora). Zwraca [{ key, label, supports_management?, supports_pdf?, ... }]
     */
    public function meta(string $category): array
    {
        $this->ensureCategory($category);
        $out = [];
        foreach ($this->providers[$category] as $key => $class) {
            try {
                $instance = app($class);
                $out[] = [
                    'key'   => $instance->key(),
                    'label' => $instance->label(),
                    'class' => $class,
                    'meta'  => $this->extractCapabilities($instance, $category),
                ];
            } catch (\Throwable $e) {
                // Provider rzuca w konstruktorze (np. brak konfiguracji) — pomijamy z UI
                $out[] = [
                    'key'   => $key,
                    'label' => $key,
                    'class' => $class,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $out;
    }

    /** Aktywny provider key z Settings (z fallback'iem na default) */
    public function activeKey(string $category): string
    {
        $this->ensureCategory($category);
        $stored = Setting::get('provider_' . $category, null);
        if ($stored && isset($this->providers[$category][$stored])) {
            return $stored;
        }
        return $this->defaults[$category];
    }

    /** Resolve aktywnej instancji providera. Throws gdy żaden nie zarejestrowany. */
    public function active(string $category): object
    {
        $this->ensureCategory($category);
        $key = $this->activeKey($category);
        $class = $this->providers[$category][$key] ?? null;

        if (!$class) {
            // Fallback: pierwszy zarejestrowany
            $class = reset($this->providers[$category]) ?: null;
        }

        if (!$class) {
            throw new \RuntimeException("No provider registered for category '{$category}'");
        }

        return app($class);
    }

    /** Ustaw aktywnego providera (np. z UI). Waliduje czy klucz jest zarejestrowany. */
    public function setActive(string $category, string $key): void
    {
        $this->ensureCategory($category);
        if (!isset($this->providers[$category][$key])) {
            throw new \InvalidArgumentException("Unknown provider '{$key}' in category '{$category}'");
        }
        Setting::set('provider_' . $category, $key);
        \Illuminate\Support\Facades\Cache::forget('setting.core.provider_' . $category);
    }

    protected function ensureCategory(string $category): void
    {
        if (!isset($this->providers[$category])) {
            $this->providers[$category] = [];
        }
    }

    protected function extractCapabilities(object $instance, string $category): array
    {
        $caps = [];
        if ($category === 'product' && method_exists($instance, 'supportsManagement')) {
            $caps['supports_management'] = $instance->supportsManagement();
        }
        if ($category === 'order' && method_exists($instance, 'supportsPdf')) {
            $caps['supports_pdf'] = $instance->supportsPdf();
        }
        if ($category === 'invoice' && method_exists($instance, 'isAvailable')) {
            $caps['available'] = $instance->isAvailable();
        }
        return $caps;
    }
}
