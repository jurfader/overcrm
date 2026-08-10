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
 *
 * KATEGORIE OBOWIĄZKOWE vs OPCJONALNE
 * Rdzeń dostarcza własnego providera dla product/order/invoice/storage/notification,
 * więc te zdolności są zawsze obecne. Telefonii i AI rdzeń NIE dostarcza —
 * bez odpowiedniego modułu tych zdolności po prostu nie ma i kod musi to
 * przewidzieć: `has('telephony')` zamiast `active('telephony')`.
 *
 * KATEGORIE WIELOAKTYWNE
 * 'notification' działa inaczej niż reszta: nie wybiera się jednego kanału,
 * tylko włącza kilka naraz. Dla takich kategorii używa się activeAll()
 * zamiast active(), a wybór trzymany jest jako lista w Setting.
 */
class ProviderRegistry
{
    /** Kategorie, dla których rdzeń NIE ma własnego providera — mogą nie istnieć wcale. */
    public const OPTIONAL = ['telephony', 'ai', 'ai_audio'];

    /** Kategorie, w których naraz działa wiele providerów, a nie jeden wybrany. */
    public const MULTI = ['notification'];

    /** @var array<string, array<string, class-string>>  category → key → class */
    protected array $providers = [
        'product'      => [],
        'order'        => [],
        'invoice'      => [],
        'telephony'    => [],
        'ai'           => [],
        'ai_audio'     => [],
        'storage'      => [],
        'notification' => [],
        'shipping'     => [],
    ];

    /** Klucz default per category (gdy admin jeszcze nic nie wybrał) */
    protected array $defaults = [
        'product'      => 'local',
        'order'        => 'local',
        'invoice'      => 'none',
        'storage'      => 'local',
        'notification' => 'mail',
        // telephony / ai / ai_audio nie mają domyślnego — brak modułu = brak zdolności
    ];

    public function register(string $category, string $key, string $class): void
    {
        $this->ensureCategory($category);
        $this->providers[$category][$key] = $class;
    }

