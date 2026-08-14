<?php

namespace App\Services;

use App\Models\RegionalAlert;
use App\Models\RegionalAlertRecipient;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Carbon\CarbonInterface;

class RegionalAlertNotificationService
{
    private const LEVEL_RANK = ['white' => 1, 'blue' => 2, 'red' => 3];

    public function ensureDefaultWhite(): RegionalAlert
    {
        $active = RegionalAlert::query()->effective()->latest('effective_at')->first();
        if ($active) {
            return $active;
        }

        $setter = User::query()->role('OCD Caraga')->where('is_active', true)->first()
            ?: User::query()->role('Super Admin')->where('is_active', true)->firstOrFail();
        $previous = RegionalAlert::query()->latest('effective_at')->first();
        $alert = RegionalAlert::create([
            'set_by' => $setter->id,
            'alert_level' => 'white',
            'incident_name' => null,
            'coverage' => 'Caraga Region',
            'reason' => 'Default regional monitoring status. LGU DROMIC / Situational Reports are due at 2:00 PM.',
            'effective_at' => now(),
            'expires_at' => null,
        ]);

        if ($previous) {
            $this->notifyAlertChanged($alert, $previous);
        }

        return $alert;
    }

    public function notifyAlertChanged(RegionalAlert $alert, ?RegionalAlert $previous): void
    {
        $previousLevel = $previous?->alert_level;
        $direction = ! $previousLevel
            ? 'issued'
            : (self::LEVEL_RANK[$alert->alert_level] > self::LEVEL_RANK[$previousLevel] ? 'raised' : (self::LEVEL_RANK[$alert->alert_level] < self::LEVEL_RANK[$previousLevel] ? 'lowered' : 'updated'));
        $times = $this->timeline($alert->alert_level);
        $schedule = implode(' and ', $times);

        $this->activeAlertRecipients()->each(function (User $user) use ($alert, $direction, $schedule, $times): void {
            $isLgu = $user->hasRole('LGU')
                || filled($user->lgu_psgc_code)
                || filled($user->lgu_level)
                || filled($user->lgu_name);
            $role = $user->getRoleNames()
                ->first(fn (string $name): bool => in_array($name, [
                    'LGU', 'DRMD Chief', 'DRMD AA', 'DRRS', 'DRIMS', 'RROS',
                    'DRMD Financial Analyst', 'QRT', 'Quick Response Team',
                ], true));

            RegionalAlertRecipient::query()->updateOrCreate(
                ['regional_alert_id' => $alert->id, 'user_id' => $user->id],
                [
                    'recipient_category' => $isLgu ? 'lgu' : 'dswd',
                    'recipient_name' => $user->name,
                    'recipient_role' => $role ?: $user->designation ?: $user->position,
                    'office' => $user->office,
                    'lgu_name' => $user->lgu_name ?: ($isLgu ? $user->area_of_assignment : null),
                    'notified_at' => now(),
                    'acknowledged_at' => null,
                    'superseded_at' => null,
                    'acknowledgement_method' => null,
                ],
            );
            $canViewAcknowledgements = $user->can('view regional alert acknowledgements')
                || $user->hasAnyRole([
                    'Super Admin',
                    'RROS',
                    'RROS AA',
                    'DRRS',
                    'DRRS AA',
                    'DRIMS',
                    'DRMD AA',
                    'DRMD Chief',
                    'DRMD Financial Analyst',
                    'OCD Caraga',
                    'QRT',
                    'Quick Response Team',
                ]);
            $notificationUrl = $isLgu
                ? route('lgu.dromic-requests.index')
                : ($canViewAcknowledgements
                    ? route('alert-acknowledgments.index', ['alert_id' => $alert->id])
                    : route('dashboard'));

            $user->notify(new WorkflowNotification([
                'workflow' => 'OCD Caraga regional alert',
                'action_key' => 'ocd_alert_changed',
                'action_required' => false,
                'title' => 'Regional alert '.$direction.' to '.strtoupper($alert->alert_level),
                'message' => 'OCD Caraga '.$direction.' the alert level to '.strtoupper($alert->alert_level).'. LGU DROMIC / Situational Reports are due at '.$schedule.'. '.$alert->reason,
                'url' => $notificationUrl,
                'meta' => [
                    'alert_id' => $alert->id,
                    'alert_level' => $alert->alert_level,
                    'incident_name' => $alert->incident_name,
                    'coverage' => $alert->coverage,
                    'reporting_times' => $times,
                ],
            ]));
        });
    }

    public function notifyUpcomingDeadline(RegionalAlert $alert, CarbonInterface $deadline): int
    {
        $count = 0;

        $this->activeLguUsers()->each(function (User $user) use ($alert, $deadline, &$count): void {
            $key = $alert->id.'|'.$deadline->format('Y-m-d H:i');
            $exists = $user->notifications()
                ->where('data->action_key', 'ocd_reporting_deadline')
                ->where('data->meta->deadline_key', $key)
                ->exists();

            if ($exists) {
                return;
            }

            $user->notify(new WorkflowNotification([
                'workflow' => 'OCD Caraga reporting timeline',
                'action_key' => 'ocd_reporting_deadline',
                'action_required' => false,
                'title' => strtoupper($alert->alert_level).' Alert reporting deadline approaching',
                'message' => 'The '.strtoupper($alert->alert_level).' Alert DROMIC / Situational Report deadline is '.$deadline->format('g:i A').' today. Submit the current report to DSWD and OCD Caraga as soon as possible.',
                'url' => route('lgu.dromic-requests.index'),
                'meta' => [
                    'alert_id' => $alert->id,
                    'alert_level' => $alert->alert_level,
                    'deadline_at' => $deadline->toIso8601String(),
                    'deadline_key' => $key,
                ],
            ]));
            $count++;
        });

        return $count;
    }

    public function timeline(string $level): array
    {
        return $level === 'white' ? ['2:00 PM'] : ['10:00 AM', '10:00 PM'];
    }

    private function activeLguUsers()
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', 'LGU'))
                    ->orWhereNotNull('lgu_psgc_code')
                    ->orWhereNotNull('lgu_level')
                    ->orWhereNotNull('lgu_name');
            })
            ->get();
    }

    private function activeAlertRecipients()
    {
        $operationalRoles = [
            'LGU',
            'DRMD Chief',
            'DRMD AA',
            'DRRS',
            'DRIMS',
            'RROS',
            'DRMD Financial Analyst',
            'QRT',
            'Quick Response Team',
        ];

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($operationalRoles): void {
                $query
                    ->whereHas('roles', fn ($roles) => $roles->whereIn('name', $operationalRoles))
                    ->orWhereNotNull('lgu_psgc_code')
                    ->orWhereNotNull('lgu_level')
                    ->orWhereNotNull('lgu_name')
                    ->orWhere(function ($operationalAssignment): void {
                        foreach (['office', 'position', 'designation', 'area_of_assignment'] as $field) {
                            foreach (['%DRMD%', '%Disaster Response Management Division%', '%QRT%', '%Quick Response Team%'] as $pattern) {
                                $operationalAssignment->orWhere($field, 'like', $pattern);
                            }
                        }
                    });
            })
            ->get();
    }
}
