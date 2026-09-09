<?php

namespace App\Services;

use App\Jobs\TrafficRefreshReport;
use App\Models\TrafficConnection;
use App\Models\TrafficReport;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TrafficReporting
{
    public function __construct(private GoogleReportingClient $google, private TrafficGoogleReports $provider) {}

    public function page(User $user, array $filters): array
    {
        $websites = $this->websites($user);
        $selected = $this->selected($websites, $filters['website_id']);
        $connection = $this->connection($user);
        $query = WcOrder::whereIn('website_id', $selected->pluck('id'))
            ->where('created_at_wp', '>=', CarbonImmutable::parse($filters['start_date'], 'UTC')->startOfDay())
            ->where('created_at_wp', '<', CarbonImmutable::parse($filters['end_date'], 'UTC')->addDay()->startOfDay());
        $groups = $query->selectRaw("status, UPPER(COALESCE(NULLIF(TRIM(currency), ''), 'UNKNOWN')) AS currency_code, COUNT(*) AS count, SUM(total) AS amount")
            ->groupBy('status', 'currency_code')->get();
        $revenue = [];
        $completed = 0;
        foreach ($groups as $group) {
            if ($group->status === 'completed') {
                $completed += (int) $group->count;
                $revenue[] = ['currency' => $group->currency_code, 'total' => round((float) $group->amount, 2)];
            }
        }
        usort($revenue, fn ($a, $b) => strcmp($a['currency'], $b['currency']));

        return [
            'websites' => $websites->map(fn ($website) => ['id' => $website->id, 'name' => $website->name])->values()->all(),
            'filters' => $filters,
            'connection' => ['connected' => $connection !== null, 'email' => $connection?->email],
            'reports' => $this->cachedReports($user, $selected, $connection, $filters),
            'orders' => ['total' => (int) $groups->sum('count'), 'completed' => $completed, 'revenue' => $revenue],
        ];
    }

    public function reports(User $user, array $filters): array
    {
        return $this->cachedReports($user, $this->selected($this->websites($user), $filters['website_id']), $this->connection($user), $filters);
    }

    private function cachedReports(User $user, Collection $websites, ?TrafficConnection $connection, array $filters): array
    {
        $expired = $connection ? null : TrafficConnection::where('user_id', $user->id)->where('reconnect_required', true)->first();
        $mappingConnection = $connection ?? $expired;
        $mappings = $mappingConnection ? TrafficWebsite::where('user_id', $user->id)->where('connection_key', $mappingConnection->connection_key)
            ->whereIn('website_id', $websites->pluck('id'))->get()->keyBy('website_id') : collect();
        // Keep an expired mapping identifiable, but never serve its cached metrics.
        $keys = $connection ? $mappings->map(fn ($mapping) => $this->identity($user, $connection, $mapping, $filters)) : collect();
        $reports = TrafficReport::where('user_id', $user->id)->whereIn('cache_key', $keys->values())->get()->keyBy('cache_key');

        return $websites->map(function ($website) use ($mappings, $keys, $reports, $expired) {
            $mapping = $mappings->get($website->id);
            $linked = $mapping && ($mapping->ga4_property_id || $mapping->gsc_site_url);
            $report = $linked ? $reports->get($keys->get($website->id)) : null;
            $timedOut = $report && in_array($report->status, ['queued', 'running'], true)
                && $report->requested_at?->lt(now()->subMinutes(15));

            return [
                'website_id' => $website->id, 'website_name' => $website->name,
                'ga4_property_id' => $mapping?->ga4_property_id, 'gsc_site_url' => $mapping?->gsc_site_url,
                'status' => ! $linked ? 'unlinked' : ($expired || $timedOut ? 'failed' : ($report?->status ?? 'missing')),
                'updated_at' => $report?->refreshed_at?->toIso8601String(),
                'error' => $expired && $linked ? 'Google reporting authorization expired or was revoked. Reconnect your reporting account in Traffic & SEO settings.'
                    : ($timedOut ? 'The report refresh did not finish. Please retry.' : $report?->error),
                'ga4' => $report?->payload['ga4'] ?? null, 'gsc' => $report?->payload['gsc'] ?? null,
                'notes' => $report?->payload['notes'] ?? [],
            ];
        })->values()->all();
    }

    public function queue(User $user, array $filters): int
    {
        $websites = $this->selected($this->websites($user), $filters['website_id']);
        $connection = $this->connection($user);
        abort_unless($connection, 409, 'Connect your reporting Google account in Traffic settings first.');
        $mappings = TrafficWebsite::where('user_id', $user->id)->where('connection_key', $connection->connection_key)
            ->whereIn('website_id', $websites->pluck('id'))->get();
        $count = 0;
        foreach ($mappings as $mapping) {
            if (! $mapping->ga4_property_id && ! $mapping->gsc_site_url) {
                continue;
            }
            $key = $this->identity($user, $connection, $mapping, $filters);
            $report = TrafficReport::firstOrCreate(['cache_key' => $key], [
                'user_id' => $user->id, 'website_id' => $mapping->website_id,
                'connection_key' => $connection->connection_key, 'mapping_key' => $mapping->mapping_key, 'app_fingerprint' => $connection->app_fingerprint,
                'ga4_property_id' => $mapping->ga4_property_id, 'gsc_site_url' => $mapping->gsc_site_url,
                'start_date' => $filters['start_date'], 'end_date' => $filters['end_date'],
            ]);
            $queued = DB::transaction(function () use ($report) {
                $locked = TrafficReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, ['queued', 'running'], true) && $locked->requested_at?->gt(now()->subMinutes(15))) {
                    return false;
                }
                $ttl = $locked->status === 'failed' ? 5 : 60;
                if ($locked->refreshed_at?->gt(now()->subMinutes($ttl))) {
                    return false;
                }
                $runKey = (string) Str::uuid();
                $locked->update(['status' => 'queued', 'run_key' => $runKey, 'requested_at' => now(), 'started_at' => null, 'error' => null]);
                TrafficRefreshReport::dispatch($locked->id, $runKey)->afterCommit();

                return true;
            });
            $count += (int) $queued;
        }

        return $count;
    }

    public function refresh(int $id, string $runKey): void
    {
        // Claim a generation exactly once; duplicate or superseded jobs cannot write.
        if (TrafficReport::whereKey($id)->where('run_key', $runKey)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }
        $report = TrafficReport::find($id);
        $connection = $report ? $this->currentConnectionFor($report) : null;
        if (! $connection) {
            $this->invalidate($id, $runKey);

            return;
        }
        $payload = ['ga4' => null, 'gsc' => null, 'notes' => []];
        $errors = [];
        foreach (['ga4' => $report->ga4_property_id, 'gsc' => $report->gsc_site_url] as $source => $resource) {
            if (! $resource) {
                continue;
            }
            try {
                $result = $this->provider->{$source}($connection, $resource, $report->start_date, $report->end_date);
                $payload[$source] = $result['data'];
                $payload['notes'] = array_merge($payload['notes'], $result['notes']);
            } catch (Throwable $exception) {
                // Never persist provider bodies, exception messages or credentials.
                $errors[] = ($source === 'ga4' ? 'Google Analytics' : 'Search Console').' could not be refreshed. Check its access and API setup in Traffic settings, then retry.';
            }
        }
        if (! $this->currentConnectionFor($report)) {
            $this->invalidate($id, $runKey);

            return;
        }
        TrafficReport::whereKey($id)->where('run_key', $runKey)->where('status', 'running')->update([
            'status' => $errors ? 'failed' : 'ready', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'error' => $errors ? implode(' ', $errors) : null, 'refreshed_at' => now(), 'run_key' => null,
        ]);
    }

    public function realtime(User $user, int $websiteId): array
    {
        $this->selected($this->websites($user), $websiteId);
        $connection = $this->connection($user);
        abort_unless($connection, 409, 'Connect your reporting Google account first.');
        $mapping = TrafficWebsite::where('user_id', $user->id)->where('website_id', $websiteId)
            ->where('connection_key', $connection->connection_key)->first();
        abort_unless($mapping?->ga4_property_id, 422, 'Link a Google Analytics property for this website first.');
        $key = 'traffic:realtime:'.$this->identity($user, $connection, $mapping, ['start_date' => '', 'end_date' => '']);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 30);
        abort_unless($lock->get(), 429, 'A realtime check is already running. Please wait a moment.');
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            try {
                $result = ['active_users' => $this->provider->realtime($connection, $mapping->ga4_property_id), 'updated_at' => now()->toIso8601String()];
            } catch (Throwable $exception) {
                abort(502, 'Realtime traffic could not be loaded. Check Google Analytics access in Traffic settings and retry.');
            }
            $current = $this->connection($user);
            $currentMapping = TrafficWebsite::whereKey($mapping->id)->first();
            abort_unless($current && $currentMapping && $currentMapping->connection_key === $current->connection_key
                && $this->identity($user, $current, $currentMapping, ['start_date' => '', 'end_date' => '']) === substr($key, strlen('traffic:realtime:')),
                409, 'Your reporting connection changed. Reload this page.');
            // Recheck website ownership after the external read as well.
            $this->selected($this->websites($user->fresh()), $websiteId);
            Cache::put($key, $result, 60);

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function currentConnectionFor(TrafficReport $report): ?TrafficConnection
    {
        $user = User::find($report->user_id);
        if (! $user || ! Website::whereKey($report->website_id)->when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))->exists()) {
            return null;
        }
        $connection = $this->connection($user);
        if (! $connection || $connection->connection_key !== $report->connection_key || $connection->app_fingerprint !== $report->app_fingerprint) {
            return null;
        }
        $mapping = TrafficWebsite::where('user_id', $user->id)->where('website_id', $report->website_id)
            ->where('connection_key', $connection->connection_key)->first();
        if (! $mapping || $mapping->mapping_key !== $report->mapping_key || $mapping->ga4_property_id !== $report->ga4_property_id || $mapping->gsc_site_url !== $report->gsc_site_url) {
            return null;
        }

        return $connection;
    }

    private function invalidate(int $id, string $runKey): void
    {
        TrafficReport::whereKey($id)->where('run_key', $runKey)->update([
            'status' => 'failed', 'payload' => null, 'error' => 'The reporting connection or website link changed. Reload this page.', 'run_key' => null,
        ]);
    }

    private function connection(User $user): ?TrafficConnection
    {
        $connection = TrafficConnection::where('user_id', $user->id)->first();

        return $this->google->isConnected($connection) ? $connection : null;
    }

    private function websites(User $user): Collection
    {
        return Website::when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))->orderBy('name')->get(['id', 'name']);
    }

    private function selected(Collection $websites, ?int $websiteId): Collection
    {
        abort_if($websiteId !== null && ! $websites->contains('id', $websiteId), 403, 'This website is not available.');

        return $websiteId === null ? $websites : $websites->where('id', $websiteId)->values();
    }

    private function identity(User $user, TrafficConnection $connection, TrafficWebsite $mapping, array $filters): string
    {
        return hash('sha256', json_encode([
            'v1', $user->id, $mapping->website_id, $connection->connection_key, $connection->app_fingerprint, $mapping->mapping_key,
            $mapping->ga4_property_id, $mapping->gsc_site_url, $filters['start_date'], $filters['end_date'],
        ], JSON_THROW_ON_ERROR));
    }
}
