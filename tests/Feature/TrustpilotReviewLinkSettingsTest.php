<?php

use App\Models\ActionHistoryEvent;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use App\Services\GmailClient;
use App\Services\WebsiteDocumentProfiles;
use App\Support\TrustpilotReviewLink;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    $this->reviewActor = User::factory()->create(['is_admin' => false]);
    $this->reviewSite = Website::create(['user_id' => $this->reviewActor->id, 'name' => 'Review Travel',
        'slug' => 'review-travel', 'base_url' => 'https://www.travel.example', 'status' => 'active']);
    $this->reviewProfile = WebsiteDocumentProfile::create([
        'website_id' => $this->reviewSite->id, 'brand_name' => 'Review Travel', 'logo_url' => 'https://travel.example/logo.png',
        'primary_color' => '#173b57', 'accent_color' => '#176d72', 'background_color' => '#f5f7fa', 'text_color' => '#17212f',
        'support_email' => 'support@travel.example', 'website_url' => 'https://travel.example', 'footer' => 'Review Travel team',
        'sender_email' => 'support@travel.example', 'sender_name' => 'Review Travel', 'reply_to' => 'support@travel.example',
        'default_language' => 'en', 'service_label' => 'reservation', 'required_services' => ['Flight reservation'],
        'templates' => BrandedDocumentRenderer::defaults(), 'workflow_enabled' => false, 'config_version' => 7,
        'store_pairing_key' => (string) Str::uuid(), 'brand_sync_version' => 7, 'store_checked_at' => now(),
        'store_capability' => ['supported' => true, 'verified' => true, 'enabled' => true, 'config_version' => 7,
            'capabilities' => ['order_revision']],
    ]);
    $this->reviewUrl = '/settings/email-documents/'.$this->reviewSite->id.'/review-link';
});

