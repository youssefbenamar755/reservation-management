<?php

use App\Console\Commands\SyncWooCommerceOrders;
use App\Console\Commands\PruneOrderEmailPreviews;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Backup WooCommerce order reconciliation every five minutes ───────────
// Reconciles orders modified since the last completed scan, with an overlap.
// The first run scans history; later runs only fetch recent changes.
//
// To run manually: php artisan orders:sync-woocommerce
// To sync a single website: php artisan orders:sync-woocommerce --website=1
// To repair historical gaps again: php artisan orders:sync-woocommerce --full
//
// The hosting scheduler must run `php artisan schedule:run` every minute.
// ──────────────────────────────────────────────────────────────────────────
Schedule::command(SyncWooCommerceOrders::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10) // recover a stale lock within ten minutes after an interrupted run
    ->runInBackground()      // don't block other scheduled tasks
    ->appendOutputTo(storage_path('logs/woo-sync.log'));

Schedule::command(PruneOrderEmailPreviews::class)->daily()->withoutOverlapping(10);

// Cloud's scheduler drains this dedicated queue without a permanently running worker.
// max-time is checked between jobs; retry_after exceeds the individual job timeout.
Schedule::command('queue:work traffic --queue=traffic --stop-when-empty --max-jobs=4 --max-time=50 --timeout=180 --tries=1 --sleep=1')
    ->everyMinute()->withoutOverlapping(6)->runInBackground();

Schedule::command(\App\Console\Commands\TrafficPruneReports::class)->daily()->withoutOverlapping(5);

// Grouped alerts read local records; no additional WooCommerce or Google requests.
Schedule::command(\App\Console\Commands\ScanUsefulAlerts::class)->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
