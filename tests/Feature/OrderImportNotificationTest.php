<?php

use App\Events\NewWcOrderReceived;
use App\Events\WcOrdersSynced;
use App\Jobs\ProcessWooWebhookEvent;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Notifications\NewOrderNotification;
use App\Services\WooCommerceOrderStore;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now('UTC')->setDate(2026, 10, 22)->setTime(12, 0));
    config(['broadcasting.default' => 'null']);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Notification fixture',
        'slug' => 'notification-fixture',
        'base_url' => 'https://notification-fixture.example.test',
        'status' => 'active',
        'wc_consumer_key' => 'fixture-key',
        'wc_consumer_secret' => 'fixture-secret',
        'wc_orders_synced_at' => '2026-10-22 11:30:00',
    ]);
    $this->website->forceFill(['created_at' => now()->subDay()])->save();
    $this->payload = [
        'id' => 42,
        'status' => 'processing',
        'currency' => 'USD',
        'total' => '16.99',
        'date_created_gmt' => '2026-10-22T11:50:00',
        'date_modified_gmt' => '2026-10-22T11:55:00',
        'billing' => ['email' => 'fictional@example.test'],
    ];
    $this->webhook = function (Website $website, array $payload): WebhookEvent {
        $event = WebhookEvent::create([
            'website_id' => $website->id,
            'source' => 'woocommerce',
            'topic' => 'order.created',
            'external_id' => $payload['id'],
            'payload' => $payload,
            'signature_valid' => true,
            'status' => 'queued',
            'received_at' => now(),
        ]);
        app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);

        return $event->fresh();
    };
    $this->sync = function (Website $website, array $payload, bool $full = false): array {
        Http::fake([$website->base_url.'/*' => Http::response([$payload])]);

        return app(WooCommerceOrderSyncService::class)->syncForWebsite($website, 50, $full);
    };
});

test('new imports notify once whether automatic sync or webhook arrives first', function (string $first) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    if ($first === 'sync') {
        expect(($this->sync)($this->website, $this->payload)['synced'])->toBe(1);
        $event = ($this->webhook)($this->website, $this->payload);
    } else {
        $event = ($this->webhook)($this->website, $this->payload);
        expect(($this->sync)($this->website, $this->payload)['synced'])->toBe(0);
    }
    app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);
    ($this->webhook)($this->website, $this->payload);
    ($this->webhook)($this->website, array_replace($this->payload, [
        'status' => 'completed', 'date_modified_gmt' => '2026-10-22T11:59:00',
    ]));

    expect($event->status)->toBe('processed')
        ->and($this->owner->notifications()->count())->toBe(1)
        ->and($admin->notifications()->count())->toBe(1)
        ->and($other->notifications()->count())->toBe(0);
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 2);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 2);
    foreach (Event::dispatched(BroadcastNotificationCreated::class) as [$broadcast]) {
        $saved = $broadcast->notifiable->notifications()->sole();
        expect($saved->type)->toBe(NewOrderNotification::class)
            ->and($broadcast->notification->id)->toBe($saved->id)
            ->and($broadcast->broadcastWith()['data'])->toBe($saved->data);
    }
    Http::assertSentCount(1);
})->with(['sync', 'webhook']);

test('matching Woo order numbers on different websites notify only each owner and unique admins', function () {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $this->owner->update(['is_admin' => true]);
    $secondOwner = User::factory()->create();
    $other = User::factory()->create();
    $secondWebsite = $this->website->replicate()->fill([
        'user_id' => $secondOwner->id,
        'name' => 'Second notification fixture',
        'slug' => 'second-notification-fixture',
        'base_url' => 'https://second-notification-fixture.example.test',
    ]);
    $secondWebsite->save();
    $secondWebsite->forceFill(['created_at' => now()->subDay()])->save();

    expect(($this->sync)($this->website, $this->payload)['synced'])->toBe(1);
    ($this->webhook)($secondWebsite, $this->payload);

    $this->assertDatabaseCount('wc_orders', 2);
    $this->assertDatabaseCount('notifications', 3);
    expect($this->owner->notifications()->count())->toBe(2)
        ->and($secondOwner->notifications()->sole()->data['website_id'])->toBe($secondWebsite->id)
        ->and($other->notifications()->count())->toBe(0);
    $this->assertEqualsCanonicalizing(
        [$this->website->id, $secondWebsite->id],
        $this->owner->notifications()->get()->pluck('data.website_id')->all(),
    );
    expect(WcOrder::where('wp_order_id', 42)->pluck('id')->unique())->toHaveCount(2);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 3);
});

