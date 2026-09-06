<?php

namespace App\Notifications;

use App\Models\WcOrder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification implements ShouldBroadcast
{
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
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    public function broadcastType(): string
    {
        return 'order';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            // Laravel assigns this before delivery; the webhook reuses the saved inbox ID.
            'id' => $this->id,
            'type' => $data['type'],
            'message' => $data['message'],
            'read_at' => null,
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
        $websiteName = $this->order->website?->name ?? 'Unknown';
        $currency = strtoupper((string) $this->order->currency);
        $amount = $this->order->total !== null
            ? trim($currency.' '.number_format((float) $this->order->total, 2))
            : 'N/A';
        $paymentStatus = $this->order->payment_status ?? 'unknown';

        return [
            'type' => 'order',
            'website_id' => $this->order->website_id,
            'website_name' => $websiteName,
            'order_id' => $this->order->id,
            'wp_order_id' => $this->order->wp_order_id,
            'amount' => $this->order->total,
            'currency' => $currency,
            'amount_formatted' => $amount,
            'payment_status' => $paymentStatus,
            'message' => "New order #{$this->order->wp_order_id} from {$websiteName} — {$amount}",
        ];
    }
}
