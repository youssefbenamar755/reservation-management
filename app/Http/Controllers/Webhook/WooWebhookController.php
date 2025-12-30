<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
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

        // Store the webhook event (RAW DATA)
        $event = WebhookEvent::create([
            'website_id' => $website->id,

            // Identify where it came from
            'source' => 'woocommerce',

            // Woo sends this header automatically
            'topic' => $request->header('X-WC-Webhook-Topic', 'unknown'),

            // Woo order ID (if present)
            'external_id' => data_get($request->all(), 'id'),

            // We'll validate signatures later
            // TODO: Add WooCommerce webhook signature validation
            'signature_valid' => true,

            // Store EVERYTHING as JSON
            'payload' => $request->all(),

            // Explicitly set received_at
            'received_at' => now(),
        ]);
        
        // Dispatch job to process the webhook asynchronously
        ProcessWooWebhookEvent::dispatchSync($event->id);

        // Track last webhook time (useful for monitoring)
        $website->update([
            'last_webhook_at' => now(),
        ]);

        // Always return 200 OK quickly
        return response()->json(['ok' => true]);
    }
}
