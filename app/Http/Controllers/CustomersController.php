<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomersFilterRequest;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\CustomerListing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CustomersController extends Controller
{
    public function __construct(private CustomerListing $listing) {}

    public function index(CustomersFilterRequest $request)
    {
        $filters = $request->filters();
        $websites = $this->listing->websites($request->user());
        $websiteIds = $websites->pluck('id')->all();
        $orders = $this->listing->orders($websiteIds, $filters);
        $customers = $this->listing->customers($orders, $filters)
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();
        $customers->setCollection($this->listing->enrich($customers->getCollection(), $orders, $websites));

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'websites' => $websites->toArray(),
            'countries' => $this->listing->uniqueCountries($websiteIds),
            'filters' => $filters,
        ]);
    }

    public function export(CustomersFilterRequest $request)
    {
        $filters = $request->filters();
        $websites = $this->listing->websites($request->user());
        $scope = $filters['website_ids'] === [] ? 'all-websites' : 'selected-websites';
        if (count($filters['website_ids']) === 1 && ($website = $websites->firstWhere('id', $filters['website_ids'][0]))) {
            $scope = substr(Str::slug($website->name), 0, 80) ?: 'selected-website';
        }

        return response()->streamDownload(function () use ($filters, $websites) {
            $output = fopen('php://output', 'w');
            try {
                // A live order must not move customers between export batches.
                // This applies to the next transaction only, not the connection default.
                if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                DB::transaction(function () use ($output, $filters, $websites) {
                    $orders = $this->listing->orders($websites->pluck('id')->all(), $filters);
                    $customers = $this->listing->customers($orders, $filters);
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, ['Email', 'Orders', 'Total spent', 'Average order value', 'Websites', 'Country', 'First order', 'Last order'], ',', '"', '', "\r\n");
                    $customers->chunk(CustomerListing::BATCH_SIZE, function (Collection $batch) use ($output, $orders, $websites) {
                        foreach ($this->listing->enrich($batch, $orders, $websites) as $customer) {
                            fputcsv($output, [
                                $this->csvText($customer['email']),
                                $customer['orders_count'],
                                number_format($customer['total_spent'], 2, '.', ''),
                                number_format($customer['average_order_value'], 2, '.', ''),
                                $this->csvText(implode('; ', $customer['websites'])),
                                $this->csvText($customer['country'] ?? ''),
                                $customer['first_order_at'] ?? '',
                                $customer['last_order_at'] ?? '',
                            ], ',', '"', '', "\r\n");
                        }

                        return ! connection_aborted();
                    });
                });
            } finally {
                fclose($output);
            }
        }, 'customers-'.$scope.'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvText(string $value): string
    {
        // Quoting alone does not prevent spreadsheet formulas, including after whitespace.
        return preg_match('/^[\s\x00-\x1F]*[=+\-@\t\r\n]/u', $value) ? "'".$value : $value;
    }

    public function show(Request $request, string $email)
    {
        $user = $request->user();
        $userWebsiteIds = Website::query()
            ->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))
            ->pluck('id')
            ->all();

        // Decode email if needed
        $email = urldecode($email);

        // Get all orders for this customer
        $ordersQuery = WcOrder::query()
            ->whereIn('website_id', $userWebsiteIds)
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
        $country = $this->listing->customerCountry($email, $userWebsiteIds);

        // Revenue over time (grouped by date)
        $revenueOverTime = $paidOrders
            ->groupBy(function ($order) {
                if (! $order->created_at_wp) {
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
        $orderCountries = $this->listing->countriesForOrders($orders);
        foreach ($orders as $order) {
            $orderCountry = $orderCountries[$order->id] ?? null;
            if (! empty($orderCountry) && $order->created_at_wp) {
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
