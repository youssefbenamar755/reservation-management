<?php

use App\Jobs\SyncDocumentCompletion;
use App\Models\ActionHistoryEvent;
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
use App\Services\DocumentCompletionSync;
use App\Services\DocumentStoreGateway;
use App\Services\GmailClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([SyncDocumentCompletion::class]);
    Storage::fake('local');
    $this->actor = User::factory()->create(['is_admin' => false]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Atlas Example', 'slug' => 'atlas-documents', 'base_url' => 'https://atlas.example', 'status' => 'active', 'wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 42, 'status' => 'processing', 'customer_email' => 'alex@example.test', 'customer_name' => 'Alex Example', 'payload' => ['locale' => 'en_US']]);
    GmailAppSetting::create(['id' => 1, 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
    $this->gmail = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'manager@example.test', 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GmailClient::class)->fingerprint(), 'access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh', 'expires_at' => now()->addHour(), 'connected_at' => now(), 'aliases' => [['email' => 'documents@atlas.example', 'name' => 'Atlas Example']]]);
    $this->profile = WebsiteDocumentProfile::create(['website_id' => $this->site->id, 'brand_name' => 'Atlas Example', 'logo_url' => 'https://atlas.example/logo.png', 'primary_color' => '#143e50', 'accent_color' => '#248070', 'background_color' => '#f5f7fa', 'text_color' => '#102030', 'support_email' => 'support@atlas.example', 'website_url' => 'https://atlas.example', 'footer' => 'The {{brand_name}} team', 'gmail_connection_id' => $this->gmail->id, 'sender_email' => 'documents@atlas.example', 'sender_name' => 'Atlas Example', 'reply_to' => 'support@atlas.example', 'default_language' => 'fr', 'templates' => BrandedDocumentRenderer::defaults(), 'service_label' => 'reservation', 'required_services' => ['Flight', 'Hotel'], 'workflow_enabled' => true, 'config_version' => 1, 'store_pairing_key' => (string) Str::uuid(), 'store_capability' => ['supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 1, 'capabilities' => ['order_revision']], 'store_checked_at' => now(), 'brand_sync_version' => 1]);
    $this->sentCount = 0;
    $this->mime = null;
    $this->sendCode = 200;
    $this->duringSend = null;
    $this->duringAliasCheck = null;
    $this->duringTokenRefresh = null;
    $this->tokenRefreshCount = 0;
    Http::fake(function ($request) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            $this->tokenRefreshCount++;
            if ($this->duringTokenRefresh) {
                ($this->duringTokenRefresh)();
            }

            return Http::response(['access_token' => 'fictional-refreshed-token', 'expires_in' => 3600]);
        }
        if (str_ends_with($request->url(), '/settings/sendAs')) {
            if ($this->duringAliasCheck) {
                ($this->duringAliasCheck)();
            }

            return Http::response(['sendAs' => [['sendAsEmail' => 'manager@example.test', 'isPrimary' => true], ['sendAsEmail' => 'documents@atlas.example', 'displayName' => 'Atlas Example', 'verificationStatus' => 'accepted']]]);
        }
        if (str_ends_with($request->url(), '/messages/send')) {
            $this->sentCount++;
            $this->mime = base64_decode(strtr($request['raw'], '-_', '+/'));
            if ($this->duringSend) {
                ($this->duringSend)();
            }

            return $this->sendCode === 0 ? Http::failedConnection('private timeout') : Http::response($this->sendCode === 200 ? ['id' => 'provider-'.$this->sentCount] : ['error' => ['message' => 'private provider error']], $this->sendCode);
        }
        throw new RuntimeException('Unexpected request in branded delivery test');
    });
    $this->gateway = Mockery::mock(DocumentStoreGateway::class);
    $this->gateway->shouldReceive('checkOrder')->andReturn(['status' => 'processing', 'paid' => true, 'modified_gmt' => '2026-10-21T10:00:00', 'customer_email' => 'alex@example.test', 'fulfillment_revision' => str_repeat('a', 64)])->byDefault();
    app()->instance(DocumentStoreGateway::class, $this->gateway);
    $this->actingAs($this->actor);
});

