<?php

use App\Http\Requests\OrderListFilterRequest;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->owner->id, 'name' => 'Orders demo', 'slug' => 'orders-demo', 'base_url' => 'https://orders.example']);
});

function listingOrder(Website $website, int $id, array $values = []): WcOrder
{
    return WcOrder::create(array_replace([
        'website_id' => $website->id, 'wp_order_id' => $id, 'status' => 'completed', 'currency' => 'USD', 'total' => 25,
        'customer_email' => 'synthetic@example.test', 'customer_name' => 'Demo customer', 'created_at_wp' => '2026-09-02 12:00:00',
        'payload' => ['private' => 'must-not-load'],
    ], $values));
}

test('orders matching summary spans all pages and keeps completed currencies separate', function () {
    foreach (range(1, 20) as $id) {
        listingOrder($this->website, $id, ['currency' => $id <= 10 ? 'usd' : 'EUR', 'total' => 10.10]);
    }
    listingOrder($this->website, 21, ['status' => 'pending', 'total' => 999]);
    listingOrder($this->website, 22, ['status' => 'on-hold']);
    listingOrder($this->website, 23, ['status' => 'processing']);
    listingOrder($this->website, 24, ['status' => 'failed']);
    $this->actingAs($this->owner)->getJson(route('orders.index'))->assertOk()->assertJsonCount(15, 'orders.data')
        ->assertJsonPath('orders.total', 24)->assertJsonPath('orders.per_page', 15)->assertJsonPath('orders.timezone', 'UTC')
        ->assertJsonPath('orders.summary.completed', 20)->assertJsonPath('orders.summary.pending', 1)
        ->assertJsonPath('orders.summary.on_hold', 1)->assertJsonPath('orders.summary.processing', 1)->assertJsonPath('orders.summary.failed', 1)
        ->assertJsonPath('orders.summary.completed_revenue', [['currency' => 'EUR', 'total' => 101], ['currency' => 'USD', 'total' => 101]])
        ->assertJsonMissingPath('summary')->assertJsonMissingPath('orders.data.0.payload');
    Http::assertNothingSent();
});

