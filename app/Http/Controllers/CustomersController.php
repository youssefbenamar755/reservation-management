<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\WcOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CustomersController extends Controller
{
    /**
     * Get unique countries from all orders
     */
    private function getUniqueCountries(): array
    {
        $allCountries = [];
        WcOrder::whereNotNull('customer_email')
            ->whereNotNull('payload')
            ->select('payload')
            ->chunk(100, function ($orders) use (&$allCountries) {
                foreach ($orders as $order) {
                    $country = $this->extractCountryFromPayload($order->payload ?? []);
                    if (!empty($country)) {
                        $allCountries[$country] = true;
                    }
                }
            });
        $uniqueCountries = array_keys($allCountries);
        sort($uniqueCountries);
        return $uniqueCountries;
    }

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

    /**
     * Extract country from order payload (with IP lookup fallback)
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
     * Get country for a customer (most frequent or first order country)
     */
    private function getCustomerCountry(string $email): ?string
    {
        // Get all orders for this customer
        $orders = WcOrder::where('customer_email', $email)
            ->whereNotNull('payload')
            ->select('payload')
            ->get();

        $countries = [];
        foreach ($orders as $order) {
            $country = $this->extractCountryFromPayload($order->payload ?? []);
            if (!empty($country)) {
                $countries[] = $country;
            }
        }

        if (empty($countries)) {
            return null;
        }

        // Return most frequent country, or first if tied
        $countryCounts = array_count_values($countries);
        arsort($countryCounts);
        return array_key_first($countryCounts);
    }

    public function index(Request $request)
    {
        // Parse date range filters
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : null;
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : null;

        // Build base query for filtering orders
        $orderQuery = WcOrder::query()
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '');

        // Apply date range filter
        if ($startDate && $endDate) {
            $orderQuery->whereBetween('created_at_wp', [$startDate, $endDate]);
        }

        // Apply website filter
        if ($request->filled('website_ids')) {
            $websiteIds = is_array($request->website_ids) 
                ? $request->website_ids 
                : explode(',', $request->website_ids);
            $websiteIds = array_map('intval', $websiteIds);
            $orderQuery->whereIn('website_id', $websiteIds);
        }

        // Apply order status filter
        $paymentStatusFilter = $request->input('payment_status', 'all');
        if ($paymentStatusFilter === 'paid') {
            $orderQuery->where('status', 'completed');
        } elseif ($paymentStatusFilter === 'pending') {
            $orderQuery->where('status', '!=', 'completed');
        }
        // 'all' means no additional filter

        // Apply country filter if provided (filter orders by country in payload)
        $countryFilter = $request->input('country');
        if ($countryFilter) {
            // Get orders matching country filter
            $countryOrderIds = [];
            (clone $orderQuery)
                ->select('id', 'payload')
                ->chunk(100, function ($orders) use (&$countryOrderIds, $countryFilter) {
                    foreach ($orders as $order) {
                        $country = $this->extractCountryFromPayload($order->payload ?? []);
                        if ($country === $countryFilter) {
                            $countryOrderIds[] = $order->id;
                        }
                    }
                });
            
            if (empty($countryOrderIds)) {
                // No orders match country filter
                $uniqueCountries = $this->getUniqueCountries();
                
                return Inertia::render('Customers/Index', [
                    'customers' => [
                        'data' => [],
                        'links' => [],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => 15,
                            'total' => 0,
                        ],
                    ],
                    'websites' => Website::select('id', 'name')->orderBy('name')->get()->toArray(),
                    'countries' => $uniqueCountries,
                    'filters' => [
                        'start_date' => $startDate?->format('Y-m-d'),
                        'end_date' => $endDate?->format('Y-m-d'),
                        'website_ids' => is_array($request->input('website_ids')) 
                            ? array_map('intval', $request->input('website_ids', []))
                            : [],
                        'country' => $countryFilter,
                        'min_spend' => $request->input('min_spend'),
                        'payment_status' => $paymentStatusFilter,
                    ],
                ]);
            }
            
            // Filter order query to only include orders matching country
            $orderQuery->whereIn('id', $countryOrderIds);
        }

        // Get customer emails that match filters
        $customerEmails = (clone $orderQuery)
            ->select('customer_email')
            ->distinct()
            ->pluck('customer_email')
            ->toArray();

        if (empty($customerEmails)) {
            // Get unique countries for filter dropdown
            $uniqueCountries = $this->getUniqueCountries();
            
            return Inertia::render('Customers/Index', [
                'customers' => [
                    'data' => [],
                    'links' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                    ],
                ],
                'websites' => Website::select('id', 'name')->orderBy('name')->get()->toArray(),
                'countries' => $uniqueCountries,
                'filters' => [
                    'start_date' => $startDate?->format('Y-m-d'),
                    'end_date' => $endDate?->format('Y-m-d'),
                    'website_ids' => is_array($request->input('website_ids')) 
                        ? array_map('intval', $request->input('website_ids', []))
                        : [],
                    'country' => $request->input('country'),
                    'min_spend' => $request->input('min_spend'),
                    'payment_status' => $paymentStatusFilter,
                ],
            ]);
        }

        // Build aggregated customer query
        $customerQuery = WcOrder::query()
            ->select([
                'customer_email',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN total ELSE 0 END) as total_spent'),
                DB::raw('MIN(created_at_wp) as first_order_at'),
                DB::raw('MAX(created_at_wp) as last_order_at'),
                DB::raw('GROUP_CONCAT(DISTINCT website_id) as website_ids'),
            ])
            ->whereIn('customer_email', $customerEmails)
            ->groupBy('customer_email');

        // Apply minimum spend filter
        if ($request->filled('min_spend')) {
            $minSpend = (float) $request->input('min_spend');
            $customerQuery->havingRaw('SUM(CASE WHEN status = "completed" THEN total ELSE 0 END) >= ?', [$minSpend]);
        }

        // Apply sorting
        $sortBy = $request->input('sort_by', 'last_order_at');
        $sortDir = $request->input('sort_dir', 'desc');
        
        if ($sortBy === 'orders_count') {
            $customerQuery->orderBy('orders_count', $sortDir);
        } elseif ($sortBy === 'total_spent') {
            $customerQuery->orderBy('total_spent', $sortDir);
        } elseif ($sortBy === 'last_order_at') {
            $customerQuery->orderBy('last_order_at', $sortDir);
        } else {
            $customerQuery->orderBy('last_order_at', 'desc');
        }

        // Get all results first (before pagination) to transform
        $allCustomers = $customerQuery->get();

        // Transform results to include additional data
        $transformedCustomers = $allCustomers->map(function ($customer) {
            $email = $customer->customer_email;
            
            // Get website names
            $websiteIds = explode(',', $customer->website_ids ?? '');
            $websiteIds = array_filter(array_map('intval', $websiteIds));
            $websites = Website::whereIn('id', $websiteIds)
                ->select('id', 'name')
                ->get()
                ->pluck('name')
                ->toArray();

            // Calculate AOV
            $totalSpent = (float) ($customer->total_spent ?? 0);
            $ordersCount = (int) ($customer->orders_count ?? 0);
            $aov = $ordersCount > 0 ? ($totalSpent / $ordersCount) : 0;

            // Get country (most frequent or first order)
            $country = $this->getCustomerCountry($email);

            // Handle dates - they come as strings from DB::raw(), so parse them
            $firstOrderAt = $customer->first_order_at 
                ? (is_string($customer->first_order_at) 
                    ? Carbon::parse($customer->first_order_at)->format('Y-m-d H:i:s')
                    : $customer->first_order_at->format('Y-m-d H:i:s'))
                : null;
            
            $lastOrderAt = $customer->last_order_at
                ? (is_string($customer->last_order_at)
                    ? Carbon::parse($customer->last_order_at)->format('Y-m-d H:i:s')
                    : $customer->last_order_at->format('Y-m-d H:i:s'))
                : null;

            return [
                'email' => $email,
                'orders_count' => $ordersCount,
                'total_spent' => $totalSpent,
                'average_order_value' => $aov,
                'websites' => $websites,
                'country' => $country,
                'first_order_at' => $firstOrderAt,
                'last_order_at' => $lastOrderAt,
            ];
        });

        // Paginate transformed results
        $perPage = (int) $request->input('per_page', 15);
        $currentPage = (int) $request->input('page', 1);
        $total = $transformedCustomers->count();
        $items = $transformedCustomers->forPage($currentPage, $perPage);
        
        $paginatedCustomers = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
        
        // Ensure paginator has proper structure for Inertia
        $paginatedCustomers->withQueryString();

        // Get unique countries for filter dropdown
        $uniqueCountries = $this->getUniqueCountries();

        return Inertia::render('Customers/Index', [
            'customers' => $paginatedCustomers,
            'websites' => Website::select('id', 'name')->orderBy('name')->get()->toArray(),
            'countries' => $uniqueCountries,
            'filters' => [
                'start_date' => $startDate?->format('Y-m-d'),
                'end_date' => $endDate?->format('Y-m-d'),
                'website_ids' => is_array($request->input('website_ids')) 
                    ? array_map('intval', $request->input('website_ids', []))
                    : [],
                'country' => $request->input('country'),
                'min_spend' => $request->input('min_spend'),
                'payment_status' => $paymentStatusFilter,
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ],
        ]);
    }

    public function show(Request $request, string $email)
    {
        // Decode email if needed
        $email = urldecode($email);

        // Get all orders for this customer
        $ordersQuery = WcOrder::query()
            ->with('website')
            ->where('customer_email', $email);

        // Apply date range filter if provided
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            $ordersQuery->whereBetween('created_at_wp', [$startDate, $endDate]);
        }

        $orders = $ordersQuery->orderBy('created_at_wp', 'desc')->get();

        if ($orders->isEmpty()) {
            abort(404, 'Customer not found');
        }

        // Calculate customer metrics
        $totalOrders = $orders->count();
        $paidOrders = $orders->where('status', 'completed');
        $totalSpent = $paidOrders->sum('total');
        $averageOrderValue = $paidOrders->count() > 0 ? ($totalSpent / $paidOrders->count()) : 0;

        // Get unique websites
        $websites = $orders->pluck('website')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($website) => [
                'id' => $website->id,
                'name' => $website->name,
            ])
            ->toArray();

        // Get country (most frequent)
        $country = $this->getCustomerCountry($email);

        // Revenue over time (grouped by date)
        $revenueOverTime = $paidOrders
            ->groupBy(function ($order) {
                if (!$order->created_at_wp) {
                    return null;
                }
                return is_string($order->created_at_wp)
                    ? Carbon::parse($order->created_at_wp)->format('Y-m-d')
                    : $order->created_at_wp->format('Y-m-d');
            })
            ->filter(function ($value, $key) {
                return $key !== null; // Remove null keys
            })
            ->map(function ($dayOrders, $date) {
                return [
                    'date' => $date,
                    'revenue' => (float) $dayOrders->sum('total'),
                ];
            })
            ->values()
            ->sortBy('date')
            ->toArray();

        // Website breakdown
        $websiteBreakdown = $orders
            ->groupBy('website_id')
            ->map(function ($websiteOrders, $websiteId) {
                $website = $websiteOrders->first()->website;
                $paidWebsiteOrders = $websiteOrders->where('status', 'completed');
                
                return [
                    'website_id' => $websiteId,
                    'website_name' => $website?->name ?? 'Unknown',
                    'orders_count' => $websiteOrders->count(),
                    'total_spent' => (float) $paidWebsiteOrders->sum('total'),
                ];
            })
            ->values()
            ->toArray();

        // Country history (if available in payloads)
        $countryHistory = [];
        foreach ($orders as $order) {
            $orderCountry = $this->extractCountryFromPayload($order->payload ?? []);
            if (!empty($orderCountry) && $order->created_at_wp) {
                $date = is_string($order->created_at_wp) 
                    ? Carbon::parse($order->created_at_wp)->format('Y-m-d')
                    : $order->created_at_wp->format('Y-m-d');
                $countryHistory[] = [
                    'date' => $date,
                    'country' => $orderCountry,
                ];
            }
        }

        // Get customer name (from first order)
        $customerName = $orders->first()->customer_name;

        return Inertia::render('Customers/Show', [
            'customer' => [
                'email' => $email,
                'name' => $customerName,
                'total_orders' => $totalOrders,
                'total_spent' => $totalSpent,
                'average_order_value' => $averageOrderValue,
                'websites' => $websites,
                'country' => $country,
                'first_order_at' => ($firstOrder = $orders->min('created_at_wp')) 
                    ? (is_string($firstOrder) 
                        ? Carbon::parse($firstOrder)->format('Y-m-d H:i:s')
                        : $firstOrder->format('Y-m-d H:i:s'))
                    : null,
                'last_order_at' => ($lastOrder = $orders->max('created_at_wp'))
                    ? (is_string($lastOrder)
                        ? Carbon::parse($lastOrder)->format('Y-m-d H:i:s')
                        : $lastOrder->format('Y-m-d H:i:s'))
                    : null,
            ],
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'wp_order_id' => $order->wp_order_id,
                'website_name' => $order->website->name ?? 'Unknown',
                'status' => $order->status,
                'total' => $order->total,
                'currency' => $order->currency,
                'created_at_wp' => $order->created_at_wp?->format('Y-m-d H:i:s'),
            ])->toArray(),
            'revenueOverTime' => $revenueOverTime,
            'websiteBreakdown' => $websiteBreakdown,
            'countryHistory' => $countryHistory,
        ]);
    }
}

