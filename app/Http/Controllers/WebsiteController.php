<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\WcOrder;
use App\Jobs\SyncFluentSubmissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WebsiteController extends Controller
{
    public function index()
    {
        $websites = Website::query()
            ->select([
                'id',
                'name',
                'slug',
                'base_url',
                'timezone',
                'status',
                'last_sync_at',
                'last_webhook_at',
                'created_at',
            ])
            ->latest()
            ->get()
            ->map(fn (Website $website) => [
                'id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
                'base_url' => $website->base_url,
                'timezone' => $website->timezone,
                'status' => $website->status,
                'last_sync_at' => optional($website->last_sync_at)->toISOString(),
                'last_webhook_at' => optional($website->last_webhook_at)->toISOString(),
                'created_at' => optional($website->created_at)->toISOString(),
                'webhooks' => [
                    'woocommerce' => url("/api/v1/webhooks/woocommerce/{$website->slug}"),
                    // tokenized URL for Fluent Forms (required by our controller)
                    'fluentforms' => url("/api/v1/webhooks/fluentforms/{$website->slug}") . '?token=' . $website->webhook_secret,
                ],
            ]);

        return Inertia::render('Websites/Index', [
            'websites' => $websites,
        ]);
    }

    public function create()
    {
        return Inertia::render('Websites/Create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'wc_consumer_key' => 'nullable|string',
            'wc_consumer_secret' => 'nullable|string',
            'ff_username' => 'nullable|string',
            'ff_app_password' => 'nullable|string',
            'timezone' => 'nullable|string|max:255',
        ]);

        $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
        $data['webhook_secret'] = Str::random(40);
        $data['status'] = 'active';
        $data['timezone'] = $data['timezone'] ?? 'UTC';

        Website::create($data);

        return redirect()->route('websites.index')
            ->with('success', 'Website added successfully');
    }

    public function show(Website $website)
    {
        return redirect()->route('websites.edit', $website);
    }

    public function edit(Website $website)
    {
        return Inertia::render('Websites/Edit', [
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
                'base_url' => $website->base_url,
                'status' => $website->status,
                'timezone' => $website->timezone,
                'last_webhook_at' => optional($website->last_webhook_at)->toISOString(),
                'last_sync_at' => optional($website->last_sync_at)->toISOString(),
                'webhooks' => [
                    'woocommerce' => url("/api/v1/webhooks/woocommerce/{$website->slug}"),
                    'fluentforms' => url("/api/v1/webhooks/fluentforms/{$website->slug}") . '?token=' . $website->webhook_secret,
                ],
            ],
        ]);
    }

    public function update(Request $request, Website $website)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'wc_consumer_key' => 'nullable|string',
            'wc_consumer_secret' => 'nullable|string',
            'ff_username' => 'nullable|string',
            'ff_app_password' => 'nullable|string',
            'status' => 'required|in:active,paused',
            'timezone' => 'nullable|string|max:255',
        ]);

        // Only update credentials if they are provided (not empty)
        if (empty($data['wc_consumer_key'])) {
            unset($data['wc_consumer_key']);
        }
        if (empty($data['wc_consumer_secret'])) {
            unset($data['wc_consumer_secret']);
        }
        if (empty($data['ff_username'])) {
            unset($data['ff_username']);
        }
        if (empty($data['ff_app_password'])) {
            unset($data['ff_app_password']);
        }

        $website->update($data);

        return redirect()->route('websites.index')
            ->with('success', 'Website updated');
    }

    public function destroy(Website $website)
    {
        $website->delete();

        return redirect()->route('websites.index')
            ->with('success', 'Website removed');
    }

    public function testWooCommerce(Request $request, Website $website)
    {
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
            return back()->with('error', 'WooCommerce API credentials are missing. Please save Consumer Key and Secret first.');
        }

        $baseUrl = rtrim($website->base_url, '/');
        $endpoint = "{$baseUrl}/wp-json/wc/v3/system_status";

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($endpoint, [
                    'consumer_key' => $website->wc_consumer_key,
                    'consumer_secret' => $website->wc_consumer_secret,
                ]);

            if ($response->successful()) {
                $woo = $response->json();
                $version = data_get($woo, 'environment.version');

                return back()->with(
                    'success',
                    $version
                        ? "WooCommerce API connected (WooCommerce v{$version})."
                        : 'WooCommerce API connected.'
                );
            }

            $status = $response->status();
            $message = data_get($response->json(), 'message') ?? $response->body();

            return back()->with('error', "WooCommerce API test failed (HTTP {$status}). {$message}");
        } catch (\Throwable $e) {
            return back()->with('error', 'WooCommerce API test failed: ' . $e->getMessage());
        }
    }

    public function testFluentForms(Request $request, Website $website)
    {
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            return back()->with('error', 'Fluent Forms authentication credentials are missing. Please save Username and Application Password first.');
        }

        $baseUrl = rtrim($website->base_url, '/');
        $endpoint = "{$baseUrl}/wp-json/fluentform/v1/forms";

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($website->ff_username, $website->ff_app_password)
                ->acceptJson()
                ->get($endpoint);

            if ($response->successful()) {
                $forms = $response->json();
                $formCount = is_array($forms) ? count($forms) : 0;
                
                // If forms exist, test fetching submissions from first form
                $submissionInfo = '';
                if ($formCount > 0 && is_array($forms) && isset($forms[0]['id'])) {
                    $firstFormId = $forms[0]['id'];
                    $submissionsEndpoint = "{$baseUrl}/wp-json/fluentform/v1/forms/{$firstFormId}/submissions";
                    
                    try {
                        $subResponse = Http::timeout(10)
                            ->withBasicAuth($website->ff_username, $website->ff_app_password)
                            ->acceptJson()
                            ->get($submissionsEndpoint, ['per_page' => 1]);
                        
                        if ($subResponse->successful()) {
                            $subData = $subResponse->json();
                            $subCount = 0;
                            
                            if (is_array($subData)) {
                                if (isset($subData['data'])) {
                                    $subCount = is_array($subData['data']) ? count($subData['data']) : 0;
                                } elseif (isset($subData['submissions'])) {
                                    $subCount = is_array($subData['submissions']) ? count($subData['submissions']) : 0;
                                } else {
                                    $subCount = count($subData);
                                }
                            }
                            
                            $submissionInfo = " Tested form #{$firstFormId}: " . ($subCount > 0 ? "found {$subCount} submission(s)" : "no submissions found");
                        }
                    } catch (\Throwable $e) {
                        $submissionInfo = " (Could not test submissions endpoint: " . $e->getMessage() . ")";
                    }
                }

                return back()->with(
                    'success',
                    $formCount > 0
                        ? "Fluent Forms API connected ({$formCount} form(s) found).{$submissionInfo}"
                        : 'Fluent Forms API connected (no forms found).'
                );
            }

            $status = $response->status();
            $message = data_get($response->json(), 'message') ?? $response->body();

            return back()->with('error', "Fluent Forms API test failed (HTTP {$status}). {$message}");
        } catch (\Throwable $e) {
            return back()->with('error', 'Fluent Forms API test failed: ' . $e->getMessage());
        }
    }

    public function syncWooCommerceOrders(Request $request, Website $website)
    {
        abort_if($website->status !== 'active', 403, 'Website is not active');

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($request->input('per_page', 50));

        $result = $this->syncOrdersForWebsite($website, $perPage);

        return back()->with($result['status'], $result['message']);
    }

    public function syncAllWooCommerceOrders(Request $request)
    {
        $websites = Website::where('status', 'active')->get();
        
        if ($websites->isEmpty()) {
            return back()->with('error', 'No active websites found.');
        }

        $totalSynced = 0;
        $processedWebsites = 0;
        $failedWebsites = 0;
        $messages = [];

        foreach ($websites as $website) {
            $result = $this->syncOrdersForWebsite($website, 20); // Default per page for bulk sync
            
            if ($result['status'] === 'success') {
                // Extract number from message "Synced X WooCommerce orders."
                if (preg_match('/Synced (\d+) WooCommerce orders/', $result['message'], $matches)) {
                    $totalSynced += (int)$matches[1];
                }
                $processedWebsites++;
            } else {
                $failedWebsites++;
                $messages[] = "{$website->name}: {$result['message']}";
            }
        }

        if ($failedWebsites === 0) {
            return back()->with('success', "Synced total {$totalSynced} orders from {$processedWebsites} websites.");
        } else {
            $errorMsg = "Synced {$totalSynced} orders from {$processedWebsites} websites. Failed: {$failedWebsites}. " . implode(' ', array_slice($messages, 0, 3));
            return back()->with('warning', $errorMsg);
        }
    }

    private function syncOrdersForWebsite(Website $website, int $perPage = 50): array
    {
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
            return ['status' => 'error', 'message' => 'WooCommerce API credentials are missing.'];
        }

        $baseUrl = rtrim($website->base_url, '/');
        $endpoint = "{$baseUrl}/wp-json/wc/v3/orders";

        try {
            $allOrders = [];
            $page = 1;
            $totalPages = 1;

            // Fetch all pages of orders
            do {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->get($endpoint, [
                        'consumer_key' => $website->wc_consumer_key,
                        'consumer_secret' => $website->wc_consumer_secret,
                        'per_page' => $perPage,
                        'page' => $page,
                        'orderby' => 'date',
                        'order' => 'desc',
                    ]);

                if (! $response->successful()) {
                    $status = $response->status();
                    $message = data_get($response->json(), 'message') ?? $response->body();
                    return ['status' => 'error', 'message' => "WooCommerce sync failed (HTTP {$status}). {$message}"];
                }

                $orders = $response->json();
                if (! is_array($orders)) {
                    return ['status' => 'error', 'message' => 'WooCommerce sync failed: unexpected response format.'];
                }

                // Merge orders from this page
                $allOrders = array_merge($allOrders, $orders);

                // Get total pages from response headers (WooCommerce provides X-WP-TotalPages)
                $totalPages = (int) $response->header('X-WP-TotalPages', 1);

                $page++;
            } while ($page <= $totalPages && count($orders) > 0);

            $synced = 0;

            foreach ($allOrders as $order) {
                $wpOrderId = data_get($order, 'id');
                if (! $wpOrderId) {
                    continue;
                }

                // Extract payment status (same logic as ProcessWooWebhookEvent)
                $paymentStatus = null;
                if (isset($order['payment_status'])) {
                    $paymentStatus = $order['payment_status'];
                } elseif (!empty(data_get($order, 'date_paid'))) {
                    $paymentStatus = 'paid';
                }

                WcOrder::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'wp_order_id' => $wpOrderId,
                    ],
                    [
                        'status' => data_get($order, 'status', 'unknown'),
                        'payment_status' => $paymentStatus,
                        'currency' => data_get($order, 'currency'),
                        'total' => (string) data_get($order, 'total', '0'),
                        'customer_email' => data_get($order, 'billing.email'),
                        'customer_name' => trim(
                            (data_get($order, 'billing.first_name') ?? '') . ' ' .
                            (data_get($order, 'billing.last_name') ?? '')
                        ),
                        'created_at_wp' => data_get($order, 'date_created_gmt'),
                        'updated_at_wp' => data_get($order, 'date_modified_gmt'),
                        'payload' => $order,
                    ]
                );

                $synced++;
            }

            $website->update(['last_sync_at' => now()]);

            return ['status' => 'success', 'message' => "Synced {$synced} WooCommerce orders."];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'WooCommerce sync failed: ' . $e->getMessage()];
        }
    }

    public function syncFluentForm(Request $request, Website $website)
    {
        abort_if($website->status !== 'active', 403, 'Website is not active');

        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            return back()->with('error', 'Fluent Forms authentication credentials are missing. Please save Username and Application Password first.');
        }

        $request->validate([
            'form_id' => 'required|integer|min:1',
        ]);

        $formId = (int) $request->input('form_id');
        $perPage = (int) ($request->input('per_page', 100));

        try {
            // Run synchronously for immediate feedback (better UX)
            // For production with many websites, consider using queues with a worker
            $job = new \App\Jobs\SyncFluentSubmissions($website->id, $formId, $perPage);
            $job->handle();
            
            // Count synced submissions for this form
            $syncedCount = \App\Models\FfSubmission::where('website_id', $website->id)
                ->where('form_id', $formId)
                ->count();
            
            return back()->with('success', "Fluent Forms sync completed successfully! Synced {$syncedCount} submission(s) for form #{$formId}.");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Fluent Forms sync error', [
                'website_id' => $website->id,
                'form_id' => $formId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return back()->with('error', 'Fluent Forms sync failed: ' . $e->getMessage());
        }
    }
}
