<?php

namespace App\Jobs;

use App\Events\NewWcOrderReceived;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\NewOrderNotification;
use App\Services\WooCommerceOrderStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ProcessWooWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $webhookEventId) {}

    public function handle(WooCommerceOrderStore $orders): void
    {
        $result = DB::transaction(function () use ($orders) {
            $event = WebhookEvent::whereKey($this->webhookEventId)->lockForUpdate()->firstOrFail();
            if ($event->status === 'processed') {
                return null;
            }

            $payload = $event->payload;
            $payload['id'] ??= $event->external_id;

            try {
                $result = $orders->store($event->website_id, $payload);
            } catch (InvalidArgumentException $e) {
                $event->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

                return null;
            }

            $notifications = [];
            if ($result['created']) {
                $order = $result['order'];
                $websiteOwner = $order->website?->user;
                $users = User::where('is_admin', true)->get()
                    ->when($websiteOwner, fn ($users) => $users->push($websiteOwner))
                    ->unique('id');

                foreach ($users as $user) {
                    $notification = new NewOrderNotification($order);
                    $notification->id = (string) Str::uuid();
                    // Persist inbox delivery atomically with the first insertion.
                    $user->notifyNow($notification, ['database']);
                    $notifications[] = [$user, $notification];
                }
            }

            $event->update(['status' => 'processed', 'processed_at' => now(), 'error_message' => null]);

            return $result + ['notifications' => $notifications];
        }, 3);

        // Refresh the list for new and changed orders; replays and stale deliveries are silent.
        if (! $result || (! $result['created'] && ! $result['changed'])) {
            return;
        }

        $order = $result['order'];
        try {
            Event::dispatch(new NewWcOrderReceived($order));
        } catch (Throwable $e) {
            Log::error('Failed to broadcast order change', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        foreach ($result['notifications'] as [$user, $notification]) {
            try {
                $user->notifyNow($notification, ['broadcast']);
            } catch (Throwable $e) {
                Log::error('Failed to send new order notification', [
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::whereKey($this->webhookEventId)->where('status', '!=', 'processed')->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
