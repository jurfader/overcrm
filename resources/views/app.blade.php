<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if(!app()->environment('local'))
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://geowidget.inpost.pl; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; frame-src 'self' https://geowidget.inpost.pl https://geowidget-app.inpost.pl; connect-src 'self' https://geowidget.inpost.pl https://geowidget-app.inpost.pl https://*.easypack24.net https://api.inpost.pl;">
        @endif

        <title inertia>{{ brand('name') }}</title>

        <link rel="icon" type="image/x-icon" href="{{ brand('favicon_url', '/favicon.ico') }}">

        {{-- Fonts: Inter (UI) + JetBrains Mono (code) --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap">

        {{-- Brand CSS variables: override defaultów z app.css --}}
        <style id="brand-vars">
            :root {
                --brand-primary:   {{ brand('primary_color') }};
                --brand-secondary: {{ brand('secondary_color') }};
            }
        </style>

        {{-- Theme bootstrap: ustawia data-theme PRZED renderowaniem (no flash) --}}
        <script>
            (function () {
                try {
                    var saved = localStorage.getItem('overcrm-theme');
                    var defaultTheme = @json(brand('default_theme', 'dark'));
                    var theme = (saved === 'dark' || saved === 'light') ? saved : defaultTheme;
                    document.documentElement.setAttribute('data-theme', theme);
                } catch (e) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            })();
        </script>

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="https://geowidget.inpost.pl/inpost-geowidget.css"/>
        <script src="https://geowidget.inpost.pl/inpost-geowidget.js" defer></script>
        @inertiaHead
    </head>
    <body class="h-full font-sans antialiased">
        @inertia
    </body>
</html>
