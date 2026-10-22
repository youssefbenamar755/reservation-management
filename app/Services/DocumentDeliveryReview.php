<?php

namespace App\Services;

use App\Models\ActionHistoryEvent;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentDeliveryReview
{
    /** An operator observation is never treated as a confirmed provider response. */
    public function resolve(WcOrder $order, OrderEmailDelivery $attempt, User $actor, array $values): OrderEmailDelivery
    {
        return DB::transaction(function () use ($order, $attempt, $actor, $values) {
            $order = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $order);
            $delivery = OrderEmailDelivery::whereKey($attempt->id)->where('website_id', $order->website_id)
                ->where('wc_order_id', $order->id)->where('workflow', true)->lockForUpdate()->firstOrFail();
            app(OrderEmailComposer::class)->expire($delivery);
            if (in_array($delivery->status, ['reviewed_sent', 'reviewed_not_sent'], true)) {
                return $delivery;
            }
            if ($delivery->status !== 'uncertain') {
                throw ValidationException::withMessages(['review' => 'Only an unknown sending outcome can be resolved. Wait for an active send to finish.']);
            }
            $review = ['decision' => $values['decision'], 'reason' => $values['reason'], 'reviewer_id' => $actor->id,
                'reviewer_name' => $actor->name, 'reviewed_at' => now()->toIso8601String(),
                'provider_message_reference' => $values['provider_message_id'] ?? null];
            $delivery->update(['status' => $values['decision'] === 'sent' ? 'reviewed_sent' : 'reviewed_not_sent',
                'manual_review' => $review, 'mime' => null, 'deduplication_key' => null,
                'sync_status' => 'not_requested', 'next_sync_at' => null,
                'result_message' => $values['decision'] === 'sent'
                    ? 'An operator recorded that the email appears in Gmail Sent. Automatic order completion remains disabled for this attempt.'
                    : 'An operator recorded that the email was not sent. Prepare and explicitly confirm a new attempt if needed.']);
            ActionHistoryEvent::create(['actor_id' => $actor->id, 'website_id' => $order->website_id, 'order_id' => $order->id,
                'kind' => 'email_review', 'entity_key' => $delivery->id, 'reference' => (string) $order->wp_order_id,
                'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(), 'details' => ['decision' => $values['decision']]]);

            return $delivery;
        }, 3);
    }
}
