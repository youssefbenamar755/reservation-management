<?php

use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\MarketingTemplate;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Models\WebsiteEmailSetting;
use App\Services\BrandedDocumentRenderer;
use App\Services\DocumentStoreGateway;
use App\Services\GmailClient;
use App\Services\WebsiteDocumentProfiles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function documentBrandConnection(User $user, string $sender): GmailConnection
{
    GmailAppSetting::updateOrCreate(['id' => 1], ['client_id' => 'documents.apps.googleusercontent.com', 'client_secret' => 'fictional-test-secret']);

    return GmailConnection::create(['user_id' => $user->id, 'email' => $sender, 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GmailClient::class)->fingerprint(), 'access_token' => 'fictional-token', 'refresh_token' => 'fictional-refresh',
        'connected_at' => now(), 'expires_at' => now()->addHour(), 'aliases' => [['email' => $sender, 'name' => 'Example Brand']]]);
}

function documentBrandInput(?GmailConnection $connection = null): array
{
    return ['brand_name' => 'Example Travel', 'logo_url' => 'https://travel.example/logo.png',
        'primary_color' => '#173b57', 'accent_color' => '#176d72', 'background_color' => '#f5f7fa', 'text_color' => '#17212f',
        'support_email' => 'support@travel.example', 'website_url' => 'https://travel.example', 'support_phone' => '+212 600 000000',
        'footer' => 'The {{brand_name}} team', 'sender_email' => $connection?->email, 'sender_name' => 'Example Travel',
        'reply_to' => 'support@travel.example', 'gmail_connection_id' => $connection?->id, 'default_language' => 'fr',
        'workflow_enabled' => false, 'config_version' => 0, 'templates' => BrandedDocumentRenderer::defaults(),
        'service_label' => 'réservation', 'required_services' => ['Flight reservation']];
}

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->brandActor = User::factory()->create(['is_admin' => false]);
    $this->brandSite = Website::create(['user_id' => $this->brandActor->id, 'name' => 'Example Travel', 'slug' => 'document-brand', 'base_url' => 'https://travel.example', 'status' => 'active']);
});

test('new websites get isolated inactive draft configuration without database or network writes', function () {
    $other = Website::create(['user_id' => $this->brandActor->id, 'name' => 'Other Brand', 'slug' => 'other-brand', 'base_url' => 'https://other.example', 'status' => 'active']);
    MarketingTemplate::create(['user_id' => $this->brandActor->id, 'website_id' => $this->brandSite->id, 'name' => 'Brand', 'content' => ['logo_url' => 'https://travel.example/logo.png', 'accent_color' => '#123456']]);
    $profiles = app(WebsiteDocumentProfiles::class);
    expect($profiles->draft($this->brandSite)->logo_url)->toBe('https://travel.example/logo.png')
        ->and($profiles->draft($other)->logo_url)->toBeNull()
        ->and($profiles->draft($other)->brand_name)->toBe('Other Brand')
        ->and($profiles->draft($other)->workflow_enabled)->toBeFalse()
        ->and($profiles->readiness($profiles->draft($other))['ready'])->toBeFalse()
        ->and(WebsiteDocumentProfile::count())->toBe(0);
    Http::assertNothingSent();
});

test('ambiguous historical senders and marketing identity remain configuration required', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    foreach ([$this->brandActor, $admin] as $i => $actor) {
        $connection = documentBrandConnection($actor, 'sender'.$i.'@example.test');
        WebsiteEmailSetting::create(['user_id' => $actor->id, 'website_id' => $this->brandSite->id, 'sender_email' => $connection->email,
            'subject_template' => 'Legacy', 'body_template' => 'Legacy', 'signature' => 'Legacy']);
        MarketingTemplate::create(['user_id' => $actor->id, 'website_id' => $this->brandSite->id, 'name' => 'Brand', 'content' => ['logo_url' => 'https://travel.example/logo'.$i.'.png']]);
    }
    $draft = app(WebsiteDocumentProfiles::class)->draft($this->brandSite);
    expect($draft->gmail_connection_id)->toBeNull()->and($draft->logo_url)->toBeNull();
});

