<?php

use App\Exceptions\GmailDeliveryException;
use App\Http\Middleware\DocumentValidationIsolation;
use App\Models\GmailConnection;
use App\Services\DocumentValidationSafety;
use App\Services\GmailClient;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['document_delivery.validation.enabled' => true,
        'document_delivery.validation.mail_transport' => 'capture',
        'document_delivery.validation.recipients' => ['approved@example.invalid'],
        'document_delivery.validation.store_origins' => [],
        'document_delivery.validation.capture_path' => storage_path('framework/testing/document-captures-'.uniqid())]);
    $this->validationSafety = app(DocumentValidationSafety::class);
    $this->sampleMime = (new Email)->from('brand@example.invalid')->to('approved@example.invalid')->subject('Fictional sample')
        ->text('Fictional fixture only')->attach('%PDF-1.4 fictional fixture', 'sample.pdf', 'application/pdf')->toString();
});

afterEach(function () {
    $path = config('document_delivery.validation.capture_path');
    if (str_contains($path, 'document-captures-')) {
        $disk = Storage::build(['driver' => 'local', 'root' => $path]);
        foreach ($disk->files() as $file) {
            $disk->delete($file);
        }
        @rmdir($path);
    }
});

test('isolated capture preserves exact mime and performs no token alias or sending requests', function () {
    $connection = new GmailConnection(['aliases' => [['email' => 'brand@example.invalid', 'name' => 'Fixture only']]]);
    $gmail = app(GmailClient::class);
    expect($gmail->aliases($connection))->toBe($connection->aliases);
    $id = $gmail->send($connection, $this->sampleMime);
    $disk = Storage::build(['driver' => 'local', 'root' => config('document_delivery.validation.capture_path')]);
    expect($id)->toStartWith('capture_')->and($disk->get($id.'.eml'))->toBe($this->sampleMime);
    Http::assertNothingSent();
});

test('validation rejects unapproved recipients even with the capture transport', function () {
    $mime = str_replace('approved@example.invalid', 'customer@example.invalid', $this->sampleMime);
    expect(fn () => app(GmailClient::class)->send(new GmailConnection, $mime))->toThrow(GmailDeliveryException::class, 'allowlist');
    Http::assertNothingSent();
});

test('validation blocks hidden duplicate or malformed recipient headers', function ($headers) {
    expect(fn () => $this->validationSafety->assertRecipients($headers."\r\n\r\nFixture"))->toThrow(GmailDeliveryException::class);
})->with([
    'bcc' => ['To: approved@example.invalid'."\r\nBcc: other@example.invalid"],
    'cc' => ['To: approved@example.invalid'."\r\nCc: other@example.invalid"],
    'resent' => ['To: approved@example.invalid'."\r\nResent-To: other@example.invalid"],
    'duplicate' => ['To: approved@example.invalid'."\r\nTo: other@example.invalid"],
    'list' => ['To: approved@example.invalid, other@example.invalid'],
    'group' => ['To: Group: approved@example.invalid;'],
    'missing' => ['Subject: Fixture'],
]);

test('gmail transport fails closed with an empty allowlist before token access', function () {
    config(['document_delivery.validation.mail_transport' => 'gmail', 'document_delivery.validation.recipients' => []]);
    expect(fn () => app(GmailClient::class)->send(new GmailConnection, $this->sampleMime))->toThrow(GmailDeliveryException::class, 'allowlist');
    Http::assertNothingSent();
});

test('capture blocks all external hosts and explicitly permitted stores expose only the order api', function () {
    foreach (['https://gmail.googleapis.com/gmail/v1/users/me/messages/send', 'https://api.brevo.com/v3/smtp/email', 'https://api.stripe.com/v1/charges', 'https://production.example/wp-json/wc/v3/orders/1'] as $url) {
        expect(fn () => $this->validationSafety->guardRequest(new Request('POST', $url)))->toThrow(RuntimeException::class);
    }
    config(['document_delivery.validation.store_origins' => ['https://staging.example.invalid']]);
    $request = new Request('GET', 'https://staging.example.invalid/wp-json/wc/v3/orders/1');
    expect($this->validationSafety->guardRequest($request))->toBe($request);
    expect(fn () => $this->validationSafety->guardRequest(new Request('POST', 'https://staging.example.invalid/payments')))->toThrow(RuntimeException::class);
    expect(fn () => $this->validationSafety->guardRequest(new Request('GET', 'https://staging.example.invalid.attacker.test/wp-json/wc/v3/orders/1')))->toThrow(RuntimeException::class);
});

