<?php

use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteEmailSetting;
use App\Services\GmailClient;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-07 18:00:00');
    $this->owner = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->owner->id, 'name' => 'Reservation Demo', 'slug' => 'reservation-demo', 'base_url' => 'https://reservation.example']);
    $this->order = WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => 42, 'status' => 'completed', 'currency' => 'EUR', 'total' => 30, 'customer_email' => 'customer@example.test', 'customer_name' => 'Synthetic Customer', 'payload' => []]);
    GmailAppSetting::create(['id' => 1, 'client_id' => 'demo-client', 'client_secret' => 'demo-client-secret']);
    $this->connection = documentEmailConnection($this->owner);
    $this->setting = documentEmailSetting($this->owner, $this->website);
    $this->aliases = ['sendAs' => [
        ['sendAsEmail' => 'manager@example.test', 'displayName' => 'Manager', 'isPrimary' => true],
        ['sendAsEmail' => 'documents@example.test', 'displayName' => 'Réservations', 'verificationStatus' => 'accepted'],
    ]];
    $this->sendStatus = 200;
    $this->sendBody = ['id' => 'gmail-message-1'];
    $this->sendCalls = 0;
    $this->sentMime = null;
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/settings/sendAs')) {
            return Http::response($this->aliases);
        }
        if (str_ends_with($request->url(), '/messages/send')) {
            $this->sendCalls++;
            $this->sentMime = base64_decode(strtr($request['raw'], '-_', '+/'));

            return $this->sendStatus === 0 ? Http::failedConnection('private provider detail') : Http::response($this->sendBody, $this->sendStatus);
        }
        throw new RuntimeException('Unexpected HTTP request in email test.');
    });
    $this->actingAs($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

function documentEmailConnection(User $user): GmailConnection
{
    return GmailConnection::create([
        'user_id' => $user->id, 'email' => 'manager@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GmailClient::class)->fingerprint(), 'access_token' => 'private-access-token', 'refresh_token' => 'private-refresh-token',
        'expires_at' => now()->addHour(), 'connected_at' => now(), 'aliases' => [['email' => 'documents@example.test', 'name' => 'Réservations']],
    ]);
}

function documentEmailSetting(User $user, Website $website): WebsiteEmailSetting
{
    return WebsiteEmailSetting::create([
        'user_id' => $user->id, 'website_id' => $website->id, 'sender_email' => 'documents@example.test',
        'subject_template' => WebsiteEmailSetting::DEFAULT_SUBJECT, 'body_template' => WebsiteEmailSetting::DEFAULT_BODY,
        'signature' => WebsiteEmailSetting::DEFAULT_SIGNATURE,
    ]);
}

function documentEmailPdf(string $name = 'Reservation-é.pdf', string $body = 'Original reservation PDF'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Title ($body) >>\nendobj\n%%EOF\n");
}

function documentEmailRequest(array $values = []): array
{
    return array_replace(['recipient' => 'customer@example.test', 'subject' => 'Vos documents — réservation', 'body' => "Bonjour,\n\nPlease find your original documents attached.\n\nReservation Demo", 'files' => [documentEmailPdf()]], $values);
}

function documentEmailPreview($test, array $values = []): OrderEmailDelivery
{
    $response = $test->post(route('orders.email.preview', $test->order), documentEmailRequest($values), ['Accept' => 'application/json'])->assertOk();

    return OrderEmailDelivery::findOrFail($response->json('preview.id'));
}

test('email options render website templates and signature without any remote calls', function () {
    $response = $this->getJson(route('orders.email.options', $this->order))->assertOk()
        ->assertJsonPath('connection.connected', true)->assertJsonPath('sender.email', 'documents@example.test')
        ->assertJsonPath('recipient', 'customer@example.test')->assertJsonPath('subject', 'Vos documents de réservation — commande #42')
        ->assertJsonPath('limits.max_total_bytes', 10485760)->assertJsonPath('history', []);
    expect($response->json('body'))->toStartWith('Bonjour Synthetic Customer,')->toEndWith("\n\nReservation Demo");
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->getContent())->not->toContain('private-access-token', 'private-refresh-token', 'demo-client-secret');
    Http::assertNothingSent();
});

