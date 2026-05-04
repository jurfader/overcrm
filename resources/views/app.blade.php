<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if(!app()->environment('local'))
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://geowidget.inpost.pl; frame-src 'self' https://geowidget.inpost.pl https://geowidget-app.inpost.pl; connect-src 'self' https://geowidget.inpost.pl https://geowidget-app.inpost.pl https://*.easypack24.net https://api.inpost.pl;">
        @endif
        <title inertia>{{ config('app.name', 'Planner') }}</title>
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="https://geowidget.inpost.pl/inpost-geowidget.css"/>
        <script src="https://geowidget.inpost.pl/inpost-geowidget.js" defer></script>
        @inertiaHead
    </head>
    <body class="h-full font-sans antialiased bg-gray-50 dark:bg-gray-900">
        @inertia
    </body>
</html>
