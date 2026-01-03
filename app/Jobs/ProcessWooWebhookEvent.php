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

        // Check if order already exists to only notify on new orders
        $existingOrder = WcOrder::where('website_id', $event->website_id)
            ->where('wp_order_id', $payload['id'])
            ->first();

        // Only notify on order.created, not order.updated
        $isNewOrder = !$existingOrder && $event->topic === 'order.created';

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
            User::where('is_admin', true)->each(function ($user) use ($order) {
                $user->notify(new NewOrderNotification($order));
            });
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
