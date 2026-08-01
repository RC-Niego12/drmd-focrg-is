<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Notifications\Events\NotificationSent;

class PublishDatabaseNotification
{
    public function __construct(private readonly RealtimePublisher $realtime) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $notificationData = $event->notification->toArray($event->notifiable);
        $this->realtime->userChanged((int) $event->notifiable->id, 'notification.changed', [
            'reason' => 'created',
            'action_key' => data_get($notificationData, 'action_key'),
            'request_id' => data_get($notificationData, 'request_id'),
            'reference_number' => data_get($notificationData, 'reference_number'),
        ]);
    }
}
