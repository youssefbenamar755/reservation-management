<?php

namespace App\Events;

use App\Models\User;
use App\Models\WcOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewWcOrderReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WcOrder $order
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $ownerId = $this->order->website?->user_id;

        return User::where('is_admin', true)->pluck('id')
            ->when($ownerId, fn ($ids) => $ids->push($ownerId))
            ->unique()
            ->map(fn ($id) => new PrivateChannel('orders.'.$id))
            ->values()
            ->all();
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.received';
    }

    /**
     * Data to broadcast (minimal; frontend will refetch the list).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'website_id' => $this->order->website_id,
        ];
    }
}
