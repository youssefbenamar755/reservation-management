<?php

use App\Console\Commands\SyncWooCommerceOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Auto-sync WooCommerce orders every 30 minutes ─────────────────────────
// Reconciles orders modified since the last completed scan, with an overlap.
// The first run scans history; later runs only fetch recent changes.
//
// To run manually: php artisan orders:sync-woocommerce
// To sync a single website: php artisan orders:sync-woocommerce --website=1
// To repair historical gaps again: php artisan orders:sync-woocommerce --full
//
// On Laravel Cloud (PAYG plan): Add a Scheduled Task running
//   `php artisan schedule:run` every minute — it's free and included.
// ──────────────────────────────────────────────────────────────────────────
Schedule::command(SyncWooCommerceOrders::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping()   // skip if a previous run is still going
    ->runInBackground()      // don't block other scheduled tasks
    ->appendOutputTo(storage_path('logs/woo-sync.log'));
