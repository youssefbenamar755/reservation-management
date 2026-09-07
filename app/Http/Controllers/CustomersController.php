<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomersFilterRequest;
use App\Services\CustomerListing;
use Carbon\Carbon;
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

    public function show(CustomersFilterRequest $request, string $email)
    {
        $filters = $request->filters();
        $authorizedWebsites = $this->listing->websites($request->user());
        // Route parameters are already decoded; decoding again corrupts plus addresses.
        $ordersQuery = $this->listing->orders($authorizedWebsites->pluck('id')->all(), $filters, $email);
        $aggregate = $this->listing->customers($ordersQuery, $filters)->first();
        abort_if($aggregate === null, 404, 'Customer not found in the selected filters');
        $summary = $this->listing->enrich(collect([$aggregate]), $ordersQuery, $authorizedWebsites)->first();
        $orders = (clone $ordersQuery)->with('website')->orderBy('created_at_wp', 'desc')->orderByDesc('id')->get();

        // Calculate customer metrics
        $paidOrders = $orders->where('status', 'completed');

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
                'email' => $summary['email'],
                'name' => $customerName,
                'total_orders' => $summary['orders_count'],
                'total_spent' => $summary['total_spent'],
                'average_order_value' => $summary['average_order_value'],
                'websites' => $websites,
                'country' => $summary['country'],
                'first_order_at' => $summary['first_order_at'],
                'last_order_at' => $summary['last_order_at'],
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
            'filters' => $filters,
            'returnUrl' => route('customers.index', array_filter([
                ...$filters,
                'page' => $request->validated('page'),
                'per_page' => $request->validated('per_page'),
            ], fn ($value) => $value !== null && $value !== [] && $value !== '')),
        ]);
    }
}