test('email preview snapshots original PDF bytes and UTF8 metadata encrypted and never sends', function () {
    $first = documentEmailPdf();
    $second = documentEmailPdf('Hôtel booking.pdf', 'Second PDF');
    $delivery = documentEmailPreview($this, ['files' => [$first, $second]]);
    $raw = DB::table('order_email_deliveries')->find($delivery->id);
    expect($delivery->snapshot['subject'])->toBe('Vos documents — réservation')
        ->and($delivery->snapshot['attachments'][0]['sha256'])->toBe(hash('sha256', $first->getContent()))
        ->and($delivery->snapshot['attachments'][1]['sha256'])->toBe(hash('sha256', $second->getContent()))
        ->and($delivery->mime)->toContain(rtrim(chunk_split(base64_encode($first->getContent()), 76, "\r\n")))
        ->and($delivery->mime)->toContain(rtrim(chunk_split(base64_encode($second->getContent()), 76, "\r\n")));
    expect($raw->mime)->not->toContain('Original reservation PDF', 'customer@example.test', 'Content-Type');
    expect($raw->snapshot)->not->toContain('customer@example.test', 'Reservation Demo');
    expect($delivery->toArray())->not->toHaveKeys(['mime', 'snapshot', 'connection_key']);
    $this->getJson(route('orders.email.show', [$this->order, $delivery]))->assertOk()->assertJsonCount(2, 'preview.attachments')
        ->assertJsonPath('preview.attachments.0.name', 'Reservation-é.pdf')->assertJsonPath('preview.status', 'prepared')->assertJsonMissingPath('preview.mime');
    expect($this->sendCalls)->toBe(0);
});

test('send uses only immutable reviewed content and purges MIME after Gmail accepts it', function () {
    $upload = documentEmailPdf();
    $delivery = documentEmailPreview($this, ['files' => [$upload]]);
    $originalMime = $delivery->mime;
    file_put_contents($upload->getRealPath(), '%PDF-changed after preview');
    $this->order->update(['customer_email' => 'different@example.test']);
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), [
        'confirmed' => true, 'recipient' => 'forged@example.test', 'subject' => 'Different subject', 'body' => 'Different body',
    ])->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sentMime)->toBe($originalMime)->not->toContain('forged@example.test', 'PDF-changed after preview');
    expect($delivery->refresh()->mime)->toBeNull()->and($delivery->gmail_message_id)->toBe('gmail-message-1')->and($delivery->send_attempts)->toBe(1);
    $this->getJson(route('orders.email.show', [$this->order, $delivery]))->assertOk()->assertJsonPath('delivery.status', 'sent')->assertJsonPath('preview.recipient', 'customer@example.test');
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sendCalls)->toBe(1);
});

test('duplicate previews reuse the same delivery including after successful send', function () {
    $first = documentEmailPreview($this);
    $second = documentEmailPreview($this);
    expect($second->id)->toBe($first->id);
    $this->postJson(route('orders.email.send', [$this->order, $first]), ['confirmed' => true])->assertOk();
    $third = documentEmailPreview($this);
    expect($third->id)->toBe($first->id)->and($third->status)->toBe('sent')->and(OrderEmailDelivery::count())->toBe(1);
    expect($this->sendCalls)->toBe(1);
});

test('an in-flight send cannot be claimed by a second request', function () {
    $delivery = documentEmailPreview($this);
    $delivery->update(['status' => 'sending', 'sending_at' => now(), 'send_attempts' => 1]);
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sending');
    expect($this->sendCalls)->toBe(0)->and($delivery->refresh()->send_attempts)->toBe(1);
});

