<?php

namespace App\Http\Controllers;

use App\Models\DispatchDeliveryUpdate;
use App\Models\DispatchPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DispatchMonitoringController extends Controller
{
    private const VIEW_ROLES = ['Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Chief', 'DRMD Financial Analyst', 'QRT', 'Quick Response Team', 'Regional Director', 'RD', 'Assistant Regional Director', 'ARD', 'Assistant Regional Director for Operations', 'ARDO'];

    public function index(Request $request): Response
    {
        abort_unless(self::userCanView($request->user()), 403, 'You are not authorized to view delivery monitoring.');
        $dispatches = DispatchPlan::query()
            ->with(['request:id,reference_number,requesting_agency,lgu,municipality,province,purpose', 'items', 'deliveryUpdates.reporter:id,name'])
            ->whereIn('status', [DispatchPlan::STATUS_PLANNED, DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT, DispatchPlan::STATUS_RECEIVED])
            ->latest('updated_at')->limit(100)->get()
            ->map(fn (DispatchPlan $dispatch): array => $this->serialize($dispatch));

        return Inertia::render('DispatchMonitoring/Index', [
            'dispatches' => $dispatches,
            'focusDispatchId' => $request->integer('dispatch_id') ?: null,
            'focusUpdateId' => $request->integer('update_id') ?: null,
            'focusOperation' => $request->query('operation') === 'local' ? 'local' : null,
            'focusStage' => filled($request->query('stage')) ? (string) $request->query('stage') : null,
        ]);
    }

    public static function userCanView(?User $user): bool
    {
        return (bool) ($user && $user->is_active && ($user->can('view dispatch delivery monitoring') || $user->hasAnyRole(self::VIEW_ROLES)));
    }

    private function serialize(DispatchPlan $dispatch): array
    {
        // resolvedVehicleDetails() intentionally creates a legacy placeholder row when
        // vehicle_details is empty. That is useful to older transport forms, but it must
        // never make a direct/local handover appear to have a vehicle or an escort.
        $resolvedVehicles = collect($dispatch->resolvedVehicleDetails());
        $vehicles = $resolvedVehicles
            ->filter(fn (array $vehicle): bool => collect([
                $vehicle['source_warehouse_id'] ?? null,
                $vehicle['source_warehouse_name'] ?? null,
                $vehicle['vehicle_type'] ?? null,
                $vehicle['vehicle_plate_number'] ?? null,
                $vehicle['driver'] ?? null,
                $vehicle['escort_name'] ?? null,
                $vehicle['plan_confirmed_at'] ?? null,
                $vehicle['warehouse_released_at'] ?? null,
                $vehicle['departed_at'] ?? null,
                $vehicle['received_at'] ?? null,
            ])->contains(fn ($value): bool => filled($value)));
        $updates = $dispatch->deliveryUpdates->map(function (DispatchDeliveryUpdate $update) use ($dispatch, $resolvedVehicles): array {
            $vehicle = $resolvedVehicles->get((int) $update->vehicle_index, []);
            return [
                'id' => $update->id, 'vehicle_index' => (int) $update->vehicle_index, 'stage' => $update->stage,
                'occurred_at' => optional($update->occurred_at)?->toIso8601String(), 'location' => $update->location,
                'latitude' => $update->latitude !== null ? (float) $update->latitude : null,
                'longitude' => $update->longitude !== null ? (float) $update->longitude : null,
                'accuracy_meters' => $update->accuracy_meters !== null ? (float) $update->accuracy_meters : null,
                'message' => $update->message, 'reporter_name' => $update->reporter?->name, 'reporter_role' => $update->reporter_role,
                'vehicle' => ['type' => $vehicle['vehicle_type'] ?? null, 'plate' => $vehicle['vehicle_plate_number'] ?? null, 'driver' => $vehicle['driver'] ?? null, 'escort' => $vehicle['escort_name'] ?? null],
                'photos' => collect($update->photo_paths ?? [])->keys()->map(fn (int $index): array => [
                    'url' => route('dispatches.delivery-updates.photos.show', [$dispatch, $update, $index]),
                    'download_url' => route('dispatches.delivery-updates.photos.show', [$dispatch, $update, $index]).'?download=1',
                    'label' => 'Evidence photo '.($index + 1),
                ])->values()->all(),
            ];
        })->values();

        return [
            'id' => $dispatch->id, 'dispatch_number' => $dispatch->dispatch_number, 'status' => $dispatch->status,
            'updated_at' => optional($dispatch->updated_at)?->toIso8601String(), 'destination' => $dispatch->destination,
            'receiving_agency_lgu' => $dispatch->receiving_agency_lgu, 'purpose' => $dispatch->purpose, 'request' => $dispatch->request,
            'local_handover' => $dispatch->local_handover_details,
            'transport_required' => $vehicles->isNotEmpty(),
            'operation_type' => $vehicles->isNotEmpty() && filled(data_get($dispatch->local_handover_details, 'source_warehouse_name'))
                ? 'mixed'
                : ($vehicles->isNotEmpty() ? 'transport_delivery' : 'local_handover'),
            'vehicles' => $vehicles->map(fn (array $vehicle, int $index): array => [
                'index' => $index, 'type' => $vehicle['vehicle_type'] ?? null, 'plate' => $vehicle['vehicle_plate_number'] ?? null,
                'driver' => $vehicle['driver'] ?? null, 'escort' => $vehicle['escort_name'] ?? null,
                'source' => $vehicle['source_warehouse_name'] ?? null, 'released_at' => $vehicle['warehouse_released_at'] ?? null,
                'departed_at' => $vehicle['departed_at'] ?? null, 'received_at' => $vehicle['received_at'] ?? null,
                'loaded_items' => collect($vehicle['loaded_items'] ?? [])->filter(fn ($item) => is_array($item))->map(fn (array $item): array => [
                    'requisition_issuance_item_id' => $item['requisition_issuance_item_id'] ?? null,
                    'quantity' => (int) ($item['loaded_quantity'] ?? 0),
                ])->values()->all(),
            ])->values()->all(),
            'items' => $dispatch->items->map(fn ($item): array => [
                'id' => $item->id, 'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                'name' => $item->item_name, 'unit' => $item->unit, 'warehouse_id' => $item->warehouse_id, 'warehouse' => $item->warehouse_name,
                'allocated' => (int) $item->allocated_quantity, 'loaded' => (int) $item->loaded_quantity,
                'received' => $item->received_quantity !== null ? (int) $item->received_quantity : null,
                'variance' => max(0, (int) $item->allocated_quantity - (int) ($item->received_quantity ?? 0)),
                'disposition' => $item->variance_disposition,
            ])->values()->all(),
            'updates' => $updates->all(), 'latest_update' => $updates->first(),
            'photo_count' => $updates->sum(fn (array $update): int => count($update['photos'])),
            'returned_quantity' => (int) ($dispatch->returned_quantity ?? 0),
            'returned_particulars' => $dispatch->returned_particulars, 'returned_reason' => $dispatch->returned_reason,
        ];
    }
}
