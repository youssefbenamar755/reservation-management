<?php

use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\Website;
use App\Services\GoogleReportingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['is_admin' => true]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Traffic fixture', 'slug' => 'traffic-fixture', 'base_url' => 'https://traffic.example.test']);
    $this->google = app(GoogleReportingClient::class);
});

function trafficSettingsConnection(User $user): TrafficConnection
{
    TrafficAppSetting::updateOrCreate(['id' => 1], ['client_id' => 'traffic-fixture.apps.googleusercontent.com', 'client_secret' => 'traffic-client-secret']);

    return TrafficConnection::create([
        'user_id' => $user->id, 'email' => 'reporting@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(), 'access_token' => 'traffic-access-token', 'refresh_token' => 'traffic-refresh-token',
        'expires_at' => now()->addHour(), 'connected_at' => now(),
        'catalog' => ['ga4' => [['id' => '123', 'name' => 'Traffic property']], 'gsc' => [['url' => 'sc-domain:example.test', 'permission' => 'siteOwner']], 'errors' => []],
        'catalog_at' => now(),
    ]);
}

function trafficSettingsCatalog(): array
{
    return [
        'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [['propertySummaries' => [
            ['property' => 'properties/123', 'displayName' => 'Traffic property'], ['property' => 'properties/456', 'displayName' => 'Another property'],
        ]]]]),
        'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
            ['siteUrl' => 'sc-domain:example.test', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://traffic.example.test/', 'permissionLevel' => 'siteFullUser'],
            ['siteUrl' => 'https://unverified.example.test/', 'permissionLevel' => 'siteUnverifiedUser'],
        ]]),
    ];
}

function trafficSettingsOAuth($test): array
{
    $response = $test->postJson('/settings/traffic/connect')->assertOk();
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);

    return $query;
}

function trafficSettingsTokens(array $override = []): array
{
    return array_replace(['access_token' => 'new-reporting-access', 'refresh_token' => 'new-reporting-refresh', 'expires_in' => 3600,
        'scope' => implode(' ', GoogleReportingClient::SCOPES)], $override);
}

test('traffic settings are isolated by actor website authorization and connection without exposing secrets or loading providers', function () {
    $this->get('/settings/traffic')->assertRedirect('/login');
    $connection = trafficSettingsConnection($this->actor);
    $mapping = TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123', 'gsc_site_url' => 'sc-domain:example.test']);
    $response = $this->actingAs($this->actor)->get('/settings/traffic')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Traffic')->where('trafficSettings.connection', ['connected' => true, 'email' => 'reporting@example.test', 'reconnect_required' => false])
        ->where('trafficSettings.websites.0.ga4_property_id', '123')->has('trafficSettings.catalog.ga4', 1));
    foreach (['traffic-client-secret', 'traffic-access-token', 'traffic-refresh-token', $connection->connection_key, $mapping->mapping_key] as $secret) {
        $response->assertDontSee($secret);
    }
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(DB::table('traffic_app_settings')->value('client_secret'))->not->toContain('traffic-client-secret')
        ->and(DB::table('traffic_connections')->value('access_token'))->not->toContain('traffic-access-token');
    $this->actingAs(User::factory()->create())->get('/settings/traffic')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trafficSettings.app.can_manage', false)->where('trafficSettings.app.client_id', null)
        ->where('trafficSettings.connection.connected', false)->has('trafficSettings.websites', 0)->has('trafficSettings.catalog.ga4', 0));
    Http::assertNothingSent();
});

test('reporting application settings reject Gmail client reuse and protect secrets on invalid or partial updates', function () {
    GmailAppSetting::create(['id' => 1, 'client_id' => 'gmail-fixture.apps.googleusercontent.com', 'client_secret' => 'gmail-client-secret']);
    $this->actingAs(User::factory()->create())->put('/settings/traffic/application', [])->assertForbidden();
    $this->actingAs($this->actor)->from('/settings/traffic')->put('/settings/traffic/application', ['client_id' => 'invalid', 'client_secret' => 'never-flash-secret'])
        ->assertSessionHasErrors('client_id')->assertSessionMissing('_old_input.client_secret');
    $this->put('/settings/traffic/application', ['client_id' => 'gmail-fixture.apps.googleusercontent.com', 'client_secret' => 'never-flash-secret'])
        ->assertSessionHasErrors('client_id')->assertSessionMissing('_old_input.client_secret');
    $this->put('/settings/traffic/application', ['client_id' => 'traffic-fixture.apps.googleusercontent.com', 'client_secret' => 'reporting-client-secret'])->assertSessionHasNoErrors();
    $this->put('/settings/traffic/application', ['client_id' => 'traffic-fixture.apps.googleusercontent.com', 'client_secret' => ''])->assertSessionHasNoErrors();
    expect(TrafficAppSetting::find(1)->client_secret)->toBe('reporting-client-secret')
        ->and(GmailAppSetting::find(1)->client_secret)->toBe('gmail-client-secret');
    $this->put('/settings/traffic/application', ['client_id' => 'different-client.apps.googleusercontent.com'])->assertSessionHasErrors('client_secret');
});

