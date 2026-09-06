<?php

use App\Events\NewWcOrderReceived;
use App\Jobs\ProcessFluentWebhookEvent;
use App\Jobs\ProcessWooWebhookEvent;
use App\Jobs\SyncFormSchema;
use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\WooCommerceOrderStore;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function integrationWebsite(array $attributes = []): Website
{
    return Website::create($attributes + [
        'user_id' => User::factory()->create()->id,
        'name' => 'Integration site',
        'slug' => 'integration-'.uniqid(),
        'base_url' => 'https://orders.example.test',
        'status' => 'active',
        'webhook_secret' => 'fixture-webhook-token',
        'wc_consumer_key' => 'fixture-key',
        'wc_consumer_secret' => 'fixture-secret',
    ]);
}

function integrationOrder(int $id, string $modified = '2026-09-01T12:00:00', string $status = 'pending'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'currency' => 'USD',
        'total' => '50.00',
        'date_created_gmt' => '2026-01-01T12:00:00',
        'date_modified_gmt' => $modified,
        'date_paid' => $status === 'completed' ? $modified : null,
        'billing' => ['email' => 'customer@example.test', 'first_name' => 'Test', 'last_name' => 'Customer'],
    ];
}

function integrationWooEvent(Website $website, array $payload, string $topic = 'order.created'): WebhookEvent
{
    return WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'woocommerce',
        'topic' => $topic,
        'external_id' => $payload['id'],
        'payload' => $payload,
        'signature_valid' => true,
        'status' => 'queued',
        'received_at' => now(),
    ]);
}

function integrationSyncUntilDone(Website $website, int $perPage = 50, bool $full = true): array
{
    $synced = 0;
    $updated = 0;
    for ($page = 0; $page < 10; $page++) {
        $result = app(WooCommerceOrderSyncService::class)->syncForWebsite($website, $perPage, $full);
        $synced += $result['synced'];
        $updated += $result['updated'];
        if ($result['status'] !== 'partial') {
            return array_replace($result, compact('synced', 'updated'));
        }
    }

    throw new RuntimeException('Fixture sync did not finish within ten pages.');
}

test('fluent webhook reads body only and accepts an object or single item list', function (bool $wrapped) {
    Queue::fake();
    $website = integrationWebsite();
    $body = ['__submission' => ['id' => 123, 'form_id' => 4], 'email' => 'customer@example.test'];
    $this->postJson('/api/v1/webhooks/fluentforms/'.$website->slug.'?token=fixture-webhook-token&email=wrong@example.test', $wrapped ? [$body] : $body)->assertOk();

    $event = WebhookEvent::sole();
    expect($event->payload)->toBe($body)
        ->and($event->external_id)->toBe('123');
    Queue::assertPushed(ProcessFluentWebhookEvent::class);
})->with([false, true]);

test('fluent webhook accepts form bodies without persisting the query token', function () {
    Queue::fake();
    $website = integrationWebsite();
    $this->post('/api/v1/webhooks/fluentforms/'.$website->slug.'?token=fixture-webhook-token', [
        '__submission' => ['id' => 123, 'form_id' => 4],
    ])->assertOk();
    expect(WebhookEvent::sole()->payload)->not->toHaveKey('token');
});

test('fluent webhook rejects malformed credentials and multi-entry envelopes without queuing', function () {
    Queue::fake();
    $website = integrationWebsite();
    $body = ['__submission' => ['id' => 123, 'form_id' => 4]];
    $this->postJson('/api/v1/webhooks/fluentforms/'.$website->slug.'?token[]=wrong', $body)->assertForbidden();
    $this->postJson('/api/v1/webhooks/fluentforms/'.$website->slug.'?token=fixture-webhook-token', [$body, $body])->assertUnprocessable();
    $this->assertDatabaseCount('webhook_events', 0);
    Queue::assertNothingPushed();
});

