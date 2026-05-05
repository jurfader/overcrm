<?php

return [
    // OVERMEDIA license server (sprawdzany co 24h)
    'license' => [
        'url' => env('LICENSE_SERVER_URL', 'http://51.38.137.199:3002'),
    ],

    // Support inbox (zgłoszenia z formularza w aplikacji)
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'support@overmedia.pl'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    
    // GUS API - dane firm po NIP
    'gus' => [
        'api_key' => env('GUS_API_KEY', ''),
        'url' => env('GUS_URL', 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'),
    ],
    
    // Apilo API - zamówienia
    'apilo' => [
        'subdomain' => env('APILO_SUBDOMAIN', ''),
        'client_id' => env('APILO_CLIENT_ID', ''),
        'client_secret' => env('APILO_CLIENT_SECRET', ''),
    ],
    
    // Fakturownia API - faktury
    'fakturownia' => [
        'api_token' => env('FAKTUROWNIA_API_TOKEN', ''),
        'subdomain' => env('FAKTUROWNIA_SUBDOMAIN', ''),
    ],

    // Ringostat - telefonia
    'ringostat' => [
        'auth_key' => env('RINGOSTAT_AUTH_KEY', ''),
        'project_id' => env('RINGOSTAT_PROJECT_ID', ''),
    ],

    // InPost Geowidget – mapa wyboru paczkomatu (token z manager.paczkomaty.pl)
    'inpost' => [
        'geowidget_token' => env('INPOST_GEOWIDGET_TOKEN', ''),
        'organization_id' => env('INPOST_ORGANIZATION_ID', ''),
    ],
];
