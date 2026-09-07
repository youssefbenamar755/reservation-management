<?php

use App\Exceptions\GmailDeliveryException;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteEmailSetting;
use App\Services\GmailClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['is_admin' => true]);
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Email fixture', 'slug' => 'email-fixture', 'base_url' => 'https://email.example.test', 'status' => 'active']);
    $this->gmail = app(GmailClient::class);
});

function connectedGmailFixture(User $user): GmailConnection
{
    GmailAppSetting::updateOrCreate(['id' => 1], ['client_id' => 'fixture.apps.googleusercontent.com', 'client_secret' => 'fixture-client-secret']);

    return GmailConnection::create([
        'user_id' => $user->id, 'email' => 'mailbox@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GmailClient::class)->fingerprint(), 'access_token' => 'fixture-access-token',
        'refresh_token' => 'fixture-refresh-token', 'expires_at' => now()->addHour(), 'connected_at' => now(),
        'aliases' => [['email' => 'sender@example.test', 'name' => 'Fixture Sender']],
    ]);
}

function acceptedGmailAliases(): array
{
    return ['sendAs' => [
        ['sendAsEmail' => 'mailbox@example.test', 'isPrimary' => true, 'displayName' => 'Mailbox'],
        ['sendAsEmail' => 'sender@example.test', 'verificationStatus' => 'accepted', 'displayName' => 'Fixture Sender'],
        ['sendAsEmail' => 'unverified@example.test', 'verificationStatus' => 'pending'],
    ]];
}

test('email settings require authentication and only expose own account and authorized websites without secrets', function () {
    $this->get('/settings/email')->assertRedirect('/login');
    $connection = connectedGmailFixture($this->actor);
    $other = User::factory()->create();
    $this->actingAs($other)->get('/settings/email')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Email')->where('emailSettings.app.can_manage', false)
        ->where('emailSettings.app.client_id', null)->where('emailSettings.connection.connected', false)
        ->has('emailSettings.websites', 0));
    $response = $this->actingAs($this->actor)->get('/settings/email')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('emailSettings.connection.email', 'mailbox@example.test')->has('emailSettings.websites', 1));
    foreach (['fixture-client-secret', 'fixture-access-token', 'fixture-refresh-token', $connection->connection_key] as $secret) {
        $response->assertDontSee($secret);
    }
    expect(DB::table('gmail_app_settings')->value('client_secret'))->not->toContain('fixture-client-secret');
    expect(DB::table('gmail_connections')->value('access_token'))->not->toContain('fixture-access-token');
    Http::assertNothingSent();
});

test('only admins save Google application credentials and validation never flashes secrets', function () {
    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->put('/settings/email/application', ['client_id' => 'x'])->assertForbidden();
    $this->actingAs($this->actor)->from('/settings/email')->put('/settings/email/application', [
        'client_id' => 'invalid', 'client_secret' => 'never-flash-this-secret',
    ])->assertSessionHasErrors('client_id')->assertSessionMissing('_old_input.client_secret');
    $this->put('/settings/email/application', ['client_id' => 'fixture.apps.googleusercontent.com', 'client_secret' => 'fixture-secret'])
        ->assertSessionHasNoErrors();
    $this->put('/settings/email/application', ['client_id' => 'fixture.apps.googleusercontent.com'])->assertSessionHasNoErrors();
    expect(GmailAppSetting::find(1)->client_secret)->toBe('fixture-secret');
    $this->put('/settings/email/application', ['client_id' => 'different.apps.googleusercontent.com'])
        ->assertSessionHasErrors('client_secret');
});

test('connect uses a fixed callback origin, bounded session state, minimal email scopes and PKCE', function () {
    $this->actingAs($this->actor)->postJson('/settings/email/connect')->assertUnprocessable();
    connectedGmailFixture($this->actor);
    config(['app.url' => 'https://wphub.example.test']);
    $response = $this->postJson('/settings/email/connect')->assertOk();
    $url = $response->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect(parse_url($url, PHP_URL_HOST))->toBe('accounts.google.com')
        ->and($query['redirect_uri'])->toBe('https://wphub.example.test/settings/email/callback')
        ->and(explode(' ', $query['scope']))->toBe(GmailClient::SCOPES)
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(strlen($query['state']))->toBe(64)
        ->and(session('gmail_oauth.state'))->toBe(hash('sha256', $query['state']));
    Http::assertNothingSent();
});

