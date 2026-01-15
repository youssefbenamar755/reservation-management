<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Models\WcOrder;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWooWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::findOrFail($this->webhookEventId);
        $payload = $event->payload;

        // Validate required fields
        if (!isset($payload['id'])) {
            throw new \Exception('Missing order ID in webhook payload');
        }

        // Determine payment status
        // WooCommerce may have payment_status field directly, or we derive it from date_paid
        $paymentStatus = $this->extractPaymentStatus($payload);

        // Check if order already exists BEFORE creating/updating
        $existingOrder = WcOrder::where('website_id', $event->website_id)
            ->where('wp_order_id', $payload['id'])
            ->first();

        // Determine if this is a new order based on:
        // 1. Order doesn't exist in database AND
        // 2. Topic indicates order creation (check for 'order.created' case-insensitively)
        $topic = strtolower(trim($event->topic ?? ''));
        $isOrderCreated = str_contains($topic, 'order.created') || str_contains($topic, 'order_created');
        $isNewOrder = !$existingOrder && $isOrderCreated;

        // Log for debugging
        Log::info('Processing WooCommerce webhook', [
            'webhook_event_id' => $this->webhookEventId,
            'order_id' => $payload['id'],
            'topic' => $event->topic,
            'existing_order' => $existingOrder ? $existingOrder->id : null,
            'is_new_order' => $isNewOrder,
        ]);

        // Upsert Woo order - handles both order.created and order.updated
        // Uses updateOrCreate to ensure idempotency (no duplicates)
        $order = WcOrder::updateOrCreate(
            [
                'website_id' => $event->website_id,
                'wp_order_id' => $payload['id'],
            ],
            [
                'status' => $payload['status'] ?? 'unknown',
                'payment_status' => $paymentStatus,
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['total'] ?? 0,
                'customer_email' => data_get($payload, 'billing.email'),
                'customer_name' =>
                    trim(
                        (data_get($payload, 'billing.first_name') ?? '') . ' ' .
                        (data_get($payload, 'billing.last_name') ?? '')
                    ),
                'created_at_wp' => data_get($payload, 'date_created_gmt'),
                'updated_at_wp' => data_get($payload, 'date_modified_gmt'),
                'payload' => $payload,
            ]
        );

        // Notify all admin users if this is a new order
        if ($isNewOrder) {
            $adminUsers = User::where('is_admin', true)->get();
            
            Log::info('Sending notifications for new order', [
                'order_id' => $order->id,
                'admin_users_count' => $adminUsers->count(),
            ]);
            
            if ($adminUsers->isEmpty()) {
                Log::warning('No admin users found to notify', [
                    'order_id' => $order->id,
                ]);
            }
            
            foreach ($adminUsers as $user) {
                try {
                    $user->notify(new NewOrderNotification($order));
                    Log::info('Notification sent successfully', [
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                    ]);
                } catch (\Exception $e) {
                    // Log error but don't fail the job
                    Log::error('Failed to send notification to user', [
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        } else {
            Log::info('Skipping notification - not a new order', [
                'order_id' => $payload['id'],
                'existing_order' => $existingOrder ? $existingOrder->id : null,
                'topic' => $event->topic,
            ]);
        }

        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Extract payment status from WooCommerce order payload.
     * 
     * WooCommerce doesn't have a direct payment_status field in standard API,
     * but we can derive it from date_paid or use payment_status if available.
     * 
     * @param array $payload
     * @return string|null
     */
    private function extractPaymentStatus(array $payload): ?string
    {
        // First, check if payment_status field exists directly
        if (isset($payload['payment_status'])) {
            return $payload['payment_status'];
        }

        // Derive from date_paid: if date_paid exists and is not empty, order is paid
        $datePaid = data_get($payload, 'date_paid');
        if (!empty($datePaid)) {
            return 'paid';
        }

        // If date_paid is null/empty, check order status
        // Some orders might be marked as paid through status
        $status = data_get($payload, 'status', '');
        if (in_array($status, ['completed', 'processing'])) {
            // These statuses typically indicate payment, but not always
            // Return null to indicate unknown payment status
            return null;
        }

        // Default to null if we can't determine
        return null;
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::where('id', $this->webhookEventId)->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
