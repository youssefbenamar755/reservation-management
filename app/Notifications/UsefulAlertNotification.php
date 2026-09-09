<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UsefulAlertNotification extends Notification
{
    public function __construct(private array $data) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function broadcastAs(): string
    {
        return 'notification';
    }

    public function broadcastType(): string
    {
        return 'useful_alert';
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(['id' => $this->id, 'type' => 'useful_alert', 'message' => $this->data['message'],
            'read_at' => null, 'created_at' => now()->toIso8601String(), 'redirect_url' => '/alerts', 'data' => $this->data]))->onConnection('sync');
    }
}
