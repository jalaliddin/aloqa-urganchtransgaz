<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AlertRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'type' => $this->alert->type->value,
            'severity' => $this->alert->severity->value,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'subject_type' => $this->alert->subject_type,
            'subject_id' => $this->alert->subject_id,
        ];
    }
}
