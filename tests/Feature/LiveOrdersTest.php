<?php

use App\Events\NewWcOrderReceived;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProcessWooWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\WooCommerceOrderStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Owned website',
        'slug' => 'owned-website',
        'base_url' => 'https://owned.example',
    ]);
    $this->payload = [
        'id' => 42,
        'status' => 'pending',
        'currency' => 'USD',
        'total' => '100.00',
        'date_created_gmt' => '2026-09-01T09:00:00',
        'date_modified_gmt' => '2026-09-01T09:00:00',
        'billing' => ['email' => 'customer@example.com', 'first_name' => 'Test', 'last_name' => 'Customer'],
        'meta_data' => [['key' => 'private_answer', 'value' => 'Not needed by the grid']],
    ];
    $this->order = app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload)['order'];
});

test('changed webhook orders broadcast once without creating new order notifications', function () {
    Event::fake([NewWcOrderReceived::class]);
    $changedPayload = array_replace($this->payload, [
        'status' => 'processing',
        'date_modified_gmt' => '2026-09-01T10:00:00',
    ]);

    foreach ([$changedPayload, $changedPayload, $this->payload] as $payload) {
        $event = WebhookEvent::create([
            'website_id' => $this->website->id,
            'source' => 'woocommerce',
            'topic' => 'order.updated',
            'external_id' => $payload['id'],
            'payload' => $payload,
            'signature_valid' => true,
            'status' => 'queued',
            'received_at' => now(),
        ]);
        app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);
        expect($event->fresh()->status)->toBe('processed');
    }

    expect($this->order->fresh()->status)->toBe('processing');
    Event::assertDispatchedTimes(NewWcOrderReceived::class, 1);
    Event::assertDispatched(NewWcOrderReceived::class, fn ($event) => $event->order->is($this->order));
    $this->assertDatabaseCount('notifications', 0);
    Http::assertNothingSent();
});

test('order change broadcasts only target the owner and unique admins', function (bool $ownerIsAdmin) {
    $this->owner->update(['is_admin' => $ownerIsAdmin]);
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    $event = new NewWcOrderReceived($this->order);
    $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

    expect($channels)->toHaveCount(2)
        ->toContain('private-orders.'.$this->owner->id, 'private-orders.'.$admin->id)
        ->not->toContain('private-orders.'.$other->id, 'private-orders')
        ->and($event->broadcastAs())->toBe('order.received')
        ->and($event->broadcastWith())->toBe([
            'order_id' => $this->order->id,
            'website_id' => $this->website->id,
        ]);
})->with(['regular owner' => false, 'admin owner' => true]);

test('users and admins can authorize only their own orders channel', function (bool $isAdmin) {
    $this->owner->update(['is_admin' => $isAdmin]);
    $other = User::factory()->create();
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'fixture-key',
        'broadcasting.connections.pusher.secret' => 'fixture-secret',
        'broadcasting.connections.pusher.app_id' => 'fixture-app',
        'broadcasting.connections.pusher.options.cluster' => 'mt1',
    ]);
    // Register the application's real callbacks on this local signing broadcaster.
    Broadcast::purge('pusher');
    require base_path('routes/channels.php');

    $this->actingAs($this->owner)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-orders.'.$this->owner->id,
    ])->assertOk()->assertJsonStructure(['auth']);

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-orders.'.$other->id,
    ])->assertForbidden();
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-orders',
    ])->assertForbidden();

    Http::assertNothingSent();
})->with(['regular user' => false, 'admin' => true]);

test('orders grid exposes only required fields and retains owner and admin scope', function () {
    $otherWebsite = Website::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Other website',
        'slug' => 'other-website',
        'base_url' => 'https://other.example',
    ]);
    app(WooCommerceOrderStore::class)->store($otherWebsite->id, $this->payload);

    $this->actingAs($this->owner)->get(route('orders.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Index')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $this->order->id)
            ->where('orders.data.0.wp_order_id', 42)
            ->where('orders.data.0.website_id', $this->website->id)
            ->where('orders.data.0.status', 'pending')
            ->where('orders.data.0.total', '100.00')
            ->where('orders.data.0.currency', 'USD')
            ->where('orders.data.0.customer_email', 'customer@example.com')
            ->where('orders.data.0.customer_name', 'Test Customer')
            ->has('orders.data.0.created_at_wp')
            ->where('orders.data.0.website', ['id' => $this->website->id, 'name' => 'Owned website'])
            ->missing('orders.data.0.payload')
            ->missing('orders.data.0.website.base_url')
            ->has('websites', 1)
        );

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('orders.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 2)
            ->has('websites', 2)
        );
});

