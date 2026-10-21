<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
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
        if (app(\App\Services\DocumentValidationSafety::class)->enabled()) {
            $safety = app(\App\Services\DocumentValidationSafety::class);
            $safety->assertEnvironment();
            config(['mail.default' => 'array', 'broadcasting.default' => 'null']);
            \Illuminate\Support\Facades\Http::globalRequestMiddleware($safety->guardRequest(...));
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, static fn () => false);
            \Illuminate\Support\Facades\Queue::before(static function (\Illuminate\Queue\Events\JobProcessing $event) {
                if ($event->job->resolveName() !== \App\Jobs\SyncDocumentCompletion::class) {
                    throw new \RuntimeException('Validation blocked an unrelated or copied queued job.');
                }
            });
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(\App\Http\Middleware\DocumentValidationIsolation::class);
        }
        \App\Models\WcOrder::observe(\App\Observers\MarketingSourceObserver::class);
        \App\Models\FfSubmission::observe(\App\Observers\MarketingSourceObserver::class);

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
