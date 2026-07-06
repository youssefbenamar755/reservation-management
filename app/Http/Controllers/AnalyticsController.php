<?php

namespace App\Http\Controllers;

use App\Models\FfSubmission;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $data = Cache::remember($this->getCacheKey($request), 300, function () use ($request) {
            return $this->calculateAnalyticsData($request);
        });

        return Inertia::render('Analytics', $data);
    }

    private function getCacheKey(Request $request): string
    {
        $websiteIds = $request->input('website_ids', []);
        if (!is_array($websiteIds)) {
            $websiteIds = explode(',', $websiteIds);
        }

        $websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));
        sort($websiteIds);

        return 'analytics:' . md5(json_encode([
            'user_id' => auth()->id(),
            'start_date' => $request->input('start_date', 'default'),
            'end_date' => $request->input('end_date', 'default'),
            'website_ids' => $websiteIds,
            'payment_status' => $request->input('payment_status', 'all'),
        ]));
    }

    private function calculateAnalyticsData(Request $request): array
    {
        $user = auth()->user();
        $userWebsiteIds = Website::when(!$user->is_admin, fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id')
            ->toArray();

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        $applyFilters = function ($query) use ($request, $userWebsiteIds) {
            return $query
                ->whereIn('website_id', $userWebsiteIds)
                ->when($request->filled('website_ids'), function ($q) use ($request, $userWebsiteIds) {
                    $websiteIds = is_array($request->website_ids)
                        ? $request->website_ids
                        : explode(',', $request->website_ids);
                    $websiteIds = array_intersect(array_map('intval', $websiteIds), $userWebsiteIds);
                    $q->whereIn('website_id', $websiteIds);
                })
                ->when($request->filled('payment_status'), fn ($q) => $q->where('status', $request->payment_status));
        };

        $baseQuery = $applyFilters(WcOrder::query()->whereBetween('created_at_wp', [$startDate, $endDate]));

        $statsQuery = (clone $baseQuery)
            ->select(
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as paid_orders'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN total ELSE 0 END) as total_revenue')
            )
            ->first();

        $totalOrders = (int) ($statsQuery->total_orders ?? 0);
        $paidOrders = (int) ($statsQuery->paid_orders ?? 0);
        $totalRevenue = (float) ($statsQuery->total_revenue ?? 0);

        $paypalFees = 0;
        $countryOrders = [];
        $countryRevenue = [];

        (clone $baseQuery)
            ->select('id', 'status', 'total', 'payload')
            ->chunkById(500, function ($orders) use (&$paypalFees, &$countryOrders, &$countryRevenue) {
                foreach ($orders as $order) {
                    $payload = $order->payload ?? [];
                    $country = $this->extractCountryFromPayload($payload);

                    if (!empty($country)) {
                        $countryOrders[$country] = ($countryOrders[$country] ?? 0) + 1;
                    }

                    if ($order->status === 'completed') {
                        $paypalFees += $this->extractPaypalFee($payload);

                        if (!empty($country)) {
                            $countryRevenue[$country] = ($countryRevenue[$country] ?? 0) + (float) $order->total;
                        }
                    }
                }
            }, 'id');

        arsort($countryOrders);
        $ordersByCountry = collect($countryOrders)
            ->take(20)
            ->map(fn ($count, $country) => ['country' => $country, 'count' => $count])
            ->values()
            ->toArray();

        arsort($countryRevenue);
        $topCountryRevenue = !empty($countryRevenue)
            ? ['country' => array_key_first($countryRevenue), 'revenue' => reset($countryRevenue)]
            : null;

        $revenueOverTime = (clone $baseQuery)
            ->select(DB::raw('DATE(created_at_wp) as date'), DB::raw('SUM(total) as revenue'))
            ->where('status', 'completed')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => ['date' => $item->date, 'revenue' => (float) $item->revenue])
            ->toArray();

        $ordersOverTime = (clone $baseQuery)
            ->select(DB::raw('DATE(created_at_wp) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => ['date' => $item->date, 'count' => (int) $item->count])
            ->toArray();

        $revenueByWebsite = (clone $baseQuery)
            ->select('websites.id', 'websites.name', DB::raw('SUM(wc_orders.total) as revenue'))
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->where('wc_orders.status', 'completed')
            ->groupBy('websites.id', 'websites.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'revenue' => (float) $item->revenue])
            ->toArray();

        $ordersByHour = (clone $baseQuery)
            ->select(DB::raw('HOUR(created_at_wp) as hour'), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($item) => ['hour' => (int) $item->hour, 'count' => (int) $item->count])
            ->toArray();

        $ordersByDayOfWeek = (clone $baseQuery)
            ->select(DB::raw('DAYOFWEEK(created_at_wp) as day_of_week'), DB::raw('COUNT(*) as count'))
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->get()
            ->map(function ($item) {
                $dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

                return [
                    'day' => $dayNames[(int) $item->day_of_week] ?? 'Unknown',
                    'day_number' => (int) $item->day_of_week,
                    'count' => (int) $item->count,
                ];
            })
            ->toArray();

        $dateRangeLength = $startDate->diffInDays($endDate);
        $previousEndDate = (clone $startDate)->subDay()->endOfDay();
        $previousStartDate = (clone $previousEndDate)->subDays($dateRangeLength)->startOfDay();
        $previousBaseQuery = $applyFilters(WcOrder::query()->whereBetween('created_at_wp', [$previousStartDate, $previousEndDate]));

        $previousStatsQuery = (clone $previousBaseQuery)
            ->select(
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN total ELSE 0 END) as total_revenue')
            )
            ->first();

        $previousOrders = (int) ($previousStatsQuery->total_orders ?? 0);
        $previousRevenue = (float) ($previousStatsQuery->total_revenue ?? 0);
        $revenueGrowthPercent = $previousRevenue > 0
            ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100
            : ($totalRevenue > 0 ? 100 : 0);
        $ordersGrowthPercent = $previousOrders > 0
            ? (($totalOrders - $previousOrders) / $previousOrders) * 100
            : ($totalOrders > 0 ? 100 : 0);

        $averageOrderValue = $paidOrders > 0 ? $totalRevenue / $paidOrders : 0;
        $netRevenue = $totalRevenue - $paypalFees;
        $feePercentage = $totalRevenue > 0 ? ($paypalFees / $totalRevenue) * 100 : 0;
        $topCountry = $topCountryRevenue ? [
            'country' => $topCountryRevenue['country'],
            'revenue' => $topCountryRevenue['revenue'],
            'percentage' => $totalRevenue > 0 ? ($topCountryRevenue['revenue'] / $totalRevenue) * 100 : 0,
        ] : null;

        $peakHourData = collect($ordersByHour)->sortByDesc('count')->first();
        $peakDayData = collect($ordersByDayOfWeek)->sortByDesc('count')->first();

        $websiteStats = (clone $baseQuery)
            ->select(
                'websites.id',
                'websites.name',
                DB::raw('COUNT(wc_orders.id) as orders_count'),
                DB::raw('SUM(CASE WHEN wc_orders.status = "completed" THEN wc_orders.total ELSE 0 END) as revenue')
            )
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->groupBy('websites.id', 'websites.name')
            ->get()
            ->keyBy('id');

        $previousRevenueByWebsite = (clone $previousBaseQuery)
            ->select('websites.id', DB::raw('SUM(wc_orders.total) as revenue'))
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->where('wc_orders.status', 'completed')
            ->groupBy('websites.id')
            ->get()
            ->keyBy('id');

        $websitePerformance = $websiteStats->map(function ($website) use ($previousRevenueByWebsite) {
            $ordersCount = (int) $website->orders_count;
            $revenue = (float) $website->revenue;
            $previousRev = $previousRevenueByWebsite->get($website->id);
            $previousRevValue = $previousRev ? (float) $previousRev->revenue : 0;

            return [
                'id' => $website->id,
                'name' => $website->name,
                'revenue' => $revenue,
                'orders' => $ordersCount,
                'aov' => $ordersCount > 0 ? $revenue / $ordersCount : 0,
                'growth_percent' => $previousRevValue > 0
                    ? (($revenue - $previousRevValue) / $previousRevValue) * 100
                    : ($revenue > 0 ? 100 : 0),
            ];
        })->sortByDesc('revenue')->values()->toArray();

        $baseSubmissionQuery = FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->whereIn('website_id', $userWebsiteIds)
            ->when($request->filled('website_ids'), function ($query) use ($request, $userWebsiteIds) {
                $websiteIds = is_array($request->website_ids)
                    ? $request->website_ids
                    : explode(',', $request->website_ids);
                $websiteIds = array_intersect(array_map('intval', $websiteIds), $userWebsiteIds);
                $query->whereIn('website_id', $websiteIds);
            });

        $formSubmissions = $baseSubmissionQuery->count();
        $submissionToOrderRate = $formSubmissions > 0 ? ($totalOrders / $formSubmissions) * 100 : 0;
        $orderToPaidRate = $totalOrders > 0 ? ($paidOrders / $totalOrders) * 100 : 0;
        $submissionToPaidRate = $formSubmissions > 0 ? ($paidOrders / $formSubmissions) * 100 : 0;
        $flightAnalytics = $this->calculateFlightAnalytics($request, $startDate, $endDate, $userWebsiteIds);

        return [
            'stats' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'paid_orders' => $paidOrders,
                'paypal_fees' => $paypalFees,
                'average_order_value' => $averageOrderValue,
                'net_revenue' => $netRevenue,
                'fee_percentage' => $feePercentage,
                'revenue_growth_percent' => $revenueGrowthPercent,
                'orders_growth_percent' => $ordersGrowthPercent,
            ],
            'revenueOverTime' => $revenueOverTime,
            'ordersOverTime' => $ordersOverTime,
            'revenueByWebsite' => $revenueByWebsite,
            'ordersByCountry' => $ordersByCountry,
            'ordersByHour' => $ordersByHour,
            'ordersByDayOfWeek' => $ordersByDayOfWeek,
            'topCountry' => $topCountry,
            'peakOrderTime' => [
                'hour' => $peakHourData ? $this->formatHour($peakHourData['hour']) : null,
                'day' => $peakDayData ? $peakDayData['day'] : null,
            ],
            'websitePerformance' => $websitePerformance,
            'conversionFunnel' => [
                'form_submissions' => $formSubmissions,
                'orders_created' => $totalOrders,
                'paid_orders' => $paidOrders,
                'submission_to_order_rate' => $submissionToOrderRate,
                'order_to_paid_rate' => $orderToPaidRate,
                'submission_to_paid_rate' => $submissionToPaidRate,
            ],
            'topDepartureAirports' => $flightAnalytics['topDepartureAirports'],
            'topArrivalAirports' => $flightAnalytics['topArrivalAirports'],
            'topRoutes' => $flightAnalytics['topRoutes'],
            'websites' => Website::when(!$user->is_admin, fn ($q) => $q->where('user_id', $user->id))
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->toArray(),
            'filters' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'website_ids' => is_array($request->input('website_ids'))
                    ? array_map('intval', $request->input('website_ids', []))
                    : [],
                'payment_status' => $request->input('payment_status'),
            ],
        ];
    }

    private function extractPayPalFee(array $payload): float
    {
        foreach ($payload['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? null) !== '_ppcp_paypal_fees') {
                continue;
            }

            $fee = data_get($meta, 'value.paypal_fee.value');
            return is_numeric($fee) ? (float) $fee : 0;
        }

        return 0;
    }

    private function extractPaypalFee(array $payload): float
    {
        return $this->extractPayPalFee($payload);
    }

    private function extractCountryFromPayload(array $payload): ?string
    {
        $country = data_get($payload, 'billing.country');
        if (!empty($country)) {
            return $country;
        }

        $ip = $this->extractIpFromPayload($payload);

        return $ip ? Cache::get("ip_country_{$ip}") : null;
    }

    private function extractIpFromPayload(array $payload): ?string
    {
        $ip = data_get($payload, 'customer_ip_address')
            ?? data_get($payload, 'customer_ip')
            ?? data_get($payload, 'ip_address');

        if (!$ip) {
            foreach ($payload['meta_data'] ?? [] as $meta) {
                $key = $meta['key'] ?? '';
                if (stripos($key, 'customer_ip') !== false || stripos($key, 'ip_address') !== false) {
                    $ip = $meta['value'] ?? null;
                    break;
                }
            }
        }

        return $ip ? trim($ip) : null;
    }

    private function formatHour(int $hour): string
    {
        $h = $hour % 12;
        if ($h === 0) {
            $h = 12;
        }

        return sprintf('%d:00 %s', $h, $hour < 12 ? 'AM' : 'PM');
    }

    private function extractIataCode($value): ?string
    {
        if (is_array($value)) {
            $value = $this->extractStringFromArray($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/\(([A-Z]{3})\)/', $value, $matches)) {
            return $matches[1];
        }

        $valueUpper = strtoupper($value);
        if (strlen($valueUpper) === 3 && ctype_alpha($valueUpper)) {
            return $valueUpper;
        }

        if (preg_match('/\b([A-Z]{3})\b/', $valueUpper, $matches)) {
            return $matches[0];
        }

        return preg_match('/[A-Z]{3}/', $valueUpper, $matches) ? $matches[0] : null;
    }

    private function extractStringFromArray($value): ?string
    {
        if (!is_array($value)) {
            return is_string($value) ? $value : null;
        }

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return $item;
            }

            if (is_array($item)) {
                $nested = $this->extractStringFromArray($item);
                if ($nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function extractFlightRoutes(array $payload): array
    {
        $routes = [];
        $possibleFlightData = [
            $payload['response'] ?? null,
            $payload['response']['data'] ?? null,
            $payload['data'] ?? null,
            $payload,
        ];

        foreach ($possibleFlightData as $flightData) {
            if (!is_array($flightData) || empty($flightData['itineraries'])) {
                continue;
            }

            foreach ($flightData['itineraries'] as $itinerary) {
                if (empty($itinerary['segments']) || !is_array($itinerary['segments'])) {
                    continue;
                }

                $segments = $itinerary['segments'];
                $firstSegment = $segments[0];
                $lastSegment = $segments[count($segments) - 1];
                $departure = $firstSegment['departure']['iataCode'] ?? null;
                $arrival = $lastSegment['arrival']['iataCode'] ?? null;

                if ($departure && $arrival) {
                    $routes[] = ['departure' => strtoupper($departure), 'arrival' => strtoupper($arrival)];
                }
            }

            if (!empty($routes)) {
                return $routes;
            }
        }

        $formFields = $payload['response'] ?? $payload;
        if (is_string($formFields)) {
            $decoded = json_decode($formFields, true);
            $formFields = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $payload;
        }

        if (!is_array($formFields)) {
            return $routes;
        }

        $metaDataFields = [];
        foreach ($payload['meta_data'] ?? [] as $meta) {
            if (isset($meta['key'], $meta['value'])) {
                $metaDataFields[$meta['key']] = $meta['value'];
            }
        }

        $departure = null;
        $arrival = null;
        foreach (array_merge($formFields, $metaDataFields) as $key => $value) {
            if (in_array($key, ['response', 'meta', 'order_items', 'meta_data'], true)) {
                continue;
            }

            if (!is_string($value) && !is_array($value) && !is_numeric($value)) {
                continue;
            }

            $keyLower = strtolower(str_replace(['_', '-', ' '], '', $key));
            if (!$departure && (str_contains($keyLower, 'flightfrom') || str_contains($keyLower, 'from') || str_contains($keyLower, 'origin') || str_contains($keyLower, 'departurecity') || str_contains($keyLower, 'departurecitycode'))) {
                $departure = $this->extractIataCode($value);
            }

            if (!$arrival && (str_contains($keyLower, 'flightto') || (str_contains($keyLower, 'to') && !str_contains($keyLower, 'from')) || str_contains($keyLower, 'destination') || str_contains($keyLower, 'arrivalcity') || str_contains($keyLower, 'arrivalcitycode'))) {
                $arrival = $this->extractIataCode($value);
            }
        }

        if ($departure && $arrival) {
            $routes[] = ['departure' => strtoupper($departure), 'arrival' => strtoupper($arrival)];
        }

        return $routes;
    }

    private function calculateFlightAnalytics(Request $request, Carbon $startDate, Carbon $endDate, array $userWebsiteIds): array
    {
        $departureCounts = [];
        $arrivalCounts = [];
        $routeCounts = [];

        FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->whereIn('website_id', $userWebsiteIds)
            ->when($request->filled('website_ids'), function ($q) use ($request, $userWebsiteIds) {
                $websiteIds = is_array($request->website_ids)
                    ? $request->website_ids
                    : explode(',', $request->website_ids);
                $websiteIds = array_intersect(array_map('intval', $websiteIds), $userWebsiteIds);
                $q->whereIn('website_id', $websiteIds);
            })
            ->select('id', 'payload')
            ->chunkById(500, function ($submissions) use (&$departureCounts, &$arrivalCounts, &$routeCounts) {
                foreach ($submissions as $submission) {
                    foreach ($this->extractFlightRoutes($submission->payload ?? []) as $route) {
                        if (!empty($route['departure'])) {
                            $departureCounts[$route['departure']] = ($departureCounts[$route['departure']] ?? 0) + 1;
                        }

                        if (!empty($route['arrival'])) {
                            $arrivalCounts[$route['arrival']] = ($arrivalCounts[$route['arrival']] ?? 0) + 1;
                        }

                        if (!empty($route['departure']) && !empty($route['arrival'])) {
                            $routeKey = $route['departure'] . ' - ' . $route['arrival'];
                            $routeCounts[$routeKey] = ($routeCounts[$routeKey] ?? 0) + 1;
                        }
                    }
                }
            }, 'id');

        arsort($departureCounts);
        arsort($arrivalCounts);
        arsort($routeCounts);

        return [
            'topDepartureAirports' => collect($departureCounts)->take(20)->map(fn ($count, $airport) => ['airport' => $airport, 'count' => $count])->values()->toArray(),
            'topArrivalAirports' => collect($arrivalCounts)->take(20)->map(fn ($count, $airport) => ['airport' => $airport, 'count' => $count])->values()->toArray(),
            'topRoutes' => collect($routeCounts)->take(20)->map(fn ($count, $route) => ['route' => $route, 'count' => $count])->values()->toArray(),
        ];
    }
}