test('legacy fluent queued list with query token is normalized before processing', function () {
    Queue::fake([SyncFormSchema::class]);
    $website = integrationWebsite();
    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'fluentforms',
        'topic' => 'form.submitted',
        'payload' => [0 => ['__submission' => ['id' => 123, 'form_id' => 4], 'email' => 'customer@example.test'], 'token' => 'legacy-secret'],
        'received_at' => now(),
    ]);
    (new ProcessFluentWebhookEvent($event->id))->handle();
    expect($event->fresh()->status)->toBe('processed')
        ->and(FfSubmission::sole()->payload['response'])->toBe(['email' => 'customer@example.test']);
});

test('legacy submission tokens cannot be read or serialized and other answers remain intact', function (bool $stringResponse) {
    $website = integrationWebsite();
    $response = ['token' => 'legacy-secret', 'email' => 'customer@example.test'];
    $id = DB::table('ff_submissions')->insertGetId([
        'website_id' => $website->id, 'form_id' => 4, 'entry_id' => 123,
        'payload' => json_encode(['token' => 'legacy-secret', 'response' => $stringResponse ? json_encode($response) : $response]),
    ]);
    $submission = FfSubmission::findOrFail($id);
    expect(json_encode($submission->payload))->not->toContain('legacy-secret')
        ->and($submission->toJson())->not->toContain('legacy-secret')
        ->and($submission->toJson())->toContain('customer@example.test');
})->with([false, true]);