    /**
     * Nazwy wszystkich znanych kategorii zdolności (także tych bez dostawcy).
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        return array_keys($this->providers);
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

    /**
     * Aktywny provider key z Settings (z fallbackiem na default).
     * Zwraca '' dla kategorii opcjonalnej, w której nic nie zarejestrowano —
     * UI ma wtedy pokazać „brak", a nie wywalić się na nieznanym kluczu.
     */
    public function activeKey(string $category): string
    {
        $this->ensureCategory($category);
        $stored = Setting::get('provider_' . $category, null);
        if ($stored && isset($this->providers[$category][$stored])) {
            return $stored;
        }

        $default = $this->defaults[$category] ?? null;
        if ($default !== null) {
            return $default;
        }

        // Kategoria opcjonalna: pierwszy zarejestrowany albo nic.
        return (string) (array_key_first($this->providers[$category]) ?? '');
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

    /**
     * Czy zdolność jest w tej instalacji dostępna — jest zarejestrowany provider
     * i zgłasza gotowość. To jest metoda, o którą pyta kod funkcjonalny zamiast
     * zakładać, że moduł istnieje.
     *
     *   if (!$registry->has('telephony')) { return; }   // brak centrali, cicho pomijamy
     */
    public function has(string $category): bool
    {
        return $this->activeOrNull($category) !== null;
    }

    /**
     * Provider UŻYWALNY albo null. Różni się od active() dwiema rzeczami:
     * nie rzuca, gdy nic nie zarejestrowano, ORAZ oddaje null, gdy provider
     * jest zarejestrowany, ale niegotowy do pracy.
     *
     * Ten drugi przypadek jest częstszy, niż się wydaje: klient instaluje moduł
     * centrali, ale nie wpisuje kluczy API. Dla kodu funkcjonalnego to musi być
     * to samo, co brak modułu — inaczej pierwsze wywołanie wywala się u klienta
     * zamiast po cichu pominąć funkcję.
     */
    public function activeOrNull(string $category): ?object
    {
        try {
            $instance = $this->active($category);
        } catch (\Throwable) {
            return null;
        }

        if (method_exists($instance, 'isAvailable') && !$instance->isAvailable()) {
            return null;
        }

        return $instance;
    }

    /**
     * Klucze providerów włączonych w kategorii wieloaktywnej ('notification').
     * Dla kategorii jednoaktywnej zwraca jednoelementową listę z aktywnym kluczem.
     *
     * @return array<int, string>
     */
    public function enabledKeys(string $category): array
    {
        $this->ensureCategory($category);

        if (!in_array($category, self::MULTI, true)) {
            $key = $this->activeKey($category);

            return $key === '' ? [] : [$key];
        }

        $stored = Setting::get('providers_' . $category, null);
        $keys = is_string($stored) ? json_decode($stored, true) : $stored;

        if (!is_array($keys)) {
            // Nic nie wybrano — włączone jest to, co rdzeń daje domyślnie.
            $default = $this->defaults[$category] ?? null;
            $keys = $default !== null ? [$default] : [];
        }

        return array_values(array_filter(
            $keys,
            fn ($key) => is_string($key) && isset($this->providers[$category][$key])
        ));
    }

    /**
     * Instancje wszystkich włączonych providerów kategorii, w kolejności
     * rejestracji. Pomija te, które nie zgłaszają gotowości — dzięki temu
     * wysyłający nie musi sprawdzać konfiguracji każdego kanału z osobna.
     *
     * @return array<int, object>
     */
    public function activeAll(string $category): array
    {
        $out = [];

        foreach ($this->enabledKeys($category) as $key) {
            $class = $this->providers[$category][$key] ?? null;

            if (!$class) {
                continue;
            }

            try {
                $instance = app($class);
            } catch (\Throwable) {
                continue;
            }

            if (method_exists($instance, 'isAvailable') && !$instance->isAvailable()) {
                continue;
            }

            $out[] = $instance;
        }

        return $out;
    }

    /** Ustawia listę włączonych providerów w kategorii wieloaktywnej. */
    public function setEnabled(string $category, array $keys): void
    {
        $this->ensureCategory($category);

        $valid = array_values(array_filter(
            $keys,
            fn ($key) => is_string($key) && isset($this->providers[$category][$key])
        ));

        Setting::set('providers_' . $category, json_encode($valid));
        \Illuminate\Support\Facades\Cache::forget('setting.core.providers_' . $category);
    }

    /**
     * Zdolności dostępne w tej instalacji — lista kategorii, w których jest
     * gotowy provider. Tym karmione są `requires: capability:*` w manifestach
     * modułów oraz frontend, który na tej podstawie ukrywa elementy UI.
     *
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        $out = [];

        foreach (array_keys($this->providers) as $category) {
            if ($this->has($category)) {
                $out[] = $category;
            }
        }

        return $out;
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
        if ($category === 'telephony') {
            if (method_exists($instance, 'supportsRecordings')) {
                $caps['supports_recordings'] = $instance->supportsRecordings();
            }
            if (method_exists($instance, 'supportsClickToCall')) {
                $caps['supports_click_to_call'] = $instance->supportsClickToCall();
            }
        }
        if (($category === 'ai' || $category === 'ai_audio')) {
            if (method_exists($instance, 'supportsAudio')) {
                $caps['supports_audio'] = $instance->supportsAudio();
            }
            if (method_exists($instance, 'model')) {
                $caps['model'] = $instance->model();
            }
        }

        // Gotowość zgłasza każdy provider — to jedyna informacja, której UI
        // potrzebuje zawsze, niezależnie od kategorii.
        if (method_exists($instance, 'isAvailable')) {
            $caps['available'] = $instance->isAvailable();
        }

        return $caps;
    }
}
