<?php

namespace App\Services;

use App\Models\ActionHistoryEvent;
use App\Models\OrderEmailDelivery;
use App\Models\UsefulAlert;
use App\Models\User;
use App\Models\WcOrder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActionHistoryRecorder
{
    public function beginStatus(User $actor, WcOrder $order, string $requested): ActionHistoryEvent
    {
        return ActionHistoryEvent::create(['actor_id' => $actor->id, 'website_id' => $order->website_id,
            'order_id' => $order->id, 'kind' => 'order_status', 'reference' => (string) $order->wp_order_id,
            'outcome' => 'pending', 'occurred_at' => now(),
            'details' => ['from' => $this->status($order->status), 'requested' => $this->status($requested)]]);
    }

    public function finishStatus(ActionHistoryEvent $event, string $outcome, string $reason, ?string $confirmed = null): void
    {
        $this->finish($event, $outcome, $event->details + ['reason' => $reason, 'confirmed' => $this->status($confirmed)]);
    }

    /** Called inside the delivery claim transaction, before any Gmail request. */
    public function beginEmail(OrderEmailDelivery $delivery, WcOrder $order): void
    {
        ActionHistoryEvent::firstOrCreate(['deduplication_key' => 'email:'.$delivery->id.':'.$delivery->send_attempts], [
            'actor_id' => $delivery->user_id, 'website_id' => $delivery->website_id, 'order_id' => $order->id,
            'kind' => 'email_send', 'entity_key' => $delivery->id, 'reference' => (string) $order->wp_order_id,
            'outcome' => 'pending', 'occurred_at' => $delivery->sending_at, 'details' => ['attempts' => (int) $delivery->send_attempts],
        ]);
    }

    public function finishEmail(OrderEmailDelivery $delivery): void
    {
        try {
            $event = ActionHistoryEvent::where('deduplication_key', 'email:'.$delivery->id.':'.$delivery->send_attempts)->first();
            if ($event) {
                $this->finish($event, match ($delivery->status) {
                    'sent' => 'succeeded', 'failed' => 'failed', default => 'uncertain'
                }, $event->details);
            }
        } catch (Throwable $exception) {
            Log::warning('Email history result could not be saved', ['exception_type' => $exception::class]);
        }
    }

    /** Alert action and history are committed in the same transaction. */
    public function alert(User $actor, UsefulAlert $alert, bool $snoozed): void
    {
        ActionHistoryEvent::create(['actor_id' => $actor->id, 'website_id' => $alert->website_id,
            'kind' => $snoozed ? 'alert_snoozed' : 'alert_resumed', 'entity_key' => (string) $alert->id,
            'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(),
            'details' => ['alert_kind' => $alert->kind, 'snoozed_until' => $alert->snoozed_until?->toIso8601String()]]);
    }

    private function finish(ActionHistoryEvent $event, string $outcome, array $details): void
    {
        try {
            ActionHistoryEvent::whereKey($event->id)->where('outcome', 'pending')->update([
                'outcome' => $outcome, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // A provider may already have accepted the operation. Never repeat it
            // merely because history finalization failed; the pending record remains.
            Log::warning('Action history result could not be saved', ['event_id' => $event->id, 'exception_type' => $exception::class]);
        }
    }

    private function status(?string $value): ?string
    {
        return in_array($value, ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft'], true) ? $value : null;
    }
}