test('ambiguous send outcomes are persisted and never retried even with new preview request', function (int $status, array $body) {
    $delivery = documentEmailPreview($this);
    $this->sendStatus = $status;
    $this->sendBody = $body;
    $response = $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    expect($response->getContent())->not->toContain('private provider detail');
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    expect(documentEmailPreview($this)->id)->toBe($delivery->id)->and($this->sendCalls)->toBe(1);
})->with([[0, []], [503, ['error' => 'private provider detail']], [200, ['unexpected' => true]]]);

test('a known Gmail rejection permits only an explicitly confirmed later retry', function () {
    $delivery = documentEmailPreview($this);
    $this->sendStatus = 429;
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sendCalls)->toBe(1)->and($delivery->refresh()->mime)->not->toBeNull();
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), [])->assertUnprocessable();
    expect($this->sendCalls)->toBe(1);
    $this->sendStatus = 200;
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sent');
    expect($this->sendCalls)->toBe(2)->and($delivery->refresh()->send_attempts)->toBe(2);
});

test('expired previews cannot send and their immutable attachments are removed', function () {
    $delivery = documentEmailPreview($this);
    $this->travel(25)->hours();
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'expired');
    expect($delivery->refresh()->mime)->toBeNull()->and($delivery->snapshot['body'])->toContain('Bonjour')->and($this->sendCalls)->toBe(0);
    $this->connection->update(['expires_at' => now()->addHour()]);
    expect(documentEmailPreview($this)->id)->not->toBe($delivery->id);
});

test('a stopped send becomes uncertain instead of silently retrying', function () {
    $delivery = documentEmailPreview($this);
    $delivery->update(['status' => 'sending', 'sending_at' => now()->subMinutes(6)]);
    $this->getJson(route('orders.email.show', [$this->order, $delivery]))->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    expect($this->sendCalls)->toBe(0);
});

test('a switched Gmail connection requires a newly reviewed preview', function () {
    $delivery = documentEmailPreview($this);
    $this->connection->update(['connection_key' => (string) Str::uuid()]);
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sendCalls)->toBe(0);
    expect(documentEmailPreview($this)->id)->not->toBe($delivery->id);
});

test('send rejects a changed website sender or newly unavailable Gmail alias', function (string $change) {
    $delivery = documentEmailPreview($this);
    if ($change === 'setting') {
        $this->setting->update(['sender_email' => 'different@example.test']);
    } else {
        $this->aliases['sendAs'] = [$this->aliases['sendAs'][0]];
    }
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'failed');
    expect($this->sendCalls)->toBe(0);
})->with(['setting', 'alias']);