function brandedPdf(string $name = 'Flight reservation.pdf', string $text = 'FICTIONAL PDF BYTES'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Title ($text) >>\nendobj\n%%EOF\n");
}

function brandedUpload($test, array $files = []): array
{
    return $test->post('/orders/'.$test->order->id.'/document-deliveries/documents', ['files' => $files ?: [brandedPdf()], 'approved' => true, 'intended_customer_email' => $test->order->customer_email], ['Accept' => 'application/json'])->assertOk()->json('documents');
}

function brandedPrepare($test, array $documents, array $overrides = []): OrderEmailDelivery
{
    $response = $test->postJson('/orders/'.$test->order->id.'/document-deliveries/prepare', array_replace(['document_ids' => array_column($documents, 'id'), 'recipient' => $test->order->customer_email, 'mode' => 'partial'], $overrides))->assertOk();

    return OrderEmailDelivery::findOrFail($response->json('preview.id'));
}

function brandedSend($test, OrderEmailDelivery $delivery)
{
    return $test->postJson('/orders/'.$test->order->id.'/document-deliveries/'.$delivery->id.'/send', ['confirmed' => true]);
}

test('branded delivery uses exact approved private files and snapshots brand language sender and MIME bytes', function () {
    $docs = brandedUpload($this, [brandedPdf(), brandedPdf('Hotel reservation.pdf', 'FICTIONAL HOTEL')]);
    $delivery = brandedPrepare($this, $docs);
    expect($delivery->snapshot)->toMatchArray(['brand_name' => 'Atlas Example', 'language' => 'en', 'reply_to' => 'support@atlas.example'])
        ->and($delivery->preview()['attachments'][0])->toHaveKeys(['id', 'sha256', 'version'])
        ->and($delivery->snapshot['html'])->toContain('Atlas Example', 'support@atlas.example', '2 PDF')
        ->and($delivery->mime)->toContain('multipart/mixed', 'multipart/alternative', 'text/plain', 'text/html', 'application/pdf', 'Reply-To: support@atlas.example', 'From: Atlas Example <documents@atlas.example>');
    foreach (OrderDocument::all() as $doc) {
        expect($delivery->mime)->toContain(chunk_split(base64_encode(Storage::disk('local')->get($doc->path)), 76, "\r\n"));
        $this->get('/orders/'.$this->order->id.'/document-deliveries/documents/'.$doc->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
    expect($this->sentCount)->toBe(0);
});

test('identical external order numbers cannot cross website document preview download or history boundaries', function () {
    $documents = brandedUpload($this);
    $delivery = brandedPrepare($this, $documents);
    $other = Website::create(['user_id' => $this->actor->id, 'name' => 'Other Brand', 'slug' => 'other-docs', 'base_url' => 'https://other.example']);
    $order = WcOrder::create(['website_id' => $other->id, 'wp_order_id' => 42, 'status' => 'processing', 'customer_email' => 'alex@example.test', 'payload' => []]);
    $profile = $this->profile->replicate();
    $profile->website_id = $other->id;
    $profile->store_pairing_key = (string) Str::uuid();
    $profile->save();
    $this->postJson('/orders/'.$order->id.'/document-deliveries/prepare', ['document_ids' => array_column($documents, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $this->get('/orders/'.$order->id.'/document-deliveries/documents/'.$documents[0]['id'])->assertNotFound();
    $this->getJson('/orders/'.$order->id.'/document-deliveries/'.$delivery->id)->assertNotFound();
    expect(app(BrandedDocumentDelivery::class)->context($order, $this->actor)['deliveries'])->toBe([]);
    $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson('/orders/'.$this->order->id.'/document-deliveries/'.$delivery->id)->assertForbidden();
});

test('missing files sender and revoked approval prevent sending', function () {
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => [], 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $docs = brandedUpload($this);
    OrderDocument::whereKey($docs[0]['id'])->update(['approved_at' => null]);
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $this->profile->update(['sender_email' => null]);
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    expect($this->sentCount)->toBe(0);
});

test('uploads require correct customer explicit approval and actual PDF bytes', function () {
    $url = '/orders/'.$this->order->id.'/document-deliveries/documents';
    $this->post($url, ['files' => [brandedPdf()], 'approved' => false, 'intended_customer_email' => 'alex@example.test'], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post($url, ['files' => [brandedPdf()], 'approved' => true, 'intended_customer_email' => 'other@example.test'], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post($url, ['files' => [UploadedFile::fake()->createWithContent('bad.pdf', '<script>not a PDF</script>')], 'approved' => true, 'intended_customer_email' => 'alex@example.test'], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(OrderDocument::count())->toBe(0);
});

test('public storage and tampered stored files are rejected', function () {
    config(['document_delivery.disk' => 'public']);
    $this->post('/orders/'.$this->order->id.'/document-deliveries/documents', ['files' => [brandedPdf()], 'approved' => true, 'intended_customer_email' => 'alex@example.test'], ['Accept' => 'application/json'])->assertUnprocessable();
    config(['document_delivery.disk' => 'local']);
    $docs = brandedUpload($this);
    $document = OrderDocument::find($docs[0]['id']);
    Storage::disk('local')->put($document->path, '%PDF-1.4 changed');
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
});

test('double preview and reentrant send clicks produce one durable attempt and one provider call', function () {
    $docs = brandedUpload($this);
    $delivery = brandedPrepare($this, $docs);
    $second = brandedPrepare($this, $docs);
    expect($delivery->id)->toBe($second->id);
    $this->duringSend = function () use ($delivery) {
        expect(app(BrandedDocumentDelivery::class)->send($this->order, $delivery, $this->actor)->status)->toBe('sending');
    };
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent');
    brandedSend($this, $delivery)->assertOk();
    expect($this->sentCount)->toBe(1)->and(ActionHistoryEvent::count())->toBe(1)->and($delivery->fresh()->mime)->toBeNull();
    Bus::assertNothingDispatched();
});

test('complete delivery queues status synchronization only after a successful send', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    expect($delivery->snapshot['expected_fulfillment_revision'])->toBe(str_repeat('a', 64));
    $this->gateway->shouldReceive('complete')->once()->andReturn(['status' => 'completed']);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent')->assertJsonPath('delivery.sync_status', 'pending');
    Bus::assertDispatched(SyncDocumentCompletion::class);
    app(DocumentCompletionSync::class)->sync($delivery->id);
    app(DocumentCompletionSync::class)->sync($delivery->id);
    expect($delivery->fresh()->sync_status)->toBe('completed')->and($this->sentCount)->toBe(1);
});

test('partial fulfillment and missing companion capability cannot silently complete orders', function () {
    $docs = brandedUpload($this);
    $url = '/orders/'.$this->order->id.'/document-deliveries/prepare';
    $data = ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight']];
    $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('fulfilled_services');
    $this->profile->update(['store_capability' => []]);
    $this->postJson($url, array_replace($data, ['fulfilled_services' => ['Flight', 'Hotel']]))->assertUnprocessable()->assertJsonValidationErrors('mode');
    $delivery = brandedPrepare($this, $docs);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.sync_status', 'not_requested');
    app(DocumentCompletionSync::class)->sync($delivery->id);
    expect($this->order->fresh()->status)->toBe('processing');
    Bus::assertNothingDispatched();
});

test('definite Gmail rejection does not complete and explicit retry remains possible', function (string $mode) {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => $mode, 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $this->sendCode = 429;
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    app(DocumentCompletionSync::class)->sync($delivery->id);
    Bus::assertNothingDispatched();
    $this->sendCode = 200;
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sentCount)->toBe(2)->and($delivery->fresh()->send_attempts)->toBe(2);
})->with(['partial', 'complete']);

test('unknown provider outcome blocks blind retries including a new document attempt', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $this->sendCode = 0;
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    $next = brandedPrepare($this, brandedUpload($this, [brandedPdf('Updated.pdf')]));
    brandedSend($this, $next)->assertUnprocessable();
    expect($this->sentCount)->toBe(1);
    Bus::assertNothingDispatched();
});

test('store synchronization failure retries the store without repeating Gmail', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    brandedSend($this, $delivery)->assertOk();
    $this->gateway->shouldReceive('complete')->once()->ordered()->andThrow(new RuntimeException('Private remote diagnostic'));
    $this->gateway->shouldReceive('complete')->once()->ordered()->andReturn(['status' => 'completed']);
    $sync = app(DocumentCompletionSync::class);
    expect($sync->sync($delivery->id)->sync_status)->toBe('pending');
    $this->getJson('/orders/'.$this->order->id.'/document-deliveries/'.$delivery->id)->assertOk()->assertDontSee('Private remote diagnostic');
    expect($sync->sync($delivery->id)->sync_status)->toBe('completed')->and($this->sentCount)->toBe(1);
});

test('changed or cancelled remote order cannot be sent through full completion action', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $this->gateway->shouldReceive('checkOrder')->once()->andReturn(['status' => 'cancelled', 'paid' => false, 'customer_email' => 'alex@example.test', 'modified_gmt' => '2026-10-21T10:10:00']);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0);
});

test('intentional resend and corrected documents preserve history and never repeat completion', function () {
    $docs = brandedUpload($this);
    $first = brandedPrepare($this, $docs);
    brandedSend($this, $first)->assertOk();
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $resend = brandedPrepare($this, $docs, ['mode' => 'resend', 'previous_delivery_id' => $first->id, 'resend_confirmed' => true]);
    brandedSend($this, $resend)->assertOk()->assertJsonPath('delivery.sync_status', 'not_requested');
    $correction = brandedPrepare($this, brandedUpload($this, [brandedPdf('Corrected.pdf')]), ['mode' => 'correction', 'previous_delivery_id' => $first->id, 'resend_confirmed' => true]);
    brandedSend($this, $correction)->assertOk()->assertJsonPath('delivery.sync_status', 'not_requested');
    expect($correction->snapshot['subject'])->toContain('Corrected')->and($this->sentCount)->toBe(3)->and(OrderEmailDelivery::where('status', 'sent')->count())->toBe(3);
    Bus::assertNothingDispatched();
});

test('recipient overrides are explicit audited and never add hidden recipients', function () {
    $docs = brandedUpload($this);
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'corrected@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $delivery = brandedPrepare($this, $docs, ['recipient' => 'corrected@example.test', 'recipient_override_reason' => 'Customer requested correction']);
    brandedSend($this, $delivery)->assertOk();
    expect($delivery->snapshot['recipient_override_reason'])->toBe('Customer requested correction')->and($this->mime)->toContain('To: corrected@example.test')->not->toContain('Bcc:', 'Cc:');
    expect(ActionHistoryEvent::sole()->entity_key)->toBe($delivery->id);
});

test('new configuration invalidates unsent preview and legacy endpoint cannot bypass active workflow', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $this->profile->increment('config_version');
    brandedSend($this, $delivery)->assertUnprocessable();
    $this->postJson('/orders/'.$this->order->id.'/email/preview', [])->assertUnprocessable();
    expect($this->sentCount)->toBe(0);
});

test('failed durable audit creation rolls back the claim before external sending', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    DB::statement("CREATE TRIGGER branded_audit_failure BEFORE INSERT ON action_history_events BEGIN SELECT RAISE(FAIL, 'fictional audit failure'); END");
    try {
        brandedSend($this, $delivery)->assertStatus(500);
        expect($delivery->fresh()->status)->toBe('prepared')->and($this->sentCount)->toBe(0);
    } finally {
        DB::statement('DROP TRIGGER branded_audit_failure');
    }
});

