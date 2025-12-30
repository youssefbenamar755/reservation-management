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

        // Normalize payload: unwrap array if needed
        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
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
