<?php

use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\User;
use App\Services\GmailClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    config([
        'inertia.ssr.enabled' => false,
        'app.url' => 'https://wphub.example.test',
        'session.driver' => 'cookie',
        'session.secure' => true,
    ]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create();
    GmailAppSetting::create([
        'id' => 1,
        'client_id' => 'cookie-fixture.apps.googleusercontent.com',
        'client_secret' => 'cookie-fixture-secret',
    ]);
});

test('cookie sessions allow the OAuth return without weakening secure HttpOnly cookies on later responses', function () {
    // Do not override same_site here: this exercises the application's configured default.
    expect(config('session.same_site'))->toBe('lax');
    $connect = $this->actingAs($this->actor)->postJson('/settings/email/connect')->assertOk();
    $sessionId = session()->getId();
    foreach ([$connect, $this->get('/settings/email')->assertOk()] as $response) {
        foreach ([config('session.cookie'), $sessionId] as $name) {
            $cookie = $response->getCookie($name, false);
            expect($cookie)->not->toBeNull()
                ->and($cookie->getSameSite())->toBe('lax')
                ->and($cookie->isSecure())->toBeTrue()
                ->and($cookie->isHttpOnly())->toBeTrue()
                ->and($cookie->getPath())->toBe('/');
        }
    }
    expect(session('gmail_oauth.user_id'))->toBe($this->actor->id);
    Http::assertNothingSent();
});

test('OAuth callback still rejects an unauthenticated return without making Google requests', function () {
    $this->get('/settings/email/callback?state=untrusted&code=untrusted')->assertRedirect('/login');
    expect(GmailConnection::count())->toBe(0);
    Http::assertNothingSent();
});

test('Lax session cookies do not exempt the connect action from CSRF validation', function () {
    // Laravel normally skips CSRF validation under PHPUnit. Exercise the real middleware.
    $this->app->instance('env', 'local');
    $this->actingAs($this->actor)->postJson('/settings/email/connect')->assertStatus(419);
    expect(session('gmail_oauth'))->toBeNull();
    Http::assertNothingSent();
});

test('cookie driver callbacks still require matching one-time state and preserve PKCE', function () {
    $connect = $this->actingAs($this->actor)->postJson('/settings/email/connect')->assertOk();
    parse_str(parse_url($connect->json('url'), PHP_URL_QUERY), $query);
    $verifier = session('gmail_oauth.verifier');
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'cookie-fixture-access', 'refresh_token' => 'cookie-fixture-refresh',
            'expires_in' => 3600, 'scope' => implode(' ', GmailClient::SCOPES),
        ]),
        'gmail.googleapis.com/gmail/v1/users/me/settings/sendAs' => Http::response([
            'sendAs' => [['sendAsEmail' => 'cookie-fixture@example.test', 'isPrimary' => true]],
        ]),
    ]);
    $callback = '/settings/email/callback?'.http_build_query(['state' => $query['state'], 'code' => 'cookie-fixture-code']);
    $this->get($callback)->assertRedirect('/settings/email')->assertSessionHas('success');
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['code_verifier'] === $verifier && $request['redirect_uri'] === $query['redirect_uri']);
    Http::assertSentCount(2);
    $this->get($callback)->assertSessionHas('error');
    Http::assertSentCount(2);
});
