<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();

    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->ownedWebsite = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Owned website',
        'slug' => 'owned-website',
        'base_url' => 'https://owned.example',
    ]);
    $this->otherWebsite = Website::create([
        'user_id' => $this->other->id,
        'name' => 'Other website',
        'slug' => 'other-website',
        'base_url' => 'https://other.example',
    ]);

    foreach ([
        [$this->ownedWebsite, 1, 'shared@example.com', 100, 'MA'],
        [$this->otherWebsite, 1, 'shared@example.com', 200, 'US'],
        [$this->otherWebsite, 2, 'shared@example.com', 300, 'US'],
        [$this->otherWebsite, 3, 'private@example.com', 400, 'US'],
    ] as [$website, $orderId, $email, $total, $country]) {
        WcOrder::create([
            'website_id' => $website->id,
            'wp_order_id' => $orderId,
            'customer_email' => $email,
            'customer_name' => 'Test Customer',
            'status' => 'completed',
            'currency' => 'USD',
            'total' => $total,
            'created_at_wp' => now(),
            'payload' => ['billing' => ['country' => $country]],
        ]);
    }
});

test('customer list scopes customers totals countries and websites to the owner', function () {
    $this->actingAs($this->owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.email', 'shared@example.com')
            ->where('customers.data.0.orders_count', 1)
            ->where('customers.data.0.total_spent', 100)
            ->where('customers.data.0.country', 'MA')
            ->where('customers.data.0.websites', ['Owned website'])
            ->where('countries', ['MA'])
            ->has('websites', 1)
            ->where('websites.0.id', $this->ownedWebsite->id)
        );
});

test('customer filters cannot select another users website or country', function (string $filter) {
    $query = $filter === 'website'
        ? ['website_ids' => [$this->otherWebsite->id]]
        : ['country' => 'US'];

    $this->actingAs($this->owner)
        ->get(route('customers.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 0)
            ->where('countries', ['MA'])
            ->has('websites', 1)
            ->where('websites.0.id', $this->ownedWebsite->id)
        );
})->with(['website', 'country']);

test('customer details exclude another websites orders for the same email', function () {
    $this->actingAs($this->owner)
        ->get(route('customers.show', ['email' => 'shared@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.total_orders', 1)
            ->where('customer.total_spent', 100)
            ->where('customer.country', 'MA')
            ->has('orders', 1)
            ->where('orders.0.website_name', 'Owned website')
            ->has('customer.websites', 1)
            ->where('customer.websites.0.id', $this->ownedWebsite->id)
            ->has('websiteBreakdown', 1)
            ->where('websiteBreakdown.0.website_id', $this->ownedWebsite->id)
            ->has('countryHistory', 1)
            ->where('countryHistory.0.country', 'MA')
        );

    $this->get(route('customers.show', ['email' => 'private@example.com']))
        ->assertNotFound();
});

test('a user without websites cannot see any customers or filter options', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 0)
            ->has('countries', 0)
            ->has('websites', 0)
        );

    $this->get(route('customers.show', ['email' => 'shared@example.com']))
        ->assertNotFound();
});

test('admins retain access to customers across all websites', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 2)
            ->has('websites', 2)
            ->where('countries', ['MA', 'US'])
        );

    $this->get(route('customers.show', ['email' => 'shared@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.total_orders', 3)
            ->where('customer.total_spent', 600)
            ->where('customer.country', 'US')
            ->has('orders', 3)
            ->has('customer.websites', 2)
        );
});

test('customer pages only use validated cached country enrichment without HTTP', function ($cachedCountry, ?string $expectedCountry) {
    Http::fake(['*' => Http::response('US')]);
    $this->ownedWebsite->wcOrders()->first()->update([
        'payload' => ['customer_ip_address' => '8.8.8.8'],
    ]);
    if ($cachedCountry !== null) {
        Cache::put('ip_country_8.8.8.8', $cachedCountry, 3600);
    }

    $this->actingAs($this->owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.country', $expectedCountry)
            ->where('countries', $expectedCountry ? [$expectedCountry] : [])
        );

    $this->get(route('customers.show', ['email' => 'shared@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.country', $expectedCountry)
            ->has('countryHistory', $expectedCountry ? 1 : 0)
            ->has('orders', 1)
        );

    $this->get(route('customers.index', ['country' => 'US']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', $expectedCountry === 'US' ? 1 : 0)
        );

    Http::assertNothingSent();
})->with([
    'missing country cache' => [null, null],
    'cached country normalized' => [' us ', 'US'],
    'invalid cached country' => ['error!', null],
    'non-string cached value' => [['country' => 'US'], null],
]);
