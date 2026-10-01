<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LguDispatchPlanController extends Controller
{
    public function show(Request $request, DispatchPlan $dispatch): Response
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('LGU') || $user->can('submit lgu dromic requests') || $user->hasRole('Super Admin')),
            403,
            'You are not authorized to view this dispatch plan.',
        );

        $dispatch->loadMissing([
            'request:id,reference_number,requesting_agency,lgu,municipality,province,purpose,lgu_psgc_code,lgu_submitted_by,encoded_by,source_lgu_dromic_request_id,lgu_relief_request_reference',
            'request.sourceLguDromicReport:id,lgu_psgc_code,lgu_submitted_by,requesting_agency',
            'items',
            'requisitionIssuanceSlip:id,ris_number,dr_number',
        ]);

        abort_unless($dispatch->request, 404);
        $this->authorizeLguViewer($user, $dispatch->request);

        return Inertia::render('Lgu/DispatchPlans/Show', [
            'plan' => $this->serializePlan($dispatch),
        ]);
    }

    private function authorizeLguViewer(User $user, AssistanceRequest $assistanceRequest): void
    {
        if ($user->hasRole('Super Admin')) {
            return;
        }

        $psgc = (string) ($user->lgu_psgc_code ?? '');
        $samePsgc = $psgc !== '' && (
            (string) ($assistanceRequest->lgu_psgc_code ?? '') === $psgc
            || (string) ($assistanceRequest->sourceLguDromicReport?->lgu_psgc_code ?? '') === $psgc
        );
        $sameLegacyLgu = blank($assistanceRequest->lgu_psgc_code)
            && filled($user->lgu_name)
            && in_array($user->lgu_name, [$assistanceRequest->requesting_agency, $assistanceRequest->lgu], true);
        $isOriginator = (int) $assistanceRequest->lgu_submitted_by === (int) $user->id
            || (int) $assistanceRequest->encoded_by === (int) $user->id
            || (int) ($assistanceRequest->sourceLguDromicReport?->lgu_submitted_by) === (int) $user->id;

        abort_unless($samePsgc || $sameLegacyLgu || $isOriginator, 403, 'This dispatch plan is not linked to your LGU.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(DispatchPlan $dispatch): array
    {
        $request = $dispatch->request;
        $vehicles = collect($dispatch->resolvedVehicleDetails())
            ->filter(fn ($row): bool => is_array($row)
                && (filled($row['source_warehouse_id'] ?? null) || filled($row['source_warehouse_name'] ?? null)))
            ->values();
        $handover = is_array($dispatch->local_handover_details) ? $dispatch->local_handover_details : [];
        $hasLocal = filled($handover['source_warehouse_id'] ?? null) || filled($handover['source_warehouse_name'] ?? null);

        return [
            'id' => $dispatch->id,
            'dispatch_number' => $dispatch->dispatch_number,
            'status' => $dispatch->status,
            'destination' => $dispatch->destination,
            'receiving_agency_lgu' => $dispatch->receiving_agency_lgu,
            'purpose' => $dispatch->purpose,
            'updated_at' => optional($dispatch->updated_at)?->toIso8601String(),
            'request' => [
                'id' => $request?->id,
                'reference_number' => $request?->reference_number,
                'relief_request_reference' => $request?->lgu_relief_request_reference,
                'requesting_agency' => $request?->requesting_agency,
                'municipality' => $request?->municipality,
                'province' => $request?->province,
                'purpose' => $request?->purpose,
            ],
            'ris' => [
                'ris_number' => $dispatch->requisitionIssuanceSlip?->ris_number,
                'dr_number' => $dispatch->requisitionIssuanceSlip?->dr_number,
            ],
            'local_handover' => $hasLocal ? [
                'source_warehouse_name' => $handover['source_warehouse_name'] ?? null,
                'expected_release_at' => $handover['expected_release_at'] ?? null,
                'plan_confirmed_at' => $handover['plan_confirmed_at'] ?? null,
                'planning_remarks' => $handover['planning_remarks'] ?? ($handover['remarks'] ?? null),
                'dr_number' => $handover['dr_number'] ?? null,
            ] : null,
            'vehicles' => $vehicles->map(function (array $vehicle, int $index): array {
                $fulfillment = (string) ($vehicle['fulfillment_type'] ?? '');
                $arrangement = $fulfillment === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP
                    ? 'Partner / recipient pickup'
                    : ($fulfillment === DispatchPlan::FULFILLMENT_FIELD_DELIVERY
                        ? 'DSWD delivery'
                        : 'To be arranged');

                return [
                    'index' => $index,
                    'label' => 'Vehicle '.($index + 1),
                    'arrangement' => $arrangement,
                    'source_warehouse_name' => $vehicle['source_warehouse_name'] ?? null,
                    'vehicle_type' => $vehicle['vehicle_type'] ?? null,
                    'plate' => $vehicle['vehicle_plate_number'] ?? null,
                    'driver' => $vehicle['driver'] ?? null,
                    'driver_contact' => $vehicle['driver_contact_number'] ?? null,
                    'mode_of_transportation' => $vehicle['mode_of_transportation'] ?? null,
                    'estimated_departure' => $vehicle['estimated_departure'] ?? null,
                    'estimated_arrival' => $vehicle['estimated_arrival'] ?? null,
                    'plan_confirmed_at' => $vehicle['plan_confirmed_at'] ?? null,
                    'planning_remarks' => $vehicle['planning_remarks'] ?? null,
                    'dr_number' => $vehicle['dr_number'] ?? null,
                    'loaded_items' => collect($vehicle['loaded_items'] ?? [])
                        ->filter(fn ($item): bool => is_array($item) && (
                            (int) ($item['planned_quantity'] ?? 0) > 0
                            || (int) ($item['loaded_quantity'] ?? 0) > 0
                            || filled($item['item_name'] ?? null)
                        ))
                        ->map(fn (array $item): array => [
                            'item_name' => $item['item_name'] ?? 'Item',
                            'planned_quantity' => (int) ($item['planned_quantity'] ?? $item['loaded_quantity'] ?? 0),
                            'remarks' => $item['remarks'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ];
            })->all(),
            'items' => $dispatch->items->map(fn ($item): array => [
                'name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse' => $item->warehouse_name,
                'allocated' => (int) $item->allocated_quantity,
            ])->values()->all(),
        ];
    }
}