test('old orders modified recently import without new order alerts', function (string $source) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $this->website->forceFill(['created_at' => now()->subDays(4)])->save();
    $payload = array_replace($this->payload, ['date_created_gmt' => '2026-10-20T11:50:00']);

    if ($source === 'webhook') {
        expect(($this->webhook)($this->website, $payload)->status)->toBe('processed');
    } else {
        expect(($this->sync)($this->website, $payload, $source === 'full')['synced'])->toBe(1);
    }

    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 0);
    Event::assertNotDispatched(BroadcastNotificationCreated::class);
})->with(['webhook', 'full', 'incremental']);

test('preconnection order history remains silent even when less than a day old', function (string $source) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $this->website->forceFill(['created_at' => now()->subMinutes(30), 'wc_orders_synced_at' => null])->save();
    $payload = array_replace($this->payload, ['date_created_gmt' => '2026-10-22T11:00:00']);

    if ($source === 'webhook') {
        ($this->webhook)($this->website, $payload);
    } else {
        expect(($this->sync)($this->website, $payload, true)['synced'])->toBe(1);
    }

    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 0);
    Event::assertNotDispatched(BroadcastNotificationCreated::class);
})->with(['webhook', 'full']);

test('missing or excessively future creation times stay silent on import and later updates', function (string $created) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $payload = $this->payload;
    if ($created === 'missing') {
        unset($payload['date_created_gmt']);
    } else {
        $payload['date_created_gmt'] = '2026-10-22T12:06:00';
    }

    expect(($this->sync)($this->website, $payload)['synced'])->toBe(1);
    ($this->webhook)($this->website, array_replace($this->payload, [
        'date_modified_gmt' => '2026-10-22T11:59:00',
    ]));

    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 0);
    Event::assertNotDispatched(BroadcastNotificationCreated::class);
})->with(['missing', 'future']);

test('small store clock skew does not permanently lose a new order notification', function () {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    ($this->webhook)($this->website, array_replace($this->payload, [
        'date_created_gmt' => '2026-10-22T12:04:00',
        'date_modified_gmt' => '2026-10-22T12:04:00',
    ]));

    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
});

test('a recent first arrival still notifies after a newer successful sync checkpoint', function (string $source) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $this->website->update(['wc_orders_synced_at' => '2026-10-22 11:59:00']);
    $payload = array_replace($this->payload, [
        'date_created_gmt' => '2026-10-22T11:40:00',
        'date_modified_gmt' => '2026-10-22T11:59:00',
    ]);

    if ($source === 'webhook') {
        ($this->webhook)($this->website, $payload);
    } else {
        expect(($this->sync)($this->website, $payload)['synced'])->toBe(1);
    }

    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
})->with(['webhook', 'incremental']);

test('rolling back an order import also rolls back its inbox notification and live delivery', function () {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);

    expect(fn () => DB::transaction(function () {
        app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload, notifyNewOrder: true);
        $this->assertDatabaseCount('notifications', 1);
        Event::assertNotDispatched(BroadcastNotificationCreated::class);
        throw new RuntimeException('Fixture outer transaction rollback');
    }))->toThrow(RuntimeException::class, 'Fixture outer transaction rollback');

    $this->assertDatabaseCount('wc_orders', 0);
    $this->assertDatabaseCount('notifications', 0);
    Event::assertNotDispatched(BroadcastNotificationCreated::class);

    ($this->webhook)($this->website, $this->payload);
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
});