test('reporting OAuth requests exact readonly scopes separate state fixed callback and PKCE without touching Gmail', function () {
    $this->actingAs($this->actor)->postJson('/settings/traffic/connect')->assertUnprocessable();
    trafficSettingsConnection($this->actor);
    config(['app.url' => 'https://hub.example.test']);
    $this->withSession(['gmail_oauth' => ['untouched' => true]]);
    $query = trafficSettingsOAuth($this);
    expect($query['client_id'])->toBe('traffic-fixture.apps.googleusercontent.com')
        ->and($query['redirect_uri'])->toBe('https://hub.example.test/settings/traffic/callback')
        ->and(explode(' ', $query['scope']))->toBe(GoogleReportingClient::SCOPES)
        ->and($query['scope'])->not->toContain('gmail', 'analytics.edit')
        ->and($query['access_type'])->toBe('offline')->and($query['prompt'])->toBe('consent select_account')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', session('traffic_oauth.verifier'), true)), '+/', '-_'), '='))
        ->and(session('traffic_oauth.state'))->toBe(hash('sha256', $query['state']))
        ->and(session('gmail_oauth'))->toBe(['untouched' => true]);
    Http::assertNothingSent();
});

test('reporting callback rejects invalid cancelled expired and replayed state with secure response headers', function () {
    trafficSettingsConnection($this->actor);
    $this->actingAs($this->actor)->get('/settings/traffic/callback?code=never-exchange')->assertSessionHas('error')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    $query = trafficSettingsOAuth($this);
    $this->get('/settings/traffic/callback?state=wrong&code=never-exchange')->assertSessionHas('error');
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replay']))->assertSessionHas('error');
    $query = trafficSettingsOAuth($this);
    $this->travel(11)->minutes();
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'expired']))->assertSessionHas('error');
    $this->travelBack();
    $query = trafficSettingsOAuth($this);
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('reporting OAuth connects a different identity loads both catalogs and resets only reporting mappings', function () {
    $connection = trafficSettingsConnection($this->actor);
    $mapping = TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123']);
    $gmail = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'email-sender@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => str_repeat('a', 64), 'access_token' => 'gmail-access-unchanged', 'refresh_token' => 'gmail-refresh-unchanged', 'connected_at' => now()]);
    $gmailBefore = $gmail->fresh()->getRawOriginal();
    Http::fake(trafficSettingsCatalog() + [
        'oauth2.googleapis.com/token' => Http::response(trafficSettingsTokens()),
        'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'different-reporting@example.test', 'verified_email' => true]),
    ]);
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $verifier = session('traffic_oauth.verifier');
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'reporting-code']))
        ->assertRedirect('/settings/traffic')->assertSessionHas('success')->assertSessionMissing('error');
    $current = $connection->fresh();
    expect($current->email)->toBe('different-reporting@example.test')->and($current->connection_key)->not->toBe($connection->connection_key)
        ->and($current->catalog['ga4'])->toHaveCount(2)->and($current->catalog['gsc'])->toHaveCount(2)->and($current->catalog['errors'])->toBe([])
        ->and($current->catalog_at)->not->toBeNull()->and($mapping->fresh())->toBeNull()
        ->and($gmail->fresh()->getRawOriginal())->toBe($gmailBefore);
    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['code_verifier'] === $verifier && $request['redirect_uri'] === $this->google->redirectUri());
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'reporting-code']))->assertSessionHas('error');
    Http::assertSentCount(4);
});

test('reporting OAuth state cannot be completed by a different WP Hub user', function () {
    $connection = trafficSettingsConnection($this->actor);
    $before = $connection->fresh()->getRawOriginal();
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $this->actingAs(User::factory()->create())
        ->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'never-exchange']))
        ->assertSessionHas('error')->assertSessionMissing('traffic_oauth');
    expect($connection->fresh()->getRawOriginal())->toBe($before)->and(TrafficConnection::count())->toBe(1);
    Http::assertNothingSent();
});

