<?php

namespace App\Jobs;

use App\Events\NewWcOrderReceived;
use App\Models\WebhookEvent;
use App\Models\WcOrder;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Facades\Event;
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
        $event   = WebhookEvent::findOrFail($this->webhookEventId);
        $payload = $event->payload;

        Log::info('WooWebhook raw payload', ['payload' => $payload]);
        // Resolve order ID — try payload first, fall back to stored external_id
        $orderId = $payload['id'] ?? $event->external_id ?? null;

        if (!$orderId) {
            Log::error('Missing order ID in webhook payload — marking as failed', [
                'webhook_event_id' => $this->webhookEventId,
                'topic'            => $event->topic,
                'payload_keys'     => array_keys($payload ?? []),
                'full_payload'     => $payload,
            ]);
            $event->update(['status' => 'failed', 'error_message' => 'Missing order ID in webhook payload']);
            return; // Soft fail — don't throw so the job doesn't keep retrying
        }

        // Determine payment status
        $paymentStatus = $this->extractPaymentStatus($payload);

        // Check if order already exists BEFORE creating/updating
        $existingOrder = WcOrder::where('website_id', $event->website_id)
            ->where('wp_order_id', $orderId)
            ->first();

        // Determine if this is a new order:
        // WooCommerce sends topic as "order.created" in X-WC-Webhook-Topic header
        $topic          = strtolower(trim($event->topic ?? ''));
        $isOrderCreated = str_contains($topic, 'order.created')
            || str_contains($topic, 'order_created')
            || str_contains($topic, 'new_order');

        // Notify on order.created even if the order was pre-synced manually
        // (existingOrder only prevents duplicate notifications on order.updated webhooks)
        $isNewOrder = $isOrderCreated;

        Log::info('Processing WooCommerce webhook', [
            'webhook_event_id' => $this->webhookEventId,
            'order_id'         => $orderId,
            'topic'            => $event->topic,
            'existing_order'   => $existingOrder?->id,
            'is_new_order'     => $isNewOrder,
        ]);

        // Upsert Woo order - handles both order.created and order.updated
        // Uses updateOrCreate to ensure idempotency (no duplicates)
        $order = WcOrder::updateOrCreate(
            [
                'website_id'  => $event->website_id,
                'wp_order_id' => $orderId,
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

        // Notify all admin users and broadcast for Orders page if this is a new order
        if ($isNewOrder) {
            Event::dispatch(new NewWcOrderReceived($order));

            $adminUsers = User::where('is_admin', true)->get();
            $websiteOwner = $event->website?->user;
            $usersToNotify = $adminUsers
                ->when($websiteOwner, fn ($collection) => $collection->push($websiteOwner))
                ->unique('id')
                ->values();
            
            Log::info('Sending notifications for new order', [
                'order_id' => $order->id,
                'users_to_notify_count' => $usersToNotify->count(),
            ]);
            
            if ($usersToNotify->isEmpty()) {
                Log::warning('No users found to notify', [
                    'order_id' => $order->id,
                ]);
            }
            
            foreach ($usersToNotify as $user) {
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
                'order_id'       => $orderId,
                'existing_order' => $existingOrder?->id,
                'topic'          => $event->topic,
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
