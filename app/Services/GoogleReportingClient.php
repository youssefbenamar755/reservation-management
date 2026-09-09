<?php

namespace App\Services;

use App\Exceptions\GoogleReportingException;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleReportingClient
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public const ACCOUNT_SUMMARIES_URL = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';

    public const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    private const IDENTITY_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function credentials(): array
    {
        $setting = TrafficAppSetting::find(1);

        return ['client_id' => (string) ($setting?->client_id ?? ''), 'client_secret' => (string) ($setting?->client_secret ?? '')];
    }

    public function configured(): bool
    {
        $credentials = $this->credentials();

        return $credentials['client_id'] !== '' && $credentials['client_secret'] !== '';
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode(':', $this->credentials()));
    }

    public function isConnected(?TrafficConnection $connection): bool
    {
        return $this->configured() && $connection !== null && ! $connection->reconnect_required && $connection->connected_at !== null
            && (bool) $connection->access_token && (bool) $connection->refresh_token
            && hash_equals($this->fingerprint(), (string) $connection->app_fingerprint);
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/settings/traffic/callback';
    }

    public function exchange(string $code, string $verifier): array
    {
        try {
            $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
                ...$this->credentials(), 'code' => $code, 'code_verifier' => $verifier,
                'redirect_uri' => $this->redirectUri(), 'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            throw new GoogleReportingException('Google could not be reached. Please connect your reporting account again.');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)
            || ! $this->validToken($data['access_token'] ?? null) || ! $this->validToken($data['refresh_token'] ?? null)
            || ! is_string($data['scope'] ?? null) || array_diff(self::SCOPES, explode(' ', $data['scope']))) {
            throw new GoogleReportingException('Google reporting was not connected. Start again and allow the requested read-only permissions.');
        }

        return $data;
    }

    public function identity(string $accessToken): string
    {
        try {
            $data = $this->response($this->request()->withToken($accessToken)->get(self::IDENTITY_URL));
        } catch (ConnectionException) {
            throw new GoogleReportingException('The reporting account could not be identified. Please connect again.');
        }
        $email = $data['email'] ?? null;
        if (! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n\x00]/', $email) || ($data['verified_email'] ?? false) !== true) {
            throw new GoogleReportingException('Google did not confirm the reporting account email. Please connect again.');
        }

        return $email;
    }

    public function get(TrafficConnection $connection, string $url, array $query = []): array
    {
        return $this->send($connection, 'GET', $url, $query);
    }

    public function post(TrafficConnection $connection, string $url, array $body = []): array
    {
        return $this->send($connection, 'POST', $url, $body);
    }

    private function send(TrafficConnection $connection, string $method, string $url, array $data): array
    {
        $allowed = $method === 'GET'
            ? in_array($url, [self::ACCOUNT_SUMMARIES_URL, self::SITES_URL], true)
            : preg_match('~\Ahttps://analyticsdata\.googleapis\.com/v1beta/properties/[1-9][0-9]{0,31}:(?:runReport|batchRunReports|runRealtimeReport)\z~D', $url)
                || preg_match('#\Ahttps://www\.googleapis\.com/webmasters/v3/sites/[A-Za-z0-9_.%~-]+/searchAnalytics/query\z#D', $url);
        if (! $allowed) {
            throw new GoogleReportingException('This Google reporting endpoint is not supported.');
        }
        $token = $this->accessToken($connection);
        try {
            $request = $this->request()->withToken($token);
            $response = $method === 'GET' ? $request->get($url, $data) : $request->asJson()->post($url, $data);
        } catch (ConnectionException) {
            throw new GoogleReportingException('Google reporting could not be reached. Please retry later.');
        }
        $this->assertCurrent($connection);

        return $this->response($response);
    }

    private function response(Response $response): array
    {
        if (! $response->successful()) {
            throw new GoogleReportingException(match ($response->status()) {
                401, 403 => 'Google denied reporting access. Check the connected account, read-only permissions and enabled reporting APIs.',
                429 => 'Google reporting has temporarily reached its request limit. Please retry later.',
                default => 'Google reporting is temporarily unavailable. Please retry later.',
            });
        }
        $data = $response->json();
        if (! is_array($data) || strlen($response->body()) > 2097152 || ! str_starts_with(ltrim($response->body()), '{')) {
            throw new GoogleReportingException('Google returned an invalid reporting response. Please retry later.');
        }

        return $data;
    }

    private function assertCurrent(TrafficConnection $connection): TrafficConnection
    {
        $current = TrafficConnection::whereKey($connection->id)->where('user_id', $connection->user_id)->first();
        if (! $this->isConnected($current) || $current->connection_key !== $connection->connection_key) {
            throw new GoogleReportingException('The reporting connection changed or expired. Reconnect in Traffic & SEO settings.');
        }

        return $current;
    }

    private function accessToken(TrafficConnection $connection): string
    {
        $token = DB::transaction(function () use ($connection) {
            // Lock configuration before the connection, consistently with settings writes.
            TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
            $current = TrafficConnection::whereKey($connection->id)->where('user_id', $connection->user_id)->lockForUpdate()->first();
            if (! $this->isConnected($current) || $current->connection_key !== $connection->connection_key) {
                throw new GoogleReportingException('The reporting connection changed or expired. Reconnect in Traffic & SEO settings.');
            }
            if ($current->expires_at?->isAfter(now()->addMinute())) {
                return $current->access_token;
            }
            try {
                $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
                    ...$this->credentials(), 'refresh_token' => $current->refresh_token, 'grant_type' => 'refresh_token',
                ]);
            } catch (ConnectionException) {
                throw new GoogleReportingException('Google reporting authorization could not be refreshed. Please retry later.');
            }
            $access = $response->json('access_token');
            $scope = $response->json('scope');
            if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
                $current = $this->assertCurrent($connection);
                $current->update(['reconnect_required' => true]);

                // Commit this durable state before throwing the public error below.
                return null;
            }
            if (! $response->successful() || ! $this->validToken($access)
                || ($scope !== null && (! is_string($scope) || array_diff(self::SCOPES, explode(' ', $scope))))) {
                throw new GoogleReportingException('Google reporting authorization expired. Please reconnect in Traffic & SEO settings.');
            }
            $current = $this->assertCurrent($connection);
            $current->access_token = $access;
            $current->expires_at = now()->addSeconds(max(60, min(86400, (int) $response->json('expires_in', 3600))));
            $refresh = $response->json('refresh_token');
            if ($this->validToken($refresh)) {
                $current->refresh_token = $refresh;
            }
            $current->save();

            return $access;
        });

        if ($token === null) {
            throw new GoogleReportingException('Google reporting authorization expired. Reconnect the same Google account in Traffic & SEO settings to restore its website links.');
        }

        return $token;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(4)->timeout(10)->withOptions(['allow_redirects' => false]);
    }

    private function validToken(mixed $token): bool
    {
        return is_string($token) && $token !== '' && strlen($token) <= 8192 && ! preg_match('/[\x00-\x20\x7F]/', $token);
    }
}