test('orders partial refresh does not load or return the website selector', function () {
    $unusedWebsite = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Unused website',
        'slug' => 'unused-website',
        'base_url' => 'https://unused.example',
    ]);
    $retrievedWebsiteIds = [];
    Website::retrieved(function (Website $website) use (&$retrievedWebsiteIds) {
        // The authorization ID pluck also hydrates cast IDs; only track named website records.
        if (array_key_exists('name', $website->getAttributes())) {
            $retrievedWebsiteIds[] = $website->id;
        }
    });

    $this->actingAs($this->owner)->get(route('orders.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/orders')) ?? '',
        'X-Inertia-Partial-Component' => 'Orders/Index',
        'X-Inertia-Partial-Data' => 'orders',
    ])->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Orders/Index')
        ->assertJsonCount(1, 'props.orders.data')
        ->assertJsonPath('props.orders.data.0.website.name', 'Owned website')
        ->assertJsonMissingPath('props.orders.data.0.payload')
        ->assertJsonMissingPath('props.websites')
        ->assertJsonMissingPath('props.filters');

    expect($retrievedWebsiteIds)->toContain($this->website->id)
        ->not->toContain($unusedWebsite->id);
    Http::assertNothingSent();
});

test('orders JSON refresh preserves tenant scope and returns only the grid paginator', function () {
    $otherWebsite = Website::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Other website',
        'slug' => 'other-website',
        'base_url' => 'https://other.example',
    ]);
    app(WooCommerceOrderStore::class)->store($otherWebsite->id, $this->payload);

    $response = $this->actingAs($this->owner)->getJson(route('orders.index'))
        ->assertOk()
        ->assertJsonCount(1, 'orders.data')
        ->assertJsonPath('orders.data.0.id', $this->order->id)
        ->assertJsonPath('orders.data.0.website', ['id' => $this->website->id, 'name' => 'Owned website'])
        ->assertJsonMissingPath('orders.data.0.payload')
        ->assertJsonMissingPath('orders.data.0.website.base_url')
        ->assertJsonMissingPath('websites')
        ->assertJsonMissingPath('filters');

    expect(array_keys($response->json()))->toBe(['orders'])
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->assertEqualsCanonicalizing([
        'id', 'website_id', 'wp_order_id', 'status', 'currency', 'total',
        'customer_email', 'customer_name', 'created_at_wp', 'website',
    ], array_keys($response->json('orders.data.0')));

    $this->getJson(route('orders.index', ['website_id' => $otherWebsite->id]))
        ->assertOk()->assertJsonCount(0, 'orders.data');
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(route('orders.index'))->assertOk()->assertJsonCount(2, 'orders.data');
    Http::assertNothingSent();
});

test('orders JSON refresh preserves website status search and pagination filters', function () {
    $orders = app(WooCommerceOrderStore::class);
    // Matching creation times exercise the stable newest-ID pagination tie-breaker.
    foreach (range(100, 115) as $id) {
        $orders->store($this->website->id, array_replace($this->payload, [
            'id' => $id,
            'status' => 'processing',
            'date_created_gmt' => '2026-09-01T10:00:00',
            'billing' => ['email' => 'alpha@example.com', 'first_name' => 'Alpha', 'last_name' => 'Customer'],
        ]));
    }
    // These owned orders each differ from one of the requested filters.
    $orders->store($this->website->id, array_replace($this->payload, [
        'id' => 200,
        'status' => 'pending',
        'billing' => ['email' => 'alpha@example.com'],
    ]));
    $orders->store($this->website->id, array_replace($this->payload, ['id' => 201, 'status' => 'processing']));
    $otherWebsite = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Other owned website',
        'slug' => 'other-owned-website',
        'base_url' => 'https://other-owned.example',
    ]);
    $orders->store($otherWebsite->id, array_replace($this->payload, [
        'status' => 'processing',
        'billing' => ['email' => 'alpha@example.com'],
    ]));

    $this->actingAs($this->owner)->getJson(route('orders.index', [
        'website_id' => $this->website->id,
        'status' => 'processing',
        'search' => 'alpha',
        'page' => 2,
    ]))->assertOk()
        ->assertJsonPath('orders.current_page', 2)
        ->assertJsonPath('orders.last_page', 2)
        ->assertJsonPath('orders.per_page', 15)
        ->assertJsonPath('orders.total', 16)
        ->assertJsonCount(1, 'orders.data')
        ->assertJsonPath('orders.data.0.wp_order_id', 100)
        ->assertJsonPath('orders.data.0.website_id', $this->website->id)
        ->assertJsonPath('orders.data.0.status', 'processing')
        ->assertJsonPath('orders.data.0.customer_email', 'alpha@example.com');
    Http::assertNothingSent();
});

test('backup order reconciliation runs every five minutes and bounds stale overlap locks', function () {
    // Listing boots the real console routes without executing remote reconciliation.
    $this->artisan('schedule:list')->assertExitCode(0);
    $event = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'orders:sync-woocommerce'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10)
        ->and($event->runInBackground)->toBeTrue();
    Http::assertNothingSent();
});
