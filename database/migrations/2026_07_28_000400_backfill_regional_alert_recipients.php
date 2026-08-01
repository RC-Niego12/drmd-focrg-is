<?php

use App\Models\RegionalAlertRecipient;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Notifications\DatabaseNotification;

return new class extends Migration
{
    public function up(): void
    {
        $userIds = collect();

        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('data->action_key', 'ocd_alert_changed')
            ->orderBy('created_at')
            ->each(function (DatabaseNotification $notification) use ($userIds): void {
                $alertId = (int) data_get($notification->data, 'meta.alert_id');
                $user = User::query()->find($notification->notifiable_id);
                if ($alertId < 1 || ! $user) {
                    return;
                }

                $isLgu = $user->hasRole('LGU')
                    || filled($user->lgu_psgc_code)
                    || filled($user->lgu_level)
                    || filled($user->lgu_name);
                RegionalAlertRecipient::query()->firstOrCreate(
                    ['regional_alert_id' => $alertId, 'user_id' => $user->id],
                    [
                        'recipient_category' => $isLgu ? 'lgu' : 'dswd',
                        'recipient_name' => $user->name,
                        'recipient_role' => $user->getRoleNames()->first() ?: $user->designation ?: $user->position,
                        'office' => $user->office,
                        'lgu_name' => $user->lgu_name ?: ($isLgu ? $user->area_of_assignment : null),
                        'notified_at' => $notification->created_at,
                    ],
                );
                $userIds->push($user->id);
            });

        $userIds->unique()->each(function (int $userId): void {
            $latestId = RegionalAlertRecipient::query()
                ->where('user_id', $userId)
                ->max('regional_alert_id');

            RegionalAlertRecipient::query()
                ->where('user_id', $userId)
                ->where('regional_alert_id', '!=', $latestId)
                ->whereNull('acknowledged_at')
                ->update(['superseded_at' => now()]);
        });
    }

    public function down(): void
    {
        // Recipient snapshots are audit data and are intentionally retained.
    }
};
