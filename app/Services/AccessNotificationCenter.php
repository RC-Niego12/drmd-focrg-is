<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Role;

class AccessNotificationCenter
{
    public const REQUESTABLE_ROLES = ['RROS', 'DRRS', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'];

    public function forUser(User $user): array
    {
        $isSuperAdmin = $user->hasRole('Super Admin');

        $pendingRequests = $isSuperAdmin
            ? User::query()
                ->where('access_status', 'pending')
                ->whereNotNull('requested_role')
                ->orderBy('access_requested_at')
                ->get()
                ->map(fn (User $pendingUser): array => $this->serializeAccessUser($pendingUser))
                ->values()
            : collect();
        $notifications = $user->notifications()
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->serializeNotification($notification))
            ->values();
        $workflowActions = $notifications
            ->filter(fn (array $notification): bool => ($notification['action_required'] ?? false) && ! ($notification['acted'] ?? false))
            ->count();

        return [
            'unread_count' => $user->unreadNotifications()->count(),
            'action_required_count' => $pendingRequests->count() + $workflowActions,
            'notifications' => $notifications,
            'pending_requests' => $pendingRequests,
            'role_options' => $isSuperAdmin ? $this->roleOptions() : [],
            'access' => [
                'status' => $user->access_status,
                'assigned_role' => $user->getRoleNames()->first(),
                'response_message' => $user->access_response_message,
                'decided_at' => $user->access_decided_at?->toDateTimeString(),
                'home_url' => route('dashboard'),
            ],
        ];
    }

    public function roleOptions(bool $includeSuperAdmin = false): array
    {
        $roles = $includeSuperAdmin
            ? ['Super Admin', ...self::REQUESTABLE_ROLES]
            : self::REQUESTABLE_ROLES;

        return Role::query()
            ->whereIn('name', $roles)
            ->orderByRaw("case name when 'Super Admin' then 0 when 'RROS' then 1 when 'DRRS' then 2 when 'DRIMS' then 3 when 'DRMD AA' then 4 when 'DRMD Financial Analyst' then 5 else 6 end")
            ->pluck('name')
            ->map(fn (string $role): array => ['value' => $role, 'label' => $role])
            ->values()
            ->all();
    }

    public function serializeAccessUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'office' => $user->office,
            'position' => $user->position,
            'designation' => $user->designation,
            'requested_role' => $user->requested_role,
            'access_status' => $user->access_status,
            'access_requested_at' => $user->access_requested_at?->toDateTimeString(),
            'response_message' => $user->access_response_message,
        ];
    }

    private function serializeNotification(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $acted = $this->notificationHasBeenActed($data);

        return [
            'id' => $notification->id,
            ...$data,
            'acted' => $acted,
            'action_required' => (bool) data_get($data, 'action_required', false),
            'read_at' => $notification->read_at?->toDateTimeString(),
            'created_at' => $notification->created_at?->toDateTimeString(),
        ];
    }

    private function notificationHasBeenActed(array $data): bool
    {
        if (($data['kind'] ?? null) === 'access_requested') {
            $user = User::query()->find(data_get($data, 'access_user_id'));

            return ! $user || $user->access_status !== 'pending';
        }

        $requestId = data_get($data, 'request_id');
        if (! $requestId) {
            return false;
        }

        $request = AssistanceRequest::query()->find($requestId);
        if (! $request) {
            return true;
        }

        return match (data_get($data, 'action_key')) {
            'drrs_assessment_required' => filled($request->assessment_status) || $request->status !== 'endorsed',
            'rros_decision_required' => in_array($request->status, ['approved', 'partially_approved', 'rejected', 'released', 'completed'], true),
            'drims_dromic_required' => DromicReport::query()->where('request_id', $request->id)->exists(),
            'lgu_dromic_aa_review' => $request->lgu_routing_status !== 'for_drmd_aa_review',
            'lgu_dromic_chief_directive' => $request->lgu_routing_status !== 'for_drmd_chief_directive',
            'lgu_dromic_aa_final_route' => $request->lgu_routing_status !== 'for_drmd_aa_routing',
            default => false,
        };
    }
}
