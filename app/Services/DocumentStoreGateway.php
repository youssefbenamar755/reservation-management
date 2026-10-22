<?php

namespace App\Services;

use App\Exceptions\DocumentStoreException;
use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DocumentStoreGateway
{
    private const CAPABILITIES = ['order_specific_suppression', 'receipt_verification', 'optimistic_completion', 'order_revision', 'local_acknowledgement', 'private_notes'];

    public function health(Website $website): array
    {
        $unavailable = ['available' => false, 'supported' => false, 'verified' => false, 'capabilities' => [], 'enabled' => false];
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
            return $unavailable + ['message' => 'No supported WooCommerce connection is configured. Send-only delivery is available after sender setup.'];
        }
        $profile = WebsiteDocumentProfile::where('website_id', $website->id)->first();
        if (! $profile?->store_pairing_key) {
            return $unavailable + ['message' => 'Completion suppression not configured. Save this website’s profile and pair the companion plugin.'];
        }
        try {
            $data = $this->call($website, 'GET', 'wphub-documents/health', [], $profile->store_pairing_key);
            $capabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
            $verified = ($data['website_identity'] ?? '') === $profile->store_pairing_key
                && ! array_diff(self::CAPABILITIES, $capabilities);

            return ['available' => true, 'supported' => $verified, 'verified' => $verified,
                'website_identity' => $data['website_identity'] ?? null, 'capabilities' => $capabilities,
                'enabled' => (bool) ($data['enabled'] ?? false), 'config_version' => $data['config_version'] ?? null,
                'message' => $verified ? 'Companion plugin pairing and completion capabilities verified.'
                    : (($data['requirements']['compatible'] ?? true) === false
                        ? 'Completion suppression not configured: the store requires WooCommerce 8.8 or newer with transactional InnoDB order tables.'
                        : 'Completion suppression not configured: plugin identity or capabilities do not match.')];
        } catch (DocumentStoreException $exception) {
            return $unavailable + ['message' => $exception->getMessage()];
        }
    }

    /** An explicit settings action; this is never called by preview or checkout rendering. */
    public function syncBrand(WebsiteDocumentProfile $profile): array
    {
        $templates = (array) $profile->templates;
        $data = [
            'enabled' => (bool) $profile->workflow_enabled, 'config_version' => (string) $profile->config_version,
            'brand_name' => $profile->brand_name, 'sender_name' => $profile->sender_name,
            'support_email' => $profile->support_email, 'reply_to' => $profile->reply_to,
            'support_phone' => $profile->support_phone, 'footer' => $profile->footer, 'service_label' => $profile->service_label,
            'website_url' => $profile->website_url, 'logo_url' => $profile->logo_url,
            'primary_color' => $profile->primary_color, 'accent_color' => $profile->accent_color,
            'background_color' => $profile->background_color, 'text_color' => $profile->text_color,
            'default_language' => $profile->default_language,
            'templates' => [],
        ];
        foreach (['fr', 'en'] as $language) {
            $template = $templates['acknowledgement'][$language] ?? [];
            $data['templates'][$language] = array_intersect_key($template, array_flip(['subject', 'heading', 'body', 'preheader', 'optional_block']));
        }
        $result = $this->call($profile->website, 'PUT', 'wphub-documents/brand', $data, $profile->store_pairing_key);
        if (($result['website_identity'] ?? '') !== $profile->store_pairing_key) {
            throw new DocumentStoreException('The store pairing changed. Review the website integration before activation.');
        }

        return $result;
    }

    /** Fresh read-only eligibility evidence. Nothing is written to the store. */
    public function checkOrder(WcOrder $order, ?float $deadline = null): array
    {
        $profile = WebsiteDocumentProfile::where('website_id', $order->website_id)->first();
        if (! $profile?->store_pairing_key) {
            throw new DocumentStoreException('A paired companion plugin is required to verify the current order revision.');
        }
        $data = $this->call($order->website, 'GET', 'wphub-documents/orders/'.(int) $order->wp_order_id.'/eligibility', [], $profile->store_pairing_key, $deadline);
        if ((int) ($data['id'] ?? 0) !== (int) $order->wp_order_id || empty($data['date_modified_gmt'])
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($data['fulfillment_revision'] ?? ''))) {
            throw new DocumentStoreException('The store returned incomplete order information. Refresh and review the order.');
        }

        return ['status' => $data['status'] ?? '',
            'paid' => in_array($data['status'] ?? '', ['processing', 'completed'], true) && ! empty($data['date_paid_gmt']),
            'modified_gmt' => $data['date_modified_gmt'], 'expected_modified_gmt' => $data['date_modified_gmt'],
            'customer_email' => data_get($data, 'billing.email'), 'fulfillment_revision' => $data['fulfillment_revision']];
    }

    /** This method retries store synchronization only. It never sends an email. */
    public function complete(WcOrder $order, OrderEmailDelivery $delivery): array
    {
        $snapshot = $delivery->snapshot ?? [];
        $profile = WebsiteDocumentProfile::where('website_id', $order->website_id)->first();
        $required = $snapshot['required_services'] ?? [];
        if (! $profile || ! $profile->workflow_enabled || (int) $delivery->wc_order_id !== (int) $order->id
            || (int) $delivery->website_id !== (int) $order->website_id
            || $delivery->status !== 'sent' || ! $delivery->gmail_message_id || ! $delivery->sent_at
            || (str_starts_with((string) $delivery->gmail_message_id, 'capture_') && ! app(DocumentValidationSafety::class)->capture())
            || ($snapshot['mode'] ?? '') !== 'complete' || ($snapshot['fulfillment_confirmed'] ?? false) !== true
            || ! is_array($required) || ! $required || array_diff($required, $snapshot['fulfilled_services'] ?? [])
            || empty($snapshot['attachments']) || empty($snapshot['document_set_version'])
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['expected_fulfillment_revision'] ?? ''))
            || (int) ($snapshot['config_version'] ?? 0) !== (int) $profile->config_version
            || (int) $profile->brand_sync_version !== (int) $profile->config_version
            || empty($profile->store_pairing_key) || ($snapshot['website_identity'] ?? '') !== $profile->store_pairing_key) {
            throw new DocumentStoreException('A successful, fully fulfilled document delivery for this exact website and order is required before completion.');
        }
        $path = 'wphub-documents/orders/'.(int) $order->wp_order_id;
        $proof = [
            'receipt_id' => $delivery->id, 'provider_message_id' => $delivery->gmail_message_id,
            'sent_at' => $delivery->sent_at->toIso8601String(), 'document_set_version' => $snapshot['document_set_version'],
            'fulfillment_confirmed' => true, 'attachment_count' => count($snapshot['attachments']),
            'expected_modified_gmt' => $snapshot['expected_modified_gmt'] ?? '',
            'expected_fulfillment_revision' => $snapshot['expected_fulfillment_revision'],
            'config_version' => (string) $snapshot['config_version'],
        ];
        $this->call($order->website, 'POST', $path.'/receipt', $proof, $profile->store_pairing_key);
        // Deliberately separate persistence, verification and transition requests. A generic
        // WooCommerce meta_data + status update cannot guarantee email-filter ordering.
        $receipt = $this->call($order->website, 'GET', $path.'/receipt', [], $profile->store_pairing_key);
        if (($receipt['verified'] ?? false) !== true || ($receipt['receipt_id'] ?? '') !== $delivery->id
            || ($receipt['document_set_version'] ?? '') !== $snapshot['document_set_version']) {
            throw new DocumentStoreException('Documents sent — order update pending. The store receipt could not be verified; completion was not requested.');
        }
        $result = $this->call($order->website, 'POST', $path.'/complete', ['receipt_id' => $delivery->id], $profile->store_pairing_key);
        if (($result['status'] ?? '') !== 'completed' || ($result['verified'] ?? false) !== true || ($result['receipt_id'] ?? '') !== $delivery->id) {
            throw new DocumentStoreException('Documents sent — order update pending. The store has not confirmed completion.');
        }
        $remote = $this->call($order->website, 'GET', 'orders/'.(int) $order->wp_order_id);
        if ((int) ($remote['id'] ?? 0) !== (int) $order->wp_order_id || ($remote['status'] ?? '') !== 'completed' || empty($remote['date_modified_gmt'])) {
            throw new DocumentStoreException('Documents sent — review required. The external order changed before its completed state could be synchronized.');
        }
        $stored = app(WooCommerceOrderStore::class)->store((int) $order->website_id, $remote);
        if ($stored['order']->status !== 'completed') {
            throw new DocumentStoreException('Documents sent — review required. A newer order update is already recorded in WP Hub.');
        }

        return $result;
    }

    protected function client(Website $website, ?string $identity): PendingRequest
    {
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret || $website->status !== 'active') {
            throw new DocumentStoreException('An active website with WooCommerce API credentials is required.');
        }
        $client = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret);

        return $identity ? $client->withHeaders(['X-WPHub-Website' => $identity]) : $client;
    }

    private function call(Website $website, string $method, string $path, array $data = [], ?string $identity = null, ?float $deadline = null): array
    {
        $base = rtrim((string) $website->base_url, '/');
        $parts = parse_url($base);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($base, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $host === 'localhost' || str_ends_with($host, '.localhost') || ! str_contains($host, '.')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new DocumentStoreException('The registered store URL must be a public HTTPS address without credentials or a custom port.');
        }
        $this->remainingSeconds($deadline);
        $addresses = $this->resolvePublicAddresses($host);
        if (! defined('CURLOPT_RESOLVE')) {
            throw new DocumentStoreException('The server requires the PHP cURL extension for a securely pinned store connection.');
        }
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $request = $this->client($website, $identity)
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]], 'proxy' => '']);
        // PHP's blocking resolver cannot be interrupted here. Include the time it
        // consumed and stop before HTTP if it exhausted the caller's budget.
        $remaining = $this->remainingSeconds($deadline);
        $request->connectTimeout(min(5, $remaining))->timeout(min(20, $remaining));
        try {
            $response = $request->send($method, $base.'/wp-json/wc/v3/'.$path, $data ? ['json' => $data] : []);
        } catch (ConnectionException) {
            throw new DocumentStoreException('The store connection timed out or is unavailable. Review the order and retry synchronization; do not resend the email.');
        }
        if (! $response->successful()) {
            $message = match ($response->status()) {
                401, 403 => 'Completion suppression not configured: verify the store pairing and WooCommerce API key permissions.',
                404 => 'Completion suppression not configured: install and pair the WP Hub Documents companion plugin.',
                409 => 'The store order or workflow configuration changed. Refresh and review it before retrying order synchronization.',
                422 => 'The store rejected the document receipt or brand configuration. Review the website settings and fulfillment proof.',
                default => 'The store could not confirm the request (HTTP '.$response->status().'). Retry order synchronization after reviewing the store.',
            };
            throw new DocumentStoreException($message);
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new DocumentStoreException('The store returned an invalid response. Completion was not confirmed.');
        }

        return $result;
    }

    /** Resolve once, reject mixed public/private answers and pin cURL to prevent DNS rebinding. */
    protected function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $addresses = $this->lookupAddresses($host);
        }
        if (! $addresses) {
            throw new DocumentStoreException('The registered store hostname could not be resolved. Check its HTTPS URL and DNS configuration.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($address), '::ffff:')) {
                throw new DocumentStoreException('The registered store hostname must resolve only to public IP addresses.');
            }
        }

        return $addresses;
    }

    protected function lookupAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(
            static fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            is_array($records) ? $records : [],
        ))));
    }

    private function remainingSeconds(?float $deadline): float
    {
        $remaining = $deadline === null ? 20.0 : $deadline - $this->monotonicSeconds();
        if ($remaining < 0.001) {
            throw new DocumentStoreException('Document validation took too long. No email was submitted. Please try again.');
        }

        return $remaining;
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