test('http guard independently validates recipients in approved Gmail transport', function () {
    config(['document_delivery.validation.mail_transport' => 'gmail']);
    $request = new Request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [], json_encode(['raw' => base64_encode($this->sampleMime)]));
    expect($this->validationSafety->guardRequest($request))->toBe($request);
    $blocked = new Request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [], json_encode(['raw' => base64_encode(str_replace('approved@example.invalid', 'customer@example.invalid', $this->sampleMime))]));
    expect(fn () => $this->validationSafety->guardRequest($blocked))->toThrow(GmailDeliveryException::class);
});

test('validation mode cannot run in production or unknown transport and cannot capture under public', function () {
    app()->instance('env', 'production');
    expect(fn () => $this->validationSafety->prepareMail($this->sampleMime))->toThrow(RuntimeException::class, 'forbidden');
    app()->instance('env', 'testing');
    config(['document_delivery.validation.mail_transport' => 'smtp']);
    expect(fn () => $this->validationSafety->prepareMail($this->sampleMime))->toThrow(RuntimeException::class);
    config(['document_delivery.validation.mail_transport' => 'capture', 'document_delivery.validation.capture_path' => public_path('captures')]);
    expect(fn () => $this->validationSafety->prepareMail($this->sampleMime))->toThrow(GmailDeliveryException::class, 'private');
});

test('incoming webhooks cannot enter the isolated application', function () {
    $response = (new DocumentValidationIsolation)->handle(\Illuminate\Http\Request::create('/api/v1/webhooks/woocommerce/example', 'POST'), fn () => throw new RuntimeException('Reached webhook'));
    expect($response->getStatusCode())->toBe(503);
});

test('capture rejects public disk roots and configured symlink targets even with private visibility', function () {
    foreach ([public_path('captures'), storage_path('app/public/new-child'), storage_path('app/private/../public/new-child')] as $root) {
        config(['document_delivery.validation.capture_path' => $root]);
        expect(fn () => $this->validationSafety->prepareMail($this->sampleMime))->toThrow(GmailDeliveryException::class, 'private');
    }
    config(['filesystems.links' => [public_path('shared') => storage_path('app/custom-shared')], 'document_delivery.validation.capture_path' => storage_path('app/custom-shared/new-child')]);
    expect(fn () => $this->validationSafety->prepareMail($this->sampleMime))->toThrow(GmailDeliveryException::class, 'private');
});

test('captured outcomes never claim Gmail acceptance or link to a nonexistent Gmail message', function () {
    $delivery = new \App\Models\OrderEmailDelivery(['workflow' => true, 'status' => 'sent', 'gmail_message_id' => 'capture_example', 'snapshot' => ['gmail_account_email' => 'brand@example.invalid'], 'result_message' => 'Sent']);
    expect($delivery->outcome())->toMatchArray(['captured' => true, 'gmail_url' => null])
        ->and($delivery->outcome()['message'])->toContain('no Gmail message was sent');
});

test('default production transport remains unchanged when validation is disabled', function () {
    config(['document_delivery.validation.enabled' => false]);
    expect($this->validationSafety->capture())->toBeFalse()->and($this->validationSafety->prepareMail('ordinary MIME'))->toBeNull();
});

test('completion jobs use an isolated persistent queue without changing the application default', function () {
    config(['queue.default' => 'sync']);
    $job = new \App\Jobs\SyncDocumentCompletion('fictional-delivery');
    expect($job->connection)->toBe('documents')->and($job->queue)->toBe('documents')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('queue.connections.documents.driver'))->toBe('database')
        ->and(config('queue.connections.documents.after_commit'))->toBeTrue()
        ->and(config('queue.connections.documents.retry_after'))->toBeGreaterThan($job->timeout);
    $this->mock(\App\Services\DocumentCompletionSync::class)->shouldNotReceive('sync');
    // RefreshDatabase wraps the test in a transaction: bypass only its commit hook here.
    \Illuminate\Support\Facades\Bus::dispatch($job->beforeCommit());
    $this->assertDatabaseHas('jobs', ['queue' => 'documents', 'attempts' => 0]);
    expect(\Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'default')->count())->toBe(0);
});