test('settings require ownership and never expose tokens or unrelated admin accounts', function () {
    $otherAdmin = User::factory()->create(['is_admin' => true]);
    documentBrandConnection($otherAdmin, 'other-admin@example.test');
    $connection = documentBrandConnection($this->brandActor, 'brand@example.test');
    $this->get('/settings/email-documents')->assertRedirect('/login');
    $response = $this->actingAs($this->brandActor)->get('/settings/email-documents')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('settings/EmailDocuments')->has('websites', 1)
        ->has('websites.0.connections', 1)->where('websites.0.connections.0.id', $connection->id));
    $response->assertDontSee('fictional-token')->assertDontSee('fictional-refresh')->assertDontSee('other-admin@example.test');
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput())->assertForbidden();
    $this->postJson('/settings/email-documents/'.$this->brandSite->id.'/preview', [])->assertForbidden();
});

test('draft save requires explicit activation and prevents stale concurrent settings overwrites', function () {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput($connection))
        ->assertOk()->assertJsonPath('website.readiness.ready', true)->assertJsonPath('website.profile.workflow_enabled', false);
    $profile = WebsiteDocumentProfile::sole();
    expect($profile->config_version)->toBe(1)->and(Str::isUuid($profile->store_pairing_key))->toBeTrue();
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput($connection))->assertUnprocessable()->assertJsonValidationErrors('config_version');
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['config_version' => 1, 'workflow_enabled' => true]))
        ->assertOk()->assertJsonPath('website.profile.workflow_enabled', true)->assertJsonPath('website.readiness.completion_supported', false);
    Http::assertNothingSent();
});

test('missing sender and invalid aliases cannot activate the workflow', function () {
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput(), ['workflow_enabled' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_enabled');
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['sender_email' => 'unverified@example.test', 'workflow_enabled' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_enabled');
    expect(WebsiteDocumentProfile::count())->toBe(0);
});

test('emergency disable succeeds with revoked gmail assignment and invalid templates without publishing or replacing configuration', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $connection = documentBrandConnection($admin, 'old-admin@example.test');
    $profile = WebsiteDocumentProfile::create(array_replace(documentBrandInput($connection), [
        'website_id' => $this->brandSite->id, 'configured_by_user_id' => $admin->id, 'workflow_enabled' => true,
        'templates' => [], 'config_version' => 8, 'store_pairing_key' => (string) Str::uuid(),
    ]));
    $admin->update(['is_admin' => false]);
    $before = $profile->attributesToArray();
    $this->actingAs($this->brandActor)->postJson('/settings/email-documents/'.$this->brandSite->id.'/disable', [
        'confirmed' => true, 'brand_name' => 'Must not overwrite saved brand', 'templates' => [],
    ])->assertOk()->assertJsonPath('workflow_enabled', false)->assertJsonPath('config_version', 9)
        ->assertJsonPath('previous_config_version', 8)->assertJsonPath('profile.brand_name', $before['brand_name']);
    $profile->refresh();
    expect($profile->workflow_enabled)->toBeFalse()->and($profile->gmail_connection_id)->toBe($connection->id)
        ->and($profile->templates)->toBe([])->and($profile->store_pairing_key)->toBe($before['store_pairing_key']);
    $this->assertDatabaseHas('action_history_events', ['website_id' => $this->brandSite->id, 'kind' => 'document_profile', 'outcome' => 'succeeded']);
    $events = \App\Models\ActionHistoryEvent::count();
    $this->postJson('/settings/email-documents/'.$this->brandSite->id.'/disable', ['confirmed' => true])->assertOk()->assertJsonPath('config_version', 9);
    expect(\App\Models\ActionHistoryEvent::count())->toBe($events);
    Http::assertNothingSent();
});

test('emergency disable still requires authorization and explicit confirmation', function () {
    $profile = WebsiteDocumentProfile::create(['website_id' => $this->brandSite->id, 'brand_name' => 'Invalid saved draft',
        'workflow_enabled' => true, 'config_version' => 2, 'store_pairing_key' => (string) Str::uuid(), 'templates' => []]);
    $this->actingAs(User::factory()->create())->postJson('/settings/email-documents/'.$this->brandSite->id.'/disable', ['confirmed' => true])->assertForbidden();
    $this->actingAs($this->brandActor)->postJson('/settings/email-documents/'.$this->brandSite->id.'/disable', [])->assertUnprocessable();
    expect($profile->fresh()->workflow_enabled)->toBeTrue()->and($profile->fresh()->config_version)->toBe(2);
    Http::assertNothingSent();
});

test('other tenants gmail and unrelated admin accounts cannot be newly selected', function () {
    $other = User::factory()->create();
    $otherAdmin = User::factory()->create(['is_admin' => true]);
    foreach ([$other, $otherAdmin] as $user) {
        $connection = documentBrandConnection($user, 'sender'.$user->id.'@example.test');
        $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput($connection))
            ->assertUnprocessable()->assertJsonValidationErrors('gmail_connection_id');
    }
});

