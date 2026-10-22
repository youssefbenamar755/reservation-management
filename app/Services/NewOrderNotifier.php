<?php

namespace App\Services;

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NewOrderNotifier
{
    /** Called only for a first insertion, inside the order store's transaction. */
    public function notify(WcOrder $order, Website $website): void
    {
        $now = now('UTC');
        $createdAt = $order->created_at_wp;

        // Use creation time, never modification time: historical repairs and old
        // orders changing status must not sound like new sales. A day allows for
        // delayed webhooks/sync recovery; pre-connection history stays silent.
        if (! $createdAt
            || $createdAt->lt($now->copy()->subDay())
            || ($website->created_at && $createdAt->lt($website->created_at))
            || $createdAt->gt($now->copy()->addMinutes(5))) {
            return;
        }

        $order->setRelation('website', $website);
        $owner = $website->user;
        $users = User::where('is_admin', true)->get()
            ->when($owner, fn ($users) => $users->push($owner))
            ->unique('id');

        foreach ($users as $user) {
            $notification = new NewOrderNotification($order);
            $notification->id = (string) Str::uuid();
            // The website row lock serializes competing imports. Saving these
            // alongside the first order insert makes retries duplicate-free.
            $user->notifyNow($notification, ['database']);

            DB::afterCommit(function () use ($user, $notification, $order) {
                try {
                    // Reuse the saved inbox ID so live delivery and refetch deduplicate.
                    $user->notifyNow($notification, ['broadcast']);
                } catch (Throwable $e) {
                    Log::error('Failed to send new order notification', [
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
    }
}