test('OAuth rejects missing wrong expired cancelled and replayed state without token calls', function () {
    connectedGmailFixture($this->actor);
    $this->actingAs($this->actor)->get('/settings/email/callback?code=do-not-exchange')
        ->assertSessionHas('error')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    $response = $this->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?state=wrong&code=do-not-exchange')->assertSessionHas('error');
    $this->get('/settings/email/callback?state='.$query['state'].'&code=do-not-exchange')->assertSessionHas('error');
    $response = $this->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->travel(11)->minutes();
    $this->get('/settings/email/callback?state='.$query['state'].'&code=expired')->assertSessionHas('error');
    $this->travelBack();
    $response = $this->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?state='.$query['state'].'&error=access_denied')->assertSessionHas('error');
    Http::assertNothingSent();
});

test('OAuth connects only after exchange and verified alias retrieval and never sends a message', function () {
    $prior = connectedGmailFixture($this->actor);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => implode(' ', GmailClient::SCOPES)]),
        'gmail.googleapis.com/gmail/v1/users/me/settings/sendAs' => Http::response(acceptedGmailAliases()),
    ]);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))
        ->assertRedirect('/settings/email')->assertSessionHas('success');
    $connection = $prior->fresh();
    expect($connection->connection_key)->not->toBe($prior->connection_key)
        ->and($connection->access_token)->toBe('new-access')->and($connection->email)->toBe('mailbox@example.test')
        ->and($connection->aliases)->toHaveCount(2);
    Http::assertSentCount(2);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    Http::assertSentCount(2);
});

test('failed OAuth alias retrieval preserves an existing mailbox connection', function () {
    $prior = connectedGmailFixture($this->actor);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'scope' => implode(' ', GmailClient::SCOPES)]),
        'gmail.googleapis.com/*' => Http::response(['error' => 'DO NOT EXPOSE PROVIDER DETAILS'], 403),
    ]);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    expect($prior->fresh()->connection_key)->toBe($prior->connection_key)
        ->and(session('error'))->not->toContain('DO NOT EXPOSE');
});

test('partial Google consent never replaces an existing connection or reads sender settings', function () {
    $prior = connectedGmailFixture($this->actor);
    Http::fake(['oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'partial-access', 'refresh_token' => 'partial-refresh',
        'scope' => 'https://www.googleapis.com/auth/gmail.send',
    ])]);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    expect($prior->fresh()->access_token)->toBe($prior->access_token);
    Http::assertSentCount(1);
});

test('credential rotation during token exchange cannot bind old tokens to new credentials', function () {
    $prior = connectedGmailFixture($this->actor);
    Http::fake(['oauth2.googleapis.com/token' => function () {
        GmailAppSetting::find(1)->update(['client_secret' => 'rotated-during-exchange']);

        return Http::response(['access_token' => 'old-client-token', 'refresh_token' => 'old-client-refresh', 'scope' => implode(' ', GmailClient::SCOPES)]);
    }]);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    expect($prior->fresh()->access_token)->toBe($prior->access_token)->and($this->gmail->isConnected($prior->fresh()))->toBeFalse();
    Http::assertSentCount(1);
});