test('preview verifies the live sender and does not trust cached aliases or client From', function () {
    $this->aliases['sendAs'] = [$this->aliases['sendAs'][0]];
    $this->post(route('orders.email.preview', $this->order), documentEmailRequest(), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('sender');
    expect(OrderEmailDelivery::count())->toBe(0);
});

test('email validation blocks header injection sender overrides bad files and over-limit inputs', function (string $case) {
    $values = match ($case) {
        'recipient' => ['recipient' => "person@example.test\r\nBcc: other@example.test"],
        'multiple' => ['recipient' => 'person@example.test,other@example.test'],
        'subject' => ['subject' => "Documents\r\nBcc: other@example.test"],
        'long-subject' => ['subject' => str_repeat('x', 256)],
        'long-body' => ['body' => str_repeat('x', 20001)],
        'sender' => ['from' => 'forged@example.test'],
        'empty' => ['files' => []],
        'text' => ['files' => [UploadedFile::fake()->createWithContent('fake.pdf', 'This is not a PDF')]],
        'large' => ['files' => [UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')]],
        'total' => ['files' => array_map(fn () => UploadedFile::fake()->create('part.pdf', 4096, 'application/pdf'), range(1, 3))],
        'count' => ['files' => array_map(fn () => documentEmailPdf(), range(1, 6))],
        'filename' => ['files' => [documentEmailPdf("File\r\nInjected.pdf")]],
    };
    $this->post(route('orders.email.preview', $this->order), documentEmailRequest($values), ['Accept' => 'application/json'])->assertUnprocessable();
    expect(OrderEmailDelivery::count())->toBe(0)->and($this->sendCalls)->toBe(0);
})->with(['recipient', 'multiple', 'subject', 'long-subject', 'long-body', 'sender', 'empty', 'text', 'large', 'total', 'count', 'filename']);

test('order email access is scoped to the website and the preparing Gmail actor even for admins', function () {
    $delivery = documentEmailPreview($this);
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson(route('orders.email.options', $this->order))->assertForbidden();
    $this->post(route('orders.email.preview', $this->order), documentEmailRequest(), ['Accept' => 'application/json'])->assertForbidden();
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertForbidden();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->getJson(route('orders.email.options', $this->order))->assertOk()->assertJsonPath('connection.connected', false)->assertJsonPath('history', []);
    $this->getJson(route('orders.email.show', [$this->order, $delivery]))->assertNotFound();
    $this->postJson(route('orders.email.send', [$this->order, $delivery]), ['confirmed' => true])->assertNotFound();
    documentEmailConnection($admin);
    documentEmailSetting($admin, $this->website);
    $own = documentEmailPreview($this);
    expect($own->id)->not->toBe($delivery->id);
    $this->postJson(route('orders.email.send', [$this->order, $own]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'sent');
});

test('delivery URLs cannot attach a preview to a different order', function () {
    $delivery = documentEmailPreview($this);
    $other = WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => 99, 'status' => 'completed', 'payload' => []]);
    $this->getJson(route('orders.email.show', [$other, $delivery]))->assertNotFound();
    $this->postJson(route('orders.email.send', [$other, $delivery]), ['confirmed' => true])->assertNotFound();
    expect($this->sendCalls)->toBe(0);
});

test('daily preview pruning bounds storage without making uncertain sends eligible again', function () {
    $expired = documentEmailPreview($this);
    $expired->update(['expires_at' => now()->subSecond()]);
    $sent = documentEmailPreview($this, ['subject' => 'Sent documents']);
    $sent->update(['status' => 'sent']);
    $uncertain = documentEmailPreview($this, ['subject' => 'Unknown documents']);
    $uncertain->update(['status' => 'uncertain', 'expires_at' => now()->subDay()]);
    $stopped = documentEmailPreview($this, ['subject' => 'Stopped documents']);
    $stopped->update(['status' => 'sending', 'sending_at' => now()->subMinutes(6), 'expires_at' => now()->subDay()]);
    $sending = documentEmailPreview($this, ['subject' => 'Active documents']);
    $sending->update(['status' => 'sending', 'sending_at' => now()->subMinute()]);
    $this->artisan('emails:prune-previews')->assertSuccessful();
    expect($expired->refresh()->status)->toBe('expired')->and($expired->mime)->toBeNull()->and($expired->snapshot['attachments'])->toHaveCount(1);
    expect($sent->refresh()->mime)->toBeNull()->and($sent->status)->toBe('sent');
    expect($uncertain->refresh()->status)->toBe('uncertain')->and($uncertain->mime)->toBeNull()->and($uncertain->deduplication_key)->toBe($uncertain->fingerprint);
    expect($stopped->refresh()->status)->toBe('uncertain')->and($stopped->mime)->toBeNull()->and($stopped->deduplication_key)->toBe($stopped->fingerprint);
    expect($sending->refresh()->status)->toBe('sending')->and($sending->mime)->not->toBeNull();
    $this->postJson(route('orders.email.send', [$this->order, $uncertain]), ['confirmed' => true])->assertOk()->assertJsonPath('delivery.status', 'uncertain');
    expect(documentEmailPreview($this, ['subject' => 'Unknown documents'])->id)->toBe($uncertain->id);
    expect($this->sendCalls)->toBe(0);
});