test('orders summary respects status website search and date filters together', function () {
    $second = Website::create(['user_id' => $this->owner->id, 'name' => 'Other', 'slug' => 'other', 'base_url' => 'https://other.example']);
    listingOrder($this->website, 1, ['status' => 'processing', 'customer_name' => 'Target', 'created_at_wp' => '2026-09-01 10:00:00']);
    listingOrder($this->website, 2, ['status' => 'pending', 'customer_name' => 'Target', 'created_at_wp' => '2026-09-01 10:00:00']);
    listingOrder($this->website, 3, ['status' => 'processing', 'customer_name' => 'Different', 'created_at_wp' => '2026-09-01 10:00:00']);
    listingOrder($this->website, 4, ['status' => 'processing', 'customer_name' => 'Target', 'created_at_wp' => '2026-08-31 10:00:00']);
    listingOrder($second, 1, ['status' => 'processing', 'customer_name' => 'Target', 'created_at_wp' => '2026-09-01 10:00:00']);
    $this->actingAs($this->owner)->getJson(route('orders.index', ['website_id' => $this->website->id, 'status' => 'processing', 'search' => 'Target', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01']))
        ->assertOk()->assertJsonPath('orders.total', 1)->assertJsonPath('orders.summary.processing', 1)
        ->assertJsonPath('orders.summary.pending', 0)->assertJsonPath('orders.summary.completed', 0)->assertJsonPath('orders.summary.completed_revenue', []);
});

test('orders day filters include both midnight boundaries and exclude the following day', function () {
    foreach (['2026-08-31 23:59:59', '2026-09-01 00:00:00', '2026-09-02 23:59:59', '2026-09-03 00:00:00'] as $id => $date) {
        listingOrder($this->website, $id + 1, ['created_at_wp' => $date]);
    }
    $this->actingAs($this->owner)->getJson(route('orders.index', ['start_date' => '2026-09-01', 'end_date' => '2026-09-02']))
        ->assertOk()->assertJsonPath('orders.total', 2)->assertJsonPath('orders.summary.completed', 2)
        ->assertJsonPath('orders.data.0.wp_order_id', 3)->assertJsonPath('orders.data.1.wp_order_id', 2);
});

test('orders sorting has deterministic IDs for equal dates and amounts', function (string $sort, array $expected) {
    listingOrder($this->website, 1, ['created_at_wp' => '2026-09-01 10:00:00', 'total' => 30]);
    listingOrder($this->website, 2, ['created_at_wp' => '2026-09-02 10:00:00', 'total' => 10]);
    listingOrder($this->website, 3, ['created_at_wp' => '2026-09-02 10:00:00', 'total' => 10]);
    $response = $this->actingAs($this->owner)->getJson(route('orders.index', ['sort' => $sort]))->assertOk();
    expect(array_column($response->json('orders.data'), 'wp_order_id'))->toBe($expected);
})->with([
    ['newest', [3, 2, 1]], ['oldest', [1, 2, 3]], ['highest', [1, 3, 2]], ['lowest', [2, 3, 1]],
]);

test('every standard WooCommerce status can be selected', function (string $status) {
    listingOrder($this->website, 1, ['status' => $status]);
    $this->actingAs($this->owner)->getJson(route('orders.index', ['status' => $status]))->assertOk()->assertJsonPath('orders.total', 1)->assertJsonPath('orders.data.0.status', $status);
})->with(OrderListFilterRequest::STATUSES);

test('orders search treats percent underscore and escape characters literally', function (string $search) {
    listingOrder($this->website, 1, ['customer_name' => "Exact $search value"]);
    listingOrder($this->website, 2, ['customer_name' => 'Different value']);
    $this->actingAs($this->owner)->getJson(route('orders.index', ['search' => $search]))->assertOk()->assertJsonPath('orders.total', 1)->assertJsonPath('orders.data.0.wp_order_id', 1);
})->with(['%', '_', '!', '!%_']);

test('orders filters reject invalid input before querying the listing', function (array $filters) {
    $this->actingAs($this->owner)->getJson(route('orders.index', $filters))->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    [['website_id' => -1]], [['website_id' => ['1']]], [['status' => 'invalid']], [['search' => str_repeat('x', 201)]],
    [['search' => ['bad']]], [['start_date' => '2026-02-30']], [['end_date' => 'September 2']],
    [['start_date' => '2026-09-02', 'end_date' => '2026-09-01']], [['sort' => 'total desc']], [['per_page' => 20]], [['page' => 0]],
]);

test('orders selected website cannot bypass tenant scope while admins can use it', function () {
    $foreign = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Foreign', 'slug' => 'foreign', 'base_url' => 'https://foreign.example']);
    listingOrder($this->website, 1, ['total' => 10]);
    listingOrder($foreign, 1, ['total' => 1000]);
    $this->actingAs($this->owner)->getJson(route('orders.index'))->assertOk()->assertJsonPath('orders.total', 1)->assertJsonPath('orders.summary.completed_revenue.0.total', 10);
    $this->getJson(route('orders.index', ['website_id' => $foreign->id]))->assertForbidden();
    $this->getJson(route('orders.index', ['website_id' => 999999]))->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson(route('orders.index', ['website_id' => $foreign->id]))
        ->assertOk()->assertJsonPath('orders.total', 1)->assertJsonPath('orders.summary.completed_revenue.0.total', 1000);
});

test('orders supported page sizes and links preserve applied filters', function (int $perPage) {
    foreach (range(1, 32) as $id) {
        listingOrder($this->website, $id);
    }
    $response = $this->actingAs($this->owner)->getJson(route('orders.index', ['per_page' => $perPage, 'sort' => 'oldest', 'start_date' => '2026-09-01']))->assertOk()
        ->assertJsonPath('orders.per_page', $perPage)->assertJsonPath('orders.total', 32)->assertJsonCount(min(32, $perPage), 'orders.data');
    expect($response->json('orders.first_page_url'))->toContain('sort=oldest', 'start_date=2026-09-01', 'per_page='.$perPage);
})->with([15, 30, 50, 100]);

test('orders filters normalize supplied values and omit absent defaults', function () {
    $this->actingAs($this->owner)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->where('filters', []));
    $this->get(route('orders.index', ['website_id' => (string) $this->website->id, 'per_page' => '30', 'search' => '  Demo  ']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.website_id', $this->website->id)->where('filters.per_page', 30)->where('filters.search', 'Demo')->missing('filters.sort'));
});

test('orders JSON uses five listing queries with no payload or named selector load', function () {
    listingOrder($this->website, 1);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $this->actingAs($this->owner)->getJson(route('orders.index'))->assertOk();
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/\b(?:wc_orders|websites)\b/', $query['query']))->values();
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(5);
    foreach ($queries as $query) {
        expect(strtolower($query['query']))->not->toContain('payload')->not->toContain('select *');
    }
    expect(array_keys($response->json()))->toBe(['orders']);
    $response->assertJsonPath('orders.data.0.website', ['id' => $this->website->id, 'name' => 'Orders demo'])
        ->assertJsonMissingPath('websites')->assertJsonMissingPath('filters');
});
