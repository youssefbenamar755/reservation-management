<?php

namespace App\Services;

use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceOrderSyncService
{
    /**
     * Incrementally sync missing WooCommerce orders for a single website.
     *
     * Only fetches orders that are NOT already in the local database:
     * - Passes `after=<latest local order date>` to the WooCommerce API
     * - Stops paginating early once it encounters an order it already has
     * - Uses firstOrCreate so existing orders are never overwritten
     *
     * @return array{status: string, message: string, synced: int}
     */
    public function syncForWebsite(Website $website, int $perPage = 50): array
    {
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
            return ['status' => 'error', 'message' => 'WooCommerce API credentials are missing.', 'synced' => 0];
        }

        $baseUrl  = rtrim($website->base_url, '/');
        $endpoint = "{$baseUrl}/wp-json/wc/v3/orders";

        // ── Incremental: only fetch orders we don't have yet ──────────────────
        $latestOrderDate = WcOrder::where('website_id', $website->id)
            ->orderByDesc('created_at_wp')
            ->value('created_at_wp');

        // 5-minute overlap buffer to handle clock skew
        $afterDate = $latestOrderDate
            ? Carbon::parse($latestOrderDate)->subMinutes(5)->toIso8601String()
            : null;

        // Pre-load existing IDs as hash set for O(1) lookups
        $existingIds = WcOrder::where('website_id', $website->id)
            ->pluck('wp_order_id')
            ->flip()
            ->all();

        Log::info('[WooSync] Starting incremental sync', [
            'website'            => $website->name,
            'after_date'         => $afterDate ?? 'all (first sync)',
            'existing_ids_count' => count($existingIds),
        ]);

        try {
            $page       = 1;
            $totalPages = 1;
            $synced     = 0;
            $caughtUp   = false;

            do {
                $params = [
                    'per_page' => $perPage,
                    'page'     => $page,
                    'orderby'  => 'date',
                    'order'    => 'desc', // newest first — stop early on known orders
                ];

                if ($afterDate) {
                    $params['after'] = $afterDate;
                }

                $response = Http::timeout(20)
                    ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                    ->acceptJson()
                    ->get($endpoint, $params);

                if (! $response->successful()) {
                    $status  = $response->status();
                    $message = data_get($response->json(), 'message') ?? $response->body();
                    return ['status' => 'error', 'message' => "WooCommerce API error (HTTP {$status}). {$message}", 'synced' => $synced];
                }

                $orders = $response->json();
                if (! is_array($orders) || empty($orders)) {
                    break;
                }

                foreach ($orders as $order) {
                    $wpOrderId = data_get($order, 'id');
                    if (! $wpOrderId) {
                        continue;
                    }

                    // Hit a known order — we're fully caught up, stop paginating
                    if (isset($existingIds[$wpOrderId])) {
                        $caughtUp = true;
                        break;
                    }

                    $paymentStatus = $this->extractPaymentStatus($order);

                    WcOrder::firstOrCreate(
                        [
                            'website_id'  => $website->id,
                            'wp_order_id' => $wpOrderId,
                        ],
                        [
                            'status'         => data_get($order, 'status', 'unknown'),
                            'payment_status' => $paymentStatus,
                            'currency'       => data_get($order, 'currency'),
                            'total'          => (string) data_get($order, 'total', '0'),
                            'customer_email' => data_get($order, 'billing.email'),
                            'customer_name'  => trim(
                                (data_get($order, 'billing.first_name') ?? '') . ' ' .
                                (data_get($order, 'billing.last_name')  ?? '')
                            ),
                            'created_at_wp'  => data_get($order, 'date_created_gmt'),
                            'updated_at_wp'  => data_get($order, 'date_modified_gmt'),
                            'payload'        => $order,
                        ]
                    );

                    $existingIds[$wpOrderId] = true;
                    $synced++;
                }

                $totalPages = (int) $response->header('X-WP-TotalPages', 1);
                $page++;

            } while (! $caughtUp && $page <= $totalPages && count($orders) > 0);

            $website->update(['last_sync_at' => now()]);

            Log::info('[WooSync] Completed', [
                'website'    => $website->name,
                'new_orders' => $synced,
                'caught_up'  => $caughtUp,
            ]);

            $message = $synced > 0
                ? "Synced {$synced} new WooCommerce order(s)."
                : 'Already up to date — no new orders found.';

            return ['status' => 'success', 'message' => $message, 'synced' => $synced];

        } catch (\Throwable $e) {
            Log::error('[WooSync] Error', [
                'website' => $website->name,
                'error'   => $e->getMessage(),
            ]);
            return ['status' => 'error', 'message' => 'Sync failed: ' . $e->getMessage(), 'synced' => 0];
        }
    }

    /**
     * Sync all active websites the given user has access to.
     *
     * @return array{total_synced: int, processed: int, failed: int, messages: string[]}
     */
    public function syncAllForUser(\App\Models\User $user, int $perPage = 50): array
    {
        $websites = Website::where('status', 'active')
            ->when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))
            ->get();

        $totalSynced = 0;
        $processed   = 0;
        $failed      = 0;
        $messages    = [];

        foreach ($websites as $website) {
            $result = $this->syncForWebsite($website, $perPage);

            if ($result['status'] === 'success') {
                $totalSynced += $result['synced'];
                $processed++;
            } else {
                $failed++;
                $messages[] = "{$website->name}: {$result['message']}";
            }
        }

        return compact('totalSynced', 'processed', 'failed', 'messages');
    }

    private function extractPaymentStatus(array $order): ?string
    {
        if (isset($order['payment_status'])) {
            return $order['payment_status'];
        }

        if (! empty(data_get($order, 'date_paid'))) {
            return 'paid';
        }

        return null;
    }
}
