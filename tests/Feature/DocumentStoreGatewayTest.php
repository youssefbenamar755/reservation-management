<?php

use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\DocumentStoreGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(DocumentStoreGateway::class, new class extends DocumentStoreGateway
    {
        protected function lookupAddresses(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->owner = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'Example store', 'slug' => 'gateway-example',
        'base_url' => 'https://shop.example.test', 'status' => 'active', 'wc_consumer_key' => 'test-key', 'wc_consumer_secret' => 'test-secret']);
    $this->profile = WebsiteDocumentProfile::create(['website_id' => $this->site->id, 'store_pairing_key' => (string) Str::uuid(),
        'brand_name' => 'Example Brand', 'support_email' => 'support@example.test', 'workflow_enabled' => true, 'config_version' => 3, 'brand_sync_version' => 3,
        'service_label' => 'flight reservation',
        'templates' => ['acknowledgement' => ['fr' => ['subject' => '{{brand_name}} — Commande {{order_number}} reçue',
            'preheader' => 'Preview text', 'optional_block' => 'Optional text']]]]);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 42, 'status' => 'processing', 'payload' => []]);
    $this->delivery = new OrderEmailDelivery(['id' => (string) Str::uuid(), 'wc_order_id' => $this->order->id, 'website_id' => $this->site->id,
        'status' => 'sent', 'gmail_message_id' => 'provider-test-id', 'sent_at' => now(), 'snapshot' => [
            'mode' => 'complete', 'website_identity' => $this->profile->store_pairing_key, 'fulfillment_confirmed' => true, 'config_version' => 3,
            'required_services' => ['flight', 'hotel'], 'fulfilled_services' => ['flight', 'hotel'],
            'attachments' => [['id' => 'example-doc', 'name' => 'reservation.pdf', 'size' => 100]],
            'document_set_version' => str_repeat('a', 64), 'expected_modified_gmt' => '2026-10-21T12:00:00',
            'expected_fulfillment_revision' => str_repeat('b', 64),
        ]]);
});

test('store sync persists and independently verifies receipt before requesting completion', function () {
    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = [$request->method(), $request->url(), $request->data()];
        if (str_contains($request->url(), '/wphub-documents/')) {
            expect($request->header('X-WPHub-Website')[0])->toBe($this->profile->store_pairing_key);
        }
        expect($request->url())->not->toContain('test-secret')->not->toContain('consumer_key');
        if (str_ends_with($request->url(), '/wc/v3/orders/42')) {
            return Http::response(['id' => 42, 'status' => 'completed', 'date_modified_gmt' => '2026-10-21T12:01:00']);
        }

        return Http::response(['verified' => true, 'receipt_id' => $this->delivery->id, 'document_set_version' => str_repeat('a', 64),
            'status' => str_ends_with($request->url(), '/complete') ? 'completed' : 'processing']);
    });
    $result = app(DocumentStoreGateway::class)->complete($this->order, $this->delivery);
    expect($result['status'])->toBe('completed')->and(array_column($requests, 0))->toBe(['POST', 'GET', 'POST', 'GET'])
        ->and($this->order->fresh()->status)->toBe('completed');
    expect($requests[0][1])->toEndWith('/42/receipt')->and($requests[1][1])->toEndWith('/42/receipt')
        ->and($requests[2][1])->toEndWith('/42/complete')->and($requests[0][2]['provider_message_id'])->toBe('provider-test-id')
        ->and($requests[0][2]['expected_fulfillment_revision'])->toBe(str_repeat('b', 64));
});

test('store sync refuses to complete when independent marker verification fails', function () {
    Http::fakeSequence()->push(['verified' => true])->push(['verified' => false]);
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))
        ->toThrow(RuntimeException::class, 'completion was not requested');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/complete'));
});

test('a store sync failure never sends an email or changes the accepted send result', function () {
    Http::fake(['*' => Http::response([], 409)]);
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))->toThrow(RuntimeException::class, 'changed');
    expect($this->delivery->status)->toBe('sent')->and($this->delivery->gmail_message_id)->toBe('provider-test-id');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
});

test('partial and incomplete service delivery cannot complete the order', function ($change) {
    $this->delivery->snapshot = array_replace($this->delivery->snapshot, $change);
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))->toThrow(RuntimeException::class, 'fully fulfilled');
    Http::assertNothingSent();
})->with([
    'partial' => [['mode' => 'partial']], 'resend' => [['mode' => 'resend']], 'correction' => [['mode' => 'correction']],
    'unconfirmed' => [['fulfillment_confirmed' => false]], 'missing service' => [['fulfilled_services' => ['flight']]],
    'no requirements' => [['required_services' => []]], 'no files' => [['attachments' => []]],
]);

test('failed or unknown email outcomes cannot complete any order', function ($status) {
    $this->delivery->status = $status;
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['failed', 'unknown', 'sending', 'prepared']);

test('captured fixture mail cannot be promoted to live store fulfillment proof', function ($validation, $transport) {
    config(['document_delivery.validation.enabled' => $validation, 'document_delivery.validation.mail_transport' => $transport]);
    $this->delivery->gmail_message_id = 'capture_fictional-only';
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))->toThrow(RuntimeException::class, 'fully fulfilled');
    Http::assertNothingSent();
})->with([[false, 'capture'], [false, 'gmail'], [true, 'gmail']]);

