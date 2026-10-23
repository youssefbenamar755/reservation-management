<?php

use App\Jobs\SyncDocumentCompletion;
use App\Models\FfSubmission;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\SubmissionItinerary;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Models\WebsiteEmailSetting;
use App\Services\BrandedDocumentRenderer;
use App\Services\GmailClient;
use App\Services\SubmissionItineraryData;
use App\Services\SubmissionItineraryPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('local');
    Bus::fake([SyncDocumentCompletion::class]);
    $this->actor = User::factory()->create(['is_admin' => false]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Example Travel', 'slug' => 'generated-docs', 'base_url' => 'https://example.test']);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 42, 'status' => 'processing', 'customer_email' => 'traveller@example.test', 'customer_name' => 'Alex Example', 'payload' => ['meta_data' => [['key' => '_fluent_id', 'value' => '1776']]]]);
    $this->entry = FfSubmission::create(['website_id' => $this->site->id, 'entry_id' => 1776, 'form_id' => 4, 'email' => 'traveller@example.test',
        'payload' => ['response' => ['names' => ['first_name' => 'Alex', 'last_name' => 'Example'], 'flight_from' => 'CMN', 'flight_to' => 'CDG', 'flight_departure' => '2026-11-01T10:15:00']]]);
    $this->itinerary = SubmissionItinerary::create(['ff_submission_id' => $this->entry->id, 'reference' => 'ITN-FICTIONAL-1042', 'reference_type' => 'internal', 'template' => 'classic_v1', 'generated_at' => now(),
        'snapshot' => app(SubmissionItineraryData::class)->fromPayload($this->entry->payload, $this->entry->email)]);
    GmailAppSetting::create(['id' => 1, 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
    $this->gmail = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'manager@example.test', 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GmailClient::class)->fingerprint(), 'access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh', 'expires_at' => now()->addHour(), 'connected_at' => now(), 'aliases' => [['email' => 'documents@example.test', 'name' => 'Example Travel']]]);
    WebsiteEmailSetting::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'sender_email' => 'documents@example.test', 'subject_template' => WebsiteEmailSetting::DEFAULT_SUBJECT, 'body_template' => WebsiteEmailSetting::DEFAULT_BODY, 'signature' => WebsiteEmailSetting::DEFAULT_SIGNATURE]);
    $this->profile = WebsiteDocumentProfile::create(['website_id' => $this->site->id, 'brand_name' => 'Example Travel', 'primary_color' => '#143e50', 'accent_color' => '#248070', 'background_color' => '#f5f7fa', 'text_color' => '#102030', 'support_email' => 'support@example.test', 'website_url' => 'https://example.test', 'footer' => 'Example Travel', 'gmail_connection_id' => $this->gmail->id, 'sender_email' => 'documents@example.test', 'sender_name' => 'Example Travel', 'reply_to' => 'support@example.test', 'default_language' => 'en', 'templates' => BrandedDocumentRenderer::defaults(), 'service_label' => 'reservation', 'required_services' => ['Flight', 'Hotel'], 'workflow_enabled' => false, 'config_version' => 1, 'store_pairing_key' => (string) Str::uuid()]);
    $this->profile->update(['logo_url' => 'https://example.test/logo.png']);
    $this->renderCount = 0;
    $this->mock(SubmissionItineraryPdf::class)->shouldReceive('render')->andReturnUsing(function ($document) {
        $this->renderCount++;

        return "%PDF-1.4\n1 0 obj\n<< /Title (Immutable itinerary {$document['reference']} render {$this->renderCount}) >>\nendobj\n%%EOF\n";
    });
    $this->sendCount = 0;
    $this->sentMime = null;
    $this->duringAliases = null;
    $this->duringTokenRefresh = null;
    Http::fake(function ($request) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            if ($this->duringTokenRefresh) {
                ($this->duringTokenRefresh)();
            }

            return Http::response(['access_token' => 'fictional-new-token', 'expires_in' => 3600]);
        }
        if (str_ends_with($request->url(), '/settings/sendAs')) {
            if ($this->duringAliases) {
                ($this->duringAliases)();
            }

            return Http::response(['sendAs' => [['sendAsEmail' => 'manager@example.test', 'isPrimary' => true], ['sendAsEmail' => 'documents@example.test', 'displayName' => 'Example Travel', 'verificationStatus' => 'accepted']]]);
        }
        if (str_ends_with($request->url(), '/messages/send')) {
            $this->sendCount++;
            $this->sentMime = base64_decode(strtr($request['raw'], '-_', '+/'));

            return Http::response(['id' => 'fictional-message-1']);
        }
        throw new RuntimeException('Unexpected remote request');
    });
    $this->actingAs($this->actor);
});

