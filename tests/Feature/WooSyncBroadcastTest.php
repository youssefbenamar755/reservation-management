<?php

use App\Events\NewWcOrderReceived;
use App\Events\WcOrdersSynced;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\WooCommerceOrderStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
    config(['broadcasting.default' => 'null']);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Scheduled website',
        'slug' => 'scheduled-website',
        'base_url' => 'https://scheduled.example.test',
        'status' => 'active',
        'wc_consumer_key' => 'fixture-key',
        'wc_consumer_secret' => 'fixture-secret',
        'wc_orders_synced_at' => '2026-09-06 11:30:00',
    ]);
    $this->payload = [
        'id' => 42,
        'status' => 'pending',
        'currency' => 'USD',
        'total' => '100.00',
        'date_created_gmt' => '2026-09-01T09:00:00',
        'date_modified_gmt' => '2026-09-06T11:45:00',
        'billing' => ['email' => 'customer@example.test'],
    ];
});

test('CLI sync batches multiple changed pages into one refresh per website', function () {
    Event::fake([WcOrdersSynced::class, NewWcOrderReceived::class]);
    app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload);
    $unchangedWebsite = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Unchanged website',
        'slug' => 'unchanged-website',
        'base_url' => 'https://unchanged.example.test',
        'status' => 'active',
        'wc_consumer_key' => 'fixture-key',
        'wc_consumer_secret' => 'fixture-secret',
    ]);
    Http::fake([
        'scheduled.example.test/*' => Http::sequence()
            ->push([array_replace($this->payload, ['status' => 'completed', 'date_modified_gmt' => '2026-09-06T11:46:00'])])
            ->push([array_replace($this->payload, ['id' => 43, 'date_modified_gmt' => '2026-09-06T11:47:00'])])
            ->push([]),
        'unchanged.example.test/*' => Http::response([]),
    ]);

    $this->artisan('orders:sync-woocommerce', ['--per-page' => 1])
        ->expectsOutputToContain('1 new order(s) synced; 1 existing order(s) updated')
        ->assertSuccessful();

    Event::assertDispatchedTimes(WcOrdersSynced::class, 1);
    Event::assertDispatched(WcOrdersSynced::class, fn ($event) => $event->website->is($this->website));
    Event::assertNotDispatched(NewWcOrderReceived::class);
    expect($this->website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 12:00:00')
        ->and($unchangedWebsite->fresh()->wc_orders_synced_at)->not->toBeNull()
        ->and(WcOrder::where('wp_order_id', 42)->sole()->status)->toBe('completed');
    $this->assertDatabaseCount('wc_orders', 2);
    $this->assertDatabaseCount('notifications', 0);
    Http::assertSentCount(4);
});

test('CLI sync broadcasts updated existing orders without creating notifications', function () {
    Event::fake([WcOrdersSynced::class, NewWcOrderReceived::class]);
    app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload);
    Http::fakeSequence()->push([array_replace($this->payload, ['status' => 'completed'])]);

    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id])
        ->expectsOutputToContain('Synced 0 new WooCommerce order(s); updated 1 existing order(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(WcOrdersSynced::class, 1);
    Event::assertNotDispatched(NewWcOrderReceived::class);
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 0);
});

test('CLI sync with no changed orders does not broadcast a refresh', function (string $delivery) {
    Event::fake([WcOrdersSynced::class, NewWcOrderReceived::class]);
    app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload);
    $payload = $delivery === 'stale'
        ? array_replace($this->payload, ['date_modified_gmt' => '2026-09-06T11:44:00'])
        : $this->payload;
    Http::fakeSequence()->push($delivery === 'empty' ? [] : [$payload]);

    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id])
        ->assertSuccessful();

    Event::assertNotDispatched(WcOrdersSynced::class);
    Event::assertNotDispatched(NewWcOrderReceived::class);
    $this->assertDatabaseCount('notifications', 0);
})->with(['empty', 'identical', 'stale']);

test('CLI sync announces persisted pages even when a later page fails', function () {
    Event::fake([WcOrdersSynced::class, NewWcOrderReceived::class]);
    Http::fakeSequence()->push([$this->payload])->pushStatus(503);

    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id, '--per-page' => 1])
        ->assertFailed();

    Event::assertDispatchedTimes(WcOrdersSynced::class, 1);
    expect($this->website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 11:30:00');
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 0);
});

test('CLI sync failure without persisted changes does not broadcast a refresh', function () {
    Event::fake([WcOrdersSynced::class]);
    Http::fakeSequence()->pushStatus(503);

    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id])->assertFailed();

    Event::assertNotDispatched(WcOrdersSynced::class);
    $this->assertDatabaseCount('wc_orders', 0);
});

test('a batched broadcast outage preserves CLI success and completed checkpoints', function () {
    $attempts = 0;
    Event::listen(WcOrdersSynced::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Fixture broadcaster outage');
    });
    $secondWebsite = $this->website->replicate()->fill([
        'name' => 'Second website',
        'slug' => 'second-website',
        'base_url' => 'https://second.example.test',
    ]);
    $secondWebsite->save();
    Http::fakeSequence()->push([$this->payload])->push([$this->payload]);

    $this->artisan('orders:sync-woocommerce')->assertSuccessful();

    expect($attempts)->toBe(2)
        ->and($this->website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 12:00:00')
        ->and($secondWebsite->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 12:00:00');
    $this->assertDatabaseCount('wc_orders', 2);
    $this->assertDatabaseCount('notifications', 0);
});

test('batched order refreshes target only the owner and unique admins', function (bool $ownerIsAdmin) {
    $this->owner->update(['is_admin' => $ownerIsAdmin]);
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();
    $event = new WcOrdersSynced($this->website);
    $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

    expect($channels)->toHaveCount(2)
        ->toContain('private-orders.'.$this->owner->id, 'private-orders.'.$admin->id)
        ->not->toContain('private-orders.'.$other->id, 'private-orders')
        ->and($event->broadcastAs())->toBe('order.received')
        ->and($event->broadcastWith())->toBe(['website_id' => $this->website->id]);
})->with(['regular owner' => false, 'admin owner' => true]);