test('expired authorization shows reconnect with its existing identity and links but no usable cached catalog', function () {
    $connection = trafficSettingsConnection($this->actor);
    $connection->update(['reconnect_required' => true]);
    TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123']);
    $this->actingAs($this->actor)->get('/settings/traffic')->assertInertia(fn (Assert $page) => $page
        ->where('trafficSettings.connection', ['connected' => false, 'email' => 'reporting@example.test', 'reconnect_required' => true])
        ->where('trafficSettings.websites.0.ga4_property_id', '123')->has('trafficSettings.catalog.ga4', 0)
        ->has('trafficSettings.catalog.gsc', 0));
    Http::assertNothingSent();
});

test('same verified account reauthorization restores only freshly confirmed mappings with new cache identities', function (string $case) {
    $connection = trafficSettingsConnection($this->actor);
    $connection->update(['reconnect_required' => true]);
    $mapping = TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123', 'gsc_site_url' => 'sc-domain:example.test']);
    $catalog = trafficSettingsCatalog();
    if ($case === 'partial') {
        $catalog['www.googleapis.com/webmasters/v3/sites'] = Http::response(['error' => 'provider details'], 403);
    } elseif ($case === 'revoked') {
        $catalog['analyticsadmin.googleapis.com/*'] = Http::response(['accountSummaries' => []]);
        $catalog['www.googleapis.com/webmasters/v3/sites'] = Http::response(['siteEntry' => []]);
    } elseif ($case === 'unlinked') {
        $catalog['analyticsadmin.googleapis.com/*'] = function () use ($mapping) {
            $mapping->delete();

            return Http::response(['accountSummaries' => [['propertySummaries' => [['property' => 'properties/123']]]]]);
        };
    }
    Http::fake($catalog + [
        'oauth2.googleapis.com/token' => Http::response(trafficSettingsTokens()),
        'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'Reporting@example.test', 'verified_email' => true]),
    ]);
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $response = $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'same-account-code']))
        ->assertSessionHas('success');
    $current = $connection->fresh();
    expect($current->reconnect_required)->toBeFalse()->and($this->google->isConnected($current))->toBeTrue()
        ->and($current->connection_key)->not->toBe($connection->connection_key);
    if (in_array($case, ['revoked', 'unlinked'], true)) {
        expect($mapping->fresh())->toBeNull();
    } else {
        $restored = $mapping->fresh();
        expect($restored->ga4_property_id)->toBe('123')->and($restored->gsc_site_url)->toBe($case === 'partial' ? null : 'sc-domain:example.test')
            ->and($restored->connection_key)->toBe($current->connection_key)->and($restored->mapping_key)->not->toBe($mapping->mapping_key);
    }
    if (in_array($case, ['partial', 'revoked'], true)) {
        $response->assertSessionHas('success', fn ($message) => str_contains($message, 'could not be confirmed'));
    }
})->with(['complete', 'partial', 'revoked', 'unlinked']);

test('partial consent or unverified identity leaves the existing reporting account unchanged', function (string $failure) {
    $connection = trafficSettingsConnection($this->actor);
    $before = $connection->fresh()->getRawOriginal();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(trafficSettingsTokens($failure === 'scope' ? ['scope' => GoogleReportingClient::SCOPES[0]] : [])),
        'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'unverified@example.test', 'verified_email' => false]),
    ]);
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount($failure === 'scope' ? 1 : 2);
})->with(['scope', 'identity']);

test('credential or connection replacement during OAuth cannot attach stale tokens to the new state', function (string $change) {
    $connection = trafficSettingsConnection($this->actor);
    Http::fake([
        'oauth2.googleapis.com/token' => function () use ($connection, $change) {
            if ($change === 'credentials') {
                TrafficAppSetting::find(1)->update(['client_secret' => 'rotated-reporting-secret']);
            } else {
                $connection->update(['connection_key' => (string) Str::uuid(), 'access_token' => 'newer-account-token']);
            }

            return Http::response(trafficSettingsTokens());
        },
        'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'old-account@example.test', 'verified_email' => true]),
    ]);
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    expect($connection->fresh()->access_token)->toBe($change === 'credentials' ? 'traffic-access-token' : 'newer-account-token');
})->with(['credentials', 'connection']);

