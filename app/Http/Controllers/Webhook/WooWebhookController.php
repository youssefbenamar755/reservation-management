<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessWooWebhookEvent;

class WooWebhookController extends Controller
{
    /**
     * This method is automatically called
     * when WooCommerce sends a webhook.
     */
    public function __invoke(Request $request, Website $website)
    {
        // Basic validation: Check if website is active
        abort_if($website->status !== 'active', 403, 'Website is not active');

        // Validate WooCommerce webhook signature — both signature header AND secret are required
        $signature = $request->header('X-WC-Webhook-Signature');

        if (!$signature || !$website->wc_webhook_secret) {
            Log::warning('WooCommerce webhook rejected: missing signature or secret not configured', [
                'website_id'    => $website->id,
                'website_slug'  => $website->slug,
                'has_signature' => (bool) $signature,
                'has_secret'    => (bool) $website->wc_webhook_secret,
                'ip'            => $request->ip(),
            ]);
            abort(403, 'Webhook signature required');
        }

        // Read raw body ONCE — reuse for both HMAC and payload parsing.
        // Important: $request->all() returns empty for JSON bodies after getContent() on some setups.
        $rawBody = $request->getContent();

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $rawBody, $website->wc_webhook_secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('WooCommerce webhook signature validation failed', [
                'website_id'   => $website->id,
                'website_slug' => $website->slug,
                'topic'        => $request->header('X-WC-Webhook-Topic'),
                'ip'           => $request->ip(),
            ]);
            abort(403, 'Invalid webhook signature');
        }

        // Parse JSON payload from the raw body (not $request->all() which may be empty for JSON)
        $parsedPayload = json_decode($rawBody, true);

        if (!is_array($parsedPayload)) {
            Log::error('WooCommerce webhook: failed to parse JSON body', [
                'website_id' => $website->id,
                'raw_body'   => substr($rawBody, 0, 500),
            ]);
            // Return 200 anyway so WooCommerce doesn't keep retrying a malformed payload
            return response()->json(['ok' => false, 'error' => 'Invalid JSON payload']);
        }

        // Store the webhook event (RAW DATA)
        $event = WebhookEvent::create([
            'website_id'     => $website->id,
            'source'         => 'woocommerce',
            'topic'          => $request->header('X-WC-Webhook-Topic', 'unknown'),
            'external_id'    => $parsedPayload['id'] ?? null,   // WC order ID
            'signature_valid' => true,
            'payload'        => $parsedPayload,                  // parsed, not $request->all()
            'received_at'    => now(),
        ]);

        // Dispatch job to process the webhook asynchronously (quick 200 OK back to WooCommerce)
        ProcessWooWebhookEvent::dispatch($event->id);

        // Track last webhook time (useful for monitoring)
        $website->update([
            'last_webhook_at' => now(),
        ]);

        // Always return 200 OK quickly
        return response()->json(['ok' => true]);
    }
}
