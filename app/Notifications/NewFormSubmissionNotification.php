<?php

namespace App\Notifications;

use App\Models\FfSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NewFormSubmissionNotification extends Notification
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
        return ['database'];
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