test('one disabled catalog API does not lose successful OAuth or the other service properties', function (string $failed) {
    trafficSettingsConnection($this->actor);
    $catalog = trafficSettingsCatalog();
    $catalog[$failed === 'ga4' ? 'analyticsadmin.googleapis.com/*' : 'www.googleapis.com/webmasters/v3/sites'] = Http::response(['error' => 'private provider diagnostic'], 403);
    Http::fake($catalog + [
        'oauth2.googleapis.com/token' => Http::response(trafficSettingsTokens()),
        'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'reporting@example.test', 'verified_email' => true]),
    ]);
    $this->actingAs($this->actor);
    $query = trafficSettingsOAuth($this);
    $this->get('/settings/traffic/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('success');
    $current = TrafficConnection::first();
    expect($this->google->isConnected($current))->toBeTrue()->and($current->catalog[$failed])->toBe([])
        ->and($current->catalog[$failed === 'ga4' ? 'gsc' : 'ga4'])->toHaveCount(2)
        ->and($current->catalog['errors'])->toHaveCount(1)
        ->and(json_encode($current->catalog))->not->toContain('private provider diagnostic');
})->with(['ga4', 'gsc']);

test('GA4 catalog pagination is complete or explicitly failed and never silently publishes partial properties', function (string $case, int $ga4Calls) {
    $connection = trafficSettingsConnection($this->actor);
    $calls = 0;
    Http::fake([
        'analyticsadmin.googleapis.com/*' => function ($request) use (&$calls, $case) {
            $calls++;
            $response = ['accountSummaries' => [['propertySummaries' => [['property' => 'properties/'.(100 + $calls), 'displayName' => 'Page '.$calls]]]]];
            if ($case !== 'complete' || $calls === 1) {
                $response['nextPageToken'] = $case === 'loop' ? 'repeated-token' : 'page-'.$calls;
            }
            if ($calls > 1) {
                expect($request['pageToken'])->not->toBeNull();
            }

            return Http::response($response);
        },
        'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []]),
    ]);
    $this->actingAs($this->actor)->post('/settings/traffic/catalog')->assertRedirect()->assertSessionHas($case === 'complete' ? 'success' : 'error');
    $catalog = $connection->fresh()->catalog;
    expect($calls)->toBe($ga4Calls)->and($catalog['ga4'])->toHaveCount($case === 'complete' ? 2 : 0)
        ->and($catalog['errors'])->toHaveCount($case === 'complete' ? 0 : 1);
})->with([['complete', 2], ['loop', 2], ['limit', 5]]);

test('mapping verifies live property access and authorization and supports either service alone', function () {
    $connection = trafficSettingsConnection($this->actor);
    Http::fake(trafficSettingsCatalog());
    $this->actingAs(User::factory()->create())->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '123'])->assertForbidden();
    $this->actingAs($this->actor)->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => 'G-123'])
        ->assertSessionHasErrors('ga4_property_id');
    $this->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '999'])->assertSessionHasErrors('ga4_property_id');
    $this->put('/settings/traffic/websites/'.$this->site->id, ['gsc_site_url' => 'https://unverified.example.test/'])->assertSessionHasErrors('gsc_site_url');
    expect(TrafficWebsite::count())->toBe(0);
    $this->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '123'])->assertSessionHasNoErrors();
    $mapping = TrafficWebsite::sole();
    expect($mapping->ga4_property_id)->toBe('123')->and($mapping->gsc_site_url)->toBeNull()->and($mapping->connection_key)->toBe($connection->connection_key);
    $originalKey = $mapping->mapping_key;
    $this->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '123'])->assertSessionHasNoErrors();
    expect($mapping->fresh()->mapping_key)->toBe($originalKey);
    $this->travel(1)->minutes();
    $this->put('/settings/traffic/websites/'.$this->site->id, ['gsc_site_url' => 'sc-domain:example.test'])->assertSessionHasNoErrors();
    expect($mapping->fresh()->ga4_property_id)->toBeNull()->and($mapping->fresh()->mapping_key)->not->toBe($originalKey);
    $this->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '123'])->assertSessionHasNoErrors();
    expect($mapping->fresh()->mapping_key)->not->toBe($originalKey);
});

