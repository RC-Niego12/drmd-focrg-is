<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\RegionalAlertRecipient;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

class AccessNotificationCenter
{
    public const REQUESTABLE_ROLES = ['RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'];

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
        $unreadCount = $user->unreadNotifications()->count();
        $unreadNotifications = $user->unreadNotifications()
            ->latest()
            ->limit(100)
            ->get();
        $recentReadNotifications = $user->readNotifications()
            ->latest()
            ->limit(max(0, 30 - $unreadNotifications->count()))
            ->get();
        $notifications = $unreadNotifications
            ->concat($recentReadNotifications)
            ->sortByDesc('created_at')
            ->map(fn (DatabaseNotification $notification): array => $this->serializeNotification($notification))
            ->values();
        $workflowActions = $notifications
            ->filter(fn (array $notification): bool => ($notification['action_required'] ?? false) && ! ($notification['acted'] ?? false))
            ->count();
        $pendingRegionalAlert = RegionalAlertRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->whereNull('superseded_at')
            ->latest('regional_alert_id')
            ->first();
        $regionalAlertPrompt = null;

        if ($pendingRegionalAlert) {
            $notification = $user->notifications()
                ->where('data->action_key', 'ocd_alert_changed')
                ->where('data->meta->alert_id', $pendingRegionalAlert->regional_alert_id)
                ->latest()
                ->first();
            $regionalAlertPrompt = $notification
                ? $this->serializeNotification($notification)
                : null;
        }

        return [
            'unread_count' => $unreadCount,
            'unread_overflow' => max(0, $unreadCount - $unreadNotifications->count()),
            'action_required_count' => $pendingRequests->count() + $workflowActions,
            'notifications' => $notifications,
            'pending_requests' => $pendingRequests,
            'role_options' => $isSuperAdmin ? $this->roleOptions() : [],
            'regional_alert_prompt' => $regionalAlertPrompt,
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
            ->orderByRaw("case name when 'Super Admin' then 0 when 'RROS' then 1 when 'RROS AA' then 2 when 'DRRS' then 3 when 'DRRS AA' then 4 when 'DRIMS' then 5 when 'DRMD AA' then 6 when 'DRMD Financial Analyst' then 7 else 8 end")
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
        // Manual RIS / DR signing has no system completion flag — treat as acted once read.
        if (! $acted && data_get($data, 'action_key') === 'ris_ready_for_signing' && filled($notification->read_at)) {
            $acted = true;
        }
        $regionalAlertAcknowledgedAt = null;
        if (data_get($data, 'action_key') === 'ocd_alert_changed') {
            $regionalAlertAcknowledgedAt = RegionalAlertRecipient::query()
                ->where('regional_alert_id', (int) data_get($data, 'meta.alert_id'))
                ->where('user_id', $notification->notifiable_id)
                ->value('acknowledged_at');
        }

        return [
            'id' => $notification->id,
            ...$data,
            'url' => $this->internalPath(data_get($data, 'url')),
            'acted' => $acted,
            'action_required' => (bool) data_get($data, 'action_required', false),
            'read_at' => $notification->read_at?->toDateTimeString(),
            'created_at' => $notification->created_at?->toDateTimeString(),
            'regional_alert_acknowledged_at' => $regionalAlertAcknowledgedAt
                ? Carbon::parse($regionalAlertAcknowledgedAt)->toDateTimeString()
                : null,
        ];
    }

    private function internalPath(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $parts = parse_url($value);
        $appParts = parse_url((string) config('app.url'));
        if (! is_array($parts)
            || ! is_array($appParts)
            || strcasecmp((string) ($parts['host'] ?? ''), (string) ($appParts['host'] ?? '')) !== 0) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $query = filled($parts['query'] ?? null) ? '?'.$parts['query'] : '';
        $fragment = filled($parts['fragment'] ?? null) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
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
            'drrs_aa_epirma_required' => in_array((string) $request->epirma_aa_status, ['in_progress', 'completed'], true)
                || filled($request->epirma_assessment_signed_at),
            'rros_decision_required' => in_array($request->status, ['approved', 'partially_approved', 'rejected', 'released', 'completed'], true),
            'rros_epirma_document_ready' => true,
            'ris_post_monitoring_required' => (function () use ($request): bool {
                $slip = $request->requisitionIssuanceSlip;

                return (bool) ($slip?->hasCompletePostRisData());
            })(),
            // Offline print/sign — stays actionable until the officer has opened (read) the notice.
            // Completion is not tied to Dispatch Plan create (that is a later logistics phase).
            'ris_ready_for_signing' => false,
            'drrs_pdrc_epirma_document_signed' => true,
            'lgu_response_letter_advance_ack_required' => filled($request->lgu_response_letter_advance_acked_at),
            'lgu_response_letter_ack_required' => filled($request->lgu_response_letter_acked_at),
            'drims_dromic_ack_required' => filled($request->lgu_dromic_acked_at),
            'drrs_relief_request_ack_required' => filled($request->lgu_relief_acked_at),
            'drims_dromic_required' => DromicReport::query()->where('request_id', $request->id)->exists(),
            'lgu_dromic_aa_review' => $request->lgu_routing_status !== 'for_drmd_aa_review',
            'lgu_dromic_chief_directive' => $request->lgu_routing_status !== 'for_drmd_chief_directive',
            'lgu_dromic_aa_final_route' => $request->lgu_routing_status !== 'for_drmd_aa_routing',
            default => false,
        };
    }
}
