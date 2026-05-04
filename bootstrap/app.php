<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\AppServiceProvider::class,
        \App\Providers\ModuleServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\ShareModulesData::class,
        ]);

        $middleware->alias([
            'module' => \App\Http\Middleware\CheckModuleEnabled::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            '2fa' => \App\Http\Middleware\EnsureTwoFactorVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