test('manual uncertain review keeps provider confirmation separate and never completes an order', function () {
    $docs = brandedUpload($this);
    $delivery = brandedPrepare($this, $docs, ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $this->sendCode = 0;
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    $reviewUrl = '/orders/'.$this->order->id.'/document-deliveries/'.$delivery->id.'/review';
    $this->postJson($reviewUrl, ['confirmed' => false, 'decision' => 'sent', 'reason' => 'Checked the correct Gmail Sent account'])->assertUnprocessable();
    $this->postJson($reviewUrl, ['confirmed' => true, 'decision' => 'sent', 'reason' => 'Checked exact recipient and PDFs in Gmail Sent', 'provider_message_id' => 'reference123'])
        ->assertOk()->assertJsonPath('delivery.status', 'reviewed_sent')->assertJsonPath('delivery.review.reviewer_name', $this->actor->name);
    $this->postJson($reviewUrl, ['confirmed' => true, 'decision' => 'not_sent', 'reason' => 'Second conflicting click must not rewrite the audit'])
        ->assertOk()->assertJsonPath('delivery.status', 'reviewed_sent');
    app(DocumentCompletionSync::class)->sync($delivery->id);
    expect($delivery->fresh()->gmail_message_id)->toBeNull()->and($delivery->fresh()->sent_at)->toBeNull()
        ->and($delivery->fresh()->sync_status)->toBe('not_requested')->and($delivery->fresh()->mime)->toBeNull();
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', ['document_ids' => array_column($docs, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'partial'])->assertUnprocessable();
    $resend = brandedPrepare($this, $docs, ['mode' => 'resend', 'previous_delivery_id' => $delivery->id, 'resend_confirmed' => true]);
    $this->sendCode = 200;
    brandedSend($this, $resend)->assertOk()->assertJsonPath('delivery.status', 'sent')->assertJsonPath('delivery.sync_status', 'not_requested');
    expect(ActionHistoryEvent::where('kind', 'email_review')->count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('reviewed not sent outcome permits a new explicit attempt and preserves the unknown record', function () {
    $docs = brandedUpload($this);
    $delivery = brandedPrepare($this, $docs);
    $this->sendCode = 0;
    brandedSend($this, $delivery)->assertOk();
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/'.$delivery->id.'/review', ['confirmed' => true, 'decision' => 'not_sent', 'reason' => 'Operator reviewed the correct account and confirmed no message was sent'])
        ->assertOk()->assertJsonPath('delivery.status', 'reviewed_not_sent');
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'reviewed_not_sent');
    $new = brandedPrepare($this, $docs);
    expect($new->id)->not->toBe($delivery->id)->and($this->sentCount)->toBe(1);
    $this->sendCode = 200;
    brandedSend($this, $new)->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($delivery->fresh()->status)->toBe('reviewed_not_sent');
});

test('review cannot resolve an active send or cross tenant boundaries', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $delivery->update(['status' => 'sending', 'sending_at' => now()]);
    $url = '/orders/'.$this->order->id.'/document-deliveries/'.$delivery->id.'/review';
    $data = ['confirmed' => true, 'decision' => 'not_sent', 'reason' => 'Fictional operator observation'];
    $this->postJson($url, $data)->assertUnprocessable();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->postJson($url, $data)->assertForbidden();
    expect($delivery->fresh()->status)->toBe('sending')->and($this->sentCount)->toBe(0);
});

test('revoking approval after preview blocks sending but cannot rewrite an already sent outcome', function () {
    $documents = brandedUpload($this);
    $delivery = brandedPrepare($this, $documents);
    OrderDocument::whereKey($documents[0]['id'])->update(['approved_at' => null]);
    brandedSend($this, $delivery)->assertUnprocessable()->assertJsonValidationErrors('documents');
    expect($this->sentCount)->toBe(0)->and($delivery->fresh()->status)->toBe('prepared');
    OrderDocument::whereKey($documents[0]['id'])->update(['approved_at' => now()]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent');
    OrderDocument::whereKey($documents[0]['id'])->update(['approved_at' => null]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sentCount)->toBe(1);
});

test('changed document ownership and version metadata invalidate the saved preview', function () {
    $documents = brandedUpload($this);
    $delivery = brandedPrepare($this, $documents);
    $document = OrderDocument::findOrFail($documents[0]['id']);
    foreach ([['customer_email' => 'someone-else@example.test'], ['sha256' => str_repeat('0', 64)], ['version' => 999], ['name' => 'Different.pdf']] as $change) {
        $original = $document->only(array_keys($change));
        $document->update($change);
        brandedSend($this, $delivery)->assertUnprocessable()->assertJsonValidationErrors('documents');
        $document->update($original);
    }
    expect($this->sentCount)->toBe(0);
});

test('saved mime keeps the exact approved bytes even when storage subsequently changes', function () {
    $documents = brandedUpload($this);
    $delivery = brandedPrepare($this, $documents);
    $document = OrderDocument::findOrFail($documents[0]['id']);
    $approvedBytes = Storage::disk('local')->get($document->path);
    $tamperedBytes = "%PDF-1.4\nUNREVIEWED REPLACEMENT\n%%EOF";
    Storage::disk('local')->put($document->path, $tamperedBytes);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->mime)->toContain(chunk_split(base64_encode($approvedBytes), 76, "\r\n"))
        ->not->toContain(base64_encode($tamperedBytes));
});

test('different previews for the same document set cannot race or send twice', function () {
    $documents = brandedUpload($this);
    $first = brandedPrepare($this, $documents);
    $second = brandedPrepare($this, $documents, ['recipient' => 'alternate@example.test', 'recipient_override_reason' => 'Customer requested alternate inbox']);
    expect($first->id)->not->toBe($second->id)->and($first->document_set_version)->toBe($second->document_set_version);
    $this->duringSend = function () use ($second) {
        try {
            app(BrandedDocumentDelivery::class)->send($this->order, $second, $this->actor);
            $this->fail('A concurrent same-set preview must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            expect($exception->errors())->toHaveKey('delivery');
        }
    };
    brandedSend($this, $first)->assertOk()->assertJsonPath('delivery.status', 'sent');
    brandedSend($this, $second)->assertUnprocessable()->assertJsonValidationErrors('delivery');
    expect($this->sentCount)->toBe(1);
});

test('unavailable completion queue retains a confirmed sent email and durable pending sync', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Fictional queue outage'));
    app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'sent')->assertJsonPath('delivery.sync_status', 'pending');
    expect($delivery->fresh()->gmail_message_id)->toBe('provider-1')->and($delivery->fresh()->next_sync_at)->not->toBeNull()
        ->and($this->sentCount)->toBe(1);
});

test('a disable or configuration change during provider checks stops transmission', function (string $change) {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $this->duringAliasCheck = function () use ($change) {
        $change === 'disabled'
            ? $this->profile->update(['workflow_enabled' => false])
            : $this->profile->increment('config_version');
    };
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0)->and($delivery->fresh()->gmail_message_id)->toBeNull();
    Bus::assertNothingDispatched();
})->with(['disabled', 'config_version']);

