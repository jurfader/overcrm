<?php

namespace App\Http\Middleware;

use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\License;
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
            'appLicensed' => fn () => License::ok(),
            'appSettings' => fn () => $this->getAppSettings(),
            'inpostGeowidgetToken' => fn () => $this->getInpostToken(),
            'inpostOrganizationId' => fn () => $this->getInpostOrganizationId(),
            'inboxUnreadCount' => fn () => $request->user() ? $this->getInboxUnreadCount($request->user()) : 0,
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
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
     * Token InPost Geowidget – tylko gdy moduł aktywny
     */
    protected function getInpostToken(): string
    {
        try {
            if (!Schema::hasTable('modules') || !Module::where('name', 'inpost')->where('is_active', true)->exists()) {
                return '';
            }
            $token = Setting::get('geowidget_token', null, 'inpost');
            return (string) ($token ?: config('services.inpost.geowidget_token', ''));
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * ID organizacji InPost – tylko gdy moduł aktywny
     */
    protected function getInpostOrganizationId(): string
    {
        try {
            if (!Schema::hasTable('modules') || !Module::where('name', 'inpost')->where('is_active', true)->exists()) {
                return '';
            }
            return (string) Setting::get('organization_id', config('services.inpost.organization_id', ''), 'inpost');
        } catch (\Exception $e) {
            return '';
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