function itineraryAttachmentValues($test, array $overrides = []): array
{
    $source = $test->getJson(route('orders.email.context', $test->order))->assertOk()->json('itinerary_attachment.source');

    return array_replace(['submission_id' => $test->entry->id, 'source_version' => $source['source_version'], 'approved' => true, 'intended_customer_email' => 'traveller@example.test'], $overrides);
}

function attachGeneratedItinerary($test): OrderDocument
{
    $response = $test->postJson(route('orders.email.itinerary', $test->order), itineraryAttachmentValues($test))->assertOk();

    return OrderDocument::findOrFail($response->json('document.id'));
}

function prepareGeneratedItinerary($test, OrderDocument $document, bool $branded): OrderEmailDelivery
{
    $test->profile->update(['workflow_enabled' => $branded]);
    $url = '/orders/'.$test->order->id.($branded ? '/document-deliveries/prepare' : '/email/preview');
    $response = $test->postJson($url, ['document_ids' => [$document->id], 'mode' => 'partial', 'recipient' => 'traveller@example.test', 'subject' => 'Your itinerary', 'body' => 'Please review your itinerary.'])->assertOk();

    return OrderEmailDelivery::findOrFail($response->json('preview.id'));
}

function generatedItinerarySendUrl($test, OrderEmailDelivery $delivery, bool $branded): string
{
    return '/orders/'.$test->order->id.($branded ? '/document-deliveries/' : '/email/').$delivery->id.'/send';
}

