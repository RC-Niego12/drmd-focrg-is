<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AccessRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly User $requestingUser) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'access_requested',
            'title' => 'New system access request',
            'message' => $this->requestingUser->name.' requested '.$this->requestingUser->requested_role.' access.',
            'access_user_id' => $this->requestingUser->id,
            'requested_role' => $this->requestingUser->requested_role,
            'url' => route('access-management.index'),
        ];
    }
}
