<?php

namespace App\Http\Middleware;

use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\License;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appUrl' => rtrim(url('/'), '/'),
            'environmentBanner' => fn () => Setting::get('environment_banner', '', 'core') ?: '',
            'buildVersion' => fn () => $this->getBuildVersion(),
            'auth' => [
                'user' => $request->user() ? $this->transformAuthUser($request->user()) : null,
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'recovery_codes' => fn () => $request->session()->get('recovery_codes'),
                'openedVisitId' => fn () => $request->session()->get('openedVisitId'),
            ],
            'brand' => fn () => brand(),
            // Zdolności dostępne w tej instalacji (telephony, ai, storage…).
            // Front ukrywa na tej podstawie elementy UI, zamiast sprawdzać nazwy
            // konkretnych modułów — dzięki temu przycisk „Analizuj rozmowę" działa
            // tak samo z Play Centralą, jak z Ringostatem czy 3CX.
            'capabilities' => fn () => app(ProviderRegistry::class)->capabilities(),
            'appLicensed' => fn () => License::ok(),
            'appSettings' => fn () => $this->getAppSettings(),
            // Nazwy propsów zostają dla zgodności z istniejącym frontem, ale
            // źródłem jest już aktywny dostawca wysyłki, a nie moduł po nazwie.
            'inpostGeowidgetToken' => fn () => (string) ($this->getShippingPointConfig()['token'] ?? ''),
            'inpostOrganizationId' => fn () => (string) ($this->getShippingPointConfig()['organization_id'] ?? ''),
            'inboxUnreadCount' => fn () => $request->user() ? $this->getInboxUnreadCount($request->user()) : 0,
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'demo' => fn () => $this->getDemoState($request),
        ];
    }

    /**
     * Transformuj użytkownika dla frontendu – developer widzi UI admina (bez ujawniania roli)
     */
    protected function transformAuthUser($user): array
    {
        $data = $user->toArray();

        $data['is_developer'] = $user->isDeveloper();
        if ($data['is_developer']) {
            $data['role'] = 'admin';
        }

        return $data;
    }

    /**
     * Pobierz ustawienia aplikacji
     */
    protected function getAppSettings(): array
    {
        try {
            if (!Schema::hasTable('settings')) {
                return $this->getDefaultSettings();
            }

            return [
                'app_name' => Setting::get('app_name', brand('name')),
                'app_logo' => Setting::get('app_logo', brand('logo_url')),
                'company_name' => Setting::get('company_name', brand('company_name')),
                'primary_color' => Setting::get('primary_color', brand('primary_color')),
                'dark_mode_default' => Setting::get('dark_mode_default', brand('default_theme') === 'dark'),
            ];
        } catch (\Exception $e) {
            return $this->getDefaultSettings();
        }
    }

    /**
     * Konfiguracja widgetu wyboru punktu odbioru.
     *
     * Rdzeń NIE pyta już o moduł „inpost" po nazwie — pyta o zdolność wysyłki
     * i o to, czy dostawca umie pokazywać punkty. Dzięki temu klient, który
     * zamiast InPostu wdroży Furgonetkę, dostanie działający widget bez zmiany
     * choćby jednej linii w rdzeniu.
     *
     * @return array<string, mixed>
     */
    protected function getShippingPointConfig(): array
    {
        try {
            if (!Schema::hasTable('settings')) {
                return [];
            }

            $provider = app(ProviderRegistry::class)->activeOrNull('shipping');

            if (!$provider || !method_exists($provider, 'supportsPointPicking') || !$provider->supportsPointPicking()) {
                return [];
            }

            return $provider->pointWidgetConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Liczba nieprzeczytanych maili (z cache – zapełniana przy wizycie w skrzynce)
     */
    protected function getInboxUnreadCount(User $user): int
    {
        $config = $user->mailConfigs()->verified()->orderBy('is_default', 'desc')->first();
        if (! $config) {
            return 0;
        }
        $cacheKey = 'inbox_unread:user:' . $user->id . ':config:' . $config->id;

        return (int) Cache::get($cacheKey, 0);
    }

    /**
     * Wersja buildu (zmienia się przy każdym deployu) – do wykrywania konieczności odświeżenia
     */
    protected function getBuildVersion(): string
    {
        $manifestPath = public_path('build/manifest.json');
        if (!file_exists($manifestPath)) {
            return 'dev';
        }
        try {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $appEntry = $manifest['resources/js/app.js'] ?? null;
            return $appEntry['file'] ?? (string) filemtime($manifestPath);
        } catch (\Throwable $e) {
            return 'dev';
        }
    }

    /**
     * Stan demo mode dla frontendu — banner uzywa expires_at do countdownu.
     * Pusty array gdy demo wylaczony, zeby frontend mogl szybko sprawdzic.
     */
    protected function getDemoState(Request $request): array
    {
        if (!config('demo.enabled')) {
            return ['enabled' => false];
        }

        $cookie = $request->cookie(config('demo.cookie'));
        $dir = config('demo.path');
        $expiresAt = null;

        if ($cookie && preg_match('/^[a-f0-9-]{8,64}$/', $cookie)) {
            $path = $dir . '/' . $cookie . '.sqlite';
            if (is_file($path)) {
                $expiresAt = filemtime($path) + (config('demo.ttl_hours', 24) * 3600);
            }
        }

        return [
            'enabled' => true,
            'session_id' => $cookie,
            'expires_at' => $expiresAt,
            'ttl_hours' => (int) config('demo.ttl_hours', 24),
        ];
    }

    /**
     * Domyślne ustawienia gdy baza nie jest dostępna
     */
    protected function getDefaultSettings(): array
    {
        return [
            'app_name' => brand('name'),
            'app_logo' => brand('logo_url'),
            'company_name' => brand('company_name'),
            'primary_color' => brand('primary_color'),
            'dark_mode_default' => brand('default_theme') === 'dark',
        ];
    }
}
