<?php

return [
    'name'           => env('BRAND_NAME', 'OVERCRM'),
    'short_name'     => env('BRAND_SHORT_NAME', 'OVERCRM'),
    'company_name'   => env('BRAND_COMPANY_NAME', 'OVERCRM'),

    'primary_color'   => env('BRAND_PRIMARY', '#E91E8C'),
    'secondary_color' => env('BRAND_SECONDARY', '#9B26D9'),
    'use_gradient'    => filter_var(env('BRAND_USE_GRADIENT', true), FILTER_VALIDATE_BOOLEAN),

    'logo_url'      => env('BRAND_LOGO_URL'),
    'logo_dark_url' => env('BRAND_LOGO_DARK_URL'),
    'favicon_url'   => env('BRAND_FAVICON_URL', '/favicon.ico'),

    'support_email' => env('BRAND_SUPPORT_EMAIL'),
    'support_phone' => env('BRAND_SUPPORT_PHONE'),

    'default_theme' => env('BRAND_DEFAULT_THEME', 'dark'),
];
