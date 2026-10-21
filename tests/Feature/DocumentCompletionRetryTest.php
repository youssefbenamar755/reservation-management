<?php

use App\Jobs\SyncDocumentCompletion;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\DocumentCompletionSync;
use App\Services\DocumentStoreGateway;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new RuntimeException('No HTTP request is permitted during manual completion retry tests.'));
    Bus::fake([SyncDocumentCompletion::class]);
    $this->actor = User::factory()->create(['is_admin' => false]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Fictional Retry Brand', 'slug' => 'fictional-retry', 'base_url' => 'https://retry.example', 'status' => 'active']);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 900042, 'status' => 'processing', 'customer_email' => 'fictional@example.test', 'payload' => []]);
    $connection = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'fictional-sender@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => str_repeat('a', 64), 'access_token' => 'fictional-token', 'refresh_token' => 'fictional-refresh', 'connected_at' => now()]);
    $this->profile = WebsiteDocumentProfile::create(['website_id' => $this->site->id, 'workflow_enabled' => true, 'config_version' => 1,
        'brand_sync_version' => 1, 'store_pairing_key' => (string) Str::uuid()]);
    $this->delivery = OrderEmailDelivery::create(['user_id' => $this->actor->id, 'wc_order_id' => $this->order->id, 'website_id' => $this->site->id,
        'gmail_connection_id' => $connection->id, 'connection_key' => $connection->connection_key, 'fingerprint' => str_repeat('b', 64),
        'snapshot' => ['config_version' => 1, 'website_identity' => $this->profile->store_pairing_key, 'gmail_account_email' => $connection->email],
        'workflow' => true, 'document_mode' => 'complete', 'status' => 'sent', 'gmail_message_id' => 'fictional-message', 'sent_at' => now(),
        'send_attempts' => 1, 'sync_status' => 'pending', 'next_sync_at' => now()->addMinutes(30), 'expires_at' => now()->addDay(),
        'result_message' => 'Documents sent — order update pending.']);
    $this->url = '/orders/'.$this->order->id.'/document-deliveries/'.$this->delivery->id.'/sync';
    $this->actingAs($this->actor);
});

test('manual retry queues on the dedicated connection without waiting for store calls or scheduled backoff', function () {
    $this->mock(DocumentCompletionSync::class)->shouldNotReceive('sync');
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('delivery.status', 'sent')
        ->assertJsonPath('delivery.sync_status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
    Bus::assertDispatched(SyncDocumentCompletion::class, fn ($job) => $job->deliveryId === $this->delivery->id
        && $job->connection === 'documents' && $job->queue === 'documents' && $job->afterCommit === true);
    expect($this->delivery->fresh()->send_attempts)->toBe(1)->and($this->delivery->fresh()->sync_attempts)->toBe(0);
    Http::assertNothingSent();
});

test('manual retry returns ineligible or already claimed state without queueing', function (array $changes) {
    $this->delivery->update($changes);
    $this->postJson($this->url)->assertOk()->assertJsonPath('delivery.status', $this->delivery->status);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
})->with([
    'prepared' => [['status' => 'prepared']],
    'failed' => [['status' => 'failed']],
    'uncertain' => [['status' => 'uncertain']],
    'reviewed sent' => [['status' => 'reviewed_sent']],
    'no provider confirmation' => [['gmail_message_id' => null]],
    'partial mode' => [['document_mode' => 'partial']],
    'resend mode' => [['document_mode' => 'resend']],
    'completed' => [['sync_status' => 'completed']],
    'active claim' => [['sync_status' => 'syncing', 'sync_claimed_at' => now()]],
]);

test('manual retry can queue a stale synchronization claim for worker recovery', function () {
    $this->delivery->update(['sync_status' => 'syncing', 'sync_claimed_at' => now()->subMinutes(6)]);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('delivery.sync_status', 'syncing');
    Bus::assertDispatchedTimes(SyncDocumentCompletion::class, 1);
    expect($this->delivery->fresh()->sync_attempts)->toBe(0);
});

test('manual retry rejects disabled or changed website document configuration before queueing', function (array $changes) {
    $this->profile->update($changes);
    $this->postJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('delivery');
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
})->with([
    'disabled workflow' => [['workflow_enabled' => false]],
    'changed configuration' => [['config_version' => 2]],
    'unsynchronized brand' => [['brand_sync_version' => null]],
    'changed pairing' => [['store_pairing_key' => '12345678-1234-1234-1234-123456789abc']],
]);

test('manual retry cannot cross ownership or exact order scope', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))->postJson($this->url)->assertForbidden();
    $other = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 900043, 'status' => 'processing', 'payload' => []]);
    $this->actingAs($this->actor)->postJson('/orders/'.$other->id.'/document-deliveries/'.$this->delivery->id.'/sync')->assertNotFound();
    $this->delivery->update(['workflow' => false]);
    $this->postJson($this->url)->assertNotFound();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

test('manual retry queue failure reports failure and preserves durable pending state', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Private queue credential diagnostic'));
    $before = $this->delivery->fresh()->getRawOriginal();
    $this->postJson($this->url)->assertStatus(503)->assertJsonPath('delivery.sync_status', 'pending')
        ->assertJsonPath('message', 'The order update could not be queued. Retry synchronization later; no email was sent by this action.')
        ->assertDontSee('Private queue credential diagnostic')->assertHeader('Cache-Control', 'no-store, private');
    expect($this->delivery->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

test('duplicate queued retries use the existing claim guard and never resend mail or repeat completed store work', function () {
    $this->postJson($this->url)->assertAccepted();
    $this->postJson($this->url)->assertAccepted();
    Bus::assertDispatchedTimes(SyncDocumentCompletion::class, 2);
    $gateway = $this->mock(DocumentStoreGateway::class);
    $gateway->shouldReceive('complete')->once()->andReturn(['status' => 'completed']);
    $sync = app(DocumentCompletionSync::class);
    foreach (Bus::dispatched(SyncDocumentCompletion::class) as $job) {
        $job->handle($sync);
    }
    expect($this->delivery->fresh()->sync_status)->toBe('completed')
        ->and($this->delivery->fresh()->sync_attempts)->toBe(1)
        ->and($this->delivery->fresh()->send_attempts)->toBe(1);
    Http::assertNothingSent();
});

test('worker still rejects a profile disabled after the retry was queued', function () {
    $this->postJson($this->url)->assertAccepted();
    $this->profile->update(['workflow_enabled' => false]);
    Bus::dispatched(SyncDocumentCompletion::class)->sole()->handle(app(DocumentCompletionSync::class));
    expect($this->delivery->fresh()->sync_status)->toBe('pending')
        ->and($this->delivery->fresh()->sync_attempts)->toBe(1)
        ->and($this->delivery->fresh()->send_attempts)->toBe(1);
    Http::assertNothingSent();
});
