<?php

use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Sync website',
        'slug' => 'sync-website',
        'base_url' => 'https://sync.example',
        'status' => 'active',
        'wc_consumer_key' => 'test-key',
        'wc_consumer_secret' => 'test-secret',
    ]);
});

test('the sync endpoint resumes pages and reports completion only after scanning the window', function () {
    $sequence = Http::sequence();
    for ($id = 1; $id <= 3; $id++) {
        $sequence->push([[
            'id' => $id,
            'status' => 'completed',
            'total' => '20.00',
            'date_modified_gmt' => '2026-01-01T00:00:00',
        ]]);
    }
    $sequence->push([]);
    Http::fake(['https://sync.example/*' => $sequence]);

    $this->actingAs($this->owner);
    for ($page = 0; $page < 3; $page++) {
        $this->postJson(route('websites.sync-woocommerce-orders', $this->website), ['per_page' => 1])
            ->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('synced', 1);
        expect($this->website->fresh()->wc_orders_synced_at)->toBeNull();
    }

    // Pagination is not rejected by the old three-syncs-per-minute limit.
    $this->postJson(route('websites.sync-woocommerce-orders', $this->website), ['per_page' => 1])
        ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('synced', 0);

    expect($this->website->fresh()->wc_orders_synced_at)->not->toBeNull();
    expect($this->website->wcOrders()->count())->toBe(3);
    Http::assertSentCount(4);
});

test('a sync API failure is returned explicitly without a success redirect', function () {
    Http::fake(['https://sync.example/*' => Http::response(['message' => 'Unavailable'], 503)]);

    $this->actingAs($this->owner)
        ->postJson(route('websites.sync-woocommerce-orders', $this->website))
        ->assertOk()->assertJsonPath('status', 'error');

    expect($this->website->fresh()->wc_orders_synced_at)->toBeNull();
});

test('another user cannot request a website sync page', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('websites.sync-woocommerce-orders', $this->website))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('inactive websites cannot be synced', function () {
    $this->website->update(['status' => 'inactive']);
    $this->actingAs($this->owner)
        ->postJson(route('websites.sync-woocommerce-orders', $this->website))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('administrators can request another owners sync page', function () {
    Http::fake(['https://sync.example/*' => Http::response([])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->postJson(route('websites.sync-woocommerce-orders', $this->website))
        ->assertOk()->assertJsonPath('status', 'success');
});

test('legacy bulk sync directs users to the resumable Orders flow without scanning inline', function () {
    $this->actingAs($this->owner)->post(route('websites.sync-all-woocommerce-orders'))
        ->assertRedirect(route('orders.index'))->assertSessionHas('error');

    Http::assertNothingSent();
});
