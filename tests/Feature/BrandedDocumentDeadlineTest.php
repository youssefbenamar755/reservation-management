<?php

use App\Exceptions\DocumentStoreException;
use App\Exceptions\GmailDeliveryException;
use App\Jobs\SyncDocumentCompletion;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentDelivery;
use App\Services\BrandedDocumentRenderer;
use App\Services\DocumentStoreGateway;
use App\Services\GmailClient;
use App\Services\OrderDocumentStorage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DocumentDeadlineClock
{
    public float $now = 100;

    public float $dnsCost = 0;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([SyncDocumentCompletion::class]);
    config(['document_delivery.validation.enabled' => false]);
    $this->clock = new DocumentDeadlineClock;
    $this->client = new class($this->clock) extends GmailClient
    {
        public function __construct(private DocumentDeadlineClock $clock) {}

        protected function monotonicSeconds(): float
        {
            return $this->clock->now;
        }
    };
    $this->gateway = new class($this->clock) extends DocumentStoreGateway
    {
        public function __construct(private DocumentDeadlineClock $clock) {}

        protected function monotonicSeconds(): float
        {
            return $this->clock->now;
        }

        protected function lookupAddresses(string $host): array
        {
            $this->clock->now += $this->clock->dnsCost;

            return ['93.184.216.34'];
        }
    };
    app()->instance(DocumentStoreGateway::class, $this->gateway);
    $this->actor = User::factory()->create(['is_admin' => false]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Deadline Example', 'slug' => 'deadline-example',
        'base_url' => 'https://deadline.example.test', 'status' => 'active', 'wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 42, 'status' => 'processing',
        'customer_email' => 'customer@example.test', 'customer_name' => 'Fictional Customer', 'payload' => ['locale' => 'en_US']]);
    GmailAppSetting::create(['id' => 1, 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
    $this->connection = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'sender@example.test',
        'connection_key' => (string) Str::uuid(), 'app_fingerprint' => $this->client->fingerprint(),
        'access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh', 'expires_at' => now()->addHour(),
        'connected_at' => now(), 'aliases' => [['email' => 'sender@example.test', 'name' => 'Example']]]);
    $this->profile = WebsiteDocumentProfile::create(['website_id' => $this->site->id, 'brand_name' => 'Deadline Example',
        'logo_url' => 'https://deadline.example.test/logo.png', 'primary_color' => '#143e50', 'accent_color' => '#248070',
        'background_color' => '#f5f7fa', 'text_color' => '#102030', 'support_email' => 'support@example.test',
        'website_url' => 'https://deadline.example.test', 'footer' => 'The {{brand_name}} team',
        'gmail_connection_id' => $this->connection->id, 'sender_email' => 'sender@example.test', 'sender_name' => 'Example',
        'reply_to' => 'support@example.test', 'default_language' => 'en', 'templates' => BrandedDocumentRenderer::defaults(),
        'service_label' => 'reservation', 'required_services' => ['Flight'], 'workflow_enabled' => true, 'config_version' => 1,
        'store_pairing_key' => (string) Str::uuid(), 'store_capability' => ['supported' => true, 'verified' => true, 'enabled' => true,
            'config_version' => 1, 'capabilities' => ['order_revision']], 'store_checked_at' => now(), 'brand_sync_version' => 1]);
    // Deadline tests do not share fake storage or create files. PDF validation has its own suite.
    $bytes = "%PDF-1.4\nFictional deadline fixture\n%%EOF\n";
    $this->document = OrderDocument::create(['website_id' => $this->site->id, 'wc_order_id' => $this->order->id,
        'uploaded_by' => $this->actor->id, 'approved_by' => $this->actor->id, 'approved_at' => now(),
        'customer_email' => $this->order->customer_email, 'name' => 'Fictional.pdf', 'disk' => 'local', 'path' => 'not-read.pdf',
        'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'version' => 1]);
    $storage = Mockery::mock(OrderDocumentStorage::class);
    $storage->shouldReceive('bytes')->andReturn($bytes);
    $this->service = new class($this->client, $storage, $this->clock) extends BrandedDocumentDelivery
    {
        public function __construct(GmailClient $gmail, OrderDocumentStorage $storage, private DocumentDeadlineClock $clock)
        {
            parent::__construct($gmail, $storage);
        }

        protected function monotonicSeconds(): float
        {
            return $this->clock->now;
        }
    };
    $this->requests = [];
    $this->costs = ['token' => 0, 'aliases' => 0, 'store' => 0, 'send' => 0];
    $this->postTimeout = false;
    Http::fake(function ($request, $options) {
        $kind = match (true) {
            str_ends_with($request->url(), '/token') => 'token',
            str_ends_with($request->url(), '/sendAs') => 'aliases',
            str_ends_with($request->url(), '/eligibility') => 'store',
            str_ends_with($request->url(), '/messages/send') => 'send',
            default => throw new RuntimeException('Unexpected request in deadline test'),
        };
        $this->requests[] = ['kind' => $kind, 'timeout' => $options['timeout'], 'connect_timeout' => $options['connect_timeout'], 'curl' => $options['curl'] ?? []];
        $this->clock->now += $this->costs[$kind];

        return match ($kind) {
            'token' => Http::response(['access_token' => 'fictional-new-access', 'expires_in' => 3600]),
            'aliases' => Http::response(['sendAs' => [['sendAsEmail' => 'sender@example.test', 'isPrimary' => true]]]),
            'store' => Http::response(['id' => 42, 'status' => 'processing', 'date_paid_gmt' => '2026-10-21T09:00:00',
                'date_modified_gmt' => '2026-10-21T10:00:00', 'billing' => ['email' => $this->order->customer_email],
                'fulfillment_revision' => str_repeat('a', 64)]),
            'send' => $this->postTimeout ? Http::failedConnection('Simulated timeout') : Http::response(['id' => 'fictional-gmail-id']),
        };
    });
    $this->prepare = fn () => $this->service->prepare($this->order, $this->actor, ['document_ids' => [$this->document->id],
        'recipient' => $this->order->customer_email, 'mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight']]);
});