test('duplicate property mappings are rejected per user without overwriting the existing website', function (string $field, string $value) {
    trafficSettingsConnection($this->actor);
    $second = Website::create(['user_id' => $this->actor->id, 'name' => 'Second site', 'slug' => 'second', 'base_url' => 'https://second.example.test']);
    Http::fake(trafficSettingsCatalog());
    $this->actingAs($this->actor)->put('/settings/traffic/websites/'.$this->site->id, [$field => $value])->assertSessionHasNoErrors();
    $this->put('/settings/traffic/websites/'.$second->id, [$field => $value])->assertSessionHasErrors($field);
    expect(TrafficWebsite::count())->toBe(1)->and(TrafficWebsite::sole()->website_id)->toBe($this->site->id);
})->with([['ga4_property_id', '123'], ['gsc_site_url', 'sc-domain:example.test']]);

test('mapping never uses a stale cached catalog when provider access has been removed', function () {
    trafficSettingsConnection($this->actor);
    Http::fake(['analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => []]),
        'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []])]);
    $this->actingAs($this->actor)->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '123', 'gsc_site_url' => 'sc-domain:example.test'])
        ->assertSessionHasErrors('ga4_property_id');
    expect(TrafficWebsite::count())->toBe(0);
});

test('catalog refresh cannot publish old account properties after a concurrent account change', function () {
    $connection = trafficSettingsConnection($this->actor);
    $newKey = (string) Str::uuid();
    $newCatalog = ['ga4' => [['id' => '789', 'name' => 'New account property']], 'gsc' => [], 'errors' => []];
    Http::fake(['analyticsadmin.googleapis.com/*' => function () use ($connection, $newKey, $newCatalog) {
        TrafficConnection::find($connection->id)->update(['connection_key' => $newKey, 'catalog' => $newCatalog]);

        return Http::response(['accountSummaries' => [['propertySummaries' => [['property' => 'properties/123']]]]]);
    }]);
    $this->actingAs($this->actor)->post('/settings/traffic/catalog')->assertSessionHasErrors('connection');
    expect($connection->fresh()->connection_key)->toBe($newKey)->and($connection->fresh()->catalog)->toBe($newCatalog);
    Http::assertSentCount(1);
});

test('rotated credentials hide the old reporting account catalog and mappings without provider requests', function () {
    $connection = trafficSettingsConnection($this->actor);
    TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123']);
    TrafficAppSetting::find(1)->update(['client_secret' => 'rotated-reporting-secret']);
    $this->actingAs($this->actor)->get('/settings/traffic')->assertInertia(fn (Assert $page) => $page
        ->where('trafficSettings.connection', ['connected' => false, 'email' => null, 'reconnect_required' => false])
        ->where('trafficSettings.websites.0.ga4_property_id', '')->has('trafficSettings.catalog.ga4', 0));
    Http::assertNothingSent();
});

test('disconnect clears reporting tokens catalog and mappings locally without changing Gmail or revoking Google access', function () {
    $connection = trafficSettingsConnection($this->actor);
    TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123']);
    $gmail = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'sender@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => str_repeat('a', 64), 'access_token' => 'gmail-access-unchanged', 'refresh_token' => 'gmail-refresh-unchanged']);
    $gmailBefore = $gmail->fresh()->getRawOriginal();
    $this->actingAs($this->actor)->withSession(['traffic_oauth' => ['state' => 'pending'], 'gmail_oauth' => ['state' => 'unchanged']])
        ->delete('/settings/traffic/connection')->assertSessionHas('success');
    expect($connection->fresh()->access_token)->toBeNull()->and($connection->fresh()->refresh_token)->toBeNull()
        ->and($connection->fresh()->catalog)->toBeNull()->and($connection->fresh()->connection_key)->not->toBe($connection->connection_key)
        ->and(TrafficWebsite::count())->toBe(0)->and($gmail->fresh()->getRawOriginal())->toBe($gmailBefore)
        ->and(session('traffic_oauth'))->toBeNull()->and(session('gmail_oauth'))->toBe(['state' => 'unchanged']);
    Http::assertNothingSent();
});

test('blank mapping values unlink without Google calls even after a connection expires', function () {
    $connection = trafficSettingsConnection($this->actor);
    TrafficWebsite::create(['user_id' => $this->actor->id, 'website_id' => $this->site->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123']);
    $connection->update(['connected_at' => null]);
    $this->actingAs($this->actor)->put('/settings/traffic/websites/'.$this->site->id, ['ga4_property_id' => '', 'gsc_site_url' => ''])->assertSessionHasNoErrors();
    expect(TrafficWebsite::count())->toBe(0);
    Http::assertNothingSent();
});