test('admin can explicitly assign their gmail to a website and its owner can retain it', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $connection = documentBrandConnection($admin, 'admin-brand@example.test');
    $this->actingAs($admin)->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput($connection))->assertOk();
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['config_version' => 1]))->assertOk();
    expect(app(WebsiteDocumentProfiles::class)->connectionAuthorized(WebsiteDocumentProfile::sole()))->toBeTrue();
    $admin->update(['is_admin' => false]);
    expect(app(WebsiteDocumentProfiles::class)->connectionAuthorized(WebsiteDocumentProfile::sole()))->toBeFalse();
});

test('fictional previews cannot save settings or use real customer information', function () {
    $response = $this->actingAs($this->brandActor)->postJson('/settings/email-documents/'.$this->brandSite->id.'/preview', [
        'profile' => documentBrandInput(), 'template' => 'delivery', 'language' => 'en', 'sample_document_count' => 2,
        'recipient' => 'real-customer@example.test', 'customer_first_name' => 'Private Customer',
    ])->assertOk()->assertJsonPath('sample.recipient', 'preview@example.invalid')->assertJsonPath('language', 'en');
    expect($response->json('html'))->toContain('Alex', 'sample-document-1.pdf', 'sample-document-2.pdf')->not->toContain('Private Customer');
    expect(WebsiteDocumentProfile::count())->toBe(0);
    Http::assertNothingSent();
});

test('templates reject unknown executable placeholders and required subject omissions', function () {
    $data = documentBrandInput();
    $data['templates']['delivery']['fr']['body'] = '{{system("whoami")}}';
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, $data)->assertUnprocessable()->assertJsonValidationErrors('templates.delivery.fr.body');
    $data = documentBrandInput();
    $data['templates']['delivery']['en']['subject'] = 'Your documents';
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, $data)->assertUnprocessable()->assertJsonValidationErrors('templates.delivery.en.subject');
});

test('renderer escapes customer and template html while preserving french and english content', function () {
    $profile = new WebsiteDocumentProfile(documentBrandInput());
    $profile->website_id = $this->brandSite->id;
    $renderer = app(BrandedDocumentRenderer::class);
    $result = $renderer->render($profile, ['customer_first_name' => '<script>alert(1)</script>', 'order_number' => "ABC\r\nBcc:bad@example.test", 'language' => 'fr'], [['name' => '../../<image>.pdf']]);
    expect($result['subject'])->not->toContain("\r", "\n")
        ->and($result['html'])->toContain('&lt;script&gt;', 'Vos documents sont prêts', 'image.pdf')->not->toContain('<script>');
    $english = $renderer->render($profile, ['customer_first_name' => 'Alex', 'order_number' => '42', 'language' => 'en'], [['name' => 'flight.pdf']]);
    expect($english['html'])->toContain('Your documents are ready', 'support@travel.example')->and($english['text'])->toContain('flight.pdf');
});

