<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AccessDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $status,
        private readonly ?string $role,
        private readonly ?string $responseMessage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'kind' => 'access_decided',
            'title' => $approved ? 'System access granted' : 'System access request disapproved',
            'message' => $approved
                ? 'Your account was granted '.($this->role ?: 'assigned').' access.'
                : ($this->responseMessage ?: 'Your system access request was not approved.'),
            'status' => $this->status,
            'assigned_role' => $this->role,
            'response_message' => $this->responseMessage,
            'url' => $approved ? route('dashboard') : route('access.request'),
        ];
    }
}