test('a broadcast outage preserves the synced inbox notification without retry duplicates', function () {
    Event::fake([NewWcOrderReceived::class]);
    $attempts = 0;
    Event::listen(BroadcastNotificationCreated::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Fixture notification broadcast outage');
    });

    $result = ($this->sync)($this->website, $this->payload);
    $event = ($this->webhook)($this->website, $this->payload);
    app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);
    ($this->sync)($this->website, array_replace($this->payload, ['date_modified_gmt' => '2026-10-22T11:59:00']));

    expect($result['status'])->toBe('success')
        ->and($event->status)->toBe('processed')
        ->and($attempts)->toBe(1)
        ->and($this->owner->notifications()->sole()->read_at)->toBeNull();
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 1);
});

test('automatic sync resumes a failed mixed history scan without losing or duplicating new order alerts', function () {
    Event::fake([NewWcOrderReceived::class, WcOrdersSynced::class, BroadcastNotificationCreated::class]);
    $this->website->forceFill(['created_at' => now()->subDays(4)])->save();
    $historical = array_replace($this->payload, [
        'id' => 41,
        'date_created_gmt' => '2026-10-20T11:00:00',
        'date_modified_gmt' => '2026-10-22T11:40:00',
    ]);
    $recent = $this->payload;
    $nextRecent = array_replace($this->payload, [
        'id' => 43,
        'date_created_gmt' => '2026-10-22T11:56:00',
        'date_modified_gmt' => '2026-10-22T11:57:00',
    ]);
    Http::fake([$this->website->base_url.'/*' => Http::sequence()
        ->push([$historical])
        ->push([$recent])
        ->pushStatus(503)
        ->push([$nextRecent])
        ->push([])
        ->push([$recent, $nextRecent]),
    ]);

    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id, '--per-page' => 1])
        ->assertFailed();

    $website = $this->website->fresh();
    expect($website->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 11:30:00')
        ->and($website->wc_orders_sync_state['until'])->toBe('2026-10-22T12:00:00+00:00')
        ->and($website->wc_orders_sync_state['cursor'])->toBe('2026-10-22T11:55:00+00:00')
        ->and($website->wc_orders_sync_state['boundary_ids'])->toBe([42])
        ->and($this->owner->notifications()->sole()->data['wp_order_id'])->toBe(42);
    $this->assertDatabaseCount('wc_orders', 2);
    Event::assertDispatchedTimes(WcOrdersSynced::class, 1);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
    Event::assertNotDispatched(NewWcOrderReceived::class);

    // Resume the original bounded window even though wall-clock time has advanced.
    $this->travelTo(now()->addMinute());
    $this->artisan('orders:sync-woocommerce', ['--website' => $this->website->id, '--per-page' => 1])
        ->assertSuccessful();

    $website = $this->website->fresh();
    expect($website->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 12:00:00')
        ->and($website->wc_orders_sync_state)->toBeNull();
    $this->assertDatabaseCount('wc_orders', 3);
    $this->assertDatabaseCount('notifications', 2);
    Event::assertDispatchedTimes(WcOrdersSynced::class, 2);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 2);
    Event::assertNotDispatched(NewWcOrderReceived::class);
    $requests = Http::recorded()->map(fn ($recorded) => $recorded[0]);
    expect($requests)->toHaveCount(5)
        ->and($requests[3]['modified_before'])->toBe('2026-10-22T12:00:00')
        ->and($requests[3]['modified_after'])->toBe('2026-10-22T11:54:59')
        ->and($requests[3]['exclude'])->toBe([42]);

    // The overlap scan and late webhook replays retain the same saved inbox items.
    ($this->webhook)($website, $recent);
    $event = ($this->webhook)($website, $nextRecent);
    app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);
    $this->artisan('orders:sync-woocommerce', ['--website' => $website->id])->assertSuccessful();

    expect($website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 12:01:00');
    $this->assertEqualsCanonicalizing([42, 43], $this->owner->notifications()->get()->pluck('data.wp_order_id')->all());
    $this->assertDatabaseCount('wc_orders', 3);
    $this->assertDatabaseCount('notifications', 2);
    Event::assertDispatchedTimes(WcOrdersSynced::class, 2);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 2);
    Event::assertNotDispatched(NewWcOrderReceived::class);
    Http::assertSentCount(6);
});
