<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Zapis brandingu do Settings (moduł 'branding', klucze brand_*).
 *
 * Wydzielone z Admin\BrandingController, bo z tej samej logiki korzystają dwa
 * miejsca: Ustawienia → Wygląd oraz kreator pierwszego uruchomienia (/setup).
 * Odczyt idzie przez App\Support\Brand (Settings → config/brand.php).
 */
class BrandingService
{
    /** Pola tekstowe/kolory zapisywane jako brand_{key} */
    public const FIELDS = [
        'name', 'short_name', 'company_name',
        'primary_color', 'secondary_color', 'use_gradient',
        'support_email', 'support_phone', 'default_theme',
    ];

    /** Pliki graficzne (upload do storage/public/branding) */
    public const ASSETS = ['logo_url', 'logo_dark_url', 'favicon_url'];

    /** Reguły walidacji wspólne dla panelu Ustawień i kreatora. */
    public static function rules(): array
    {
        return [
            'name' => 'nullable|string|max:80',
            'short_name' => 'nullable|string|max:30',
            'company_name' => 'nullable|string|max:120',
            'primary_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'use_gradient' => 'nullable|boolean',
            'support_email' => 'nullable|email|max:120',
            'support_phone' => 'nullable|string|max:40',
            'default_theme' => 'nullable|in:dark,light',
        ];
    }

    public function update(array $data): void
    {
        foreach ($data as $key => $value) {
            if (! in_array($key, self::FIELDS, true)) {
                continue;
            }
            $stored = is_bool($value) ? ($value ? '1' : '0') : $value;
            Setting::set('brand_'.$key, $stored, 'branding');
        }

        Cache::flush();
    }

    /** Zapisuje plik i zwraca publiczny URL (/storage/...). Stary plik kasowany. */
    public function uploadAsset(string $asset, UploadedFile $file): string
    {
        $this->deleteStoredFile($asset);

        $path = $file->store('branding', 'public');
        $url = '/storage/'.$path;

        Setting::set('brand_'.$asset, $url, 'branding');
        Cache::flush();

        return $url;
    }

    public function removeAsset(string $asset): void
    {
        $this->deleteStoredFile($asset);

        Setting::set('brand_'.$asset, null, 'branding');
        Cache::flush();
    }

    /**
     * Kasuje plik z dysku tylko gdy był wgrany przez nas (/storage/...).
     * Wartość z .env (np. zewnętrzny URL) zostawiamy nietkniętą.
     */
    protected function deleteStoredFile(string $asset): void
    {
        $old = Setting::get('brand_'.$asset, null, 'branding');

        if ($old && str_starts_with($old, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $old));
        }
    }
}
