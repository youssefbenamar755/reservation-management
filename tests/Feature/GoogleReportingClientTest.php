<?php

use App\Exceptions\GoogleReportingException;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\User;
use App\Services\GoogleReportingClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'reporting-client.apps.googleusercontent.com', 'client_secret' => 'fixture-client-secret']);
    $this->client = app(GoogleReportingClient::class);
    $this->connection = TrafficConnection::create([
        'user_id' => User::factory()->create()->id, 'email' => 'reporter@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => $this->client->fingerprint(), 'access_token' => 'fixture-access-token', 'refresh_token' => 'fixture-refresh-token',
        'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
});

test('reporting client allows supported readonly report endpoints with bounded timeouts and no redirects', function (string $url) {
    $options = null;
    Http::fake(function ($request, $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['rows' => []]);
    });
    expect($this->client->post($this->connection, $url, ['fixture' => 'report']))->toBe(['rows' => []]);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === $url
        && $request->hasHeader('Authorization', 'Bearer fixture-access-token') && $request['fixture'] === 'report');
    expect($options['allow_redirects'])->toBeFalse()->and($options['timeout'])->toBe(10)->and($options['connect_timeout'])->toBe(4);
})->with([
    'https://analyticsdata.googleapis.com/v1beta/properties/123:runReport',
    'https://analyticsdata.googleapis.com/v1beta/properties/123:batchRunReports',
    'https://analyticsdata.googleapis.com/v1beta/properties/123:runRealtimeReport',
    'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.test/searchAnalytics/query',
    'https://www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fexample.test%2Ffolder%2F/searchAnalytics/query',
]);

test('reporting client rejects arbitrary hosts protocols methods and path confusion before attaching tokens', function (string $method, string $url) {
    expect(fn () => $this->client->{$method}($this->connection, $url))->toThrow(GoogleReportingException::class, 'not supported');
    Http::assertNothingSent();
})->with([
    ['get', 'https://attacker.example/'], ['get', 'http://analyticsadmin.googleapis.com/v1beta/accountSummaries'],
    ['get', 'https://analyticsadmin.googleapis.com.attacker.example/v1beta/accountSummaries'],
    ['get', 'https://user:password@analyticsadmin.googleapis.com/v1beta/accountSummaries'],
    ['get', 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?access_token=forged'],
    ['post', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'],
    ['post', 'https://analyticsdata.googleapis.com/v1beta/properties/123:delete'],
    ['post', 'https://www.googleapis.com/webmasters/v3/sites/https://example.test/searchAnalytics/query'],
    ['post', 'https://www.googleapis.com/webmasters/v3/sites/test/searchAnalytics/query#fragment'],
]);

test('expired reporting tokens refresh once without changing identity and preserve the refresh token when Google omits it', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-access-token', 'expires_in' => 3600]),
        'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => []]),
    ]);
    $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL);
    $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL);
    expect($this->connection->fresh()->refresh_token)->toBe('fixture-refresh-token')
        ->and($this->connection->fresh()->connection_key)->toBe($this->connection->connection_key);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'fixture-refresh-token');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), GoogleReportingClient::ACCOUNT_SUMMARIES_URL)
        && $request->hasHeader('Authorization', 'Bearer refreshed-access-token'));
});

test('replaced or disconnected reporting connections cannot issue a request or accept stale provider data', function (string $case) {
    if ($case === 'before') {
        $this->connection->fresh()->update(['connection_key' => (string) Str::uuid()]);
    } elseif ($case === 'disconnected') {
        $this->connection->fresh()->update(['connected_at' => null]);
    } elseif ($case === 'credentials') {
        TrafficAppSetting::find(1)->update(['client_secret' => 'new-client-secret']);
    }
    Http::fake(function () {
        $this->connection->fresh()->update(['connection_key' => (string) Str::uuid(), 'access_token' => 'new-account-token']);

        return Http::response(['accountSummaries' => [['private' => 'stale-account-report']]]);
    });
    expect(fn () => $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL))->toThrow(GoogleReportingException::class);
    Http::assertSentCount($case === 'during' ? 1 : 0);
})->with(['before', 'during', 'disconnected', 'credentials']);

test('Google provider failures are sanitized and never retried or retained as previous exceptions', function (string $case) {
    Http::fake(function () use ($case) {
        return match ($case) {
            'timeout' => Http::failedConnection('private-token provider details'),
            'redirect' => Http::response('private-token provider details', 302, ['Location' => 'https://attacker.example/']),
            'forbidden' => Http::response(['error' => 'private-token provider details'], 403),
            'limited' => Http::response(['error' => 'private-token provider details'], 429),
            'server' => Http::response(['error' => 'private-token provider details'], 503),
            default => Http::response('private-token provider details', 200),
        };
    });
    try {
        $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL);
        $this->fail('Expected a safe provider exception.');
    } catch (GoogleReportingException $exception) {
        expect($exception->getMessage())->not->toContain('private-token', 'provider details', 'attacker.example')
            ->and($exception->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['timeout', 'redirect', 'forbidden', 'limited', 'server', 'invalid']);

test('a failed token refresh does not overwrite stored reporting tokens or contact the reporting API', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'private-token reason'], 400)]);
    expect(fn () => $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL))->toThrow(GoogleReportingException::class, 'authorization expired');
    expect($this->connection->fresh()->access_token)->toBe('fixture-access-token');
    Http::assertSentCount(1);
});

test('invalid grant commits a durable reconnect requirement and prevents repeated refresh requests', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'private provider details'], 400)]);
    expect(fn () => $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL))
        ->toThrow(GoogleReportingException::class, 'Reconnect the same Google account');
    $current = $this->connection->fresh();
    expect($current->reconnect_required)->toBeTrue()->and($this->client->isConnected($current))->toBeFalse()
        ->and($current->connection_key)->toBe($this->connection->connection_key)
        ->and($current->access_token)->toBe('fixture-access-token')->and($current->refresh_token)->toBe('fixture-refresh-token');
    expect(fn () => $this->client->get($this->connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL))->toThrow(GoogleReportingException::class);
    Http::assertSentCount(1);
});
