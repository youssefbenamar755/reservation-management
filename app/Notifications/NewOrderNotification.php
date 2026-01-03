<?php

namespace App\Notifications;

use App\Models\WcOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NewOrderNotification extends Notification
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
        return ['database'];
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

