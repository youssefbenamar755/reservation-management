<?php

use App\Exceptions\GmailDeliveryException;
use App\Models\DocumentTestSend;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use App\Services\DocumentStoreGateway;
use App\Services\GmailClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tester = User::factory()->create();
    $this->testSite = Website::create(['user_id' => $this->tester->id, 'name' => 'Fictional Test Brand', 'slug' => 'test-documents', 'base_url' => 'https://test.example', 'status' => 'active']);
    GmailAppSetting::create(['id' => 1, 'client_id' => 'test.apps.googleusercontent.com', 'client_secret' => 'test-secret']);
    $this->testConnection = GmailConnection::create(['user_id' => $this->tester->id, 'email' => 'sender@test.example',
        'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GmailClient::class)->fingerprint(),
        'access_token' => 'fictional-test-access', 'refresh_token' => 'fictional-test-refresh', 'connected_at' => now(), 'expires_at' => now()->addHour(),
        'aliases' => [['email' => 'sender@test.example', 'name' => 'Fictional Test Brand']]]);
    $this->testProfile = WebsiteDocumentProfile::create(['website_id' => $this->testSite->id, 'configured_by_user_id' => $this->tester->id,
        'gmail_connection_id' => $this->testConnection->id, 'brand_name' => 'Fictional Test Brand', 'logo_url' => 'https://test.example/logo.png',
        'primary_color' => '#123456', 'accent_color' => '#176d72', 'background_color' => '#f5f7fa', 'text_color' => '#17212f',
        'support_email' => 'support@test.example', 'website_url' => 'https://test.example', 'footer' => 'Fictional Brand support',
        'sender_email' => 'sender@test.example', 'sender_name' => 'Fictional Test Brand', 'reply_to' => 'support@test.example',
        'default_language' => 'fr', 'service_label' => 'reservation', 'templates' => BrandedDocumentRenderer::defaults(),
        'workflow_enabled' => false, 'config_version' => 1, 'store_pairing_key' => (string) Str::uuid()]);
    $this->testUrl = '/settings/email-documents/'.$this->testSite->id.'/test';
    $this->testInput = ['recipient' => 'approved-test@example.test', 'language' => 'en', 'template' => 'delivery', 'sample_document_count' => 2, 'idempotency_key' => (string) Str::uuid()];
    $this->mock(DocumentStoreGateway::class)->shouldNotReceive('complete', 'syncBrand');
});

test('explicit fictional test sends actual sample pdfs once and never changes orders', function () {
    $order = WcOrder::create(['website_id' => $this->testSite->id, 'wp_order_id' => 42, 'status' => 'processing', 'total' => 15, 'currency' => 'USD', 'customer_email' => 'real-customer@example.test', 'customer_name' => 'Private Customer', 'payload' => []]);
    $mime = null;
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/settings/sendAs' => Http::response(['sendAs' => [['sendAsEmail' => 'sender@test.example', 'displayName' => 'Fictional Test Brand', 'isPrimary' => true]]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages/send' => function ($request) use (&$mime) {
            $mime = base64_decode(strtr($request['raw'], '-_', '+/'));

            return Http::response(['id' => 'fictional-message-123']);
        },
    ]);
    $this->actingAs($this->tester)->postJson($this->testUrl, $this->testInput)->assertOk()->assertJsonPath('result', 'sent');
    $this->postJson($this->testUrl, $this->testInput)->assertOk()->assertJsonPath('result', 'sent');
    expect($order->fresh()->status)->toBe('processing')
        ->and($mime)->toContain('approved-test@example.test', 'multipart/mixed', 'application/pdf', 'Sample-document-1.pdf', 'Sample-document-2.pdf', 'JVBERi0')
        ->not->toContain('real-customer@example.test', 'Private Customer')
        ->and(DocumentTestSend::count())->toBe(1)
        ->and($this->testProfile->fresh()->last_test_result['status'])->toBe('sent');
    Http::assertSentCount(2);
});

test('unknown test send results are durable and the same request never resends', function () {
    $gmail = $this->mock(GmailClient::class)->makePartial();
    $gmail->shouldReceive('aliases')->once()->andReturn($this->testConnection->aliases);
    $gmail->shouldReceive('send')->once()->andThrow(new GmailDeliveryException('Unknown', true));
    $this->actingAs($this->tester)->postJson($this->testUrl, $this->testInput)->assertOk()->assertJsonPath('result', 'uncertain');
    $this->postJson($this->testUrl, $this->testInput)->assertOk()->assertJsonPath('result', 'uncertain');
    expect(DocumentTestSend::sole()->status)->toBe('uncertain');
});

test('test attempts cannot cross website or operator boundaries', function () {
    $other = User::factory()->create();
    $this->actingAs($other)->postJson($this->testUrl, $this->testInput)->assertForbidden();
    DocumentTestSend::create(['id' => $this->testInput['idempotency_key'], 'website_id' => $this->testSite->id, 'user_id' => $other->id, 'status' => 'sending', 'snapshot' => []]);
    $this->actingAs($this->tester)->postJson($this->testUrl, $this->testInput)->assertNotFound();
    Http::assertNothingSent();
});

test('test sends require an explicit recipient and acknowledgement tests attach no pdfs', function () {
    $this->actingAs($this->tester)->postJson($this->testUrl, array_replace($this->testInput, ['recipient' => '']))->assertUnprocessable();
    $gmail = $this->mock(GmailClient::class)->makePartial();
    $gmail->shouldReceive('aliases')->once()->andReturn($this->testConnection->aliases);
    $gmail->shouldReceive('send')->once()->withArgs(function ($connection, $mime) {
        expect($mime)->toContain('approved-test@example.test')->not->toContain('application/pdf');

        return true;
    })->andReturn('acknowledgement-test-only');
    $this->postJson($this->testUrl, array_replace($this->testInput, ['template' => 'acknowledgement']))->assertOk()->assertJsonPath('result', 'sent');
    expect(DocumentTestSend::sole()->snapshot['attachments'])->toBe([]);
});
