<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Jobs\SyncFluentSubmissions;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WebsiteController extends Controller
{
    public function __construct(private WooCommerceOrderSyncService $syncService) {}
    public function index()
    {
        $user = auth()->user();
        
        $websites = Website::query()
            ->when(!$user->is_admin, fn($q) => $q->where('user_id', $user->id))
            ->select([
                'id',
                'user_id',
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
                    // Show URL structure without exposing the secret
                    'fluentforms' => url("/api/v1/webhooks/fluentforms/{$website->slug}") . '?token=***HIDDEN***',
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
            'timezone' => 'nullable|timezone',
        ]);

        $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
        $data['webhook_secret'] = Str::random(40); // For Fluent Forms
        $data['wc_webhook_secret'] = 'wc_' . Str::random(40); // For WooCommerce
        $data['status'] = 'active';
        $data['timezone'] = $data['timezone'] ?? 'UTC';
        $data['user_id'] = auth()->id(); // Assign to current user

        Website::create($data);

        return redirect()->route('websites.index')
            ->with('success', 'Website added successfully');
    }

    public function show(Website $website)
    {
        $this->authorize('view', $website);
        return redirect()->route('websites.edit', $website);
    }

    public function edit(Website $website)
    {
        $this->authorize('view', $website);
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
                    // Show URL structure without exposing the secret
                    'fluentforms' => url("/api/v1/webhooks/fluentforms/{$website->slug}") . '?token=***HIDDEN***',
                    // Note: Actual secrets can be revealed via separate endpoint with password confirmation
                ],
            ],
        ]);
    }

    public function update(Request $request, Website $website)
    {
        $this->authorize('update', $website);
        
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'wc_consumer_key' => 'nullable|string',
            'wc_consumer_secret' => 'nullable|string',
            'ff_username' => 'nullable|string',
            'ff_app_password' => 'nullable|string',
            'status' => 'required|in:active,paused',
            'timezone' => 'nullable|timezone',
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
        $this->authorize('delete', $website);
        
        $website->delete();

        return redirect()->route('websites.index')
            ->with('success', 'Website removed');
    }

    public function testWooCommerce(Request $request, Website $website)
    {
        $this->authorize('view', $website);
        
        if (! $website->wc_consumer_key || ! $website->wc_consumer_secret) {
            return back()->with('error', 'WooCommerce API credentials are missing. Please save Consumer Key and Secret first.');
        }

        $baseUrl = rtrim($website->base_url, '/');
        $endpoint = "{$baseUrl}/wp-json/wc/v3/system_status";

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                ->acceptJson()
                ->get($endpoint);

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
        $this->authorize('view', $website);
        
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
        $this->authorize('view', $website);

        abort_if($website->status !== 'active', 403, 'Website is not active.');

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($request->input('per_page', 50));
        $result  = $this->syncService->syncForWebsite($website, $perPage);

        // The browser requests one page at a time and continues from saved progress.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result);
        }

        $flashType = $result['status'] === 'success' ? 'success' : 'error';
        $message = $result['status'] === 'partial'
            ? 'Sync is still in progress. Use Sync orders on the Orders page to continue.'
            : $result['message'];

        return back()->with($flashType, $message);
    }

    public function syncAllWooCommerceOrders(Request $request)
    {
        // Kept for older clients; the Orders page drives the resumable per-site flow.
        // A single server request must not scan every site's complete order history.
        return to_route('orders.index')->with('error',
            'Use Sync orders on the Orders page with all websites selected to complete this sync.'
        );
    }


    /**
     * Reveal webhook secrets (requires password confirmation)
     */
    public function revealWebhookSecrets(Website $website)
    {
        $this->authorize('view', $website);
        
        return response()->json([
            'woocommerce_secret' => $website->wc_webhook_secret,
            'woocommerce_url' => url("/api/v1/webhooks/woocommerce/{$website->slug}"),
            'woocommerce_instructions' => 'Configure this secret in your WooCommerce webhook settings under Advanced Options > Secret',
            'fluentforms_secret' => $website->webhook_secret,
            'fluentforms_url' => url("/api/v1/webhooks/fluentforms/{$website->slug}") . '?token=' . $website->webhook_secret,
            'fluentforms_instructions' => 'Use the complete URL with token parameter in your Fluent Forms webhook configuration',
        ]);
    }

    public function syncFluentForm(Request $request, Website $website)
    {
        $this->authorize('view', $website);
        
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
