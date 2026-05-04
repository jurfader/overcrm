<?php

namespace App\Support;

use App\Models\Setting;

class Brand
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $settingKey = 'brand_' . $key;
        $value = Setting::get($settingKey, null, 'branding');
        if ($value !== null && $value !== '') {
            return $value;
        }
        return config("brand.$key", $default);
    }

    public static function all(): array
    {
        return [
            'name'            => self::get('name'),
            'short_name'      => self::get('short_name'),
            'company_name'    => self::get('company_name'),
            'primary_color'   => self::get('primary_color'),
            'secondary_color' => self::get('secondary_color'),
            'use_gradient'    => (bool) self::get('use_gradient'),
            'logo_url'        => self::get('logo_url'),
            'logo_dark_url'   => self::get('logo_dark_url'),
            'favicon_url'     => self::get('favicon_url'),
            'support_email'   => self::get('support_email'),
            'support_phone'   => self::get('support_phone'),
            'default_theme'   => self::get('default_theme'),
        ];
    }
}
