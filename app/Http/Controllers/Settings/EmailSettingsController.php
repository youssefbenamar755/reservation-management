<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\GmailDeliveryException;
use App\Http\Controllers\Controller;
use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\Website;
use App\Models\WebsiteEmailSetting;
use App\Services\GmailClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class EmailSettingsController extends Controller
{
    public function index(Request $request, GmailClient $gmail)
    {
        $connection = GmailConnection::where('user_id', $request->user()->id)->first();
        $connected = $gmail->isConnected($connection);
        $settings = WebsiteEmailSetting::where('user_id', $request->user()->id)->get()->keyBy('website_id');
        $websites = Website::query()->when(! $request->user()->is_admin, fn ($query) => $query->where('user_id', $request->user()->id))
            ->orderBy('name')->get(['id', 'name', 'base_url'])->map(function ($site) use ($settings) {
                $setting = $settings->get($site->id);

                return [
                    'id' => $site->id, 'name' => $site->name, 'base_url' => $site->base_url,
                    'sender_email' => $setting?->sender_email ?? '',
                    'subject_template' => $setting?->subject_template ?? WebsiteEmailSetting::DEFAULT_SUBJECT,
                    'body_template' => $setting?->body_template ?? WebsiteEmailSetting::DEFAULT_BODY,
                    'signature' => $setting?->signature ?? WebsiteEmailSetting::DEFAULT_SIGNATURE,
                ];
            })->all();
        $credentials = $gmail->credentials();

        return Inertia::render('settings/Email', ['emailSettings' => [
            'app' => [
                'configured' => $gmail->configured(),
                'client_id' => $request->user()->is_admin ? $credentials['client_id'] : null,
                'has_secret' => $credentials['client_secret'] !== '', 'redirect_uri' => $gmail->redirectUri(),
                'can_manage' => (bool) $request->user()->is_admin,
            ],
            'connection' => ['connected' => $connected, 'email' => $connected ? $connection->email : null, 'aliases' => $connected ? $connection->aliases : []],
            'websites' => $websites,
            'placeholders' => ['{{customer_name}}', '{{order_number}}', '{{website_name}}'],
        ]])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function application(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $existing = GmailAppSetting::find(1);
        $validator = Validator::make($request->only('client_id', 'client_secret'), [
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.-]+\.apps\.googleusercontent\.com$/D'],
            'client_secret' => ['nullable', 'string', 'min:6', 'max:500', 'not_regex:/[\r\n]/'],
        ]);
        // Do not flash secrets into the session when a validation error occurs.
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        if (empty($data['client_secret']) && (! $existing || $existing->client_id !== $data['client_id'])) {
            return back()->withErrors(['client_secret' => 'Enter the client secret for this Google application.']);
        }
        GmailAppSetting::updateOrCreate(['id' => 1], [
            'client_id' => $data['client_id'], 'client_secret' => ($data['client_secret'] ?? null) ?: $existing->client_secret,
        ]);

        return back()->with('success', 'Google application settings saved. Connect Gmail to activate email delivery.');
    }

    public function connect(Request $request, GmailClient $gmail)
    {
        if (! $gmail->configured()) {
            return response()->json(['message' => 'An administrator must configure the Google application first.'], 422);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request->session()->put('gmail_oauth', [
            'state' => hash('sha256', $state), 'verifier' => $verifier, 'expires_at' => now()->addMinutes(10)->timestamp,
            'user_id' => $request->user()->id, 'app_fingerprint' => $gmail->fingerprint(),
        ]);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $gmail->credentials()['client_id'], 'redirect_uri' => $gmail->redirectUri(),
            'response_type' => 'code', 'scope' => implode(' ', GmailClient::SCOPES),
            'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $request->expectsJson() ? response()->json(['url' => $url])->header('Cache-Control', 'private, no-store') : redirect()->away($url);
    }

    public function callback(Request $request, GmailClient $gmail)
    {
        $pending = $request->session()->pull('gmail_oauth');
        $state = $request->query('state');
        if (! is_array($pending) || ! is_string($state) || strlen($state) > 128
            || ! hash_equals($pending['state'], hash('sha256', $state))
            || $pending['user_id'] !== $request->user()->id || $pending['expires_at'] < now()->timestamp
            || ! hash_equals($pending['app_fingerprint'], $gmail->fingerprint())) {
            return redirect('/settings/email')->with('error', 'The Gmail connection request expired. Please start again.');
        }
        $code = $request->query('code');
        if ($request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return redirect('/settings/email')->with('error', 'Gmail connection was cancelled or not authorized.');
        }
        try {
            $tokens = $gmail->exchange($code, $pending['verifier']);
            DB::transaction(function () use ($request, $tokens, $gmail, $pending) {
                if (! hash_equals($pending['app_fingerprint'], $gmail->fingerprint())) {
                    throw new GmailDeliveryException('Google application settings changed. Please connect Gmail again.');
                }
                $connection = GmailConnection::updateOrCreate(['user_id' => $request->user()->id], [
                    'connection_key' => (string) Str::uuid(), 'app_fingerprint' => $pending['app_fingerprint'],
                    'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => now()->addSeconds(max(60, min(86400, (int) ($tokens['expires_in'] ?? 3600)))),
                    'connected_at' => now(), 'aliases' => [],
                ]);
                $gmail->aliases($connection);
                if (! hash_equals($pending['app_fingerprint'], $gmail->fingerprint())) {
                    throw new GmailDeliveryException('Google application settings changed. Please connect Gmail again.');
                }
            });
        } catch (GmailDeliveryException $exception) {
            return redirect('/settings/email')->with('error', $exception->getMessage());
        }

        return redirect('/settings/email')->with('success', 'Gmail connected. Choose the sender address for each website.');
    }

    public function disconnect(Request $request)
    {
        $request->session()->forget('gmail_oauth');
        $connection = GmailConnection::where('user_id', $request->user()->id)->first();
        $connection?->update([
            'access_token' => null, 'refresh_token' => null, 'expires_at' => null,
            'connected_at' => null, 'aliases' => [], 'connection_key' => (string) Str::uuid(),
        ]);

        return back()->with('success', 'Gmail disconnected from WP Hub.');
    }

    public function refreshAliases(Request $request, GmailClient $gmail)
    {
        $connection = GmailConnection::where('user_id', $request->user()->id)->first();
        if (! $gmail->isConnected($connection)) {
            return back()->withErrors(['connection' => 'Connect Gmail first.']);
        }
        try {
            $gmail->aliases($connection);
        } catch (GmailDeliveryException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('success', 'Verified Gmail sender addresses refreshed.');
    }

    public function website(Request $request, Website $website, GmailClient $gmail)
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'sender_email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'subject_template' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'body_template' => ['required', 'string', 'max:16000'],
            'signature' => ['nullable', 'string', 'max:3000'],
        ]);
        $connection = GmailConnection::where('user_id', $request->user()->id)->first();
        if (! $gmail->isConnected($connection)) {
            return back()->withErrors(['sender_email' => 'Connect Gmail before selecting a sender.']);
        }
        try {
            $aliases = $gmail->aliases($connection);
        } catch (GmailDeliveryException $exception) {
            return back()->withErrors(['sender_email' => $exception->getMessage()]);
        }
        $sender = collect($aliases)->first(fn ($alias) => strcasecmp($alias['email'], $data['sender_email']) === 0);
        if ($sender === null) {
            return back()->withErrors(['sender_email' => 'Select a verified sender address from your connected Gmail account.']);
        }
        $data['sender_email'] = $sender['email'];
        WebsiteEmailSetting::updateOrCreate(['user_id' => $request->user()->id, 'website_id' => $website->id], $data);

        return back()->with('success', 'Email sender and template saved for '.$website->name.'.');
    }
}
