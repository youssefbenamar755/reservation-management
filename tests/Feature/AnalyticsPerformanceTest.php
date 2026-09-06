<?php

use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
    config(['inertia.ssr.enabled' => false, 'cache.default' => 'database']);
    Cache::purge('database');
    Http::preventStrayRequests();

    if (DB::getDriverName() === 'sqlite') {
        DB::connection()->getPdo()->sqliteCreateFunction('HOUR', fn ($date) => Carbon::parse($date)->hour);
        DB::connection()->getPdo()->sqliteCreateFunction('DAYOFWEEK', fn ($date) => Carbon::parse($date)->dayOfWeek + 1);
    }

    $this->analyticsUser = User::factory()->create();
    $website = Website::create([
        'user_id' => $this->analyticsUser->id,
        'name' => 'Analytics performance fixture',
        'slug' => 'analytics-performance',
        'base_url' => 'https://analytics.example.test',
        'status' => 'active',
    ]);
    for ($id = 1; $id <= 251; $id++) {
        WcOrder::create([
            'website_id' => $website->id,
            'wp_order_id' => $id,
            'status' => 'completed',
            'total' => 20,
            'created_at_wp' => now(),
            'payload' => [
                'customer_ip_address' => $id % 2 ? '8.8.8.8' : '8.8.4.4',
                'meta_data' => [['key' => '_ppcp_paypal_fees', 'value' => ['paypal_fee' => ['value' => '1.25']]]],
            ],
        ]);
        FfSubmission::create([
            'website_id' => $website->id,
            'form_id' => 1,
            'entry_id' => $id,
            'created_at_wp' => now(),
            'payload' => ['response' => ['flight_from' => 'CMN', 'flight_to' => 'CDG']],
        ]);
    }
    Cache::put('ip_country_8.8.8.8', 'US', 3600);
    Cache::forget('ip_country_8.8.4.4');
});

function analyticsQueryProfile(array $queries): array
{
    $orderPayloads = 0;
    $submissionPayloads = 0;
    $ipCacheReads = 0;
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        if (! str_starts_with(ltrim($sql), 'select')) {
            continue;
        }
        if (str_contains($sql, 'payload')) {
            $orderPayloads += (int) preg_match('/\bfrom\s+["`]?wc_orders\b/', $sql);
            $submissionPayloads += (int) preg_match('/\bfrom\s+["`]?ff_submissions\b/', $sql);
        }
        if (preg_match('/\bfrom\s+["`]?cache\b/', $sql)) {
            $ipBindings = array_filter($query['bindings'], fn ($binding) => is_string($binding) && str_contains($binding, 'ip_country_'));
            $ipCacheReads += (int) ($ipBindings !== []);
        }
    }

    return ['order_payload_selects' => $orderPayloads, 'submission_payload_selects' => $submissionPayloads, 'ip_cache_selects' => $ipCacheReads];
}

function analyticsRecordedRequest($test): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = microtime(true);
    $response = $test->actingAs($test->analyticsUser)->get(route('analytics.index'));
    $elapsed = microtime(true) - $started;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk();

    return ['queries' => $queries, 'elapsed' => $elapsed, 'response' => $response];
}

test('cold analytics scans each payload once and batches repeated cached and missing IP lookups', function () {
    $request = analyticsRecordedRequest($this);
    $profile = analyticsQueryProfile($request['queries']);
    if (getenv('ANALYTICS_PERF_DIAGNOSTICS')) {
        fwrite(STDERR, json_encode($profile + ['total_queries' => count($request['queries']), 'request_seconds' => round($request['elapsed'], 4)]).PHP_EOL);
    }

    expect($profile)->toBe(['order_payload_selects' => 2, 'submission_payload_selects' => 2, 'ip_cache_selects' => 1]);
    $request['response']->assertInertia(fn ($page) => $page
        ->where('stats.total_orders', 251)
        ->where('stats.paypal_fees', 313.75)
        ->where('ordersByCountry.0.count', 126)
        ->where('topDepartureAirports.0.count', 251)
        ->where('topRoutes.0.count', 251));
    Http::assertNothingSent();
});

test('warm analytics cache performs no order or submission payload scans and no IP cache reads', function () {
    analyticsRecordedRequest($this);
    $warm = analyticsRecordedRequest($this);

    expect(analyticsQueryProfile($warm['queries']))->toBe([
        'order_payload_selects' => 0, 'submission_payload_selects' => 0, 'ip_cache_selects' => 0,
    ]);
    $warm['response']->assertInertia(fn ($page) => $page->where('stats.total_orders', 251));
    Http::assertNothingSent();
});
