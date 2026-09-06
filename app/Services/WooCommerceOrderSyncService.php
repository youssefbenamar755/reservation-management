<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WooCommerceOrderSyncService
{
    public function __construct(private WooCommerceOrderStore $orders) {}

    /**
     * Process one bounded page, persisting the cursor for the next request.
     * Full scans repair history; incremental scans start at the last completed
     * window. An interrupted full scan is also resumed by scheduled runs.
     *
     * @return array{status: string, message: string, synced: int, updated: int}
     */
    public function syncForWebsite(Website $website, int $perPage = 50, bool $full = true): array
    {
        $lock = Cache::lock("woocommerce-sync:{$website->id}", 60);
        if (! $lock->get()) {
            return ['status' => 'error', 'message' => 'A WooCommerce sync is already running for this website.', 'synced' => 0, 'updated' => 0];
        }

        $synced = 0;
        $updated = 0;

        try {
            $website->refresh();
            if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
                return ['status' => 'error', 'message' => 'WooCommerce API credentials are missing.', 'synced' => 0, 'updated' => 0];
            }

            $state = $website->wc_orders_sync_state;
            if (! $state || ($full && ! $state['full'])) {
                $state = [
                    'full' => $full,
                    'until' => now('UTC')->startOfSecond()->toIso8601String(),
                    'cursor' => ! $full && $website->wc_orders_synced_at
                        ? $website->wc_orders_synced_at->copy()->utc()->subMinutes(5)->toIso8601String()
                        : null,
                    'boundary_ids' => [],
                ];
                $website->update(['wc_orders_sync_state' => $state]);
            }

            $until = Carbon::parse($state['until'], 'UTC');
            $cursor = $state['cursor'] ? Carbon::parse($state['cursor'], 'UTC') : null;
            $boundaryIds = $state['boundary_ids'];
            $perPage = max(1, min(100, $perPage));
            $params = [
                'per_page' => $perPage,
                'page' => 1,
                'orderby' => 'modified',
                'order' => 'asc',
                'dates_are_gmt' => 'true',
                'modified_before' => $until->format('Y-m-d\TH:i:s'),
            ];
            if ($cursor) {
                // Repeat the boundary second and exclude only IDs consumed there;
                // orders sharing a timestamp are never skipped.
                $params['modified_after'] = $cursor->copy()->subSecond()->format('Y-m-d\TH:i:s');
            }
            if ($boundaryIds) {
                $params['exclude'] = $boundaryIds;
            }

            $response = Http::timeout(15)
                ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                ->acceptJson()
                ->get(rtrim($website->base_url, '/').'/wp-json/wc/v3/orders', $params);

            if (! $response->successful()) {
                throw new RuntimeException('WooCommerce API error (HTTP '.$response->status().').');
            }

            $batch = $response->json();
            if (! is_array($batch) || ! array_is_list($batch)) {
                throw new RuntimeException('WooCommerce returned an invalid order list.');
            }

            foreach ($batch as $payload) {
                if (! is_array($payload) || empty($payload['date_modified_gmt'])) {
                    throw new RuntimeException('WooCommerce returned an order without a modification date.');
                }
                $modifiedAt = Carbon::parse($payload['date_modified_gmt'], 'UTC');
                if ($modifiedAt->gt($until) || ($cursor && $modifiedAt->lt($cursor)) || in_array($payload['id'] ?? null, $boundaryIds, true)) {
                    throw new RuntimeException('WooCommerce did not honor the requested sync window.');
                }

                $result = $this->orders->store($website->id, $payload);
                $synced += (int) $result['created'];
                $updated += (int) (! $result['created'] && $result['changed']);
                if (! $cursor || ! $modifiedAt->equalTo($cursor)) {
                    $boundaryIds = [];
                }
                $cursor = $modifiedAt;
                $boundaryIds[] = $payload['id'];
            }

            if (count($batch) >= $perPage) {
                // Keep paging by modification cursor, never by a shifting offset.
                $state['cursor'] = $cursor?->toIso8601String();
                $state['boundary_ids'] = $boundaryIds;
                $website->update(['wc_orders_sync_state' => $state]);

                return ['status' => 'partial', 'message' => 'WooCommerce sync is in progress.', 'synced' => $synced, 'updated' => $updated];
            }

            // The completed checkpoint advances only when the bounded window is
            // exhausted; later remote changes belong to the next sync window.
            $website->update(['wc_orders_synced_at' => $until, 'last_sync_at' => now(), 'wc_orders_sync_state' => null]);

            return [
                'status' => 'success',
                'message' => "Synced {$synced} new WooCommerce order(s); updated {$updated} existing order(s).",
                'synced' => $synced,
                'updated' => $updated,
            ];
        } catch (\Throwable $e) {
            Log::error('[WooSync] Sync failed', ['website_id' => $website->id, 'error' => $e->getMessage()]);

            return ['status' => 'error', 'message' => 'WooCommerce sync did not complete. Retry to resume from the last successful page.', 'synced' => $synced, 'updated' => $updated];
        } finally {
            $lock->release();
        }
    }
}
