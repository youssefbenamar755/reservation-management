<?php

namespace App\Notifications;

use App\Models\WcOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NewOrderNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        public WcOrder $order
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the broadcast event name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast(object $notifiable): array
    {
        $website = $this->order->website;
        $amount = $this->order->total ? '$' . number_format($this->order->total, 2) : 'N/A';
        $paymentStatus = $this->order->payment_status ?? 'unknown';

        $data = [
            'type' => 'order',
            'website_id' => $this->order->website_id,
            'website_name' => $website->name ?? 'Unknown',
            'order_id' => $this->order->id,
            'wp_order_id' => $this->order->wp_order_id,
            'amount' => $this->order->total,
            'amount_formatted' => $amount,
            'payment_status' => $paymentStatus,
            'message' => "New order #{$this->order->wp_order_id} from {$website->name} — {$amount}",
        ];

        // Get the notification ID from database (it's created when stored)
        // Use the notification's ID property which Laravel sets after storing
        $notificationId = $this->id ?? null;
        
        if (!$notificationId) {
            // Fallback: try to find it in the database
            $notification = $notifiable->notifications()
                ->where('type', self::class)
                ->where('data->order_id', $this->order->id)
                ->latest()
                ->first();
            $notificationId = $notification?->id;
        }

        return [
            'id' => $notificationId ?? uniqid(),
            'type' => $data['type'],
            'message' => $data['message'],
            'created_at' => now()->toISOString(),
            'redirect_url' => route('orders.show', $this->order->id),
            'data' => $data,
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $website = $this->order->website;
        $amount = $this->order->total ? '$' . number_format($this->order->total, 2) : 'N/A';
        $paymentStatus = $this->order->payment_status ?? 'unknown';

        return [
            'type' => 'order',
            'website_id' => $this->order->website_id,
            'website_name' => $website->name ?? 'Unknown',
            'order_id' => $this->order->id,
            'wp_order_id' => $this->order->wp_order_id,
            'amount' => $this->order->total,
            'amount_formatted' => $amount,
            'payment_status' => $paymentStatus,
            'message' => "New order #{$this->order->wp_order_id} from {$website->name} — {$amount}",
        ];
    }
}

