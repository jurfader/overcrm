<?php

namespace Modules\Inpost;

use Illuminate\Support\ServiceProvider;

class InpostServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'inpost');
    }
}
