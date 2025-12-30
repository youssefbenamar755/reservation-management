<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Models\WcOrder;
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

        // Upsert Woo order
        WcOrder::updateOrCreate(
            [
                'website_id' => $event->website_id,
                'wp_order_id' => $payload['id'],
            ],
            [
                'status' => $payload['status'] ?? 'unknown',
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

        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::where('id', $this->webhookEventId)->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
