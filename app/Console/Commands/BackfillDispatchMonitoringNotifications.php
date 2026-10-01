<?php

namespace App\Console\Commands;

use App\Http\Controllers\DispatchMonitoringController;
use App\Models\DispatchDeliveryUpdate;
use App\Models\DispatchPlan;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillDispatchMonitoringNotifications extends Command
{
    protected $signature = 'dispatch:backfill-monitoring-notifications {--user= : Limit to one user ID or email}';

    protected $description = 'Create clickable historical notifications for already-posted delivery updates';

    public function handle(): int
    {
        $updates = DispatchDeliveryUpdate::query()
            ->with(['dispatchPlan.request'])
            ->oldest('occurred_at')
            ->get();

        $localHandovers = DispatchPlan::query()->with('request')
            ->whereNotNull('local_handover_details')->get()
            ->filter(fn (DispatchPlan $dispatch): bool => filled(data_get($dispatch->local_handover_details, 'released_at'))
                || filled(data_get($dispatch->local_handover_details, 'received_at')));

        if ($updates->isEmpty() && $localHandovers->isEmpty()) {
            $this->warn('No posted delivery updates or local handovers were found.');
            return self::SUCCESS;
        }

        $users = User::query()->where('is_active', true)
            ->when($this->option('user'), function ($query, $value): void {
                $query->where(fn ($match) => $match->whereKey($value)->orWhere('email', $value));
            })
            ->with(['roles', 'permissions'])
            ->get()
            ->filter(fn (User $user): bool => DispatchMonitoringController::userCanView($user));

        if ($users->isEmpty()) {
            $this->error('No active authorized monitoring users matched.');
            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        foreach ($users as $user) {
            $existingKeys = $user->notifications()->get(['data'])->pluck('data')
                ->map(fn (array $data) => $data['backfill_key'] ?? null)->filter()->flip();

            foreach ($updates as $update) {
                $dispatch = $update->dispatchPlan;
                if (! $dispatch) continue;
                $key = "dispatch-update:{$update->id}:user:{$user->id}";
                if ($existingKeys->has($key)) {
                    $skipped++;
                    continue;
                }

                $stage = Str::headline((string) $update->stage);
                $vehicle = $dispatch->resolvedVehicleDetails()[(int) $update->vehicle_index] ?? [];
                $vehicleLabel = trim((string) ($vehicle['vehicle_plate_number'] ?? $vehicle['vehicle_type'] ?? 'Vehicle '.((int) $update->vehicle_index + 1)));
                $photoCount = count($update->photo_paths ?? []);
                $user->notify(new WorkflowNotification([
                    'workflow' => 'Delivery situation monitoring · Historical test',
                    'action_key' => 'dispatch_delivery_update_'.$update->stage,
                    'action_required' => false,
                    'title' => "{$dispatch->dispatch_number}: {$stage}",
                    'message' => "Historical update for {$vehicleLabel} at {$update->location}. Open to test the monitoring details".($photoCount ? " and {$photoCount} evidence photo(s)." : '.'),
                    'request_id' => $dispatch->request_id,
                    'reference_number' => $dispatch->request?->reference_number,
                    'url' => route('delivery-monitoring.index', ['dispatch_id' => $dispatch->id, 'update_id' => $update->id]),
                    'meta' => [
                        'dispatch_id' => $dispatch->id,
                        'delivery_update_id' => $update->id,
                        'vehicle_index' => (int) $update->vehicle_index,
                        'stage' => $update->stage,
                        'historical_test' => true,
                    ],
                    'backfill_key' => $key,
                ]));
                $created++;
            }

            foreach ($localHandovers as $dispatch) {
                $handover = is_array($dispatch->local_handover_details) ? $dispatch->local_handover_details : [];
                foreach (['released' => 'released_at', 'received' => 'received_at'] as $milestone => $field) {
                    if (blank($handover[$field] ?? null)) continue;
                    $key = "dispatch-local-{$milestone}:{$dispatch->id}:user:{$user->id}";
                    if ($existingKeys->has($key)) {
                        $skipped++;
                        continue;
                    }
                    $warehouse = trim((string) ($handover['source_warehouse_name'] ?? 'Local warehouse'));
                    $isReceipt = $milestone === 'received';
                    $user->notify(new WorkflowNotification([
                        'workflow' => 'Local release and receipt monitoring · Historical test',
                        'action_key' => 'dispatch_local_handover_'.$milestone,
                        'action_required' => false,
                        'title' => $isReceipt ? "{$dispatch->dispatch_number}: Local receipt confirmed" : "{$dispatch->dispatch_number}: Local items released",
                        'message' => $isReceipt
                            ? "Historical no-transport receipt from {$warehouse}. Open to test the local handover view."
                            : "Historical no-transport release by {$warehouse}. No vehicle or escort was involved.",
                        'request_id' => $dispatch->request_id,
                        'reference_number' => $dispatch->request?->reference_number,
                        'url' => route('delivery-monitoring.index', ['dispatch_id' => $dispatch->id, 'operation' => 'local']),
                        'meta' => ['dispatch_id' => $dispatch->id, 'operation' => 'local', 'stage' => $milestone, 'historical_test' => true],
                        'backfill_key' => $key,
                    ]));
                    $created++;
                }
            }
        }

        $this->info("Created {$created} historical monitoring notification(s); skipped {$skipped} existing notification(s)." );
        return self::SUCCESS;
    }
}