test('zero attachment preview never claims pdf files are attached and single multiple use exact list', function () {
    $renderer = app(BrandedDocumentRenderer::class);
    $profile = new WebsiteDocumentProfile(documentBrandInput());
    $sample = ['customer_first_name' => 'Alex', 'order_number' => 'DEMO', 'language' => 'en'];
    $zero = $renderer->render($profile, $sample, []);
    expect($zero['text'])->toContain('No PDF selected — preview only')->not->toContain('ready and attached');
    $one = $renderer->render($profile, $sample, [['name' => 'reservation.pdf']]);
    $two = $renderer->render($profile, $sample, [['name' => 'reservation.pdf'], ['name' => 'hotel.pdf']]);
    expect($one['text'])->toContain('1 PDF attachment(s)', 'reservation.pdf')->not->toContain('hotel.pdf');
    expect($two['text'])->toContain('2 PDF attachment(s)', 'reservation.pdf', 'hotel.pdf');
});

test('same external order number stays isolated and recorded language precedes website fallback', function () {
    $profile = new WebsiteDocumentProfile(documentBrandInput());
    $profile->website_id = $this->brandSite->id;
    $order = new WcOrder(['website_id' => $this->brandSite->id, 'wp_order_id' => 42, 'payload' => ['locale' => 'en_US', 'billing' => ['first_name' => 'Alex']]]);
    $renderer = app(BrandedDocumentRenderer::class);
    expect($renderer->render($profile, $order, [['name' => 'file.pdf']])['language'])->toBe('en');
    $order->payload = [];
    expect($renderer->render($profile, $order, [['name' => 'file.pdf']])['language'])->toBe('fr');
    $order->website_id = $this->brandSite->id + 100;
    expect(fn () => $renderer->render($profile, $order, [['name' => 'file.pdf']]))->toThrow(ValidationException::class);
});

test('private and unsafe logo urls are rejected without remote asset fetching', function () {
    foreach (['https://127.0.0.1/logo.png', 'https://localhost/logo.png', 'https://user:password@example.com/logo.png', 'javascript:alert(1)'] as $url) {
        $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput(), ['logo_url' => $url]))
            ->assertUnprocessable()->assertJsonValidationErrors('logo_url');
    }
    Http::assertNothingSent();
});

test('store health is cached only for an authorized saved profile and missing capability is explicit', function () {
    $this->actingAs($this->brandActor)->postJson('/settings/email-documents/'.$this->brandSite->id.'/capability')->assertUnprocessable();
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, documentBrandInput())->assertOk();
    $gateway = $this->mock(DocumentStoreGateway::class);
    $gateway->shouldReceive('health')->once()->andReturn(['available' => false, 'supported' => false, 'verified' => false, 'message' => 'Completion suppression not configured.']);
    $this->postJson('/settings/email-documents/'.$this->brandSite->id.'/capability')->assertOk()
        ->assertJsonPath('website.readiness.completion_supported', false)
        ->assertJsonPath('website.profile.store_capability.message', 'Completion suppression not configured.');
    expect(WebsiteDocumentProfile::sole()->store_checked_at)->not->toBeNull();
});

