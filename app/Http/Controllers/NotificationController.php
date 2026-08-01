<?php

namespace App\Http\Controllers;

use App\Models\RegionalAlert;
use App\Models\RegionalAlertRecipient;
use App\Services\AccessNotificationCenter;
use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function __construct(private readonly AccessNotificationCenter $notificationCenter) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notificationCenter->forUser($request->user()));
    }

    public function read(Request $request, string $notification, RealtimePublisher $realtime): Response
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();
        $realtime->userChanged((int) $request->user()->id, 'notification.changed', ['reason' => 'read']);

        return response()->noContent();
    }

    public function readAll(Request $request, RealtimePublisher $realtime): Response
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        $realtime->userChanged((int) $request->user()->id, 'notification.changed', [
            'reason' => 'read_all',
        ]);

        return response()->noContent();
    }

    public function acknowledgeRegionalAlert(
        Request $request,
        string $notification,
        RealtimePublisher $realtime,
    ): Response
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        abort_unless(data_get($item->data, 'action_key') === 'ocd_alert_changed', 422, 'This notification is not an OCD regional alert update.');
        $alert = RegionalAlert::query()->findOrFail((int) data_get($item->data, 'meta.alert_id'));
        $user = $request->user();
        $isLgu = $user->hasRole('LGU')
            || filled($user->lgu_psgc_code)
            || filled($user->lgu_level)
            || filled($user->lgu_name);

        RegionalAlertRecipient::query()->updateOrCreate(
            ['regional_alert_id' => $alert->id, 'user_id' => $user->id],
            [
                'recipient_category' => $isLgu ? 'lgu' : 'dswd',
                'recipient_name' => $user->name,
                'recipient_role' => $user->getRoleNames()->first() ?: $user->designation ?: $user->position,
                'office' => $user->office,
                'lgu_name' => $user->lgu_name ?: ($isLgu ? $user->area_of_assignment : null),
                'notified_at' => $item->created_at,
                'acknowledged_at' => now(),
                'superseded_at' => null,
                'acknowledgement_method' => 'attention_modal',
            ],
        );

        RegionalAlertRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->whereNull('superseded_at')
            ->where('regional_alert_id', '!=', $alert->id)
            ->whereHas('alert', fn ($query) => $query->where('effective_at', '<=', $alert->effective_at))
            ->update(['superseded_at' => now()]);

        $user->unreadNotifications()
            ->where('data->action_key', 'ocd_alert_changed')
            ->where('created_at', '<=', $item->created_at)
            ->update(['read_at' => now()]);

        $realtime->userChanged((int) $user->id, 'notification.changed', ['reason' => 'acknowledged']);
        $monitorIds = \App\Models\User::permission('view regional alert acknowledgements')
            ->where('is_active', true)
            ->pluck('id');
        $realtime->usersChanged($monitorIds, 'regional-alert.acknowledgement.changed', [
            'alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);

        return response()->noContent();
    }
}
