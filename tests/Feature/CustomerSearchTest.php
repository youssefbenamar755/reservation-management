<?php

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
    $this->other = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'First website', 'slug' => 'search-first', 'base_url' => 'https://first.example']);
    $this->second = Website::create(['user_id' => $this->owner->id, 'name' => 'Second website', 'slug' => 'search-second', 'base_url' => 'https://second.example']);
    $this->foreign = Website::create(['user_id' => $this->other->id, 'name' => 'Foreign website', 'slug' => 'search-foreign', 'base_url' => 'https://foreign.example']);
});

function customerSearchOrder(Website $website, array $values = []): WcOrder
{
    static $id = 0;

    return WcOrder::create(array_merge([
        'website_id' => $website->id, 'wp_order_id' => ++$id,
        'customer_email' => 'customer+test@example.test', 'customer_name' => 'Morgan Sample',
        'status' => 'completed', 'currency' => 'USD', 'total' => 100,
        'created_at_wp' => '2026-09-06 12:00:00', 'payload' => ['billing' => ['country' => 'MA']],
    ], $values));
}

test('name and email searches select customer groups without truncating their scoped orders', function (string $search) {
    customerSearchOrder($this->site);
    customerSearchOrder($this->site, ['customer_name' => 'Changed Name', 'total' => 60]);
    customerSearchOrder($this->site, ['customer_name' => 'Changed Name', 'status' => 'pending', 'total' => 400]);
    customerSearchOrder($this->site, ['customer_email' => 'separate@example.test', 'customer_name' => 'Other Customer']);
    customerSearchOrder($this->second, ['total' => 500]);
    customerSearchOrder($this->foreign, ['total' => 900]);
    $filters = ['search' => $search, 'website_ids' => [$this->site->id], 'min_spend' => 150];

    DB::enableQueryLog();
    $this->actingAs($this->owner)->get(route('customers.index', $filters))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)
            ->where('customers.data.0.email', 'customer+test@example.test')
            ->where('customers.data.0.orders_count', 3)
            ->where('customers.data.0.total_spent', 160)
            ->where('customers.data.0.average_order_value', 80)
            ->where('customers.data.0.websites', ['First website'])
            ->where('filters.search', trim($search)));
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'wc_orders')))->toHaveCount(4);
    DB::disableQueryLog();

    $content = $this->get(route('customers.export', $filters))->assertOk()->streamedContent();
    expect($content)->toContain('customer+test@example.test,3,160.00,80.00')
        ->not->toContain('separate@example.test')->not->toContain('Second website')->not->toContain('Foreign website');

    $this->get('/customers/'.rawurlencode('customer+test@example.test').'?'.http_build_query($filters))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customer.total_orders', 3)
            ->where('customer.total_spent', 160)->where('customer.average_order_value', 80)->has('orders', 3));
    Http::assertNothingSent();
})->with(['name' => '  Morgan  ', 'email' => 'customer+test@', 'case-insensitive email' => 'CUSTOMER+TEST@']);

test('search never matches names from excluded websites dates countries or payment states', function (array $filters, array $excludedValues, string $website) {
    customerSearchOrder($this->site, ['customer_name' => 'Visible Name']);
    customerSearchOrder($this->{$website}, array_merge(['customer_name' => 'Hidden Name'], $excludedValues));
    $filters['search'] = 'Hidden';
    if ($website === 'second') {
        $filters['website_ids'] = [$this->site->id];
    }
    $this->actingAs($this->owner)->get(route('customers.index', $filters))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    $this->get('/customers/'.rawurlencode('customer+test@example.test').'?'.http_build_query($filters))->assertNotFound();
    expect($this->get(route('customers.export', $filters))->assertOk()->streamedContent())->not->toContain('customer+test@example.test');
})->with([
    'foreign website' => [[], [], 'foreign'],
    'unselected website' => [[], [], 'second'],
    'date' => [['start_date' => '2026-09-01'], ['created_at_wp' => '2026-01-01 00:00:00'], 'site'],
    'country' => [['country' => 'MA'], ['payload' => ['billing' => ['country' => 'FR']]], 'site'],
    'payment' => [['payment_status' => 'paid'], ['status' => 'pending'], 'site'],
]);

test('search treats wildcard characters as literal text and supports zero', function (string $search) {
    customerSearchOrder($this->site, ['customer_name' => 'Literal '.$search.' Name']);
    customerSearchOrder($this->site, ['customer_email' => 'other@example.test', 'customer_name' => 'Other Name']);
    $this->actingAs($this->owner)->get(route('customers.index', ['search' => $search]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.email', 'customer+test@example.test'));
})->with(['%', '_', '!', '0']);

test('detail uses all applied order filters and returns to the same search sort and page', function () {
    $expected = customerSearchOrder($this->site);
    customerSearchOrder($this->site, ['status' => 'pending', 'total' => 40]);
    customerSearchOrder($this->site, ['created_at_wp' => '2026-01-01 00:00:00', 'total' => 800]);
    customerSearchOrder($this->site, ['payload' => ['billing' => ['country' => 'FR']], 'total' => 500]);
    customerSearchOrder($this->second, ['total' => 900]);
    $filters = ['search' => 'Morgan', 'website_ids' => [$this->site->id], 'start_date' => '2026-09-01', 'country' => 'MA', 'payment_status' => 'paid', 'min_spend' => 80, 'sort_by' => 'total_spent', 'sort_dir' => 'asc', 'page' => 3, 'per_page' => 25];
    $this->actingAs($this->owner)->get('/customers/'.rawurlencode($expected->customer_email).'?'.http_build_query($filters))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.email', $expected->customer_email)->where('customer.total_orders', 1)
            ->where('customer.total_spent', 100)->where('customer.average_order_value', 100)
            ->where('customer.country', 'MA')->where('customer.first_order_at', '2026-09-06 12:00:00')
            ->has('orders', 1)->where('orders.0.id', $expected->id)->has('countryHistory', 1)
            ->has('websiteBreakdown', 1)->has('revenueOverTime', 1)
            ->where('returnUrl', function ($url) use ($filters) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == $filters;
            }));
    $this->get('/customers/'.rawurlencode($expected->customer_email).'?'.http_build_query([...$filters, 'min_spend' => 101]))->assertNotFound();
});

test('pending only customers have zero AOV consistently in list detail and CSV', function () {
    $order = customerSearchOrder($this->site, ['status' => 'pending']);
    $this->actingAs($this->owner)->get(route('customers.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.average_order_value', 0));
    $this->get('/customers/'.rawurlencode($order->customer_email))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customer.average_order_value', 0));
    expect($this->get(route('customers.export'))->assertOk()->streamedContent())->toContain('customer+test@example.test,1,0.00,0.00');
});

test('customer detail decodes plus and percent addresses only once', function (string $email) {
    customerSearchOrder($this->site, ['customer_email' => $email]);
    $this->actingAs($this->owner)->get('/customers/'.rawurlencode($email))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customer.email', $email));
})->with(['customer+tag@example.test', 'literal%2B@example.test']);

test('search and detail filters validate input before querying', function (array $filters, string $field) {
    foreach (['/customers', '/customers/export', '/customers/customer%40example.test'] as $url) {
        $this->actingAs($this->owner)->getJson($url.'?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
})->with([
    'long search' => [['search' => str_repeat('a', 201)], 'search'],
    'array search' => [['search' => ['x']], 'search'],
    'malformed date' => [['start_date' => 'tomorrow'], 'start_date'],
]);
