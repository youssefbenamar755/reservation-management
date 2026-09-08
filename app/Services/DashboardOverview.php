<?php

namespace App\Services;

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardOverview
{
    public function build(User $user, array $filters): array
    {
        $now = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
        $period = $filters['period'] ?? 'month';
        $start = match ($period) {
            'today' => $now->startOfDay(),
            '7d' => $now->startOfDay()->subDays(6),
            '30d' => $now->startOfDay()->subDays(29),
            default => $now->startOfMonth(),
        };
        $days = (int) $start->diffInDays($now->startOfDay()) + 1;
        $end = $now->addSecond();
        $previousStart = $start->subDays($days);
        $previousEnd = $end->subDays($days);
        $window = compact('start', 'end', 'previousStart', 'previousEnd');

        $websites = DB::table('websites')->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))
            ->select('id', 'user_id', 'name', 'status', 'base_url', 'last_sync_at', 'last_webhook_at', 'wc_orders_synced_at')
            ->orderBy('name')->orderBy('id')->get()->keyBy('id');
        $selectedId = isset($filters['website_id']) ? (int) $filters['website_id'] : null;
        abort_if($selectedId !== null && ! $websites->has($selectedId), 403);
        $selected = $selectedId === null ? $websites : $websites->only([$selectedId]);
        $ids = $selected->keys()->all();

        $orders = $this->range('wc_orders', $ids, $window)
            ->selectRaw("CASE WHEN created_at_wp >= ? THEN 'current' ELSE 'previous' END AS period, DATE(created_at_wp) AS date, website_id, status, UPPER(COALESCE(NULLIF(TRIM(currency), ''), 'UNKNOWN')) AS currency_code, COUNT(*) AS count, SUM(total) AS total", [$start->toDateTimeString()])
            ->groupBy('period', 'date', 'website_id', 'status', 'currency_code')->get();
        $customerCounts = $this->range('wc_orders', $ids, $window)
            ->selectRaw("COUNT(DISTINCT CASE WHEN created_at_wp >= ? THEN NULLIF(LOWER(TRIM(customer_email)), '') END) AS current, COUNT(DISTINCT CASE WHEN created_at_wp < ? THEN NULLIF(LOWER(TRIM(customer_email)), '') END) AS previous", [$start->toDateTimeString(), $start->toDateTimeString()])->first();
        $submissions = $this->range('ff_submissions', $ids, $window)
            ->selectRaw("CASE WHEN created_at_wp >= ? THEN 'current' ELSE 'previous' END AS period, DATE(created_at_wp) AS date, website_id, COUNT(*) AS count", [$start->toDateTimeString()])
            ->groupBy('period', 'date', 'website_id')->get();

        $totals = ['orders' => ['current' => 0, 'previous' => 0], 'submissions' => ['current' => 0, 'previous' => 0]];
        $trend = [];
        for ($day = $start; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $trend[$day->toDateString()] = ['date' => $day->toDateString(), 'orders' => 0, 'submissions' => 0, 'revenue' => []];
        }
        $standardStatuses = ['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed'];
        $statusCounts = array_fill_keys($standardStatuses, 0);
        $performance = [];
        foreach ($selected as $website) {
            $performance[$website->id] = ['id' => $website->id, 'name' => $website->name, 'status' => $website->status, 'orders' => 0, 'submissions' => 0, 'revenue' => []];
        }
        $currencies = [];
        foreach ($orders as $row) {
            $count = (int) $row->count;
            $totals['orders'][$row->period] += $count;
            if ($row->period === 'current') {
                $trend[$row->date]['orders'] += $count;
                $performance[$row->website_id]['orders'] += $count;
                $statusCounts[$row->status] = ($statusCounts[$row->status] ?? 0) + $count;
            }
            if ($row->status !== 'completed') {
                continue;
            }
            $currency = $row->currency_code;
            $currencies[$currency] ??= ['current' => 0, 'previous' => 0, 'completed_orders' => 0, 'previous_completed_orders' => 0];
            // Combine the database's two-decimal aggregates as cents.
            $cents = (int) round((float) $row->total * 100);
            $currencies[$currency][$row->period] += $cents;
            $currencies[$currency][$row->period === 'current' ? 'completed_orders' : 'previous_completed_orders'] += $count;
            if ($row->period === 'current') {
                $trend[$row->date]['revenue'][$currency] = ($trend[$row->date]['revenue'][$currency] ?? 0) + $cents;
                $performance[$row->website_id]['revenue'][$currency] ??= ['currency' => $currency, 'total' => 0, 'completed_orders' => 0];
                $performance[$row->website_id]['revenue'][$currency]['total'] += $cents;
                $performance[$row->website_id]['revenue'][$currency]['completed_orders'] += $count;
            }
        }
        foreach ($submissions as $row) {
            $count = (int) $row->count;
            $totals['submissions'][$row->period] += $count;
            if ($row->period === 'current') {
                $trend[$row->date]['submissions'] += $count;
                $performance[$row->website_id]['submissions'] += $count;
            }
        }
        $summary = [];
        foreach ($totals as $name => $values) {
            $summary[$name] = $this->comparison($values['current'], $values['previous']);
        }
        $summary['customers'] = $this->comparison((int) $customerCounts->current, (int) $customerCounts->previous);
        ksort($currencies);
        $revenue = [];
        foreach ($currencies as $currency => $values) {
            $revenue[] = ['currency' => $currency] + $this->comparison($values['current'] / 100, $values['previous'] / 100) + [
                'completed_orders' => $values['completed_orders'],
                'previous_completed_orders' => $values['previous_completed_orders'],
                'average_order_value' => $values['completed_orders'] > 0 ? round($values['current'] / 100 / $values['completed_orders'], 2) : 0,
                'previous_average_order_value' => $values['previous_completed_orders'] > 0 ? round($values['previous'] / 100 / $values['previous_completed_orders'], 2) : 0,
            ];
        }
        foreach ($trend as &$day) {
            $dailyRevenue = [];
            foreach ($currencies as $currency => $values) {
                $dailyRevenue[$currency] = ($day['revenue'][$currency] ?? 0) / 100;
            }
            $day['revenue'] = (object) $dailyRevenue;
        }
        unset($day);
        foreach ($performance as &$website) {
            ksort($website['revenue']);
            $website['revenue'] = array_values(array_map(fn ($item) => array_replace($item, ['total' => $item['total'] / 100]), $website['revenue']));
        }
        unset($website);
        $extraStatuses = array_diff_key($statusCounts, array_flip($standardStatuses));
        ksort($extraStatuses);
        $statusCounts = array_intersect_key($statusCounts, array_flip($standardStatuses)) + $extraStatuses;

        [$operations, $websiteOperations] = $this->operations($ids, $now);
        $health = [];
        foreach ($selected as $website) {
            $operational = $websiteOperations[$website->id] ?? $this->emptyOperations();
            $health[] = [
                'id' => $website->id, 'name' => $website->name, 'status' => $website->status, 'base_url' => $website->base_url,
                'orders_count' => $performance[$website->id]['orders'], 'submissions_count' => $performance[$website->id]['submissions'],
                'open_orders' => $operational['open_orders'], 'failed_webhooks' => $operational['failed_webhooks'], 'queued_webhooks' => $operational['queued_webhooks'],
                'last_webhook_at' => $this->iso($website->last_webhook_at), 'last_sync_at' => $this->iso($website->last_sync_at),
                'wc_orders_synced_at' => $this->iso($website->wc_orders_synced_at),
                'last_woo_received_at' => $operational['last_woo_received_at'], 'last_fluent_received_at' => $operational['last_fluent_received_at'],
            ];
        }

        $recentOrders = DB::table('wc_orders')->whereIn('website_id', $ids)->where('created_at_wp', '>=', $start)->where('created_at_wp', '<', $end)
            ->select('id', 'website_id', 'wp_order_id', 'status', 'total', 'currency', 'customer_email', 'customer_name', 'created_at_wp')
            ->orderByDesc('created_at_wp')->orderByDesc('id')->limit(10)->get()
            ->map(function ($order) use ($user, $selected) {
                $authorizationOrder = (new WcOrder)->forceFill((array) $order)->setRelation('website',
                    (new Website)->forceFill(['id' => $order->website_id, 'user_id' => (int) $selected[$order->website_id]->user_id]));

                return [
                    'id' => $order->id, 'website_id' => $order->website_id, 'wp_order_id' => $order->wp_order_id,
                    'website_name' => $selected[$order->website_id]->name, 'status' => $order->status,
                    'can_update_status' => $user->can('update', $authorizationOrder),
                    'total' => (float) $order->total, 'currency' => strtoupper(trim($order->currency ?? '')) ?: 'UNKNOWN',
                    'customer_email' => $order->customer_email, 'customer_name' => $order->customer_name, 'created_at_wp' => $this->iso($order->created_at_wp),
                ];
            })->all();
        $recentSubmissions = DB::table('ff_submissions as submissions')->whereIn('submissions.website_id', $ids)
            ->where('submissions.created_at_wp', '>=', $start)->where('submissions.created_at_wp', '<', $end)
            ->leftJoin('ff_forms as forms', fn ($join) => $join->on('forms.website_id', '=', 'submissions.website_id')->on('forms.form_id', '=', 'submissions.form_id'))
            ->select('submissions.id', 'submissions.website_id', 'submissions.entry_id', 'submissions.form_id', 'submissions.email', 'submissions.created_at_wp', 'forms.title as form_title')
            ->orderByDesc('submissions.created_at_wp')->orderByDesc('submissions.id')->limit(10)->get()
            ->map(fn ($entry) => [
                'id' => $entry->id, 'website_id' => $entry->website_id, 'entry_id' => $entry->entry_id, 'form_id' => $entry->form_id,
                'website_name' => $selected[$entry->website_id]->name, 'email' => $entry->email,
                'form_title' => $entry->form_title ?: "Form #{$entry->form_id}", 'created_at_wp' => $this->iso($entry->created_at_wp),
            ])->all();

        return [
            'filters' => ['period' => $period, 'website_id' => $selectedId],
            'period' => [
                'label' => match ($period) {
                    'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', default => 'Month to date'
                },
                'start_date' => $start->toDateString(), 'end_date' => $now->toDateString(),
                'previous_start_date' => $previousStart->toDateString(), 'previous_end_date' => $previousEnd->subSecond()->toDateString(),
                'timezone' => config('app.timezone'),
            ],
            'websites' => $websites->map(fn ($website) => ['id' => $website->id, 'name' => $website->name, 'status' => $website->status])->values()->all(),
            'summary' => $summary, 'revenue' => $revenue, 'trend' => array_values($trend),
            'statusBreakdown' => array_map(fn ($status, $count) => ['status' => $status, 'count' => $count], array_keys($statusCounts), array_values($statusCounts)),
            'websitePerformance' => array_values($performance), 'recentOrders' => $recentOrders, 'recentSubmissions' => $recentSubmissions,
            'operations' => $operations, 'websiteHealth' => $health, 'generated_at' => $now->toIso8601String(),
        ];
    }

    private function range(string $table, array $ids, array $window): Builder
    {
        return DB::table($table)->whereIn('website_id', $ids)->where(fn ($query) => $query
            ->where(fn ($current) => $current->where('created_at_wp', '>=', $window['start'])->where('created_at_wp', '<', $window['end']))
            ->orWhere(fn ($previous) => $previous->where('created_at_wp', '>=', $window['previousStart'])->where('created_at_wp', '<', $window['previousEnd'])));
    }

    private function comparison(int|float $current, int|float $previous): array
    {
        return ['current' => $current, 'previous' => $previous, 'change' => round($current - $previous, 2), 'change_percent' => $previous != 0 ? round(($current - $previous) / abs($previous) * 100, 1) : ($current == 0 ? 0 : null)];
    }

    private function emptyOperations(): array
    {
        return ['open_orders' => 0, 'pending' => 0, 'on_hold' => 0, 'processing' => 0, 'queued_webhooks' => 0, 'failed_webhooks' => 0, 'processed_webhooks' => 0, 'failed_webhooks_24h' => 0, 'oldest_queued_at' => null, 'last_received_at' => null, 'last_woo_received_at' => null, 'last_fluent_received_at' => null];
    }

    private function operations(array $ids, CarbonImmutable $now): array
    {
        $total = $this->emptyOperations();
        $byWebsite = [];
        $orders = DB::table('wc_orders')->whereIn('website_id', $ids)->whereIn('status', ['pending', 'on-hold', 'processing'])
            ->selectRaw('website_id, status, COUNT(*) AS count')->groupBy('website_id', 'status')->get();
        foreach ($orders as $row) {
            $byWebsite[$row->website_id] ??= $this->emptyOperations();
            $key = str_replace('-', '_', $row->status);
            $total[$key] += (int) $row->count;
            $total['open_orders'] += (int) $row->count;
            $byWebsite[$row->website_id][$key] += (int) $row->count;
            $byWebsite[$row->website_id]['open_orders'] += (int) $row->count;
        }
        $webhooks = DB::table('webhook_events')->whereIn('website_id', $ids)
            ->selectRaw('website_id, source, status, COUNT(*) AS count, MAX(received_at) AS last_received_at, MIN(received_at) AS first_received_at, SUM(CASE WHEN received_at >= ? AND received_at <= ? THEN 1 ELSE 0 END) AS recent_count', [$now->subDay()->toDateTimeString(), $now->toDateTimeString()])
            ->groupBy('website_id', 'source', 'status')->get();
        foreach ($webhooks as $row) {
            $byWebsite[$row->website_id] ??= $this->emptyOperations();
            $key = $row->status.'_webhooks';
            if (array_key_exists($key, $total)) {
                $total[$key] += (int) $row->count;
                $byWebsite[$row->website_id][$key] += (int) $row->count;
            }
            $latest = $this->iso($row->last_received_at);
            foreach (['last_received_at', $row->source === 'woocommerce' ? 'last_woo_received_at' : 'last_fluent_received_at'] as $timestamp) {
                $total[$timestamp] = max($total[$timestamp], $latest);
                $byWebsite[$row->website_id][$timestamp] = max($byWebsite[$row->website_id][$timestamp], $latest);
            }
            if ($row->status === 'failed') {
                $total['failed_webhooks_24h'] += (int) $row->recent_count;
                $byWebsite[$row->website_id]['failed_webhooks_24h'] += (int) $row->recent_count;
            }
            if ($row->status === 'queued') {
                $oldest = $this->iso($row->first_received_at);
                $total['oldest_queued_at'] = $total['oldest_queued_at'] === null ? $oldest : min($total['oldest_queued_at'], $oldest);
                $byWebsite[$row->website_id]['oldest_queued_at'] = $byWebsite[$row->website_id]['oldest_queued_at'] === null ? $oldest : min($byWebsite[$row->website_id]['oldest_queued_at'], $oldest);
            }
        }

        return [$total, $byWebsite];
    }

    private function iso(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, config('app.timezone'))->toIso8601String();
    }
}
