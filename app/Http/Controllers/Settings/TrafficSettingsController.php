<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\GoogleReportingException;
use App\Http\Controllers\Controller;
use App\Models\GmailAppSetting;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\Website;
use App\Services\GoogleReportingClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TrafficSettingsController extends Controller
{
    private const MAX_CATALOG_PAGES = 5;

    private const MAX_CATALOG_ITEMS = 2000;

    public function index(Request $request, GoogleReportingClient $google)
    {
        $actor = $request->user();
        $connection = TrafficConnection::where('user_id', $actor->id)->first();
        $connected = $google->isConnected($connection);
        $reconnectRequired = (bool) $connection?->reconnect_required;
        $mappings = $connected || $reconnectRequired ? TrafficWebsite::where('user_id', $actor->id)->where('connection_key', $connection->connection_key)->get()->keyBy('website_id') : collect();
        $credentials = $google->credentials();

        return Inertia::render('settings/Traffic', ['trafficSettings' => [
            'app' => [
                'configured' => $google->configured(), 'client_id' => $actor->is_admin ? $credentials['client_id'] : null,
                'has_secret' => $credentials['client_secret'] !== '', 'redirect_uri' => $google->redirectUri(), 'can_manage' => (bool) $actor->is_admin,
            ],
            'connection' => ['connected' => $connected, 'email' => $connected || $reconnectRequired ? $connection->email : null,
                'reconnect_required' => $reconnectRequired],
            'websites' => Website::when(! $actor->is_admin, fn ($query) => $query->where('user_id', $actor->id))
                ->orderBy('name')->get(['id', 'name', 'base_url'])->map(fn ($website) => [
                    'id' => $website->id, 'name' => $website->name, 'base_url' => $website->base_url,
                    'ga4_property_id' => $mappings->get($website->id)?->ga4_property_id ?? '',
                    'gsc_site_url' => $mappings->get($website->id)?->gsc_site_url ?? '',
                ])->all(),
            'catalog' => $connected ? [
                'ga4' => $connection->catalog['ga4'] ?? [], 'gsc' => $connection->catalog['gsc'] ?? [],
                'errors' => $connection->catalog['errors'] ?? [], 'loaded_at' => $connection->catalog_at?->toIso8601String(),
            ] : ['ga4' => [], 'gsc' => [], 'errors' => [], 'loaded_at' => null],
        ]])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function application(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $validation = Validator::make($request->only('client_id', 'client_secret'), [
            'client_id' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_.-]+\.apps\.googleusercontent\.com\z/D'],
            'client_secret' => ['nullable', 'string', 'min:6', 'max:500', 'not_regex:/[\r\n\x00]/'],
        ]);
        if ($validation->fails()) {
            return back()->withErrors($validation);
        }
        $data = $validation->validated();
        if (GmailAppSetting::whereKey(1)->value('client_id') === $data['client_id']) {
            return back()->withErrors(['client_id' => 'Create a separate Web application OAuth client for Traffic & SEO. Keep the Gmail client unchanged.']);
        }

        return DB::transaction(function () use ($data) {
            $existing = TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
            if (empty($data['client_secret']) && (! $existing || $existing->client_id !== $data['client_id'])) {
                return back()->withErrors(['client_secret' => 'Enter the client secret for this reporting application.']);
            }
            TrafficAppSetting::updateOrCreate(['id' => 1], [
                'client_id' => $data['client_id'], 'client_secret' => ($data['client_secret'] ?? null) ?: $existing->client_secret,
            ]);

            return back()->with('success', 'Reporting application saved. Connect the Google account that has access to your website reports.');
        });
    }

    public function connect(Request $request, GoogleReportingClient $google)
    {
        if (! $google->configured()) {
            return response()->json(['message' => 'An administrator must configure the reporting application first.'], 422)->header('Cache-Control', 'private, no-store');
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request->session()->put('traffic_oauth', [
            'state' => hash('sha256', $state), 'verifier' => $verifier, 'expires_at' => now()->addMinutes(10)->timestamp,
            'user_id' => $request->user()->id, 'app_fingerprint' => $google->fingerprint(),
            'connection_key' => TrafficConnection::where('user_id', $request->user()->id)->value('connection_key'),
        ]);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $google->credentials()['client_id'], 'redirect_uri' => $google->redirectUri(),
            'response_type' => 'code', 'scope' => implode(' ', GoogleReportingClient::SCOPES), 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $request->expectsJson() ? response()->json(['url' => $url])->header('Cache-Control', 'private, no-store') : redirect()->away($url);
    }

    public function callback(Request $request, GoogleReportingClient $google)
    {
        $pending = $request->session()->pull('traffic_oauth');
        $state = $request->query('state');
        if (! is_array($pending) || ! is_string($state) || strlen($state) > 128
            || ! is_string($pending['state'] ?? null) || ! hash_equals($pending['state'], hash('sha256', $state))
            || ($pending['user_id'] ?? null) !== $request->user()->id || ($pending['expires_at'] ?? 0) < now()->timestamp
            || ! is_string($pending['verifier'] ?? null) || ! is_string($pending['app_fingerprint'] ?? null)
            || ! hash_equals($pending['app_fingerprint'], $google->fingerprint())) {
            return redirect('/settings/traffic')->with('error', 'The reporting connection request expired. Please start again.');
        }
        $code = $request->query('code');
        if ($request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return redirect('/settings/traffic')->with('error', 'Google reporting connection was cancelled or not authorized.');
        }

        try {
            $tokens = $google->exchange($code, $pending['verifier']);
            if (! hash_equals($pending['app_fingerprint'], $google->fingerprint())) {
                throw new GoogleReportingException('Reporting application settings changed. Please connect again.');
            }
            $email = $google->identity($tokens['access_token']);
            [$connection, $previousMappings] = DB::transaction(function () use ($request, $google, $pending, $tokens, $email) {
                TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $current = TrafficConnection::where('user_id', $request->user()->id)->lockForUpdate()->first();
                if (! hash_equals($pending['app_fingerprint'], $google->fingerprint())
                    || $current?->connection_key !== ($pending['connection_key'] ?? null)) {
                    throw new GoogleReportingException('Reporting settings or the connected account changed. Please start the connection again.');
                }
                $sameAccount = $current?->email !== null && strcasecmp($current->email, $email) === 0;
                $previousMappings = $sameAccount ? TrafficWebsite::where('user_id', $request->user()->id)->get() : collect();
                $connection = TrafficConnection::updateOrCreate(['user_id' => $request->user()->id], [
                    'email' => $email, 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => $pending['app_fingerprint'],
                    'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => now()->addSeconds(max(60, min(86400, (int) ($tokens['expires_in'] ?? 3600)))),
                    'connected_at' => now(), 'reconnect_required' => false, 'catalog' => null, 'catalog_at' => null,
                ]);
                if (! $sameAccount) {
                    TrafficWebsite::where('user_id', $request->user()->id)->delete();
                }

                return [$connection, $previousMappings];
            });
            $catalog = $this->refreshCatalog($connection, $google);
            $unconfirmedLinks = $this->restoreMappings($connection, $previousMappings, $catalog, $google);
        } catch (GoogleReportingException $exception) {
            return redirect('/settings/traffic')->with('error', $exception->getMessage());
        }

        return redirect('/settings/traffic')->with('success', $unconfirmedLinks
            ? 'Google reporting connected. Accessible website links were restored. Some previous links could not be confirmed; review the property lists and link those sources again.'
            : ($catalog['errors'] === []
            ? ($previousMappings->isNotEmpty() ? 'Google reporting reconnected. Your accessible website links were restored.'
                : 'Google reporting connected. Choose the matching GA4 and Search Console properties for each website.')
            : 'Google reporting connected. Some property lists could not be loaded; check the notices and refresh the lists.'));
    }

    public function disconnect(Request $request)
    {
        $request->session()->forget('traffic_oauth');
        DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $connection = TrafficConnection::where('user_id', $request->user()->id)->lockForUpdate()->first();
            $connection?->update(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'connected_at' => null,
                'reconnect_required' => false, 'catalog' => null, 'catalog_at' => null, 'connection_key' => (string) Str::uuid()]);
            TrafficWebsite::where('user_id', $request->user()->id)->delete();
        });

        return back()->with('success', 'Google reporting disconnected from WP Hub. Your Gmail connection is unchanged.');
    }

    public function catalog(Request $request, GoogleReportingClient $google)
    {
        $connection = TrafficConnection::where('user_id', $request->user()->id)->first();
        if (! $google->isConnected($connection)) {
            return back()->withErrors(['connection' => 'Connect your reporting account first.']);
        }
        try {
            $catalog = $this->refreshCatalog($connection, $google);
        } catch (GoogleReportingException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with($catalog['errors'] === [] ? 'success' : 'error', $catalog['errors'] === []
            ? 'Accessible Google reporting properties refreshed.' : 'Some property lists could not be loaded. Check the notices before linking a website.');
    }

    public function website(Request $request, Website $website, GoogleReportingClient $google)
    {
        $this->authorize('update', $website);
        $values = $request->validate([
            'ga4_property_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,31}\z/D'],
            'gsc_site_url' => ['nullable', 'string', 'max:2048', 'not_regex:/[\x00-\x20\x7F]/'],
        ]);
        $values = ['ga4_property_id' => ($values['ga4_property_id'] ?? '') ?: null, 'gsc_site_url' => ($values['gsc_site_url'] ?? '') ?: null];
        $connection = TrafficConnection::where('user_id', $request->user()->id)->first();
        if ($values['ga4_property_id'] !== null || $values['gsc_site_url'] !== null) {
            if (! $google->isConnected($connection)) {
                return back()->withErrors(['connection' => 'Connect your reporting account before linking a website.']);
            }
            try {
                $catalog = $this->refreshCatalog($connection, $google);
            } catch (GoogleReportingException $exception) {
                return back()->withErrors(['connection' => $exception->getMessage()]);
            }
            if ($values['ga4_property_id'] !== null && ! collect($catalog['ga4'])->contains('id', $values['ga4_property_id'])) {
                return back()->withErrors(['ga4_property_id' => 'Select an accessible GA4 property from the current Google account. Refresh the list if needed.']);
            }
            if ($values['gsc_site_url'] !== null && ! collect($catalog['gsc'])->contains('url', $values['gsc_site_url'])) {
                return back()->withErrors(['gsc_site_url' => 'Select a verified Search Console property from the current Google account. Refresh the list if needed.']);
            }
        }

        return DB::transaction(function () use ($request, $website, $connection, $values, $google) {
            TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
            // Serialize this user's mapping writes so duplicate properties cannot race.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($values['ga4_property_id'] === null && $values['gsc_site_url'] === null) {
                TrafficWebsite::where('user_id', $request->user()->id)->where('website_id', $website->id)->delete();

                return back()->with('success', 'Reporting properties unlinked from '.$website->name.'.');
            }
            $current = TrafficConnection::where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $google->isConnected($current) || $current->connection_key !== $connection->connection_key) {
                throw ValidationException::withMessages(['connection' => 'The reporting account changed. Refresh settings before linking a website.']);
            }
            foreach (['ga4_property_id', 'gsc_site_url'] as $field) {
                if ($values[$field] !== null && TrafficWebsite::where('user_id', $request->user()->id)
                    ->where('connection_key', $current->connection_key)->where('website_id', '!=', $website->id)->where($field, $values[$field])->exists()) {
                    throw ValidationException::withMessages([$field => 'This property is already linked to another website. Unlink it there first to avoid double-counting.']);
                }
            }
            $mapping = TrafficWebsite::firstOrNew(['user_id' => $request->user()->id, 'website_id' => $website->id]);
            $mapping->fill($values + ['connection_key' => $current->connection_key]);
            if (! $mapping->exists || $mapping->isDirty(['ga4_property_id', 'gsc_site_url', 'connection_key'])) {
                $mapping->mapping_key = (string) Str::uuid();
            }
            $mapping->save();

            return back()->with('success', 'Reporting properties saved for '.$website->name.'.');
        });
    }

    private function restoreMappings(TrafficConnection $connection, Collection $previousMappings, array $catalog, GoogleReportingClient $google): bool
    {
        if ($previousMappings->isEmpty()) {
            return false;
        }

        return DB::transaction(function () use ($connection, $previousMappings, $catalog, $google) {
            TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $current = TrafficConnection::whereKey($connection->id)->lockForUpdate()->first();
            if (! $google->isConnected($current) || $current->connection_key !== $connection->connection_key) {
                throw new GoogleReportingException('The reporting account changed. Refresh settings before continuing.');
            }
            $ga4 = array_column($catalog['ga4'], 'id');
            $gsc = array_column($catalog['gsc'], 'url');
            $unconfirmed = false;
            foreach ($previousMappings as $mapping) {
                $property = in_array($mapping->ga4_property_id, $ga4, true) ? $mapping->ga4_property_id : null;
                $site = in_array($mapping->gsc_site_url, $gsc, true) ? $mapping->gsc_site_url : null;
                $unchanged = TrafficWebsite::whereKey($mapping->id)->where('user_id', $connection->user_id)
                    ->where('connection_key', $mapping->connection_key)->where('mapping_key', $mapping->mapping_key);
                // Never restore links that were concurrently edited or unlinked.
                if ($property === null && $site === null) {
                    $changed = $unchanged->delete();
                } else {
                    $changed = $unchanged->update(['ga4_property_id' => $property, 'gsc_site_url' => $site,
                        'connection_key' => $current->connection_key, 'mapping_key' => (string) Str::uuid(), 'updated_at' => now()]);
                }
                if ($changed && ($property !== $mapping->ga4_property_id || $site !== $mapping->gsc_site_url)) {
                    $unconfirmed = true;
                }
            }

            return $unconfirmed;
        });
    }

    private function refreshCatalog(TrafficConnection $connection, GoogleReportingClient $google): array
    {
        $catalog = ['ga4' => [], 'gsc' => [], 'errors' => []];
        try {
            $catalog['ga4'] = $this->ga4Catalog($connection, $google);
        } catch (GoogleReportingException $exception) {
            $catalog['errors'][] = 'GA4: '.$exception->getMessage();
        }
        try {
            $data = $google->get($connection, GoogleReportingClient::SITES_URL);
            if (isset($data['siteEntry']) && (! is_array($data['siteEntry']) || ! array_is_list($data['siteEntry']) || count($data['siteEntry']) > self::MAX_CATALOG_ITEMS)) {
                throw new GoogleReportingException('The Search Console property list could not be confirmed. Please retry.');
            }
            foreach ($data['siteEntry'] ?? [] as $site) {
                if (! is_array($site)) {
                    continue;
                }
                $url = $site['siteUrl'] ?? null;
                $permission = $site['permissionLevel'] ?? null;
                if (is_string($url) && $this->validSiteUrl($url) && in_array($permission, ['siteOwner', 'siteFullUser', 'siteRestrictedUser'], true)) {
                    $catalog['gsc'][$url] = ['url' => $url, 'permission' => $permission];
                }
            }
            ksort($catalog['gsc']);
            $catalog['gsc'] = array_values($catalog['gsc']);
        } catch (GoogleReportingException $exception) {
            $catalog['gsc'] = [];
            $catalog['errors'][] = 'Search Console: '.$exception->getMessage();
        }

        DB::transaction(function () use ($connection, $google, $catalog) {
            TrafficAppSetting::whereKey(1)->lockForUpdate()->first();
            $current = TrafficConnection::whereKey($connection->id)->where('user_id', $connection->user_id)->lockForUpdate()->first();
            if (! $google->isConnected($current) || $current->connection_key !== $connection->connection_key) {
                throw new GoogleReportingException('The reporting account changed. Refresh settings before continuing.');
            }
            $current->update(['catalog' => $catalog, 'catalog_at' => now()]);
        });

        return $catalog;
    }

    private function ga4Catalog(TrafficConnection $connection, GoogleReportingClient $google): array
    {
        $properties = [];
        $tokens = [];
        $token = null;
        for ($page = 0; $page < self::MAX_CATALOG_PAGES; $page++) {
            $data = $google->get($connection, GoogleReportingClient::ACCOUNT_SUMMARIES_URL, ['pageSize' => 200] + ($token === null ? [] : ['pageToken' => $token]));
            if (isset($data['accountSummaries']) && (! is_array($data['accountSummaries']) || ! array_is_list($data['accountSummaries']))) {
                throw new GoogleReportingException('The GA4 property list could not be confirmed. Please retry.');
            }
            foreach ($data['accountSummaries'] ?? [] as $account) {
                if (! is_array($account) || ! is_array($account['propertySummaries'] ?? []) || ! array_is_list($account['propertySummaries'] ?? [])) {
                    throw new GoogleReportingException('The GA4 property list could not be confirmed. Please retry.');
                }
                foreach ($account['propertySummaries'] ?? [] as $property) {
                    if (! is_array($property) || ! is_string($property['property'] ?? null)
                        || ! preg_match('~\Aproperties/([1-9][0-9]{0,31})\z~D', $property['property'], $match)) {
                        continue;
                    }
                    $name = is_string($property['displayName'] ?? null) ? $property['displayName'] : 'Property '.$match[1];
                    $properties[$match[1]] = ['id' => $match[1], 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 0, 200)];
                    if (count($properties) > self::MAX_CATALOG_ITEMS) {
                        throw new GoogleReportingException('The GA4 property list is too large to load at once. Use an account with access to fewer properties.');
                    }
                }
            }
            $token = $data['nextPageToken'] ?? null;
            if ($token === null || $token === '') {
                uasort($properties, fn ($left, $right) => strcasecmp($left['name'], $right['name']) ?: strcmp($left['id'], $right['id']));

                return array_values($properties);
            }
            if (! is_string($token) || strlen($token) > 4096 || isset($tokens[$token])) {
                throw new GoogleReportingException('The GA4 property list was incomplete. Please refresh the list before linking a website.');
            }
            $tokens[$token] = true;
        }

        throw new GoogleReportingException('The GA4 property list exceeded the page limit. Use an account with access to fewer properties.');
    }

    private function validSiteUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        if (str_starts_with($url, 'sc-domain:')) {
            return (bool) preg_match('/\Asc-domain:[A-Za-z0-9.-]+\z/D', $url);
        }
        $parts = parse_url($url);

        return is_array($parts) && filter_var($url, FILTER_VALIDATE_URL) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
