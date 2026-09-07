<?php

namespace App\Services;

use App\Exceptions\GmailDeliveryException;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GmailClient
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/gmail.settings.basic',
    ];

    public function credentials(): array
    {
        $setting = GmailAppSetting::find(1);

        return [
            'client_id' => (string) ($setting?->client_id ?? ''),
            'client_secret' => (string) ($setting?->client_secret ?? ''),
        ];
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

    public function isConnected(?GmailConnection $connection): bool
    {
        return $this->configured() && $connection !== null && $connection->connected_at !== null
            && (bool) $connection->access_token && (bool) $connection->refresh_token
            && hash_equals($this->fingerprint(), $connection->app_fingerprint);
    }

    public function redirectUri(): string
    {
        // Use the deployment's configured origin, never an incoming Host header.
        return rtrim(config('app.url'), '/').'/settings/email/callback';
    }

    public function exchange(string $code, string $verifier): array
    {
        try {
            $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
                ...$this->credentials(), 'code' => $code, 'code_verifier' => $verifier,
                'redirect_uri' => $this->redirectUri(), 'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            throw new GmailDeliveryException('Google could not be reached. Please try connecting Gmail again.');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)
            || ! is_string($data['access_token'] ?? null) || ! is_string($data['refresh_token'] ?? null)
            || empty($data['access_token']) || empty($data['refresh_token'])
            || array_diff(self::SCOPES, explode(' ', (string) ($data['scope'] ?? '')))) {
            throw new GmailDeliveryException('Gmail could not be connected. Reconnect and allow the requested email permissions.');
        }

        return $data;
    }

    public function aliases(GmailConnection $connection): array
    {
        try {
            $response = $this->request()->withToken($this->accessToken($connection))
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/settings/sendAs');
        } catch (ConnectionException) {
            throw new GmailDeliveryException('Gmail sender addresses could not be checked. Please try again.');
        }
        if (! $response->successful() || ! is_array($response->json('sendAs'))) {
            throw new GmailDeliveryException('Gmail sender addresses could not be checked. Reconnect Gmail in Email settings.');
        }
        $aliases = [];
        $primary = null;
        foreach ($response->json('sendAs') as $alias) {
            $email = $alias['sendAsEmail'] ?? null;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || preg_match('/[\r\n]/', $email)) {
                continue;
            }
            if (! empty($alias['isPrimary'])) {
                $primary = $email;
            }
            if (! empty($alias['isPrimary']) || ($alias['verificationStatus'] ?? '') === 'accepted') {
                $name = preg_replace('/[\r\n\x00-\x1F]/', '', (string) ($alias['displayName'] ?? ''));
                $aliases[] = ['email' => $email, 'name' => mb_substr($name, 0, 150)];
            }
        }
        if ($primary === null || $aliases === []) {
            throw new GmailDeliveryException('No verified Gmail sender addresses were found.');
        }
        // Refresh/alias responses must not overwrite a connection switched in another tab.
        DB::transaction(function () use ($connection, $primary, $aliases) {
            $current = GmailConnection::whereKey($connection->id)->lockForUpdate()->first();
            if (! $current || $current->connection_key !== $connection->connection_key) {
                throw new GmailDeliveryException('The Gmail connection changed. Please reopen the email preview.');
            }
            $current->update(['email' => $primary, 'aliases' => $aliases]);
        });
        $connection->email = $primary;
        $connection->aliases = $aliases;

        return $aliases;
    }

    public function send(GmailConnection $connection, string $mime): string
    {
        // Deliberately no HTTP retries: Gmail has no idempotency key for messages.send.
        $token = $this->accessToken($connection);
        try {
            $response = $this->request()->withToken($token)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '='),
            ]);
        } catch (ConnectionException) {
            throw new GmailDeliveryException('Gmail did not confirm the result. Check Gmail Sent before sending another email.', true);
        }
        if ($response->clientError()) {
            $message = match ($response->status()) {
                401, 403 => 'Gmail rejected the request. Check the sender address and reconnect Gmail in Email settings.',
                429 => 'Gmail has temporarily limited sending. Wait before retrying this email.',
                default => 'Gmail rejected this email. Check the recipient and attachments before trying again.',
            };
            throw new GmailDeliveryException($message);
        }
        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id)) {
            throw new GmailDeliveryException('Gmail did not confirm the result. Check Gmail Sent before sending another email.', true);
        }

        return $id;
    }

    private function accessToken(GmailConnection $connection): string
    {
        return DB::transaction(function () use ($connection) {
            $current = GmailConnection::whereKey($connection->id)->lockForUpdate()->first();
            if (! $this->isConnected($current) || $current->connection_key !== $connection->connection_key) {
                throw new GmailDeliveryException('Connect Gmail in Email settings before sending documents.');
            }
            if ($current->expires_at?->isAfter(now()->addMinute())) {
                return $current->access_token;
            }
            try {
                $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
                    ...$this->credentials(), 'refresh_token' => $current->refresh_token,
                    'grant_type' => 'refresh_token',
                ]);
            } catch (ConnectionException) {
                throw new GmailDeliveryException('Gmail authorization could not be refreshed. Please try again.');
            }
            $access = $response->json('access_token');
            if (! $response->successful() || ! is_string($access) || $access === '') {
                throw new GmailDeliveryException('Gmail authorization expired. Please reconnect Gmail in Email settings.');
            }
            $current->access_token = $access;
            $current->expires_at = now()->addSeconds(max(60, min(86400, (int) $response->json('expires_in', 3600))));
            $refresh = $response->json('refresh_token');
            if (is_string($refresh) && $refresh !== '') {
                $current->refresh_token = $refresh;
            }
            $current->save();

            return $access;
        });
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false]);
    }
}