test('another website with the same external number cannot use the delivery receipt', function () {
    $other = Website::create(['user_id' => $this->owner->id, 'name' => 'Other site', 'slug' => 'gateway-other', 'base_url' => 'https://other.example.test']);
    $otherOrder = WcOrder::create(['website_id' => $other->id, 'wp_order_id' => 42, 'status' => 'processing', 'payload' => []]);
    expect(fn () => app(DocumentStoreGateway::class)->complete($otherOrder, $this->delivery))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('missing companion plugin is reported without claiming suppression capability', function () {
    Http::fake(['*' => Http::response([], 404)]);
    $health = app(DocumentStoreGateway::class)->health($this->site);
    expect($health['supported'])->toBeFalse()->and($health['verified'])->toBeFalse()
        ->and($health['message'])->toContain('Completion suppression not configured');
});

test('a capability response for a different paired website is refused', function () {
    Http::fake(['*' => Http::response(['website_identity' => 'wrong-site', 'capabilities' => ['order_specific_suppression', 'receipt_verification', 'optimistic_completion', 'local_acknowledgement', 'private_notes']])]);
    expect(app(DocumentStoreGateway::class)->health($this->site)['verified'])->toBeFalse();
});

test('unsupported ecommerce websites clearly report the capability limitation', function () {
    $this->site->wc_consumer_key = null;
    $health = app(DocumentStoreGateway::class)->health($this->site);
    expect($health['supported'])->toBeFalse()->and($health['message'])->toContain('No supported WooCommerce');
    Http::assertNothingSent();
});

test('fresh order inspection only reads and requires recorded paid evidence', function () {
    Http::fake(['*' => Http::response(['id' => 42, 'status' => 'processing', 'date_modified_gmt' => '2026-10-21T12:00:00', 'date_paid_gmt' => null, 'fulfillment_revision' => str_repeat('b', 64)])]);
    expect(app(DocumentStoreGateway::class)->checkOrder($this->order)['paid'])->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/wphub-documents/orders/42/eligibility')
        && $request->header('X-WPHub-Website')[0] === $this->profile->store_pairing_key);
});

test('missing or invalid fulfillment revision blocks combined delivery eligibility and completion', function ($revision) {
    Http::fake(['*' => Http::response(['id' => 42, 'status' => 'processing', 'date_modified_gmt' => '2026-10-21T12:00:00', 'fulfillment_revision' => $revision])]);
    expect(fn () => app(DocumentStoreGateway::class)->checkOrder($this->order))->toThrow(RuntimeException::class, 'incomplete order');
    $this->delivery->snapshot = array_replace($this->delivery->snapshot, ['expected_fulfillment_revision' => $revision]);
    expect(fn () => app(DocumentStoreGateway::class)->complete($this->order, $this->delivery))->toThrow(RuntimeException::class, 'fully fulfilled');
    Http::assertSentCount(1);
})->with([null, '', 'not-a-revision', str_repeat('A', 64)]);

test('old companion without strong revision capability cannot claim completion readiness', function () {
    Http::fake(['*' => Http::response(['website_identity' => $this->profile->store_pairing_key,
        'capabilities' => ['order_specific_suppression', 'receipt_verification', 'optimistic_completion', 'local_acknowledgement', 'private_notes']])]);
    expect(app(DocumentStoreGateway::class)->health($this->site)['verified'])->toBeFalse();
});

test('explicit brand synchronization keeps acknowledgement configuration local to the correct store', function () {
    Http::fake(['*' => Http::response(['website_identity' => $this->profile->store_pairing_key, 'enabled' => true, 'config_version' => '3'])]);
    app(DocumentStoreGateway::class)->syncBrand($this->profile);
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['brand_name'] === 'Example Brand'
        && $request['service_label'] === 'flight reservation'
        && $request['templates']['fr']['subject'] === '{{brand_name}} — Commande {{order_number}} reçue'
        && $request['templates']['fr']['preheader'] === 'Preview text'
        && $request['templates']['fr']['optional_block'] === 'Optional text');
});

test('store credentials are never sent over unsafe or redirected URLs', function ($url) {
    $this->site->base_url = $url;
    $this->order->setRelation('website', $this->site);
    expect(fn () => app(DocumentStoreGateway::class)->checkOrder($this->order))->toThrow(RuntimeException::class, 'public HTTPS');
    Http::assertNothingSent();
})->with(['http://shop.example.test', 'https://127.0.0.1', 'https://localhost', 'https://shop.example.test:8443', 'https://user:password@shop.example.test', 'https://shop.example.test/?key=x']);

test('private and mixed public private DNS answers are rejected before sending store credentials', function ($addresses) {
    $gateway = new class($addresses) extends DocumentStoreGateway
    {
        public function __construct(private array $addresses) {}

        protected function lookupAddresses(string $host): array
        {
            return $this->addresses;
        }
    };
    expect(fn () => $gateway->checkOrder($this->order))->toThrow(\App\Exceptions\DocumentStoreException::class);
    Http::assertNothingSent();
})->with([
    'private' => [['10.0.0.1']], 'mixed DNS answer' => [['93.184.216.34', '192.168.1.1']],
    'metadata endpoint' => [['169.254.169.254']], 'IPv6 loopback' => [['::1']],
    'mapped private IPv6' => [['::ffff:127.0.0.1']], 'no addresses' => [[]],
]);
