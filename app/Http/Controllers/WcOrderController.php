<?php

namespace App\Http\Controllers;

use App\Models\WcOrder;
use App\Models\Website;
use App\Models\FfSubmission;
use App\Services\AmadeusDummyTicketGeneratorService;
use App\Services\WooCommerceOrderStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WcOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Get user's website IDs for filtering
        $userWebsiteIds = Website::when(!$user->is_admin, fn($q) => $q->where('user_id', $user->id))
            ->pluck('id');
        
        $orders = WcOrder::query()
            ->select([
                'id', 'website_id', 'wp_order_id', 'status', 'currency', 'total',
                'customer_email', 'customer_name', 'created_at_wp',
            ])
            ->with('website:id,name')
            ->whereIn('website_id', $userWebsiteIds) // Only show orders from user's websites
            ->when($request->website_id, fn ($q) =>
                $q->where('website_id', $request->website_id)
            )
            ->when($request->status, fn ($q) =>
                $q->where('status', $request->status)
            )
            ->when($request->search, fn ($q) =>
                $q->where(function ($query) use ($request) {
                    $search = $request->search;
                    $query->where('wp_order_id', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                })
            )
            ->latest('created_at_wp')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['orders' => $orders])
                ->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'websites' => fn () => Website::when(!$user->is_admin, fn($q) => $q->where('user_id', $user->id))
                ->select('id', 'name')
                ->get(),
            'filters' => $request->only(['website_id', 'status', 'search']),
        ]);
    }

    public function show(WcOrder $order)
    {
        $this->authorize('view', $order);
        
        $order->load('website');
        $website = $order->website;

        // Get customer history (other orders from the same customer)
        $customerHistory = [];
        if ($order->customer_email) {
            $customerHistory = WcOrder::query()
                ->where('website_id', $order->website_id)
                ->where('customer_email', $order->customer_email)
                ->where('id', '!=', $order->id)
                ->orderBy('created_at_wp', 'desc')
                ->limit(10)
                ->get(['id', 'wp_order_id', 'status', 'total', 'currency', 'created_at_wp']);
        }

        // Fetch order notes from WooCommerce API if credentials are available
        $orderNotes = [];
        if ($website->wc_consumer_key && $website->wc_consumer_secret) {
            try {
                $baseUrl = rtrim($website->base_url, '/');
                $endpoint = "{$baseUrl}/wp-json/wc/v3/orders/{$order->wp_order_id}/notes";

                $response = Http::timeout(8)
                    ->connectTimeout(5)
                    ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                    ->acceptJson()
                    ->get($endpoint);

                if ($response->successful()) {
                    $notes = $response->json();
                    $orderNotes = is_array($notes) ? $notes : [];
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch order notes from WooCommerce', [
                    'order_id' => $order->id,
                    'wp_order_id' => $order->wp_order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Extract order attribution from meta_data
        $payload = $order->payload ?? [];
        $metaData = data_get($payload, 'meta_data', []);
        $attribution = [];
        
        // Common attribution fields
        $attributionFields = [
            '_order_attribution',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            '_wca_source',
            '_wca_medium',
            '_wca_campaign',
            'source',
            'referer',
        ];

        foreach ($attributionFields as $field) {
            foreach ($metaData as $meta) {
                if (isset($meta['key']) && $meta['key'] === $field && isset($meta['value']) && $meta['value']) {
                    $attribution[$field] = $meta['value'];
                }
            }
        }

        // Extract _fluent_id from meta_data and fetch Fluent Forms submission
        $fluentSubmission = null;
        $fluentId = null;
        foreach ($metaData as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_fluent_id' && isset($meta['value']) && $meta['value']) {
                $fluentId = $meta['value'];
                break;
            }
        }

        if ($fluentId) {
            // Find the Fluent Forms submission by entry_id matching the _fluent_id
            // The submission must belong to the same website
            $fluentSubmission = FfSubmission::where('website_id', $order->website_id)
                ->where('entry_id', $fluentId)
                ->with('website')
                ->first();
        }

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'customerHistory' => $customerHistory,
            'orderNotes' => $orderNotes,
            'attribution' => $attribution,
            'fluentSubmission' => $fluentSubmission,
        ]);
    }

    public function update(Request $request, WcOrder $order, WooCommerceOrderStore $orders)
    {
        $this->authorize('update', $order);
        
        $request->validate([
            'status' => 'required|string|in:pending,processing,on-hold,completed,cancelled,refunded,failed',
        ]);

        // Load website relationship to access credentials
        $order->load('website');
        $website = $order->website;

        // Check if WooCommerce API credentials are available
        if ($website->wc_consumer_key && $website->wc_consumer_secret) {
            try {
                $baseUrl = rtrim($website->base_url, '/');
                $endpoint = "{$baseUrl}/wp-json/wc/v3/orders/{$order->wp_order_id}";

                $response = Http::timeout(15)
                    ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                    ->acceptJson()
                    ->asJson()
                    ->put($endpoint, [
                        'status' => $request->status,
                    ]);

                if (!$response->successful()) {
                    $status = $response->status();
                    $message = data_get($response->json(), 'message') ?? $response->body();

                    Log::warning('Failed to update order status in WooCommerce', [
                        'order_id' => $order->id,
                        'wp_order_id' => $order->wp_order_id,
                        'website_id' => $website->id,
                        'new_status' => $request->status,
                        'http_status' => $status,
                        'error_message' => $message,
                    ]);

                    // Still update local database but inform user about WooCommerce sync failure
                    $order->update([
                        'status' => $request->status,
                    ]);

                    return back()->with('error', "Order status updated locally, but WooCommerce update failed (HTTP {$status}). {$message}");
                }

                // Use the same timestamp guard as sync and webhooks. A newer
                // webhook may arrive while the WooCommerce request is in flight.
                $wooOrder = $response->json();
                if (is_array($wooOrder)) {
                    $wooOrder['id'] ??= $order->wp_order_id;
                    $wooOrder['status'] ??= $request->status;
                    $result = $orders->store($website->id, $wooOrder);

                    if ($result['order']->status !== $wooOrder['status']) {
                        return back()->with('success', 'WooCommerce accepted the status update. A newer order update was retained locally.');
                    }
                } else {
                    // Fallback: just update status
                    $order->update([
                        'status' => $request->status,
                    ]);
                }

                return back()->with('success', 'Order status updated successfully in WooCommerce and locally.');
            } catch (\Throwable $e) {
                Log::error('Exception while updating order status in WooCommerce', [
                    'order_id' => $order->id,
                    'wp_order_id' => $order->wp_order_id,
                    'website_id' => $website->id,
                    'new_status' => $request->status,
                    'error' => $e->getMessage(),
                ]);

                // Still update local database
                $order->update([
                    'status' => $request->status,
                ]);

                return back()->with('error', 'Order status updated locally, but WooCommerce update failed: ' . $e->getMessage());
            }
        } else {
            // No WooCommerce API credentials, just update locally
            $order->update([
                'status' => $request->status,
            ]);

            return back()->with('success', 'Order status updated locally. WooCommerce API credentials not configured, so the order was not updated in WooCommerce.');
        }
    }

    /**
     * Generate Amadeus dummy ticket command block for an order
     * Uses the linked Fluent Forms submission if available
     */
    public function generateAmadeusCode(WcOrder $order, AmadeusDummyTicketGeneratorService $service)
    {
        $this->authorize('generateAmadeusCode', $order);
        
        // Find the linked Fluent Forms submission
        $payload = $order->payload ?? [];
        $metaData = data_get($payload, 'meta_data', []);
        $fluentId = null;
        
        foreach ($metaData as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_fluent_id' && isset($meta['value']) && $meta['value']) {
                $fluentId = $meta['value'];
                break;
            }
        }

        if (!$fluentId) {
            return back()->with('error', 'No Fluent Forms submission linked to this order.');
        }

        // Find the Fluent Forms submission
        $fluentSubmission = FfSubmission::where('website_id', $order->website_id)
            ->where('entry_id', $fluentId)
            ->first();

        if (!$fluentSubmission) {
            return back()->with('error', 'Fluent Forms submission not found.');
        }

        // Use the same logic as FfSubmissionController
        try {
            $payload = $fluentSubmission->payload ?? [];
            $response = $payload['response'] ?? [];

            // Handle case where response is a JSON string
            if (is_string($response)) {
                try {
                    $response = json_decode($response, true);
                    if (!is_array($response)) {
                        $response = [];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to parse response JSON string', [
                        'entry_id' => $fluentSubmission->id,
                        'error' => $e->getMessage(),
                    ]);
                    $response = [];
                }
            }

            // Ensure response is an array
            if (!is_array($response)) {
                $response = [];
            }

            // Use FfSubmissionController's extraction methods via a temporary instance
            $ffController = new FfSubmissionController();
            $reflection = new \ReflectionClass($ffController);
            
            // Get the private methods
            $normalizeFlightDataMethod = $reflection->getMethod('normalizeFlightData');
            $normalizeFlightDataMethod->setAccessible(true);
            
            $hasSufficientFlightDataMethod = $reflection->getMethod('hasSufficientFlightData');
            $hasSufficientFlightDataMethod->setAccessible(true);
            
            $extractPassengerDataMethod = $reflection->getMethod('extractPassengerData');
            $extractPassengerDataMethod->setAccessible(true);

            // Extract and normalize flight data
            $normalizedFlightData = $normalizeFlightDataMethod->invoke($ffController, $response);

            // Validate that we have sufficient flight data
            if (!$hasSufficientFlightDataMethod->invoke($ffController, $normalizedFlightData)) {
                return back()->with('error', 'Insufficient flight data to generate ticket. Please ensure flight information (origin, destination, departure date) is available.');
            }

            // Extract passenger data
            $passengerData = $extractPassengerDataMethod->invoke($ffController, $response, $fluentSubmission);

            // Generate the full command block
            $result = $service->generateCommandBlock($normalizedFlightData, $passengerData);

            // Store the generated command block in the submission
            $fluentSubmission->update([
                'amadeus_command_block' => $result['full_command_block'],
                'amadeus_generated_at' => now(),
            ]);

            Log::info('Amadeus dummy ticket command block generated for order', [
                'order_id' => $order->id,
                'entry_id' => $fluentSubmission->id,
                'command_block_length' => strlen($result['full_command_block']),
            ]);

            return back()->with('success', 'Amadeus dummy ticket command block generated successfully.');
        } catch (\Throwable $e) {
            Log::error('Failed to generate Amadeus dummy ticket command block for order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to generate Amadeus dummy ticket command block: ' . $e->getMessage());
        }
    }
}