test('review link save preserves active workflow readiness and all store configuration', function () {
    GmailAppSetting::create(['client_id' => 'review.apps.googleusercontent.com', 'client_secret' => 'fictional-secret']);
    $connection = GmailConnection::create(['user_id' => $this->reviewActor->id, 'email' => 'support@travel.example',
        'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GmailClient::class)->fingerprint(),
        'access_token' => 'fictional-token', 'refresh_token' => 'fictional-refresh', 'connected_at' => now(),
        'expires_at' => now()->addHour(), 'aliases' => [['email' => 'support@travel.example', 'name' => 'Review Travel']]]);
    $this->reviewSite->update(['wc_consumer_key' => 'fictional-key', 'wc_consumer_secret' => 'fictional-secret']);
    $this->reviewProfile->update(['workflow_enabled' => true, 'gmail_connection_id' => $connection->id,
        'configured_by_user_id' => $this->reviewActor->id]);
    $before = $this->reviewProfile->fresh()->attributesToArray();
    $readyBefore = app(WebsiteDocumentProfiles::class)->readiness($this->reviewProfile->fresh());
    expect($readyBefore['ready'])->toBeTrue()->and($readyBefore['completion_supported'])->toBeTrue();

    $this->actingAs($this->reviewActor)->patchJson($this->reviewUrl, [
        'trustpilot_url' => 'https://fr.trustpilot.com/evaluate/www.travel.example/', 'expected_trustpilot_url' => null,
        'workflow_enabled' => false, 'config_version' => 999, 'store_pairing_key' => 'must-not-change',
    ])->assertOk()->assertJsonPath('website_id', $this->reviewSite->id)
        ->assertJsonPath('trustpilot_url', 'https://www.trustpilot.com/review/travel.example');

    $after = $this->reviewProfile->fresh();
    foreach (array_diff(array_keys($before), ['updated_at', 'trustpilot_url']) as $field) {
        expect($after->attributesToArray()[$field])->toBe($before[$field]);
    }
    expect(app(WebsiteDocumentProfiles::class)->readiness($after))->toBe($readyBefore)
        ->and(ActionHistoryEvent::sole()->details)->toBe(['review_link_updated' => true]);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

test('review link updates reject other owners and require existing profiles and both explicit values', function () {
    $payload = ['trustpilot_url' => 'https://www.trustpilot.com/review/travel.example', 'expected_trustpilot_url' => null];
    $this->patchJson($this->reviewUrl, $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->patchJson($this->reviewUrl, $payload)->assertForbidden();
    $this->actingAs($this->reviewActor)->patchJson($this->reviewUrl, [])->assertUnprocessable()
        ->assertJsonValidationErrors(['trustpilot_url', 'expected_trustpilot_url']);
    $this->reviewProfile->delete();
    $this->patchJson($this->reviewUrl, $payload)->assertUnprocessable()->assertJsonValidationErrors('trustpilot_url');
    expect(WebsiteDocumentProfile::count())->toBe(0)->and(ActionHistoryEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('review link endpoint rejects another website profile and unsafe destinations without changing saved state', function () {
    $this->actingAs($this->reviewActor);
    foreach (['https://www.trustpilot.com/review/other.example', 'https://evil.example/review/travel.example',
        'https://www.trustpilot.com/review/travel.example?stars=5', 'http://www.trustpilot.com/review/travel.example'] as $url) {
        $this->patchJson($this->reviewUrl, ['trustpilot_url' => $url, 'expected_trustpilot_url' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('trustpilot_url');
    }
    expect($this->reviewProfile->fresh()->trustpilot_url)->toBeNull()->and(ActionHistoryEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('stale review link edits cannot overwrite or clear a newer value', function () {
    $canonical = 'https://www.trustpilot.com/review/travel.example';
    $this->actingAs($this->reviewActor)->patchJson($this->reviewUrl, ['trustpilot_url' => $canonical, 'expected_trustpilot_url' => null])->assertOk();
    $this->patchJson($this->reviewUrl, ['trustpilot_url' => null, 'expected_trustpilot_url' => null])->assertConflict();
    $this->patchJson($this->reviewUrl, ['trustpilot_url' => null, 'expected_trustpilot_url' => 'https://fr.trustpilot.com/review/travel.example'])->assertConflict();
    expect($this->reviewProfile->fresh()->trustpilot_url)->toBe($canonical)->and(ActionHistoryEvent::count())->toBe(1);
    $this->patchJson($this->reviewUrl, ['trustpilot_url' => $canonical, 'expected_trustpilot_url' => $canonical])->assertOk();
    expect(ActionHistoryEvent::count())->toBe(1);
    $this->patchJson($this->reviewUrl, ['trustpilot_url' => null, 'expected_trustpilot_url' => $canonical])->assertOk()->assertJsonPath('trustpilot_url', null);
    expect($this->reviewProfile->fresh()->config_version)->toBe(7)->and(ActionHistoryEvent::count())->toBe(2);
    Http::assertNothingSent();
});

test('ordinary profile save cannot overwrite the independently saved review link', function () {
    $canonical = 'https://www.trustpilot.com/review/travel.example';
    $this->reviewProfile->update(['trustpilot_url' => $canonical]);
    $input = $this->reviewProfile->attributesToArray();
    $input['trustpilot_url'] = 'https://www.trustpilot.com/review/other.example';
    $this->actingAs($this->reviewActor)->putJson('/settings/email-documents/'.$this->reviewSite->id, $input)->assertOk();
    expect($this->reviewProfile->fresh()->trustpilot_url)->toBe($canonical)
        ->and($this->reviewProfile->fresh()->workflow_enabled)->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

test('action links use the persisted website identity and omit mismatched or missing profiles', function () {
    $this->reviewProfile->update(['trustpilot_url' => 'https://www.trustpilot.com/review/travel.example']);
    expect(TrustpilotReviewLink::actionUrl($this->reviewProfile))->toBe('https://www.trustpilot.com/evaluate/travel.example');
    $spoofedWebsite = clone $this->reviewSite;
    $spoofedWebsite->base_url = 'https://other.example';
    $this->reviewProfile->trustpilot_url = 'https://www.trustpilot.com/review/other.example';
    $this->reviewProfile->website_url = 'https://other.example';
    $this->reviewProfile->setRelation('website', $spoofedWebsite);
    expect(TrustpilotReviewLink::actionUrl($this->reviewProfile))->toBeNull();
    $this->reviewProfile->trustpilot_url = null;
    expect(TrustpilotReviewLink::actionUrl($this->reviewProfile))->toBeNull()
        ->and(TrustpilotReviewLink::actionUrl(new WebsiteDocumentProfile))->toBeNull();
    Http::assertNothingSent();
});
