<?php

namespace App\Providers;

use App\Services\LogoService;
use Illuminate\Support\ServiceProvider;

class LogoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('logo.service', function () {
            return new LogoService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