test('store branding requires explicit confirmation and a verified matching version', function () {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->brandSite->update(['wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['workflow_enabled' => true]))->assertOk();
    $gateway = $this->mock(DocumentStoreGateway::class);
    $gateway->shouldReceive('syncBrand')->once()->andReturn([]);
    $gateway->shouldReceive('health')->once()->andReturn(['available' => true, 'supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 1, 'capabilities' => ['order_revision']]);
    $url = '/settings/email-documents/'.$this->brandSite->id.'/sync-brand';
    $this->postJson($url, ['config_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $this->postJson($url, ['confirmed' => true, 'config_version' => 1])->assertOk()->assertJsonPath('website.readiness.completion_supported', true);
    expect(WebsiteDocumentProfile::sole()->brand_sync_version)->toBe(1);
});

test('wrong store brand version cannot enable combined completion', function () {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['workflow_enabled' => true]))->assertOk();
    $gateway = $this->mock(DocumentStoreGateway::class);
    $gateway->shouldReceive('syncBrand')->once()->andReturn([]);
    $gateway->shouldReceive('health')->once()->andReturn(['supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 99]);
    $this->postJson('/settings/email-documents/'.$this->brandSite->id.'/sync-brand', ['confirmed' => true, 'config_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('store');
    expect(WebsiteDocumentProfile::sole()->brand_sync_version)->toBeNull();
});

test('old cached capability without strong order revisions cannot advertise completion readiness', function () {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->brandSite->update(['wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['workflow_enabled' => true]))->assertOk();
    $profile = WebsiteDocumentProfile::sole();
    $oldCapability = ['supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 1];
    $profile->update(['store_capability' => $oldCapability, 'store_checked_at' => now(), 'brand_sync_version' => 1]);
    $profiles = app(WebsiteDocumentProfiles::class);
    expect($profiles->readiness($profile)['completion_supported'])->toBeFalse();
    $profile->update(['store_capability' => $oldCapability + ['capabilities' => ['order_revision']]]);
    expect($profiles->readiness($profile)['completion_supported'])->toBeTrue();
    Http::assertNothingSent();
});

test('decorative bright accents retain branding while footer links use readable text and underline', function () {
    $profile = new WebsiteDocumentProfile(array_replace(documentBrandInput(), ['accent_color' => '#00cc61']));
    $rendered = app(BrandedDocumentRenderer::class)->render($profile, ['customer_first_name' => 'Alex', 'order_number' => 'DEMO', 'language' => 'en'], [['name' => 'sample.pdf']]);
    expect($rendered['html'])->toContain('border-left:3px solid #00cc61', 'style="color:#17212f;text-decoration:underline"')
        ->not->toContain('style="color:#00cc61"');
    expect(BrandedDocumentRenderer::contrastRatio('#00cc61', '#ffffff'))->toBeLessThan(4.5)
        ->and(BrandedDocumentRenderer::contrastRatio('#17212f', '#ffffff'))->toBeGreaterThan(4.5);
});

test('unreadable text palettes remain explicit drafts and cannot be activated', function (array $colors, string $reason) {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $data = array_replace(documentBrandInput($connection), $colors);
    $response = $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, $data)
        ->assertOk()->assertJsonPath('website.readiness.ready', false)->assertJsonPath('website.profile.workflow_enabled', false);
    expect($response->json('website.readiness.missing'))->toContain($reason);
    foreach ($colors as $field => $value) {
        $response->assertJsonPath('website.profile.'.$field, $value);
    }
    $this->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace($data, ['config_version' => 1, 'workflow_enabled' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_enabled');
    expect(WebsiteDocumentProfile::sole()->workflow_enabled)->toBeFalse();
})->with([
    'white primary on white' => [['primary_color' => '#ffffff'], 'primary color contrast against white (minimum 4.5:1)'],
    'white text on white' => [['text_color' => '#ffffff'], 'text color contrast against white (minimum 4.5:1)'],
    'dark text on dark attachment background' => [['text_color' => '#17212f', 'background_color' => '#17212f'], 'text color contrast against the selected background (minimum 4.5:1)'],
]);

test('high contrast palettes with bright decorative accents remain eligible for explicit activation', function () {
    $connection = documentBrandConnection($this->brandActor, 'sender@travel.example');
    $this->actingAs($this->brandActor)->putJson('/settings/email-documents/'.$this->brandSite->id, array_replace(documentBrandInput($connection), ['accent_color' => '#00cc61', 'workflow_enabled' => true]))
        ->assertOk()->assertJsonPath('website.readiness.ready', true)->assertJsonPath('website.profile.accent_color', '#00cc61');
    expect(BrandedDocumentRenderer::contrastRatio('#000000', '#ffffff'))->toBe(21.0)
        ->and(BrandedDocumentRenderer::contrastRatio('#ffffff', '#ffffff'))->toBe(1.0);
});
