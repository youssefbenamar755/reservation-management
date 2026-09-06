<?php

namespace App\Services;

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CustomerListing
{
    public const BATCH_SIZE = 250;

    /** Includes cache misses, so an unknown IP is fetched at most once per request. */
    private array $ipCountries = [];

    public function websites(User $user): Collection
    {
        return Website::query()
            ->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))
            ->select('id', 'name')->orderBy('name')->get();
    }

    public function orders(array $authorizedWebsiteIds, array $filters = []): Builder
    {
        $query = WcOrder::query()->whereIn('website_id', $authorizedWebsiteIds)
            ->whereNotNull('customer_email')->where('customer_email', '!=', '');

        if (! empty($filters['website_ids'])) {
            $query->whereIn('website_id', $filters['website_ids']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('created_at_wp', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at_wp', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }
        if (($filters['payment_status'] ?? 'all') === 'paid') {
            $query->where('status', 'completed');
        } elseif (($filters['payment_status'] ?? 'all') === 'pending') {
            $query->where('status', '!=', 'completed');
        }
        if (! empty($filters['country'])) {
            $ids = [];
            (clone $query)->select('id', 'payload')->chunkById(self::BATCH_SIZE, function ($orders) use (&$ids, $filters) {
                foreach ($this->countriesForOrders($orders) as $id => $country) {
                    if ($country === $filters['country']) {
                        $ids[] = (int) $id;
                    }
                }
            });
            // Integer literals avoid database placeholder limits on a large country match.
            $query->whereIntegerInRaw('id', $ids);
        }

        return $query;
    }

    public function customers(Builder $orders, array $filters): Builder
    {
        $query = (clone $orders)->select('customer_email')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as total_spent")
            ->selectRaw('MIN(created_at_wp) as first_order_at, MAX(created_at_wp) as last_order_at')
            ->groupBy('customer_email');

        if ($filters['min_spend'] !== null) {
            // Arithmetic gives the bound value numeric affinity on SQLite as well as MySQL.
            $query->havingRaw("SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) >= (? + 0)", [$filters['min_spend']]);
        }

        return $query->orderBy($filters['sort_by'], $filters['sort_dir'])->orderBy('customer_email');
    }

    /** Enrich only a page/export batch, using its same filtered orders. */
    public function enrich(Collection $customers, Builder $orders, Collection $websites): Collection
    {
        if ($customers->isEmpty()) {
            return collect();
        }
        $emails = $customers->pluck('customer_email')->all();
        $details = array_fill_keys($emails, ['countries' => [], 'websites' => []]);
        // Let the database associate each order with the group's representative.
        // PHP normalization cannot reproduce every database email collation.
        $customerKeys = DB::query()->selectRaw('? as customer_key', [$emails[0]]);
        foreach (array_slice($emails, 1) as $email) {
            $customerKeys->unionAll(DB::query()->selectRaw('? as customer_key', [$email]));
        }

        (clone $orders)->joinSub($customerKeys, 'customer_keys', 'wc_orders.customer_email', '=', 'customer_keys.customer_key')
            ->select('wc_orders.id', 'customer_keys.customer_key as customer_email', 'website_id', 'payload')
            ->chunkById(self::BATCH_SIZE, function ($batch) use (&$details) {
                $countries = $this->countriesForOrders($batch);
                foreach ($batch as $order) {
                    $email = $order->customer_email;
                    $details[$email]['websites'][$order->website_id] = true;
                    $country = $countries[$order->id];
                    if ($country !== null) {
                        $details[$email]['countries'][$country] = ($details[$email]['countries'][$country] ?? 0) + 1;
                    }
                }
            }, 'wc_orders.id', 'id');

        $websiteNames = $websites->pluck('name', 'id');

        return $customers->map(function ($customer) use ($details, $websiteNames) {
            $detail = $details[$customer->customer_email];
            $websiteIds = array_keys($detail['websites']);
            sort($websiteIds, SORT_NUMERIC);
            $count = (int) $customer->orders_count;
            $spent = (float) $customer->total_spent;

            return [
                'email' => $customer->customer_email,
                'orders_count' => $count,
                'total_spent' => $spent,
                // Retain the list's existing formula: completed revenue / all matching orders.
                'average_order_value' => $count > 0 ? $spent / $count : 0,
                'websites' => array_values(array_map(fn ($id) => $websiteNames[$id], $websiteIds)),
                'country' => $this->mostFrequentCountry($detail['countries']),
                'first_order_at' => $customer->first_order_at ? Carbon::parse($customer->first_order_at)->format('Y-m-d H:i:s') : null,
                'last_order_at' => $customer->last_order_at ? Carbon::parse($customer->last_order_at)->format('Y-m-d H:i:s') : null,
            ];
        });
    }

    public function uniqueCountries(array $websiteIds): array
    {
        $websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));
        sort($websiteIds, SORT_NUMERIC);
        if ($websiteIds === []) {
            return [];
        }

        // The options may lag five minutes; exact filtering/export always reads current orders.
        return Cache::remember('customer_countries_v2_'.hash('sha256', json_encode($websiteIds)), 300, function () use ($websiteIds) {
            $countries = [];
            $this->orders($websiteIds)->whereNotNull('payload')->select('id', 'payload')
                ->chunkById(self::BATCH_SIZE, function ($orders) use (&$countries) {
                    foreach ($this->countriesForOrders($orders) as $country) {
                        if ($country !== null) {
                            $countries[$country] = true;
                        }
                    }
                });
            $result = array_keys($countries);
            sort($result);

            return $result;
        });
    }

    /** Bulk country lookup shared by listing, export, and the detail history. */
    public function countriesForOrders(Collection $orders): array
    {
        $countries = [];
        $ips = [];
        foreach ($orders as $order) {
            $payload = is_array($order->payload) ? $order->payload : [];
            $countries[$order->id] = $this->validCountry(data_get($payload, 'billing.country'));
            if ($countries[$order->id] === null && ($ip = $this->publicIp($payload)) !== null) {
                $ips[$order->id] = $ip;
            }
        }
        $missing = array_values(array_filter(array_unique($ips), fn ($ip) => ! array_key_exists($ip, $this->ipCountries)));
        if ($missing !== []) {
            $cached = Cache::many(array_map(fn ($ip) => 'ip_country_'.$ip, $missing));
            foreach ($missing as $ip) {
                $this->ipCountries[$ip] = $this->validCountry($cached['ip_country_'.$ip] ?? null);
            }
        }
        foreach ($ips as $id => $ip) {
            $countries[$id] = $this->ipCountries[$ip];
        }

        return $countries;
    }

    public function customerCountry(string $email, array $websiteIds): ?string
    {
        $counts = [];
        $this->orders($websiteIds)->where('customer_email', $email)->select('id', 'payload')
            ->chunkById(self::BATCH_SIZE, function ($orders) use (&$counts) {
                foreach ($this->countriesForOrders($orders) as $country) {
                    if ($country !== null) {
                        $counts[$country] = ($counts[$country] ?? 0) + 1;
                    }
                }
            });

        return $this->mostFrequentCountry($counts);
    }

    private function mostFrequentCountry(array $counts): ?string
    {
        // Stable ties use the first country encountered in ascending order ID.
        arsort($counts);

        return array_key_first($counts);
    }

    private function validCountry(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{2,3}$/i', trim($value)) ? strtoupper(trim($value)) : null;
    }

    private function publicIp(array $payload): ?string
    {
        $ip = $payload['customer_ip_address'] ?? $payload['customer_ip'] ?? $payload['ip_address'] ?? null;
        if (! $ip) {
            foreach (is_array($payload['meta_data'] ?? null) ? $payload['meta_data'] : [] as $meta) {
                $key = is_array($meta) ? ($meta['key'] ?? null) : null;
                if (is_string($key) && (stripos($key, 'customer_ip') !== false || stripos($key, 'ip_address') !== false)) {
                    $ip = $meta['value'] ?? null;
                    break;
                }
            }
        }

        return is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? trim($ip) : null;
    }
}
