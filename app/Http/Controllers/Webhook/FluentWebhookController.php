<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use App\Jobs\ProcessFluentWebhookEvent;

class FluentWebhookController extends Controller
{
    public function __invoke(Request $request, Website $website)
    {
        // Check if website is active
        abort_if($website->status !== 'active', 403, 'Website is not active');

        // Security check (simple but effective)
        $token = $request->query('token');
        abort_unless(
            $website->webhook_secret && $token && hash_equals(
                $website->webhook_secret,
                $token
            ),
            403,
            'Invalid webhook token'
        );

        $event = WebhookEvent::create([
            'website_id' => $website->id,
            'source' => 'fluentforms',
            'topic' => 'form.submitted',
            'external_id' => data_get($request->all(), 'entry_id'),
            'signature_valid' => true,
            'payload' => $request->all(),
            'received_at' => now(),
        ]);
        
        ProcessFluentWebhookEvent::dispatch($event->id);

        $website->update([
            'last_webhook_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
