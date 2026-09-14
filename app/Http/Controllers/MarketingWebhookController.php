<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Models\MarketingContact;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MarketingWebhookController extends Controller
{
    public function __invoke(Request $request, MarketingConnection $connection)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) === 64 && hash_equals($connection->webhook_secret, $token), 403);
        abort_if(strlen($request->getContent()) > 131072, 413);
        $input = $request->json()->all();
        $events = array_is_list($input) ? $input : [$input];
        abort_if(count($events) > 100, 413);
        foreach ($events as $event) {
            $data = Validator::make(is_array($event) ? $event : [], ['email' => ['required', 'email:rfc', 'max:254'],
                'event' => ['required', 'string', 'max:40'], 'camp_id' => ['nullable', 'integer', 'min:1'],
                'ts_event' => ['nullable', 'integer', 'min:0', 'max:'.now()->addMinutes(5)->timestamp],
            ])->validate();
            $email = strtolower(trim($data['email']));
            $kind = strtolower(str_replace('_', '', $data['event']));
            $field = match ($kind) {
                'delivered' => 'delivered_at', 'opened', 'uniqueopened' => 'opened_at', 'click' => 'clicked_at',
                'hardbounce', 'softbounce' => 'bounced_at', 'spam' => 'complained_at', 'unsubscribe', 'unsubscribed' => 'unsubscribed_at', default => null,
            };
            if (! $field) {
                continue;
            }
            $at = isset($data['ts_event']) ? Carbon::createFromTimestampUTC($data['ts_event']) : now();
            DB::transaction(function () use ($connection, $data, $email, $kind, $field, $at) {
                $campaign = MarketingCampaign::where('marketing_connection_id', $connection->id)->where('connection_key', $connection->connection_key)
                    ->where('provider_campaign_id', $data['camp_id'] ?? 0)->first();
                if (in_array($kind, ['hardbounce', 'spam', 'unsubscribe', 'unsubscribed'], true)) {
                    DB::table('marketing_suppressions')->insertOrIgnore(['marketing_connection_id' => $connection->id, 'email' => $email, 'reason' => $kind, 'created_at' => $at, 'updated_at' => now()]);
                    if ($campaign && in_array($kind, ['unsubscribe', 'unsubscribed'], true)) {
                        $contact = MarketingContact::where('website_id', $campaign->website_id)->where('email', $email)->lockForUpdate()->first();
                        if ($contact && $contact->status !== 'unsubscribed' && (! $contact->consented_at || $at->gte($contact->consented_at))) {
                            $contact->update(['status' => 'unsubscribed', 'unsubscribed_at' => $at]);
                            DB::table('marketing_consent_events')->insert(['marketing_contact_id' => $contact->id, 'status' => 'unsubscribed', 'source' => 'Brevo unsubscribe event', 'created_at' => now()]);
                        }
                    }
                }
                // One first-event timestamp per recipient: replay and out-of-order notifications cannot inflate totals.
                if ($campaign) {
                    $campaign->recipients()->where('email', $email)->where(fn ($q) => $q->whereNull($field)->orWhere($field, '>', $at))->update([$field => $at]);
                }
            });
        }
        $connection->update(['last_event_at' => now()]);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
