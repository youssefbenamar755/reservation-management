<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\MarketingProviderException;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Services\BrevoClient;
use App\Services\MarketingLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MarketingSettingsController extends Controller
{
    public function index(Request $request)
    {
        $connection = MarketingConnection::where('user_id', $request->user()->id)->first();

        return Inertia::render('settings/Marketing', ['connection' => $connection ? [
            'connected' => $connection->ready(), 'has_key' => (bool) $connection->api_key,
            'account_email' => $connection->account_email, 'senders' => $connection->senders ?? [],
            'verified_at' => $connection->verified_at?->toIso8601String(), 'last_event_at' => $connection->last_event_at?->toIso8601String(),
        ] : null])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function connect(Request $request, BrevoClient $client, MarketingLock $lock)
    {
        $validator = Validator::make($request->only('api_key'), ['api_key' => ['nullable', 'string', 'min:16', 'max:500', 'regex:/^[a-zA-Z0-9_-]+$/D']]);
        if ($validator->fails()) {
            return back()->withErrors($validator); // Never flash API keys into session old-input.
        }
        try {
            $lock->run($request->user()->id, function () use ($request, $client, $validator) {
                $connection = MarketingConnection::firstOrCreate(['user_id' => $request->user()->id], ['connection_key' => (string) Str::uuid(), 'webhook_secret' => bin2hex(random_bytes(32))]);
                $key = $validator->validated()['api_key'] ?? $connection->api_key;
                if (! $key) {
                    throw ValidationException::withMessages(['api_key' => 'Enter your Brevo API key.']);
                }
                if ($key !== $connection->api_key && MarketingCampaign::where('user_id', $request->user()->id)->whereIn('status', ['scheduled', 'preparing', 'sending'])->exists()) {
                    throw ValidationException::withMessages(['api_key' => 'Cancel scheduled campaigns and wait for active campaigns before changing the connection.']);
                }
                $account = $client->request($key, 'GET', '/account');
                if (! filter_var($account['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    throw new MarketingProviderException;
                }
                // Changing accounts requires a separate connection to preserve suppression and webhook ownership.
                if ($connection->account_email && strcasecmp($connection->account_email, $account['email']) !== 0) {
                    throw ValidationException::withMessages(['api_key' => 'Use an API key for the existing Brevo account. Contact support before switching accounts with campaign history.']);
                }
                $senders = $client->senders($key);
                $connection->fill(['api_key' => $key, 'account_email' => strtolower($account['email']), 'senders' => $senders, 'verified_at' => null]);
                $connection->save();
                if (! $connection->folder_id) {
                    $folder = $client->request($connection, 'POST', '/contacts/folders', ['name' => 'WP Hub '.$connection->id]);
                    if (! is_numeric($folder['id'] ?? null)) {
                        throw new MarketingProviderException;
                    }
                    $connection->update(['folder_id' => $folder['id']]);
                }
                $webhook = ['url' => route('marketing.events', ['connection' => $connection->id]),
                    'description' => 'WP Hub marketing results and suppression', 'type' => 'marketing', 'batched' => false,
                    'events' => ['delivered', 'opened', 'click', 'hardBounce', 'softBounce', 'spam', 'unsubscribed'],
                    'auth' => ['type' => 'bearer', 'token' => $connection->webhook_secret]];
                if ($connection->webhook_id) {
                    $client->request($connection, 'PUT', '/webhooks/'.$connection->webhook_id, $webhook);
                } else {
                    $result = $client->request($connection, 'POST', '/webhooks', $webhook);
                    if (! is_numeric($result['id'] ?? null)) {
                        throw new MarketingProviderException;
                    }
                    $connection->update(['webhook_id' => $result['id']]);
                }
                $connection->update(['verified_at' => now()]);
            });
        } catch (MarketingProviderException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Brevo connected. Verified senders and campaign events are ready.');
    }

    public function disconnect(Request $request, MarketingLock $lock)
    {
        return $lock->run($request->user()->id, function () use ($request) {
            if (MarketingCampaign::where('user_id', $request->user()->id)->where('status', 'sending')->exists()) {
                throw ValidationException::withMessages(['connection' => 'Wait for the sending request to finish before disconnecting.']);
            }
            MarketingCampaign::where('user_id', $request->user()->id)->whereIn('status', ['scheduled', 'preparing'])
                ->update(['status' => 'cancelled', 'result_message' => 'Cancelled because Brevo was disconnected.']);
            MarketingConnection::where('user_id', $request->user()->id)->first()?->update(['api_key' => null, 'verified_at' => null]);

            return back()->with('success', 'Brevo disconnected and pending campaigns cancelled. Campaigns already accepted by Brevo cannot be recalled here.');
        });
    }
}