test('losing website access while provider checks run stops transmission', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $newOwner = User::factory()->create(['is_admin' => false]);
    $this->duringAliasCheck = fn () => $this->site->update(['user_id' => $newOwner->id]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0);
    Bus::assertNothingDispatched();
});

test('losing administrator permission during provider checks stops transmission', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $this->actingAs($administrator);
    $delivery = brandedPrepare($this, brandedUpload($this));
    $this->duringAliasCheck = fn () => $administrator->update(['is_admin' => false]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0);
});

test('invalidated completion capability after preview blocks transmission', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $this->duringAliasCheck = fn () => $this->profile->update(['store_capability' => ['supported' => true, 'verified' => false]]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0);
    Bus::assertNothingDispatched();
});

test('an emergency disable during OAuth refresh is a definite failure before transmitting MIME', function () {
    $delivery = brandedPrepare($this, brandedUpload($this));
    $this->duringAliasCheck = fn () => $this->gmail->update(['expires_at' => now()->subMinute()]);
    $this->duringTokenRefresh = fn () => $this->profile->update(['workflow_enabled' => false]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->tokenRefreshCount)->toBe(1)->and($this->sentCount)->toBe(0)
        ->and($delivery->fresh()->gmail_message_id)->toBeNull();
    Bus::assertNothingDispatched();
});

