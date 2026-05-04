<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('local')) {
            return;
        }
        URL::forceScheme('https');
        $appUrl = config('app.url', '');
        if ($appUrl) {
            URL::forceRootUrl(preg_replace('#^http:#', 'https:', $appUrl));
        }
    }
}