test('disconnect cancels a pending OAuth connection request', function () {
    connectedGmailFixture($this->actor);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->delete('/settings/email/connection');
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('OAuth state cannot be used after changing the logged-in user or Google credentials', function () {
    connectedGmailFixture($this->actor);
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    $this->actingAs(User::factory()->create())->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    $response = $this->actingAs($this->actor)->postJson('/settings/email/connect');
    parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
    GmailAppSetting::find(1)->update(['client_secret' => 'changed-credentials']);
    $this->get('/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('website templates require owned website and freshly verified sender from own connection', function () {
    connectedGmailFixture($this->actor);
    Http::fake(['gmail.googleapis.com/*' => Http::response(acceptedGmailAliases())]);
    $data = ['sender_email' => 'sender@example.test', 'subject_template' => 'Order {{order_number}}', 'body_template' => 'Hello {{customer_name}}', 'signature' => '{{website_name}}'];
    $other = User::factory()->create();
    $this->actingAs($other)->put('/settings/email/websites/'.$this->site->id, $data)->assertForbidden();
    $this->actingAs($this->actor)->from('/settings/email')->put('/settings/email/websites/'.$this->site->id, $data)->assertSessionHasNoErrors();
    expect(WebsiteEmailSetting::first()->user_id)->toBe($this->actor->id);
    $this->put('/settings/email/websites/'.$this->site->id, [...$data, 'sender_email' => 'unverified@example.test'])->assertSessionHasErrors('sender_email');
    $this->put('/settings/email/websites/'.$this->site->id, [...$data, 'subject_template' => "Hello\r\nBcc: hidden@example.test"])->assertSessionHasErrors('subject_template');
});

test('disconnect affects only current account and credential rotation invalidates existing connections', function () {
    $connection = connectedGmailFixture($this->actor);
    $this->actingAs(User::factory()->create())->delete('/settings/email/connection');
    expect($this->gmail->isConnected($connection->fresh()))->toBeTrue();
    GmailAppSetting::find(1)->update(['client_secret' => 'rotated-secret']);
    expect($this->gmail->isConnected($connection->fresh()))->toBeFalse();
    $this->actingAs($this->actor)->delete('/settings/email/connection');
    expect($connection->fresh()->access_token)->toBeNull()->and($connection->fresh()->refresh_token)->toBeNull()
        ->and($connection->fresh()->connection_key)->not->toBe($connection->connection_key);
    Http::assertNothingSent();
});

test('Gmail refreshes expired access tokens without losing the refresh token', function () {
    $connection = connectedGmailFixture($this->actor);
    $connection->update(['expires_at' => now()->subMinute()]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-access', 'expires_in' => 3600]),
        'gmail.googleapis.com/*' => Http::response(acceptedGmailAliases()),
    ]);
    $this->gmail->aliases($connection);
    expect($connection->fresh()->access_token)->toBe('refreshed-access')->and($connection->fresh()->refresh_token)->toBe('fixture-refresh-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/settings/sendAs') && $request->hasHeader('Authorization', 'Bearer refreshed-access'));
    Http::assertSentCount(2);
});

test('Gmail sends the supplied MIME once and distinguishes rejection from uncertain delivery', function () {
    $connection = connectedGmailFixture($this->actor);
    $mime = "From: sender@example.test\r\nTo: recipient@example.test\r\nSubject: Fixture\r\n\r\nBody";
    Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'abc123'])]);
    expect($this->gmail->send($connection, $mime))->toBe('abc123');
    Http::assertSent(fn ($request) => base64_decode(strtr($request['raw'], '-_', '+/')) === $mime);
    Http::assertSentCount(1);
    foreach ([400 => false, 401 => false, 403 => false, 429 => false, 500 => true, 503 => true] as $status => $uncertain) {
        Http::swap((new \Illuminate\Http\Client\Factory)->preventStrayRequests());
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'private provider data'], $status)]);
        try {
            $this->gmail->send($connection, $mime);
            $this->fail('Provider failure must not be reported as sent');
        } catch (GmailDeliveryException $exception) {
            expect($exception->uncertain)->toBe($uncertain)->and($exception->getMessage())->not->toContain('private provider data');
        }
        Http::assertSentCount(1);
    }
    Http::swap((new \Illuminate\Http\Client\Factory)->preventStrayRequests());
    Http::fake(['gmail.googleapis.com/*' => Http::failedConnection()]);
    try {
        $this->gmail->send($connection, $mime);
        $this->fail('A connection failure must be uncertain');
    } catch (GmailDeliveryException $exception) {
        expect($exception->uncertain)->toBeTrue();
    }
});
