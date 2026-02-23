<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
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
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Security check: Alert if debug mode is enabled in production
        if ($this->app->environment('production') && config('app.debug')) {
            Log::critical('SECURITY ALERT: DEBUG MODE IS ENABLED IN PRODUCTION!', [
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
            ]);
            
            // Forcefully disable debug mode to prevent information leakage
            config(['app.debug' => false]);
        }
    }
}