test('prepare and send each share twenty seconds across refresh aliases DNS store and submission', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    $this->costs = ['token' => 3, 'aliases' => 7, 'store' => 3, 'send' => 1];
    $this->clock->dnsCost = 2;
    $delivery = ($this->prepare)();
    $result = $this->service->send($this->order, $delivery, $this->actor);
    expect($result->status)->toBe('sent')->and($result->sync_status)->toBe('pending')
        ->and(array_column($this->requests, 'kind'))->toBe(['token', 'aliases', 'store', 'aliases', 'store', 'send'])
        ->and(array_column($this->requests, 'timeout'))->toEqual([20, 17, 8, 20, 11, 8]);
    foreach (array_filter($this->requests, fn ($request) => $request['kind'] === 'store') as $request) {
        expect($request['curl'][CURLOPT_RESOLVE])->toBe(['deadline.example.test:443:93.184.216.34']);
    }
    Bus::assertDispatched(SyncDocumentCompletion::class);
});

test('a refresh that exhausts the deadline cannot start the alias request', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    $this->costs['token'] = 20;
    try {
        $this->client->aliases($this->connection, 120);
        $this->fail('Expected a definite deadline error');
    } catch (GmailDeliveryException $exception) {
        expect($exception->uncertain)->toBeFalse();
    }
    expect(array_column($this->requests, 'kind'))->toBe(['token']);
});

test('store DNS time is subtracted and an exhausted resolver budget cannot issue HTTP', function () {
    $this->clock->dnsCost = 20;
    expect(fn () => ($this->prepare)())->toThrow(DocumentStoreException::class, 'No email was submitted');
    expect(array_column($this->requests, 'kind'))->toBe(['aliases'])->and(OrderEmailDelivery::count())->toBe(0);
});

test('exhaustion after the final store check is a definite failed delivery with no Gmail POST', function () {
    $delivery = ($this->prepare)();
    $this->requests = [];
    $this->costs['store'] = 20;
    $result = $this->service->send($this->order, $delivery, $this->actor);
    expect($result->status)->toBe('failed')->and($result->send_attempts)->toBe(1)
        ->and($result->result_message)->toContain('No email was submitted')
        ->and(array_column($this->requests, 'kind'))->toBe(['aliases', 'store']);
    Bus::assertNothingDispatched();
});

test('the final authorization callback cannot consume the budget then submit an email', function () {
    try {
        $this->client->send($this->connection, 'fictional MIME', function () {
            $this->clock->now += 20;
        }, 120);
        $this->fail('Expected a definite deadline error');
    } catch (GmailDeliveryException $exception) {
        expect($exception->uncertain)->toBeFalse();
    }
    Http::assertNothingSent();
});

test('remaining fractional seconds clamp both timeouts without becoming unlimited', function () {
    $this->client->send($this->connection, 'fictional MIME', null, 100.25);
    expect($this->requests[0]['timeout'])->toBe(0.25)->and($this->requests[0]['connect_timeout'])->toBe(0.25);
    $this->requests = [];
    expect(fn () => $this->client->send($this->connection, 'fictional MIME', null, 100.0005))
        ->toThrow(GmailDeliveryException::class, 'No email was submitted');
    expect($this->requests)->toBe([]);
});

test('a timeout during Gmail POST remains uncertain and subsequent clicks do not resend', function () {
    $delivery = ($this->prepare)();
    $this->requests = [];
    $this->postTimeout = true;
    $this->costs['send'] = 20;
    $result = $this->service->send($this->order, $delivery, $this->actor);
    expect($result->status)->toBe('uncertain')->and($result->send_attempts)->toBe(1);
    $again = $this->service->send($this->order, $delivery, $this->actor);
    expect($again->status)->toBe('uncertain')->and($again->send_attempts)->toBe(1)
        ->and(array_column($this->requests, 'kind'))->toBe(['aliases', 'store', 'send']);
    Bus::assertNothingDispatched();
});

test('a confirmed Gmail response is retained even if it arrives at the deadline', function () {
    $delivery = ($this->prepare)();
    $this->costs['send'] = 20;
    $result = $this->service->send($this->order, $delivery, $this->actor);
    expect($result->status)->toBe('sent')->and($result->gmail_message_id)->toBe('fictional-gmail-id')->and($result->mime)->toBeNull();
    Bus::assertDispatched(SyncDocumentCompletion::class);
});

test('existing callers without a deadline keep independent twenty second requests', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    $this->costs = ['token' => 100, 'aliases' => 100, 'store' => 100, 'send' => 100];
    $this->client->aliases($this->connection);
    $this->gateway->checkOrder($this->order);
    expect($this->client->send($this->connection, 'fictional MIME'))->toBe('fictional-gmail-id')
        ->and(array_column($this->requests, 'timeout'))->toEqual([20, 20, 20, 20])
        ->and(array_column($this->requests, 'connect_timeout'))->toEqual([5, 5, 5, 5]);
});
