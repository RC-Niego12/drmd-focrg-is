<?php

namespace App\Console\Commands;

use App\Models\DispatchPlan;
use App\Services\WorkflowNotificationService;
use Illuminate\Console\Command;

class BackfillDispatchPlanReadyNotifications extends Command
{
    protected $signature = 'dispatch:notify-plan-ready
                            {--dispatch= : Limit to one dispatch ID}
                            {--force : Resend even when a ready-for-release notice already exists}';

    protected $description = 'Notify DRMD and concerned LGUs for dispatches that already have a complete transport plan';

    public function handle(WorkflowNotificationService $notifications): int
    {
        $dispatches = DispatchPlan::query()
            ->with(['request.sourceLguDromicReport.lguSubmitter', 'request.encoder', 'request.lguSubmitter'])
            ->when($this->option('dispatch'), fn ($query, $id) => $query->whereKey($id))
            ->whereIn('status', [
                DispatchPlan::STATUS_PLANNED,
                DispatchPlan::STATUS_RELEASED,
                DispatchPlan::STATUS_IN_TRANSIT,
                DispatchPlan::STATUS_RECEIVED,
            ])
            ->latest('updated_at')
            ->get()
            ->filter(fn (DispatchPlan $dispatch): bool => $dispatch->isTransportPlanComplete());

        if ($dispatches->isEmpty()) {
            $this->warn('No fully planned dispatches matched.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $sent = 0;
        $skipped = 0;

        foreach ($dispatches as $dispatch) {
            $request = $dispatch->request;
            if (! $request) {
                $skipped++;
                continue;
            }

            if (! $force) {
                $exists = \App\Models\User::query()
                    ->where('is_active', true)
                    ->where(function ($query): void {
                        $query->where('office', 'like', 'DRMD%')
                            ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'like', 'DRMD%'));
                    })
                    ->whereHas('notifications', function ($query) use ($dispatch): void {
                        $query->where('data->action_key', 'dispatch_plan_ready_for_release')
                            ->where('data->meta->dispatch_id', $dispatch->id);
                    })
                    ->exists();

                if ($exists) {
                    $this->line("Skip {$dispatch->dispatch_number}: ready-for-release notice already exists.");
                    $skipped++;
                    continue;
                }
            }

            $notifications->notifyDispatchPlanReadyForRelease($request, $dispatch);
            $this->info("Notified for {$dispatch->dispatch_number} ({$request->reference_number}).");
            $sent++;
        }

        $this->info("Done. Sent {$sent}; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