test('woo replays create one order and one inbox notification per recipient', function () {
    Event::fake([NewWcOrderReceived::class]);
    $website = integrationWebsite();
    $first = integrationWooEvent($website, integrationOrder(123));
    $job = new ProcessWooWebhookEvent($first->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    app()->call([new ProcessWooWebhookEvent(integrationWooEvent($website, integrationOrder(123))->id), 'handle']);

    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(NewWcOrderReceived::class, 1);
});

test('an older or undated woo delivery cannot replace newer payment or order data', function () {
    Event::fake([NewWcOrderReceived::class]);
    $website = integrationWebsite();
    app()->call([new ProcessWooWebhookEvent(integrationWooEvent($website, integrationOrder(123, '2026-09-02T12:00:00', 'completed'))->id), 'handle']);
    $stale = integrationWooEvent($website, integrationOrder(123, '2026-09-01T12:00:00'), 'order.updated');
    app()->call([new ProcessWooWebhookEvent($stale->id), 'handle']);
    $undated = integrationOrder(123);
    unset($undated['date_modified_gmt']);
    app()->call([new ProcessWooWebhookEvent(integrationWooEvent($website, $undated, 'order.updated')->id), 'handle']);

    expect(WcOrder::sole()->status)->toBe('completed')
        ->and(WcOrder::sole()->payment_status)->toBe('paid')
        ->and($stale->fresh()->status)->toBe('processed');
    $this->assertDatabaseCount('notifications', 1);
});

test('broadcast failure does not lose the persisted order notification', function () {
    Event::listen(NewWcOrderReceived::class, function () {
        throw new RuntimeException('Fixture broadcaster outage');
    });
    $website = integrationWebsite();
    $event = integrationWooEvent($website, integrationOrder(123));
    $job = new ProcessWooWebhookEvent($event->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($event->fresh()->status)->toBe('processed');
    $this->assertDatabaseCount('notifications', 1);
});

test('manual woo sync repairs historical gaps and reconciles known order status', function () {
    $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
    $website = integrationWebsite(['wc_orders_synced_at' => '2026-09-05 12:00:00']);
    app(WooCommerceOrderStore::class)->store($website->id, integrationOrder(123, '2026-01-01T12:00:00'));
    Http::fakeSequence()
        ->push([integrationOrder(123, '2026-08-01T12:00:00', 'completed')])
        ->push([integrationOrder(124, '2026-08-02T12:00:00')])
        ->push([]);

    $result = integrationSyncUntilDone($website, 1);
    expect($result['status'])->toBe('success')->and($result['synced'])->toBe(1)->and($result['updated'])->toBe(1)
        ->and(WcOrder::where('wp_order_id', 123)->first()->status)->toBe('completed');
    $this->assertDatabaseCount('wc_orders', 2);
    $requests = Http::recorded();
    expect($requests[0][0]->data())->not->toHaveKey('modified_after')
        ->and($requests[1][0]['modified_after'])->toBe('2026-08-01T11:59:59')
        ->and($requests[1][0]['page'])->toBe(1);
});

test('scheduled woo sync uses its own checkpoint and a fixed upper bound across equal timestamp batches', function () {
    $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
    $website = integrationWebsite(['wc_orders_synced_at' => '2026-09-06 11:30:00', 'last_sync_at' => '2026-09-06 11:59:00']);
    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->data();
        $this->travel(1)->minutes();

        return Http::response(count($calls) < 3 ? [integrationOrder(122 + count($calls), '2026-09-06T11:45:00')] : []);
    });

    $result = integrationSyncUntilDone($website, 1, full: false);
    expect($result['status'])->toBe('success')
        ->and($calls[0]['modified_after'])->toBe('2026-09-06T11:24:59')
        ->and($calls[1]['exclude'])->toBe([123])
        ->and($calls[2]['exclude'])->toBe([123, 124])
        ->and(array_unique(array_column($calls, 'modified_before')))->toBe(['2026-09-06T12:00:00'])
        ->and($website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 12:00:00');
});

test('a failed woo sync preserves checkpoint and retries the unfinished window', function () {
    $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
    $website = integrationWebsite(['wc_orders_synced_at' => '2026-09-06 11:30:00']);
    Http::fakeSequence()
        ->push([integrationOrder(123, '2026-09-06T11:45:00')])->pushStatus(503)
        ->push([integrationOrder(124, '2026-09-06T11:46:00')])->push([]);
    $result = integrationSyncUntilDone($website, 1, full: false);
    expect($result['status'])->toBe('error')->and($result['synced'])->toBe(1)
        ->and($website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 11:30:00');

    $result = integrationSyncUntilDone($website, 1, full: false);
    expect($result['status'])->toBe('success')->and($result['synced'])->toBe(1);
    $this->assertDatabaseCount('wc_orders', 2);
});

test('woo sync fails visibly if API ignores cursor filters instead of looping or advancing checkpoint', function () {
    $website = integrationWebsite();
    Http::fakeSequence()->push([integrationOrder(123)])->push([integrationOrder(123)]);
    $result = integrationSyncUntilDone($website, 1, full: false);
    expect($result['status'])->toBe('error')->and($website->fresh()->wc_orders_synced_at)->toBeNull();
    Http::assertSentCount(2);
});

test('one-page woo sync persists progress for a later scheduled request and hides internal state', function () {
    $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
    $website = integrationWebsite(['wc_orders_synced_at' => '2026-09-06 11:30:00']);
    Http::fakeSequence()->push([integrationOrder(123)])->push([]);

    $result = app(WooCommerceOrderSyncService::class)->syncForWebsite($website, 1);
    expect($result['status'])->toBe('partial')
        ->and($website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 11:30:00')
        ->and($website->fresh()->wc_orders_sync_state['cursor'])->toBe('2026-09-01T12:00:00+00:00')
        ->and($website->toArray())->not->toHaveKey('wc_orders_sync_state');
    Http::assertSentCount(1);

    $this->travel(1)->hours();
    $result = app(WooCommerceOrderSyncService::class)->syncForWebsite($website->fresh(), 1, full: false);
    expect($result['status'])->toBe('success')
        ->and($website->fresh()->wc_orders_sync_state)->toBeNull()
        ->and($website->fresh()->wc_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-06 12:00:00');
    $requests = Http::recorded();
    expect($requests[1][0]['modified_after'])->toBe('2026-09-01T11:59:59');
});

test('woo console sync consumes partial pages until the window is complete', function () {
    $website = integrationWebsite();
    Http::fakeSequence()->push([integrationOrder(123)])->push([integrationOrder(124)])->push([]);
    $this->artisan('orders:sync-woocommerce', ['--website' => $website->id, '--per-page' => 1])
        ->expectsOutputToContain('Synced 2 new WooCommerce order(s)')
        ->assertSuccessful();
    $this->assertDatabaseCount('wc_orders', 2);
    expect($website->fresh()->wc_orders_sync_state)->toBeNull()
        ->and($website->fresh()->wc_orders_synced_at)->not->toBeNull();
});
