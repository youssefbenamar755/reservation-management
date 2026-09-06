<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFluentWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Support\FluentWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FluentWebhookController extends Controller
{
    public function __invoke(Request $request, Website $website)
    {
        // Check if website is active
        abort_if($website->status !== 'active', 403, 'Website is not active');

        // Security check (simple but effective)
        $token = $request->query('token');
        abort_unless(
            $website->webhook_secret && is_string($token) && hash_equals(
                $website->webhook_secret,
                $token
            ),
            403,
            'Invalid webhook token'
        );

        // Read only the body. Request::all() also includes the URL's secret token.
        $payload = FluentWebhookPayload::normalize(
            $request->isJson() ? $request->json()->all() : $request->request->all()
        );
        Validator::make($payload, [
            '__submission.form_id' => ['required', 'integer', 'min:1'],
            '__submission.id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $event = WebhookEvent::create([
            'website_id' => $website->id,
            'source' => 'fluentforms',
            'topic' => 'form.submitted',
            'external_id' => data_get($payload, '__submission.id'),
            'signature_valid' => true,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        ProcessFluentWebhookEvent::dispatch($event->id);

        $website->update([
            'last_webhook_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
