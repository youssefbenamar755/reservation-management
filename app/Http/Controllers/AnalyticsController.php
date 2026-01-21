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
        // Generate cache key based on filters
        $cacheKey = $this->getCacheKey($request);
        
        // Cache expensive calculations for 5 minutes
        // Cache the data array, then render with Inertia
        $data = Cache::remember($cacheKey, 300, function () use ($request) {
            return $this->calculateAnalyticsData($request);
        });
        
        return Inertia::render('Analytics', $data);
    }

    /**
     * Generate cache key from request filters
     */
    private function getCacheKey(Request $request): string
    {
        $filters = [
            'start_date' => $request->input('start_date', 'default'),
            'end_date' => $request->input('end_date', 'default'),
            'website_ids' => $request->input('website_ids', []),
            'payment_status' => $request->input('payment_status', 'all'),
        ];
        
        return 'analytics:' . md5(json_encode($filters));
    }

    /**
     * Calculate analytics data
     */
    private function calculateAnalyticsData(Request $request): array
    {
        // Parse date range filters
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Build filter closure to reuse
        $applyFilters = function ($query) use ($request) {
            return $query
                ->when($request->filled('website_ids'), function ($q) use ($request) {
                    $websiteIds = is_array($request->website_ids) 
                        ? $request->website_ids 
                        : explode(',', $request->website_ids);
                    $websiteIds = array_map('intval', $websiteIds);
                    $q->whereIn('website_id', $websiteIds);
                })
                ->when($request->filled('payment_status'), function ($q) use ($request) {
                    $q->where('status', $request->payment_status);
                });
        };

        // Base query with filters
        $baseQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate]);
        
        $baseQuery = $applyFilters($baseQuery);

        // Combined stats query - get multiple metrics in one query
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

        // PayPal Fees - optimized to only select payload column and process in chunks
        $paypalFees = 0;
        (clone $baseQuery)
            ->where('status', 'completed')
            ->select('id', 'payload')
            ->chunk(100, function ($orders) use (&$paypalFees) {
                foreach ($orders as $order) {
                    $fee = $this->extractPaypalFee($order->payload ?? []);
                    $paypalFees += $fee;
                }
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
            ])
            ->toArray();

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
            ])
            ->toArray();

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
            ])
            ->toArray();

        // Orders by Country - optimized to only select payload and process in chunks
        $countryOrders = [];
        (clone $baseQuery)
            ->select('id', 'payload')
            ->chunk(100, function ($orders) use (&$countryOrders) {
                foreach ($orders as $order) {
                    $country = $this->extractCountryFromPayload($order->payload ?? []);
                    if (!empty($country)) {
                        $countryOrders[$country] = ($countryOrders[$country] ?? 0) + 1;
                    }
                }
            });

        arsort($countryOrders);
        $ordersByCountry = collect($countryOrders)
            ->take(20)
            ->map(function ($count, $country) {
                return [
                    'country' => $country,
                    'count' => $count,
                ];
            })
            ->values()
            ->toArray();

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
            ])
            ->toArray();

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
            })
            ->toArray();

        // ========== NEW ANALYTICS ==========

        // Calculate date range length for comparison period
        $dateRangeLength = $startDate->diffInDays($endDate);

        // Previous period dates (same length as current period)
        // Previous period ends right before current period starts
        $previousEndDate = (clone $startDate)->subDay()->endOfDay();
        $previousStartDate = (clone $previousEndDate)->subDays($dateRangeLength)->startOfDay();

        // Base query for previous period
        $previousBaseQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$previousStartDate, $previousEndDate]);
        $previousBaseQuery = $applyFilters($previousBaseQuery);

        // Combined previous period stats query
        $previousStatsQuery = (clone $previousBaseQuery)
            ->select(
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN total ELSE 0 END) as total_revenue')
            )
            ->first();

        $previousOrders = (int) ($previousStatsQuery->total_orders ?? 0);
        $previousRevenue = (float) ($previousStatsQuery->total_revenue ?? 0);

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

        // Revenue by Country - optimized to only select needed columns and process in chunks
        $countryRevenue = [];
        (clone $baseQuery)
            ->where('status', 'completed')
            ->select('id', 'total', 'payload')
            ->chunk(100, function ($orders) use (&$countryRevenue) {
                foreach ($orders as $order) {
                    $country = $this->extractCountryFromPayload($order->payload ?? []);
                    if (!empty($country)) {
                        $countryRevenue[$country] = ($countryRevenue[$country] ?? 0) + (float) $order->total;
                    }
                }
            });

        arsort($countryRevenue);
        $topCountryRevenue = !empty($countryRevenue) 
            ? ['country' => array_key_first($countryRevenue), 'revenue' => reset($countryRevenue)]
            : null;

        // Top Performing Country
        $topCountry = $topCountryRevenue ? [
            'country' => $topCountryRevenue['country'],
            'revenue' => $topCountryRevenue['revenue'],
            'percentage' => $totalRevenue > 0 ? ($topCountryRevenue['revenue'] / $totalRevenue) * 100 : 0,
        ] : null;

        // Peak Order Time Insight (derived from existing data)
        $peakHourData = collect($ordersByHour)->sortByDesc('count')->first();
        $peakDayData = collect($ordersByDayOfWeek)->sortByDesc('count')->first();
        $peakHourFormatted = $peakHourData ? $this->formatHour($peakHourData['hour']) : null;
        $peakDayName = $peakDayData ? $peakDayData['day'] : null;

        // Website Performance Ranking - combined query for better performance
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

        // Get previous period revenue by website
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

        $websitePerformance = $websiteStats->map(function ($website) use ($previousRevenueByWebsite) {
            $ordersCount = (int) $website->orders_count;
            $revenue = (float) $website->revenue;
            $aov = $ordersCount > 0 ? $revenue / $ordersCount : 0;

            $previousRev = $previousRevenueByWebsite->get($website->id);
            $previousRevValue = $previousRev ? (float) $previousRev->revenue : 0;
            $growthPercent = $previousRevValue > 0 
                ? (($revenue - $previousRevValue) / $previousRevValue) * 100 
                : ($revenue > 0 ? 100 : 0);

            return [
                'id' => $website->id,
                'name' => $website->name,
                'revenue' => $revenue,
                'orders' => $ordersCount,
                'aov' => $aov,
                'growth_percent' => $growthPercent,
            ];
        })
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

        // Flight Analytics
        $topDepartureAirports = $this->topDepartureAirports($request, $startDate, $endDate, $applyFilters);
        $topArrivalAirports = $this->topArrivalAirports($request, $startDate, $endDate, $applyFilters);
        $topRoutes = $this->topRoutes($request, $startDate, $endDate, $applyFilters);

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
            'topDepartureAirports' => $topDepartureAirports,
            'topArrivalAirports' => $topArrivalAirports,
            'topRoutes' => $topRoutes,
            'websites' => Website::select('id', 'name')->orderBy('name')->get()->toArray(),
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

    /**
     * Extract PayPal fee from order payload
     */
    private function extractPaypalFee(array $payload): float
    {
        $metaData = $payload['meta_data'] ?? [];
        
        foreach ($metaData as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_ppcp_paypal_fees') {
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
    }

    /**
     * Extract country from order payload (with caching for IP lookups)
     */
    private function extractCountryFromPayload(array $payload): ?string
    {
        // First try to get country from billing address (most common and fastest)
        $country = data_get($payload, 'billing.country');
        
        if (!empty($country)) {
            return $country;
        }
        
        // If no country in billing, try to get from IP address (slower, but cached)
        $ip = $this->extractIpFromPayload($payload);
        if ($ip) {
            return $this->getCountryFromIp($ip);
        }
        
        return null;
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

    /**
     * Extract IATA code from value (handles both codes and full names)
     */
    private function extractIataCode($value): ?string
    {
        if (is_array($value)) {
            $value = $this->extractStringFromArray($value);
        }
        
        if (!is_string($value)) {
            return null;
        }
        
        $value = trim($value);
        
        // PRIORITY 1: Extract code from parentheses (most common format)
        // Example: "Madrid - Barajas Airport (MAD)" -> "MAD"
        if (preg_match('/\(([A-Z]{3})\)/', $value, $matches)) {
            return $matches[1];
        }
        
        // PRIORITY 2: If it's already a 3-letter IATA code
        $valueUpper = strtoupper($value);
        if (strlen($valueUpper) === 3 && ctype_alpha($valueUpper)) {
            return $valueUpper;
        }
        
        // PRIORITY 3: Try to extract 3-letter code (uppercase letters only) with word boundaries
        if (preg_match('/\b([A-Z]{3})\b/', strtoupper($value), $matches)) {
            return $matches[0];
        }
        
        // PRIORITY 4: Try to extract any 3 consecutive uppercase letters
        if (preg_match('/[A-Z]{3}/', strtoupper($value), $matches)) {
            return $matches[0];
        }
        
        return null;
    }

    /**
     * Extract string value from array
     */
    private function extractStringFromArray($value): ?string
    {
        if (!is_array($value)) {
            return is_string($value) ? $value : null;
        }
        
        // Try to find first string value
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

    /**
     * Extract flight routes from a payload (supports both JSON flight data and form fields)
     * Returns array of routes, each route has 'departure' and 'arrival' IATA codes
     */
    private function extractFlightRoutes(array $payload): array
    {
        $routes = [];
        
        // Method 1: Try to extract from flight JSON (Amadeus/aviationstack format)
        // Check multiple possible locations for flight data
        // For Fluent Forms: payload['response'] contains form fields, but flight JSON might be in payload['response'] or payload
        $possibleFlightData = [
            $payload['response'] ?? null,  // Fluent Forms structure
            $payload['response']['data'] ?? null,
            $payload['data'] ?? null,
            $payload,  // Direct payload (for WooCommerce orders)
        ];
        
        foreach ($possibleFlightData as $flightData) {
            if (!is_array($flightData) || empty($flightData['itineraries'])) {
                continue;
            }
            
            // Process each itinerary
            foreach ($flightData['itineraries'] as $itinerary) {
                if (empty($itinerary['segments']) || !is_array($itinerary['segments'])) {
                    continue;
                }
                
                $segments = $itinerary['segments'];
                if (empty($segments)) {
                    continue;
                }
                
                // For multi-segment flights:
                // - First segment departure = route departure
                // - Last segment arrival = route arrival
                // Intermediate segments should NOT inflate route counts
                $firstSegment = $segments[0];
                $lastSegment = $segments[count($segments) - 1];
                
                $departure = $firstSegment['departure']['iataCode'] ?? null;
                $arrival = $lastSegment['arrival']['iataCode'] ?? null;
                
                if ($departure && $arrival) {
                    $routes[] = [
                        'departure' => strtoupper($departure),
                        'arrival' => strtoupper($arrival),
                    ];
                }
            }
            
            // If we found routes, break early
            if (!empty($routes)) {
                break;
            }
        }
        
        // Method 2: Fallback to form fields (flight_from, flight_to)
        // Form fields are stored in payload['response'] for Fluent Forms submissions
        if (empty($routes)) {
            $departure = null;
            $arrival = null;
            
            // Check payload['response'] first (Fluent Forms structure)
            $formFields = $payload['response'] ?? $payload;
            
            // Handle case where payload['response'] might be a JSON string
            if (is_string($formFields)) {
                $decoded = json_decode($formFields, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $formFields = $decoded;
                } else {
                    // If it's not valid JSON, try the payload itself
                    $formFields = $payload;
                }
            }
            
            // Ensure formFields is an array before iterating
            if (!is_array($formFields)) {
                // Try payload directly as fallback
                if (is_array($payload)) {
                    $formFields = $payload;
                } else {
                    return $routes;
                }
            }
            
            // Also check meta_data for WooCommerce orders
            $metaDataFields = [];
            if (isset($payload['meta_data']) && is_array($payload['meta_data'])) {
                foreach ($payload['meta_data'] as $meta) {
                    if (isset($meta['key']) && isset($meta['value'])) {
                        $metaDataFields[$meta['key']] = $meta['value'];
                    }
                }
            }
            
            // Merge meta_data fields with formFields for searching
            $allFields = array_merge($formFields, $metaDataFields);
            
            // Try various field name patterns
            foreach ($allFields as $key => $value) {
                // Skip non-form fields
                if (in_array($key, ['response', 'meta', 'order_items', 'meta_data'])) {
                    continue;
                }
                
                // Skip if value is not a string, array, or numeric (could be object, null, etc.)
                if (!is_string($value) && !is_array($value) && !is_numeric($value)) {
                    continue;
                }
                
                $keyLower = strtolower(str_replace(['_', '-', ' '], '', $key));
                
                // Flight from (origin)
                if (!$departure && (
                    strpos($keyLower, 'flightfrom') !== false || 
                    strpos($keyLower, 'from') !== false ||
                    strpos($keyLower, 'origin') !== false ||
                    strpos($keyLower, 'departurecity') !== false ||
                    strpos($keyLower, 'departurecitycode') !== false
                )) {
                    $departure = $this->extractIataCode($value);
                }
                
                // Flight to (destination)
                if (!$arrival && (
                    strpos($keyLower, 'flightto') !== false || 
                    (strpos($keyLower, 'to') !== false && strpos($keyLower, 'from') === false) ||
                    strpos($keyLower, 'destination') !== false ||
                    strpos($keyLower, 'arrivalcity') !== false ||
                    strpos($keyLower, 'arrivalcitycode') !== false
                )) {
                    $arrival = $this->extractIataCode($value);
                }
            }
            
            if ($departure && $arrival) {
                $routes[] = [
                    'departure' => strtoupper($departure),
                    'arrival' => strtoupper($arrival),
                ];
            }
        }
        
        return $routes;
    }

    /**
     * Get top departure airports
     */
    private function topDepartureAirports(Request $request, $startDate, $endDate, $applyFilters): array
    {
        $departureCounts = [];
        
        // Process Fluent Form submissions
        $submissionQuery = FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->when($request->filled('website_ids'), function ($q) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $q->whereIn('website_id', $websiteIds);
            })
            ->select('id', 'payload');
        
        $submissionQuery->chunk(100, function ($submissions) use (&$departureCounts) {
            foreach ($submissions as $submission) {
                $routes = $this->extractFlightRoutes($submission->payload ?? []);
                foreach ($routes as $route) {
                    if (!empty($route['departure'])) {
                        $departureCounts[$route['departure']] = ($departureCounts[$route['departure']] ?? 0) + 1;
                    }
                }
            }
        });
        
        // Process WooCommerce orders
        $orderQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate]);
        $orderQuery = $applyFilters($orderQuery);
        
        $orderQuery->select('id', 'payload')
            ->chunk(100, function ($orders) use (&$departureCounts) {
                foreach ($orders as $order) {
                    $routes = $this->extractFlightRoutes($order->payload ?? []);
                    foreach ($routes as $route) {
                        if (!empty($route['departure'])) {
                            $departureCounts[$route['departure']] = ($departureCounts[$route['departure']] ?? 0) + 1;
                        }
                    }
                }
            });
        
        arsort($departureCounts);
        
        return collect($departureCounts)
            ->take(20)
            ->map(function ($count, $airport) {
                return [
                    'airport' => $airport,
                    'count' => $count,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get top arrival airports
     */
    private function topArrivalAirports(Request $request, $startDate, $endDate, $applyFilters): array
    {
        $arrivalCounts = [];
        
        // Process Fluent Form submissions
        $submissionQuery = FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->when($request->filled('website_ids'), function ($q) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $q->whereIn('website_id', $websiteIds);
            })
            ->select('id', 'payload');
        
        $submissionQuery->chunk(100, function ($submissions) use (&$arrivalCounts) {
            foreach ($submissions as $submission) {
                $routes = $this->extractFlightRoutes($submission->payload ?? []);
                foreach ($routes as $route) {
                    if (!empty($route['arrival'])) {
                        $arrivalCounts[$route['arrival']] = ($arrivalCounts[$route['arrival']] ?? 0) + 1;
                    }
                }
            }
        });
        
        // Process WooCommerce orders
        $orderQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate]);
        $orderQuery = $applyFilters($orderQuery);
        
        $orderQuery->select('id', 'payload')
            ->chunk(100, function ($orders) use (&$arrivalCounts) {
                foreach ($orders as $order) {
                    $routes = $this->extractFlightRoutes($order->payload ?? []);
                    foreach ($routes as $route) {
                        if (!empty($route['arrival'])) {
                            $arrivalCounts[$route['arrival']] = ($arrivalCounts[$route['arrival']] ?? 0) + 1;
                        }
                    }
                }
            });
        
        arsort($arrivalCounts);
        
        return collect($arrivalCounts)
            ->take(20)
            ->map(function ($count, $airport) {
                return [
                    'airport' => $airport,
                    'count' => $count,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get top routes (FROM → TO)
     */
    private function topRoutes(Request $request, $startDate, $endDate, $applyFilters): array
    {
        $routeCounts = [];
        
        // Process Fluent Form submissions
        $submissionQuery = FfSubmission::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate])
            ->when($request->filled('website_ids'), function ($q) use ($request) {
                $websiteIds = is_array($request->website_ids) 
                    ? $request->website_ids 
                    : explode(',', $request->website_ids);
                $websiteIds = array_map('intval', $websiteIds);
                $q->whereIn('website_id', $websiteIds);
            })
            ->select('id', 'payload');
        
        $submissionQuery->chunk(100, function ($submissions) use (&$routeCounts) {
            foreach ($submissions as $submission) {
                $routes = $this->extractFlightRoutes($submission->payload ?? []);
                foreach ($routes as $route) {
                    if (!empty($route['departure']) && !empty($route['arrival'])) {
                        $routeKey = $route['departure'] . ' → ' . $route['arrival'];
                        $routeCounts[$routeKey] = ($routeCounts[$routeKey] ?? 0) + 1;
                    }
                }
            }
        });
        
        // Process WooCommerce orders
        $orderQuery = WcOrder::query()
            ->whereBetween('created_at_wp', [$startDate, $endDate]);
        $orderQuery = $applyFilters($orderQuery);
        
        $orderQuery->select('id', 'payload')
            ->chunk(100, function ($orders) use (&$routeCounts) {
                foreach ($orders as $order) {
                    $routes = $this->extractFlightRoutes($order->payload ?? []);
                    foreach ($routes as $route) {
                        if (!empty($route['departure']) && !empty($route['arrival'])) {
                            $routeKey = $route['departure'] . ' → ' . $route['arrival'];
                            $routeCounts[$routeKey] = ($routeCounts[$routeKey] ?? 0) + 1;
                        }
                    }
                }
            });
        
        arsort($routeCounts);
        
        return collect($routeCounts)
            ->take(20)
            ->map(function ($count, $route) {
                return [
                    'route' => $route,
                    'count' => $count,
                ];
            })
            ->values()
            ->toArray();
    }
}