test('itinerary discovery is read only and explicit approved attachment reuses one immutable PDF', function () {
    $values = itineraryAttachmentValues($this);
    expect(OrderDocument::count())->toBe(0)->and($this->renderCount)->toBe(0);
    Http::assertNothingSent();
    $first = $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertOk()
        ->assertJsonPath('document.source', 'submission_itinerary')->assertJsonPath('document.approved', true)->assertJsonPath('document.submission_id', $this->entry->id);
    $second = $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertOk();
    expect($second->json('document'))->toBe($first->json('document'));
    $doc = OrderDocument::firstOrFail();
    expect($this->renderCount)->toBe(1)->and(OrderDocument::count())->toBe(1)->and($doc->version)->toBe(1)
        ->and($doc->customer_email)->toBe('traveller@example.test')->and($doc->approved_by)->toBe($this->actor->id)
        ->and($this->order->fresh()->status)->toBe('processing')->and(OrderEmailDelivery::count())->toBe(0);
    $this->get($first->json('document.download_url'))->assertOk();
    expect($first->getContent())->not->toContain($doc->path, 'fictional-access');
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

test('the actual itinerary renderer produces an attachable PDF without external calls', function () {
    app()->instance(SubmissionItineraryPdf::class, new SubmissionItineraryPdf);
    $document = attachGeneratedItinerary($this);
    $bytes = Storage::disk($document->disk)->get($document->path);
    expect($bytes)->toStartWith('%PDF-')->and(strlen($bytes))->toBeGreaterThan(1000)
        ->and($document->sha256)->toBe(hash('sha256', $bytes));
    Http::assertNothingSent();
});

test('attach requires explicit approval exact customer and no arbitrary document paths', function (array $overrides, string $field) {
    $this->postJson(route('orders.email.itinerary', $this->order), itineraryAttachmentValues($this, $overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(OrderDocument::count())->toBe(0)->and($this->renderCount)->toBe(0);
})->with([
    [['approved' => false], 'approved'], [['intended_customer_email' => 'other@example.test'], 'itinerary'],
    [['path' => 'private/arbitrary.pdf'], 'path'], [['url' => 'https://example.test/file.pdf'], 'url'],
]);

test('generated attachment rejects wrong site unrelated ambiguous or conflicting submission links', function (string $mutation) {
    $values = itineraryAttachmentValues($this);
    if ($mutation === 'foreign_actor') {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertForbidden();

        return;
    }
    if ($mutation === 'wrong_entry') {
        $values['submission_id'] = $this->entry->id + 999;
    }
    if ($mutation === 'foreign_site') {
        $other = Website::create(['user_id' => $this->actor->id, 'name' => 'Other', 'slug' => 'other-generated', 'base_url' => 'https://other.test']);
        $this->entry->update(['website_id' => $other->id]);
    }
    if ($mutation === 'duplicate_form') {
        $entry = $this->entry->replicate();
        $entry->form_id = 99;
        $entry->save();
    }
    if ($mutation === 'duplicate_order') {
        $order = $this->order->replicate();
        $order->wp_order_id = 88;
        $order->save();
    }
    if (in_array($mutation, ['missing_link', 'conflicting_links', 'malformed_link'])) {
        $meta = match ($mutation) {
            'missing_link' => [],
            'conflicting_links' => [['key' => '_fluent_id', 'value' => 1776], ['key' => '_fluent_id', 'value' => 1777]],
            'malformed_link' => [['key' => '_fluent_id', 'value' => ['1776']]],
        };
        $this->order->update(['payload' => ['meta_data' => $meta]]);
    }
    $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    expect(OrderDocument::count())->toBe(0)->and($this->renderCount)->toBe(0);
})->with(['foreign_actor', 'wrong_entry', 'foreign_site', 'duplicate_form', 'duplicate_order', 'missing_link', 'conflicting_links', 'malformed_link']);

test('every available customer identity must agree and missing ownership is blocked', function (string $mutation) {
    $values = itineraryAttachmentValues($this);
    $snapshot = $this->itinerary->snapshot;
    if ($mutation === 'entry') {
        $this->entry->update(['email' => 'different@example.test']);
    }
    if ($mutation === 'snapshot') {
        $snapshot['contact']['email'] = 'different@example.test';
        $this->itinerary->update(['snapshot' => $snapshot]);
    }
    if ($mutation === 'payload') {
        $payload = $this->entry->payload;
        $payload['response']['email'] = 'different@example.test';
        $this->entry->update(['payload' => $payload]);
    }
    if ($mutation === 'missing') {
        $this->entry->update(['email' => null]);
        $snapshot['contact']['email'] = null;
        $this->itinerary->update(['snapshot' => $snapshot]);
    }
    $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertUnprocessable()->assertJsonValidationErrors('itinerary');
})->with(['entry', 'snapshot', 'payload', 'missing']);

test('case-only email differences are accepted and source version changes within the same second', function () {
    $this->entry->update(['email' => 'TRAVELLER@EXAMPLE.TEST']);
    $document = attachGeneratedItinerary($this);
    $oldValues = itineraryAttachmentValues($this);
    $snapshot = $this->itinerary->snapshot;
    $snapshot['passengers'][0]['first_name'] = 'Corrected';
    $this->itinerary->update(['snapshot' => $snapshot]);
    $this->postJson(route('orders.email.itinerary', $this->order), $oldValues)->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    $new = attachGeneratedItinerary($this);
    expect($new->id)->not->toBe($document->id)->and($new->source_version)->not->toBe($document->source_version)
        ->and($new->version)->toBe(2)->and($this->renderCount)->toBe(2);
});

test('unknown templates cannot bypass the itinerary download restriction', function () {
    $values = itineraryAttachmentValues($this);
    $this->itinerary->update(['template' => 'unknown_future_template']);
    $this->postJson(route('orders.email.itinerary', $this->order), $values)->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    expect($this->renderCount)->toBe(0);
});

test('outer database rollback removes only the newly written private itinerary file', function () {
    DB::statement("CREATE TRIGGER reject_itinerary_source BEFORE UPDATE ON order_documents BEGIN SELECT RAISE(FAIL, 'synthetic source metadata failure'); END");
    try {
        $this->postJson(route('orders.email.itinerary', $this->order), itineraryAttachmentValues($this))->assertStatus(500);
        expect(OrderDocument::count())->toBe(0)->and(Storage::disk('local')->allFiles('order-documents'))->toBe([]);
    } finally {
        DB::statement('DROP TRIGGER reject_itinerary_source');
    }
});

test('both email workflows preserve reviewed generated PDF bytes and require final send confirmation', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $bytes = Storage::disk($document->disk)->get($document->path);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $original = $delivery->mime;
    expect($delivery->snapshot['attachments'][0])->toMatchArray($document->summary());
    expect($original)->toContain(rtrim(chunk_split(base64_encode($bytes), 76, "\r\n")));
    expect($this->sendCount)->toBe(0)->and($this->order->fresh()->status)->toBe('processing');
    $url = generatedItinerarySendUrl($this, $delivery, $branded);
    $this->postJson($url, [])->assertUnprocessable();
    // The immutable MIME is the delivery authority; sending does not re-render or
    // download the source PDF, which may have since been archived from storage.
    Storage::disk($document->disk)->delete($document->path);
    $this->postJson($url, ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sent');
    $this->postJson($url, ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sentMime)->toBe($original)->and($this->sendCount)->toBe(1)->and($this->renderCount)->toBe(1)
        ->and($this->order->fresh()->status)->toBe('processing');
    Bus::assertNothingDispatched();
})->with([false, true]);

test('both workflows reject stale generated documents at preview and before sending', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $this->itinerary->update(['external_pnr' => 'NEW123', 'reference_type' => 'external', 'booking_source' => 'Operator system']);
    $url = '/orders/'.$this->order->id.($branded ? '/document-deliveries/prepare' : '/email/preview');
    $this->postJson($url, ['document_ids' => [$document->id], 'mode' => 'partial', 'recipient' => 'traveller@example.test', 'subject' => 'Your itinerary', 'body' => 'Please review.'])->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    $this->postJson(generatedItinerarySendUrl($this, $delivery, $branded), ['confirmed' => true])->assertUnprocessable();
    expect($this->sendCount)->toBe(0)->and($delivery->fresh()->status)->toBe('prepared');
})->with([false, true]);

test('both workflows recheck generated source after slow alias refresh', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $this->duringAliases = fn () => $this->entry->update(['email' => 'changed@example.test']);
    $this->postJson(generatedItinerarySendUrl($this, $delivery, $branded), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sendCount)->toBe(0);
})->with([false, true]);

test('revoking the approval actor prevents reuse legacy preview and send', function () {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, false);
    $document->update(['approved_by' => null]);
    $this->postJson(route('orders.email.itinerary', $this->order), itineraryAttachmentValues($this))->assertUnprocessable();
    $this->postJson(route('orders.email.preview', $this->order), ['document_ids' => [$document->id], 'recipient' => 'traveller@example.test', 'subject' => 'Itinerary', 'body' => 'Review'])->assertUnprocessable()->assertJsonValidationErrors('document_ids');
    $this->postJson(generatedItinerarySendUrl($this, $delivery, false), ['confirmed' => true])->assertUnprocessable();
    expect($this->sendCount)->toBe(0);
});

test('legacy generated and uploaded files share count and total size limits', function () {
    $document = attachGeneratedItinerary($this);
    $values = ['document_ids' => [$document->id], 'recipient' => 'traveller@example.test', 'subject' => 'Itinerary', 'body' => 'Review'];
    $small = fn () => UploadedFile::fake()->createWithContent('hotel.pdf', "%PDF-1.4\nFictional\n%%EOF\n");
    $this->post(route('orders.email.preview', $this->order), $values + ['files' => [$small(), $small(), $small(), $small(), $small()]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files');
    $large = fn () => UploadedFile::fake()->createWithContent('large.pdf', '%PDF-'.str_repeat('x', 5242875));
    $this->post(route('orders.email.preview', $this->order), $values + ['files' => [$large(), $large()]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files');
    $this->post(route('orders.email.preview', $this->order), $values + ['files' => [$small()]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'preview.attachments');
    expect($this->sendCount)->toBe(0);
});

test('repeated attachment cannot bypass the uncertain outcome using a fresh PDF render', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $delivery->update(['status' => 'uncertain']);
    $again = attachGeneratedItinerary($this);
    $repeated = prepareGeneratedItinerary($this, $again, $branded);
    expect($again->id)->toBe($document->id)->and($repeated->id)->toBe($delivery->id)->and($this->renderCount)->toBe(1);
    $this->postJson(generatedItinerarySendUrl($this, $repeated, $branded), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    expect($this->sendCount)->toBe(0);
})->with([false, true]);

test('deleted source itinerary cannot be sent through its old approved snapshot', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $this->itinerary->delete();
    expect($document->fresh()->submission_itinerary_id)->toBeNull()->and($document->fresh()->source_version)->not->toBeNull();
    $this->postJson(generatedItinerarySendUrl($this, $delivery, $branded), ['confirmed' => true])->assertUnprocessable();
    expect($this->sendCount)->toBe(0);
})->with([false, true]);

test('slow OAuth refresh cannot send a generated itinerary after its order link changes', function (bool $branded) {
    $document = attachGeneratedItinerary($this);
    $delivery = prepareGeneratedItinerary($this, $document, $branded);
    $this->gmail->update(['expires_at' => now()->subMinute()]);
    $this->duringTokenRefresh = fn () => $this->order->update(['payload' => []]);
    $this->postJson(generatedItinerarySendUrl($this, $delivery, $branded), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sendCount)->toBe(0);
})->with([false, true]);

test('a saved generated attachment is unavailable on another order even with the same customer', function () {
    $document = attachGeneratedItinerary($this);
    $other = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 99, 'customer_email' => $this->order->customer_email, 'status' => 'processing', 'payload' => []]);
    $this->postJson(route('orders.email.preview', $other), ['document_ids' => [$document->id], 'recipient' => $other->customer_email, 'subject' => 'Itinerary', 'body' => 'Review'])->assertUnprocessable()->assertJsonValidationErrors('document_ids');
    expect($this->sendCount)->toBe(0);
});

test('generated document completion still requires every service and explicit fulfillment confirmation', function () {
    $document = attachGeneratedItinerary($this);
    $this->profile->update(['workflow_enabled' => true, 'store_capability' => ['supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 1, 'capabilities' => ['order_revision']], 'store_checked_at' => now(), 'brand_sync_version' => 1]);
    $this->site->update(['wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $values = ['document_ids' => [$document->id], 'mode' => 'complete', 'recipient' => 'traveller@example.test'];
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', $values)->assertUnprocessable()->assertJsonValidationErrors('fulfilled_services');
    $this->postJson('/orders/'.$this->order->id.'/document-deliveries/prepare', $values + ['fulfillment_confirmed' => true, 'fulfilled_services' => ['Flight']])->assertUnprocessable()->assertJsonValidationErrors('fulfilled_services');
    expect($this->sendCount)->toBe(0)->and($this->order->fresh()->status)->toBe('processing');
    Bus::assertNothingDispatched();
});
