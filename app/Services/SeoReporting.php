<?php

namespace App\Services;

use App\Http\Requests\SeoFilterRequest;
use App\Jobs\SeoRefreshReport;
use App\Models\SeoReport;
use App\Models\TrafficConnection;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SeoReporting
{
    private const RECONNECT = 'Google reporting authorization expired or was revoked. Reconnect your reporting account in Traffic & SEO settings.';

    private const PAGE_CHANGED = 'This page is not available in the current SEO overview. Refresh the overview and choose a returned page.';

    public function __construct(private GoogleReportingClient $google, private SeoGoogleReports $provider) {}

    public function page(User $user, array $filters): array
    {
        $websites = $this->websites($user);
        $connection = $this->connection($user);
        $expired = $connection ? null : $this->expired($user);

        return [
            'websites' => $websites->map(fn ($website) => ['id' => $website->id, 'name' => $website->name])->values()->all(),
            'filters' => $filters, 'max_end_date' => SeoFilterRequest::maxEndDate(),
            'connection' => ['connected' => $connection !== null, 'email' => $connection?->email ?? $expired?->email, 'reconnect_required' => $expired !== null],
            'reports' => $this->cachedReports($user, $this->selected($websites, $filters['website_id']), $connection, $filters),
        ];
    }

    public function reports(User $user, array $filters): array
    {
        return $this->cachedReports($user, $this->selected($this->websites($user), $filters['website_id']), $this->connection($user), $filters);
    }

    private function cachedReports(User $user, Collection $websites, ?TrafficConnection $connection, array $filters): array
    {
        $expired = $connection ? null : $this->expired($user);
        $mappingConnection = $connection ?? $expired;
        $mappings = $mappingConnection ? $this->mappings($user, $mappingConnection, $websites)->keyBy('website_id') : collect();
        $keys = $connection ? $mappings->map(fn ($mapping) => $this->identity($user, $connection, $mapping, $filters)) : collect();
        $reports = SeoReport::where('user_id', $user->id)->whereIn('cache_key', $keys->values())->get()->keyBy('cache_key');

        return $websites->map(function ($website) use ($mappings, $keys, $reports, $expired) {
            $mapping = $mappings->get($website->id);

            return $this->publicReport($website, $mapping, $reports->get($keys->get($website->id)), $expired !== null);
        })->values()->all();
    }

    private function publicReport(Website $website, ?TrafficWebsite $mapping, ?SeoReport $report, bool $expired = false): array
    {
        $linked = (bool) $mapping?->gsc_site_url;
        $timedOut = $report && in_array($report->status, ['queued', 'running'], true)
            && $report->requested_at?->lte(now()->subMinutes(15));
        $data = $linked && ! $expired && ! $timedOut && $report?->status === 'ready' ? $report->payload : null;
        if (is_array($data)) {
            unset($data['page_names']);
        }

        return [
            'website_id' => $website->id, 'website_name' => $website->name, 'gsc_site_url' => $mapping?->gsc_site_url,
            'status' => ! $linked ? 'unlinked' : ($expired || $timedOut ? 'failed' : ($report?->status ?? 'missing')),
            'updated_at' => $expired ? null : $report?->refreshed_at?->toIso8601String(),
            'error' => $expired && $linked ? self::RECONNECT : ($timedOut ? 'The SEO analysis did not finish. Please retry.' : $report?->error),
            'data' => $data,
        ];
    }

    public function detail(User $user, array $filters, string $pageUrl): array
    {
        [$website, $connection, $mapping] = $this->detailContext($user, $filters, $pageUrl);
        $report = SeoReport::where('user_id', $user->id)->where('cache_key', $this->identity($user, $connection, $mapping, $filters, $pageUrl))->first();

        return $this->publicReport($website, $mapping, $report);
    }

    public function queue(User $user, array $filters, ?string $pageUrl = null): int
    {
        if ($pageUrl !== null) {
            [, $connection, $mapping] = $this->detailContext($user, $filters, $pageUrl);
            $mappings = collect([$mapping]);
        } else {
            $websites = $this->selected($this->websites($user), $filters['website_id']);
            $connection = $this->connection($user);
            abort_unless($connection, 409, $this->expired($user) ? self::RECONNECT : 'Connect your reporting Google account in Traffic & SEO settings first.');
            $mappings = $this->mappings($user, $connection, $websites);
        }
        $count = 0;
        foreach ($mappings as $mapping) {
            if (! $mapping->gsc_site_url) {
                continue;
            }
            $report = SeoReport::firstOrCreate(['cache_key' => $this->identity($user, $connection, $mapping, $filters, $pageUrl)], [
                'user_id' => $user->id, 'website_id' => $mapping->website_id,
                'connection_key' => $connection->connection_key, 'mapping_key' => $mapping->mapping_key,
                'app_fingerprint' => $connection->app_fingerprint, 'gsc_site_url' => $mapping->gsc_site_url, 'page_url' => $pageUrl,
                'start_date' => $filters['start_date'], 'end_date' => $filters['end_date'],
            ]);
            $queued = DB::transaction(function () use ($report) {
                $locked = SeoReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, ['queued', 'running'], true) && $locked->requested_at?->gt(now()->subMinutes(15))) {
                    return false;
                }
                $ttl = $locked->status === 'failed' ? 5 : 60;
                if ($locked->refreshed_at?->gt(now()->subMinutes($ttl))) {
                    return false;
                }
                $runKey = (string) Str::uuid();
                $locked->update(['status' => 'queued', 'run_key' => $runKey, 'requested_at' => now(), 'started_at' => null, 'error' => null, 'payload' => null]);
                SeoRefreshReport::dispatch($locked->id, $runKey)->afterCommit();

                return true;
            });
            $count += (int) $queued;
        }

        return $count;
    }

    public function refresh(int $id, string $runKey): void
    {
        // Only the queued generation may claim work. No Google call happens in a database transaction.
        if (SeoReport::whereKey($id)->where('run_key', $runKey)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }
        $report = SeoReport::find($id);
        $connection = $report ? $this->currentConnectionFor($report) : null;
        if (! $connection) {
            $this->invalidate($id, $runKey);

            return;
        }
        $payload = null;
        $error = null;
        try {
            $payload = $this->provider->report($connection, $report->gsc_site_url, $report->start_date, $report->end_date, $report->page_url);
        } catch (Throwable) {
            $error = 'Search Console analysis could not be refreshed. Check its access and API setup in Traffic & SEO settings, then retry.';
        }
        // This also rechecks the current overview's exact page membership for drill-down results.
        if (! $this->currentConnectionFor($report)) {
            $this->invalidate($id, $runKey);

            return;
        }
        SeoReport::whereKey($id)->where('run_key', $runKey)->where('status', 'running')->update([
            'status' => $error ? 'failed' : 'ready', 'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'error' => $error, 'refreshed_at' => now(), 'run_key' => null,
        ]);
    }

    private function detailContext(User $user, array $filters, string $pageUrl): array
    {
        abort_unless($filters['website_id'] !== null, 422, 'Choose one website to analyse a page.');
        $website = $this->selected($this->websites($user), $filters['website_id'])->first();
        $connection = $this->connection($user);
        abort_unless($connection, 409, $this->expired($user) ? self::RECONNECT : 'Connect your reporting Google account first.');
        $mapping = TrafficWebsite::where('user_id', $user->id)->where('website_id', $website->id)
            ->where('connection_key', $connection->connection_key)->first();
        abort_unless($mapping?->gsc_site_url, 422, 'Link Search Console for this website first.');
        abort_unless($this->eligiblePage($user, $connection, $mapping, $filters, $pageUrl), 409, self::PAGE_CHANGED);

        return [$website, $connection, $mapping];
    }

    private function eligiblePage(User $user, TrafficConnection $connection, TrafficWebsite $mapping, array $filters, string $pageUrl): bool
    {
        $parts = parse_url($pageUrl);
        if (strlen($pageUrl) > 2048 || ! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $pageUrl)) {
            return false;
        }
        $overview = SeoReport::where('user_id', $user->id)->where('cache_key', $this->identity($user, $connection, $mapping, $filters))
            ->where('status', 'ready')->first();
        $pages = $overview?->payload['page_names'] ?? [];

        return is_array($pages) && in_array($pageUrl, $pages, true);
    }

    private function currentConnectionFor(SeoReport $report): ?TrafficConnection
    {
        $user = User::find($report->user_id);
        if (! $user || ! Website::whereKey($report->website_id)->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))->exists()) {
            return null;
        }
        $connection = $this->connection($user);
        if (! $connection || $connection->connection_key !== $report->connection_key || $connection->app_fingerprint !== $report->app_fingerprint) {
            return null;
        }
        $mapping = TrafficWebsite::where('user_id', $user->id)->where('website_id', $report->website_id)
            ->where('connection_key', $connection->connection_key)->first();
        if (! $mapping || $mapping->mapping_key !== $report->mapping_key || $mapping->gsc_site_url !== $report->gsc_site_url) {
            return null;
        }
        if ($report->page_url !== null && ! $this->eligiblePage($user, $connection, $mapping, ['start_date' => $report->start_date, 'end_date' => $report->end_date], $report->page_url)) {
            return null;
        }

        return $connection;
    }

    private function invalidate(int $id, string $runKey): void
    {
        SeoReport::whereKey($id)->where('run_key', $runKey)->update([
            'status' => 'failed', 'payload' => null, 'error' => 'The reporting connection, website link, or current overview changed. Reload this page.',
            'refreshed_at' => now(), 'run_key' => null,
        ]);
    }

    private function connection(User $user): ?TrafficConnection
    {
        $connection = TrafficConnection::where('user_id', $user->id)->first();

        return $this->google->isConnected($connection) ? $connection : null;
    }

    private function expired(User $user): ?TrafficConnection
    {
        return TrafficConnection::where('user_id', $user->id)->where('reconnect_required', true)->first();
    }

    private function mappings(User $user, TrafficConnection $connection, Collection $websites): Collection
    {
        return TrafficWebsite::where('user_id', $user->id)->where('connection_key', $connection->connection_key)
            ->whereIn('website_id', $websites->pluck('id'))->get();
    }

    private function websites(User $user): Collection
    {
        return Website::when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))->orderBy('name')->get(['id', 'name']);
    }

    private function selected(Collection $websites, ?int $websiteId): Collection
    {
        abort_if($websiteId !== null && ! $websites->contains('id', $websiteId), 403, 'This website is not available.');

        return $websiteId === null ? $websites : $websites->where('id', $websiteId)->values();
    }

    private function identity(User $user, TrafficConnection $connection, TrafficWebsite $mapping, array $filters, ?string $pageUrl = null): string
    {
        return hash('sha256', json_encode([
            'v1', $user->id, $mapping->website_id, $connection->connection_key, $connection->app_fingerprint, $mapping->mapping_key,
            $mapping->gsc_site_url, $filters['start_date'], $filters['end_date'], $pageUrl === null ? 'overview' : 'page',
            $pageUrl === null ? null : hash('sha256', $pageUrl),
        ], JSON_THROW_ON_ERROR));
    }
}