test('same second fulfillment edits invalidate the reviewed completion email before sending', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $this->gateway->shouldReceive('checkOrder')->once()->andReturn([
        'status' => 'processing', 'paid' => true, 'customer_email' => 'alex@example.test',
        'modified_gmt' => '2026-10-21T10:00:00', 'fulfillment_revision' => str_repeat('b', 64),
    ]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($delivery->fresh()->result_message)->toContain('store order changed')
        ->and($this->sentCount)->toBe(0);
    Bus::assertNothingDispatched();
});

test('a missing or malformed fulfillment revision cannot prepare a completion email', function (mixed $revision) {
    $documents = brandedUpload($this);
    $this->gateway->shouldReceive('checkOrder')->once()->andReturn([
        'status' => 'processing', 'paid' => true, 'customer_email' => 'alex@example.test',
        'modified_gmt' => '2026-10-21T10:00:00', 'fulfillment_revision' => $revision,
    ]);
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', [
        'document_ids' => array_column($documents, 'id'), 'recipient' => 'alex@example.test', 'mode' => 'complete',
        'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel'],
    ])->assertUnprocessable()->assertJsonValidationErrors('delivery');
    expect(OrderEmailDelivery::count())->toBe(0)->and($this->sentCount)->toBe(0);
    Bus::assertNothingDispatched();
})->with([null, '', 'timestamp-only', str_repeat('g', 64)]);

