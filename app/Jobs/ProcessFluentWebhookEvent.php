<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Models\FfSubmission;
use App\Services\FluentFormSchemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFluentWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::findOrFail($this->webhookEventId);
        $payload = $event->payload;

        // Normalize payload: if payload is a list array (starts with [0]), use payload[0]
        if (is_array($payload) && !empty($payload) && array_is_list($payload)) {
            // It's a list array, use the first element
            $payload = $payload[0];
        }

        // Extract identifiers from __submission
        $formId = $payload['__submission']['form_id'] ?? null;
        $entryId = $payload['__submission']['id'] ?? null;

        // Validate required fields - handle gracefully without throwing
        if (!$formId || !$entryId) {
            $errorMessage = 'Missing form_id or entry_id in webhook payload. ' .
                'Expected in payload["__submission"]["form_id"] and payload["__submission"]["id"]';
            
            Log::error('ProcessFluentWebhookEvent failed', [
                'webhook_event_id' => $this->webhookEventId,
                'error' => $errorMessage,
                'payload_structure' => array_keys($payload),
            ]);

            $event->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            return;
        }

        // Extract clean form response (remove all keys starting with "__")
        $response = [];
        $meta = [];
        $orderItems = null;

        foreach ($payload as $key => $value) {
            if (str_starts_with($key, '__')) {
                // Store meta keys separately
                if ($key === '__submission') {
                    $meta['submission'] = $value;
                } elseif ($key === '__order_items') {
                    $orderItems = $value;
                } else {
                    $meta[$key] = $value;
                }
            } else {
                // Keep only real form inputs
                $response[$key] = $value;
            }
        }

        // Build final payload structure
        $finalPayload = [
            'response' => $response,
            'meta' => $meta,
        ];

        if ($orderItems !== null) {
            $finalPayload['order_items'] = $orderItems;
        }

        // Extract email and created_at from response or meta
        $email = $response['email'] ?? $meta['submission']['email'] ?? null;
        $createdAt = $meta['submission']['created_at'] ?? $response['created_at'] ?? now();

        // Extract payment_status from __submission.payment_status
        $paymentStatus = $meta['submission']['payment_status'] ?? null;

        // Extract amount from multiple possible locations
        $amount = null;
        
        // First try: __submission.payment_total (likely in cents)
        if (isset($meta['submission']['payment_total'])) {
            $paymentTotal = $meta['submission']['payment_total'];
            $numAmount = is_numeric($paymentTotal) ? (float) $paymentTotal : null;
            if ($numAmount !== null && $numAmount > 0) {
                // Convert from cents to dollars
                $amount = $numAmount / 100;
            }
        }
        
        // Second try: __order_items[0].formatted_line_total or formatted_item_price (formatted string like "$25.00")
        if ($amount === null && !empty($orderItems) && is_array($orderItems) && isset($orderItems[0])) {
            $firstItem = $orderItems[0];
            $formattedAmount = $firstItem['formatted_line_total'] ?? $firstItem['formatted_item_price'] ?? null;
            
            if ($formattedAmount && is_string($formattedAmount)) {
                // Remove currency symbols and commas, then parse
                $cleaned = preg_replace('/[^\d.]/', '', $formattedAmount);
                $numAmount = is_numeric($cleaned) ? (float) $cleaned : null;
                if ($numAmount !== null && $numAmount > 0) {
                    $amount = $numAmount;
                }
            } elseif ($formattedAmount && is_numeric($formattedAmount)) {
                $amount = (float) $formattedAmount;
            }
        }

        // Auto-sync form schema if not already synced
        $website = $event->website;
        if ($website) {
            $schemaService = app(FluentFormSchemaService::class);
            $schemaService->syncFormSchema($website, (int) $formId);
        }

        FfSubmission::updateOrCreate(
            [
                'website_id' => $event->website_id,
                'form_id' => (int) $formId,
                'entry_id' => (int) $entryId,
            ],
            [
                'email' => $email,
                'payment_status' => $paymentStatus,
                'amount' => $amount,
                'created_at_wp' => $createdAt,
                'payload' => $finalPayload,
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
