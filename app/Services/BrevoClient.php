<?php

namespace App\Services;

use App\Exceptions\MarketingProviderException;
use App\Models\MarketingConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BrevoClient
{
    /** Fixed destination; no API keys or provider response bodies enter application logs. */
    public function request(MarketingConnection|string $connection, string $method, string $path, array $data = []): array
    {
        $key = is_string($connection) ? $connection : $connection->api_key;
        if (! $key) {
            throw new MarketingProviderException;
        }
        try {
            $response = Http::baseUrl('https://api.brevo.com/v3')->acceptJson()->asJson()
                ->withHeaders(['api-key' => $key])->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->send($method, $path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new MarketingProviderException($method !== 'GET');
        }
        if (! $response->successful()) {
            throw new MarketingProviderException($method !== 'GET' && ($response->serverError() || $response->status() === 408), $response->status());
        }

        return is_array($response->json()) ? $response->json() : [];
    }

    public function senders(MarketingConnection|string $connection): array
    {
        return collect($this->request($connection, 'GET', '/senders')['senders'] ?? [])
            ->filter(fn ($sender) => ($sender['active'] ?? false) === true && filter_var($sender['email'] ?? '', FILTER_VALIDATE_EMAIL))
            ->map(fn ($sender) => ['email' => strtolower($sender['email']), 'name' => (string) ($sender['name'] ?? '')])->values()->all();
    }

    public function assertSender(MarketingConnection $connection, string $email): void
    {
        if (! in_array(strtolower($email), array_column($this->senders($connection), 'email'), true)) {
            throw new MarketingProviderException;
        }
    }
}