test('old completion previews without a strong revision cannot send', function () {
    $delivery = brandedPrepare($this, brandedUpload($this), ['mode' => 'complete', 'fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight', 'Hotel']]);
    $snapshot = $delivery->snapshot;
    unset($snapshot['expected_fulfillment_revision']);
    $delivery->update(['snapshot' => $snapshot]);
    brandedSend($this, $delivery)->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sentCount)->toBe(0);
    Bus::assertNothingDispatched();
});

test('a custom local disk cannot approve PDFs under a publicly served root', function (string $location) {
    $roots = ['web' => public_path('documents'), 'linked' => storage_path('app/public/documents'),
        'custom_link' => storage_path('app/custom-downloads'), 'dot_segments' => storage_path('app/private/../public/documents')];
    config(['document_delivery.disk' => 'document_test', 'filesystems.disks.document_test' => [
        'driver' => 'local', 'root' => $roots[$location], 'visibility' => 'private',
    ], 'filesystems.links' => [public_path('downloads') => storage_path('app/custom-downloads')]]);
    $this->post('/orders/'.$this->order->id.'/document-deliveries/documents', [
        'files' => [brandedPdf()], 'approved' => true, 'intended_customer_email' => 'alex@example.test',
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('documents');
    expect(OrderDocument::count())->toBe(0);
})->with(['web', 'linked', 'custom_link', 'dot_segments']);
