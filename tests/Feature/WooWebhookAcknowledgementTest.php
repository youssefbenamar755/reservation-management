<?php

use App\Http\Controllers\Webhook\WooWebhookController;
use App\Jobs\ProcessWooWebhookEvent;
use App\Models\UsefulAlert;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\UsefulAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);
    $this->website = Website::create([
        'user_id' => User::factory()->create()->id, 'name' => 'Receipt fixture',
        'slug' => 'receipt-fixture', 'base_url' => 'https://fixture.example.test',
        'status' => 'active', 'wc_webhook_secret' => 'test-only-secret',
    ]);
});

test('remote hook checks alert on disabled hooks and resolve only after a healthy check', function () {
    $this->website->update(['wc_consumer_key' => 'fixture-key', 'wc_consumer_secret' => 'fixture-secret']);
    $target = rtrim(config('app.url'), '/').'/api/v1/webhooks/woocommerce/'.$this->website->slug;
    Http::preventStrayRequests();
    Http::fake(['https://fixture.example.test/*' => Http::sequence()->push([
        ['topic' => 'order.created', 'status' => 'disabled', 'delivery_url' => $target],
        ['topic' => 'order.updated', 'status' => 'active', 'delivery_url' => $target],
        ['topic' => 'order.created', 'status' => 'active', 'delivery_url' => 'https://other.example.test/webhook'],
    ])->push([], 403)->push([
        ['topic' => 'order.created', 'status' => 'active', 'delivery_url' => $target],
        ['topic' => 'order.updated', 'status' => 'active', 'delivery_url' => $target],
    ])]);
    $this->artisan('webhooks:check-woocommerce')->assertSuccessful();
    expect(DB::table('woo_webhook_checks')->value('issues'))->toBe(1);
    $actor = $this->website->user;
    app(UsefulAlerts::class)->scan($actor);
    expect(UsefulAlert::where('kind', 'webhook_connection')->sole()->resolved_at)->toBeNull();

    $this->artisan('webhooks:check-woocommerce')->assertSuccessful();
    expect(DB::table('woo_webhook_checks')->value('state'))->toBe('check_failed');
    app(UsefulAlerts::class)->scan($actor);
    expect(UsefulAlert::where('kind', 'webhook_connection')->sole()->resolved_at)->toBeNull();

    $this->artisan('webhooks:check-woocommerce')->assertSuccessful();
    app(UsefulAlerts::class)->scan($actor);
    expect(UsefulAlert::where('kind', 'webhook_connection')->sole()->resolved_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

test('signed delivery is persisted and acknowledged before processing even with the sync queue', function () {
    Bus::fake();
    $body = json_encode(['id' => 42, 'status' => 'processing']);
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'test-only-secret', true)),
    ], $body);

    $response = app(WooWebhookController::class)($request, $this->website);

    expect($response->getStatusCode())->toBe(200);
    $event = WebhookEvent::sole();
    expect($event->status)->toBe('queued');
    expect(WcOrder::count())->toBe(0);
    Bus::assertDispatchedAfterResponse(ProcessWooWebhookEvent::class, fn ($job) => $job->webhookEventId === $event->id);
    expect(Bus::dispatched(ProcessWooWebhookEvent::class))->toHaveCount(0);
});

test('recovery imports interrupted receipts once and leaves recent failed and unverified events alone', function () {
    $attributes = [
        'website_id' => $this->website->id, 'source' => 'woocommerce', 'topic' => 'order.created',
        'external_id' => 42, 'signature_valid' => true, 'status' => 'queued',
        'received_at' => now()->subMinutes(3),
        'payload' => ['id' => 42, 'status' => 'processing', 'currency' => 'USD', 'total' => '25.00'],
    ];
    $interrupted = WebhookEvent::create($attributes);
    $recent = WebhookEvent::create(array_replace($attributes, ['received_at' => now()]));
    $failed = WebhookEvent::create(array_replace($attributes, ['status' => 'failed']));
    $unsigned = WebhookEvent::create(array_replace($attributes, ['signature_valid' => false]));

    $this->artisan('webhooks:recover-woocommerce')->assertSuccessful();
    $this->artisan('webhooks:recover-woocommerce')->assertSuccessful();

    expect($interrupted->fresh()->status)->toBe('processed');
    expect($recent->fresh()->status)->toBe('queued');
    expect($failed->fresh()->status)->toBe('failed');
    expect($unsigned->fresh()->status)->toBe('queued');
    expect(WcOrder::count())->toBe(1);
});
