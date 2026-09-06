<?php

namespace App\Events;

use App\Models\User;
use App\Models\Website;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WcOrdersSynced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Website $website) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return User::where('is_admin', true)->pluck('id')
            ->when($this->website->user_id, fn ($ids) => $ids->push($this->website->user_id))
            ->unique()
            ->map(fn ($id) => new PrivateChannel('orders.'.$id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'order.received';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['website_id' => $this->website->id];
    }
}
