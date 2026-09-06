<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('customer SQL pagination bounds enrichment and batches cached country reads', function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false, 'cache.default' => 'database']);
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $site = Website::create(['user_id' => $owner->id, 'name' => 'Performance demo', 'slug' => 'performance-demo', 'base_url' => 'https://performance.example']);
    $orders = [];
    for ($i = 0; $i < 601; $i++) {
        $orders[] = ['website_id' => $site->id, 'wp_order_id' => $i + 1, 'customer_email' => sprintf('customer%03d@example.com', $i), 'status' => 'completed', 'total' => 10, 'created_at_wp' => '2026-09-06 12:00:00', 'payload' => json_encode(['customer_ip_address' => '8.8.8.8'])];
    }
    WcOrder::insert($orders);
    Cache::put('ip_country_8.8.8.8', 'MA', 3600);

    DB::enableQueryLog();
    $this->actingAs($owner)->get(route('customers.index', ['per_page' => 15, 'sort_by' => 'total_spent']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 15)->where('customers.total', 601)->where('customers.data.0.email', 'customer000@example.com')->where('customers.data.0.country', 'MA')->where('countries', ['MA']));
    $coldQueries = collect(DB::getQueryLog());
    $coldPayloads = $coldQueries->filter(fn ($query) => str_contains($query['query'], 'payload') && str_contains($query['query'], 'wc_orders'));
    expect($coldPayloads)->toHaveCount(4); // One 15-customer page and three 250-order dropdown batches.
    foreach ($coldPayloads as $query) {
        expect($query['query'])->toContain('limit 250')->not->toContain('offset');
    }
    $ipReads = $coldQueries->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains(implode(' ', $query['bindings']), 'ip_country_8.8.8.8'));
    expect($ipReads)->toHaveCount(1);
    expect($ipReads->first()['query'])->toContain(' in (');
    expect($coldQueries->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'websites')))->toHaveCount(1);

    DB::flushQueryLog();
    $this->get(route('customers.index', ['page' => 2, 'per_page' => 15, 'sort_by' => 'total_spent']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 15)->where('customers.total', 601)->where('customers.data.0.email', 'customer015@example.com'));
    $warmQueries = collect(DB::getQueryLog());
    expect($warmQueries->filter(fn ($query) => str_contains($query['query'], 'payload') && str_contains($query['query'], 'wc_orders')))->toHaveCount(1);
    expect($warmQueries->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'wc_orders')))->toHaveCount(3);
    DB::disableQueryLog();
    Http::assertNothingSent();
});
