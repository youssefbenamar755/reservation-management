<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\WcOrder;
use App\Models\FfSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Get country from IP address using ipinfo.io
     */
    private function getCountryFromIp(string $ip): ?string
    {
        // Skip local/private IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        // Cache results for 24 hours to avoid API rate limits
        return Cache::remember("ip_country_{$ip}", 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)->get("https://ipinfo.io/{$ip}/country");
                
                if ($response->successful()) {
                    $countryCode = trim($response->body());
                    // Return country code if valid (2-3 letters)
                    return !empty($countryCode) && strlen($countryCode) <= 3 ? $countryCode : null;
                }
            } catch (\Exception $e) {
                // Silently fail - return null if API is unavailable
            }
            
            return null;
        });
    }

    /**
     * Extract IP address from order payload
     */
    private function extractIpFromPayload(array $payload): ?string
    {
        // Check common WooCommerce IP fields
        $ip = data_get($payload, 'customer_ip_address') 
            ?? data_get($payload, 'customer_ip')
            ?? data_get($payload, 'ip_address');

        // Also check meta_data for IP
        if (!$ip) {
            $metaData = $payload['meta_data'] ?? [];
            foreach ($metaData as $meta) {
                if (isset($meta['key']) && 
                    (stripos($meta['key'], 'customer_ip') !== false || 
                     stripos($meta['key'], 'ip_address') !== false)) {
                    $ip = $meta['value'] ?? null;
                    break;
                }
            }
        }

        return $ip ? trim($ip) : null;
    }

    public function index(Request $request)
    {
        // Parse date range filters
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Base query with filters
        $baseQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->when($request->filled('website_ids'), function ($query) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $query->whereIn('website_id', $websiteIds);
            })
            ->when($request->filled('payment_status'), function ($query) use ($request) {
                $query->where('status', $request->payment_status);
            });

        // Total Revenue (aggregated)
        $totalRevenue = (float) (clone $baseQuery)
            ->where('status', 'completed')
            ->sum('total');

        // Total Orders (aggregated)
        $totalOrders = (clone $baseQuery)->count();

        // Paid Orders (aggregated) - completed orders
        $paidOrders = (clone $baseQuery)
            ->where('status', 'completed')
            ->count();

        // PayPal Fees (extracted from meta_data) - only for completed orders
        // Path: meta_data[] -> where key == "_ppcp_paypal_fees" -> value.paypal_fee.value
        $paypalFees = (clone $baseQuery)
            ->where('status', 'completed')
            ->get()
            ->sum(function ($order) {
                $payload = $order->payload ?? [];
                $metaData = $payload['meta_data'] ?? [];
                
                // Search for _ppcp_paypal_fees in meta_data
                foreach ($metaData as $meta) {
                    if (isset($meta['key']) && $meta['key'] === '_ppcp_paypal_fees') {
                        // Extract value.paypal_fee.value
                        $value = $meta['value'] ?? null;
                        if (is_array($value) && isset($value['paypal_fee']['value'])) {
                            $paypalFeeValue = $value['paypal_fee']['value'];
                            if ($paypalFeeValue !== null && $paypalFeeValue !== '') {
                                return is_numeric($paypalFeeValue) ? (float) $paypalFeeValue : 0;
                            }
                        }
                        break;
                    }
                }
                
                return 0;
            });

        // Revenue Over Time (aggregated by date)
        $revenueOverTime = (clone $baseQuery)
            ->select(
                DB::raw('DATE(created_at_wp) as date'),
                DB::raw('SUM(total) as revenue')
            )
            ->where('status', 'completed')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'revenue' => (float) $item->revenue,
            ]);

        // Orders Over Time (aggregated by date)
        $ordersOverTime = (clone $baseQuery)
            ->select(
                DB::raw('DATE(created_at_wp) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'count' => (int) $item->count,
            ]);

        // Revenue by Website (aggregated)
        $revenueByWebsite = (clone $baseQuery)
            ->select(
                'websites.id',
                'websites.name',
                DB::raw('SUM(wc_orders.total) as revenue')
            )
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->where('wc_orders.status', 'completed')
            ->groupBy('websites.id', 'websites.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'revenue' => (float) $item->revenue,
            ]);

        // Orders by Country (aggregated)
        $ordersByCountry = (clone $baseQuery)
            ->get()
            ->map(function ($order) {
                $payload = $order->payload ?? [];
                
                // First try to get country from billing address
                $country = data_get($payload, 'billing.country');
                
                // If no country in billing, try to get from IP address
                if (empty($country)) {
                    $ip = $this->extractIpFromPayload($payload);
                    if ($ip) {
                        $country = $this->getCountryFromIp($ip);
                    }
                }
                
                return [
                    'country' => $country,
                    'order_id' => $order->id,
                ];
            })
            ->filter(function ($item) {
                // Filter out orders without a country (null, empty string, etc.)
                return !empty($item['country']);
            })
            ->groupBy('country')
            ->map(function ($orders, $country) {
                return [
                    'country' => $country,
                    'count' => $orders->count(),
                ];
            })
            ->values()
            ->sortByDesc('count')
            ->take(20)
            ->values();

        // Orders by Hour of Day (aggregated)
        $ordersByHour = (clone $baseQuery)
            ->select(
                DB::raw('HOUR(created_at_wp) as hour'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($item) => [
                'hour' => (int) $item->hour,
                'count' => (int) $item->count,
            ]);

        // Orders by Day of Week (aggregated)
        $ordersByDayOfWeek = (clone $baseQuery)
            ->select(
                DB::raw('DAYOFWEEK(created_at_wp) as day_of_week'),
                DB::raw('COUNT(*) as count')
            )
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
            });

        // ========== NEW ANALYTICS ==========

        // Calculate date range length for comparison period
        $dateRangeLength = $startDate->diffInDays($endDate);

        // Previous period dates (same length as current period)
        // Previous period ends right before current period starts
        $previousEndDate = (clone $startDate)->subDay()->endOfDay();
        $previousStartDate = (clone $previousEndDate)->subDays($dateRangeLength)->startOfDay();

        // Base query for previous period
        $previousBaseQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$previousStartDate, $previousEndDate])
            ->when($request->filled('website_ids'), function ($query) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $query->whereIn('website_id', $websiteIds);
            })
            ->when($request->filled('payment_status'), function ($query) use ($request) {
                $query->where('status', $request->payment_status);
            });

        // Previous period revenue
        $previousRevenue = (float) (clone $previousBaseQuery)
            ->where('status', 'completed')
            ->sum('total');

        // Previous period orders
        $previousOrders = (clone $previousBaseQuery)->count();

        // Revenue Growth Calculation
        $revenueGrowthPercent = $previousRevenue > 0 
            ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100 
            : ($totalRevenue > 0 ? 100 : 0);

        // Orders Growth Calculation
        $ordersGrowthPercent = $previousOrders > 0 
            ? (($totalOrders - $previousOrders) / $previousOrders) * 100 
            : ($totalOrders > 0 ? 100 : 0);

        // Average Order Value (AOV)
        $averageOrderValue = $paidOrders > 0 ? $totalRevenue / $paidOrders : 0;

        // Net Revenue (After Fees)
        $netRevenue = $totalRevenue - $paypalFees;
        $feePercentage = $totalRevenue > 0 ? ($paypalFees / $totalRevenue) * 100 : 0;

        // Revenue by Country (for top performing country)
        $revenueByCountry = (clone $baseQuery)
            ->where('status', 'completed')
            ->get()
            ->map(function ($order) {
                $payload = $order->payload ?? [];
                $country = data_get($payload, 'billing.country');
                
                if (empty($country)) {
                    $ip = $this->extractIpFromPayload($payload);
                    if ($ip) {
                        $country = $this->getCountryFromIp($ip);
                    }
                }
                
                return [
                    'country' => $country,
                    'revenue' => (float) $order->total,
                ];
            })
            ->filter(function ($item) {
                return !empty($item['country']);
            })
            ->groupBy('country')
            ->map(function ($orders, $country) {
                return [
                    'country' => $country,
                    'revenue' => $orders->sum('revenue'),
                ];
            })
            ->values()
            ->sortByDesc('revenue')
            ->first();

        // Top Performing Country
        $topCountry = $revenueByCountry ? [
            'country' => $revenueByCountry['country'],
            'revenue' => $revenueByCountry['revenue'],
            'percentage' => $totalRevenue > 0 ? ($revenueByCountry['revenue'] / $totalRevenue) * 100 : 0,
        ] : null;

        // Peak Order Time Insight (derived from existing data)
        $peakHour = $ordersByHour->sortByDesc('count')->first();
        $peakDay = $ordersByDayOfWeek->sortByDesc('count')->first();
        $peakHourFormatted = $peakHour ? $this->formatHour($peakHour['hour']) : null;
        $peakDayName = $peakDay ? $peakDay['day'] : null;

        // Website Performance Ranking
        $websitePerformance = [];
        $websiteRevenueData = $revenueByWebsite->keyBy('id');
        
        // Get orders by website for AOV calculation
        $ordersByWebsite = (clone $baseQuery)
            ->select(
                'websites.id',
                'websites.name',
                DB::raw('COUNT(wc_orders.id) as orders_count')
            )
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->groupBy('websites.id', 'websites.name')
            ->get()
            ->keyBy('id');

        // Get previous period revenue by website for growth calculation
        $previousRevenueByWebsite = (clone $previousBaseQuery)
            ->select(
                'websites.id',
                DB::raw('SUM(wc_orders.total) as revenue')
            )
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->where('wc_orders.status', 'completed')
            ->groupBy('websites.id')
            ->get()
            ->keyBy('id');

        foreach ($websiteRevenueData as $website) {
            $orders = $ordersByWebsite->get($website['id']);
            $ordersCount = $orders ? (int) $orders->orders_count : 0;
            $revenue = $website['revenue'];
            $aov = $ordersCount > 0 ? $revenue / $ordersCount : 0;

            $previousRev = $previousRevenueByWebsite->get($website['id']);
            $previousRevValue = $previousRev ? (float) $previousRev->revenue : 0;
            $growthPercent = $previousRevValue > 0 
                ? (($revenue - $previousRevValue) / $previousRevValue) * 100 
                : ($revenue > 0 ? 100 : 0);

            $websitePerformance[] = [
                'id' => $website['id'],
                'name' => $website['name'],
                'revenue' => $revenue,
                'orders' => $ordersCount,
                'aov' => $aov,
                'growth_percent' => $growthPercent,
            ];
        }

        // Sort by revenue descending
        $websitePerformance = collect($websitePerformance)
            ->sortByDesc('revenue')
            ->values()
            ->toArray();

        // Conversion Funnel: Form Submissions → Orders Created → Paid Orders
        $baseSubmissionQuery = FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->when($request->filled('website_ids'), function ($query) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $query->whereIn('website_id', $websiteIds);
            });

        $formSubmissions = $baseSubmissionQuery->count();
        $ordersCreated = $totalOrders;
        $paidOrdersCount = $paidOrders;

        $submissionToOrderRate = $formSubmissions > 0 
            ? ($ordersCreated / $formSubmissions) * 100 
            : 0;
        $orderToPaidRate = $ordersCreated > 0 
            ? ($paidOrdersCount / $ordersCreated) * 100 
            : 0;
        $submissionToPaidRate = $formSubmissions > 0 
            ? ($paidOrdersCount / $formSubmissions) * 100 
            : 0;

        return Inertia::render('Analytics', [
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
                'hour' => $peakHourFormatted,
                'day' => $peakDayName,
            ],
            'websitePerformance' => $websitePerformance,
            'conversionFunnel' => [
                'form_submissions' => $formSubmissions,
                'orders_created' => $ordersCreated,
                'paid_orders' => $paidOrdersCount,
                'submission_to_order_rate' => $submissionToOrderRate,
                'order_to_paid_rate' => $orderToPaidRate,
                'submission_to_paid_rate' => $submissionToPaidRate,
            ],
            'websites' => Website::select('id', 'name')->orderBy('name')->get(),
            'filters' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'website_ids' => is_array($request->input('website_ids')) 
                    ? array_map('intval', $request->input('website_ids', []))
                    : [],
                'payment_status' => $request->input('payment_status'),
            ],
        ]);
    }

    /**
     * Format hour for display (e.g., 19 -> "7:00 PM")
     */
    private function formatHour(int $hour): string
    {
        $h = $hour % 12;
        if ($h == 0) $h = 12;
        $ampm = $hour < 12 ? 'AM' : 'PM';
        return sprintf('%d:00 %s', $h, $ampm);
    }
}

