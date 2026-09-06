<?php

namespace App\Notifications;

use App\Models\FfSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;

class NewFormSubmissionNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        public FfSubmission $submission
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
        return 'form_submission';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast(object $notifiable): array
    {
        $website = $this->submission->website;

        $data = [
            'type' => 'form_submission',
            'website_id' => $this->submission->website_id,
            'website_name' => $website->name ?? 'Unknown',
            'form_id' => $this->submission->form_id,
            'submission_id' => $this->submission->id,
            'message' => "New form submission from {$website->name} (Form #{$this->submission->form_id})",
        ];

        // Get the notification ID from database (it's created when stored)
        // Use the notification's ID property which Laravel sets after storing
        $notificationId = $this->id ?? null;

        if (! $notificationId) {
            // Fallback: try to find it in the database
            $notification = $notifiable->notifications()
                ->where('type', self::class)
                ->where('data->submission_id', $this->submission->id)
                ->latest()
                ->first();
            $notificationId = $notification?->id;
        }

        return [
            'id' => $notificationId ?? uniqid(),
            'type' => $data['type'],
            'message' => $data['message'],
            'created_at' => now()->toISOString(),
            'redirect_url' => route('submissions.entry-details', $this->submission->id),
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
        $website = $this->submission->website;

        return [
            'type' => 'form_submission',
            'website_id' => $this->submission->website_id,
            'website_name' => $website->name ?? 'Unknown',
            'form_id' => $this->submission->form_id,
            'submission_id' => $this->submission->id,
            'message' => "New form submission from {$website->name} (Form #{$this->submission->form_id})",
        ];
    }
}
