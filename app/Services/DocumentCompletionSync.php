<?php

namespace App\Services;

use App\Models\ActionHistoryEvent;
use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DocumentCompletionSync
{
    public function sync(string $id): OrderEmailDelivery
    {
        [$delivery, $claimed] = DB::transaction(function () use ($id) {
            $delivery = OrderEmailDelivery::whereKey($id)->where('workflow', true)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'sent' || ! $delivery->gmail_message_id || $delivery->document_mode !== 'complete'
                || $delivery->sync_status === 'completed'
                || ($delivery->sync_status === 'syncing' && $delivery->sync_claimed_at?->gt(now()->subMinutes(5)))) {
                return [$delivery, false];
            }
            $delivery->update(['sync_status' => 'syncing', 'sync_claimed_at' => now(), 'sync_attempts' => $delivery->sync_attempts + 1, 'sync_error' => null]);
            ActionHistoryEvent::create(['actor_id' => $delivery->user_id, 'website_id' => $delivery->website_id, 'order_id' => $delivery->wc_order_id,
                'kind' => 'email_sync', 'entity_key' => $delivery->id, 'deduplication_key' => 'document-sync:'.$delivery->id.':'.$delivery->sync_attempts,
                'outcome' => 'pending', 'occurred_at' => now(), 'details' => ['attempts' => $delivery->sync_attempts]]);

            return [$delivery, true];
        }, 3);
        if (! $claimed) {
            return $delivery;
        }
        try {
            $order = WcOrder::whereKey($delivery->wc_order_id)->where('website_id', $delivery->website_id)->firstOrFail();
            app(DocumentStoreGateway::class)->complete($order, $delivery);
            $delivery->update(['sync_status' => 'completed', 'synced_at' => now(), 'next_sync_at' => null, 'sync_error' => null,
                'result_message' => 'Documents sent — order completed with the redundant completion email suppressed.']);
        } catch (Throwable $exception) {
            Log::warning('Document completion requires retry or review', ['delivery_id' => $delivery->id, 'exception_type' => $exception::class]);
            $delivery->update(['sync_status' => 'pending', 'next_sync_at' => now()->addMinutes(min(60, 5 * $delivery->sync_attempts)),
                'sync_error' => 'The store did not confirm completion. Check plugin capability and the current paid order status. Retry updates the order only; it does not resend the email.',
                'result_message' => 'Documents sent — order update pending.']);
        }
        try {
            ActionHistoryEvent::where('deduplication_key', 'document-sync:'.$delivery->id.':'.$delivery->sync_attempts)
                ->where('outcome', 'pending')->update(['outcome' => $delivery->sync_status === 'completed' ? 'succeeded' : 'failed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('Document synchronization audit finalization failed', ['delivery_id' => $delivery->id, 'exception_type' => $exception::class]);
        }

        return $delivery->fresh();
    }
}
