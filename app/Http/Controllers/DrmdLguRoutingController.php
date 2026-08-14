<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DrmdLguRoutingController extends Controller
{
    public function aaIndex(): Response
    {
        return Inertia::render('DrmdAa/LguIntake', [
            'requests' => $this->sanitizeReportPaginator($this->baseQuery()
                ->whereNotNull('lgu_submitted_to_dswd_at')
                ->whereNotNull('lgu_signed_report_path')
                ->where('lgu_dromic_validation_status', 'validated_no_findings')
                ->where(function ($query): void {
                    $query->where(fn ($withoutRequest) => $withoutRequest
                        ->whereNull('lgu_relief_request_reference')
                        ->orWhere('lgu_dromic_payload->has_relief_request', false))
                        ->orWhere(fn ($withRequest) => $withRequest
                            ->whereNotNull('lgu_signed_request_path')
                            ->where('lgu_relief_validation_status', 'validated_no_findings'));
                })
                ->latest('lgu_dromic_reviewed_at')
                ->paginate(15)
                ->withQueryString()),
        ]);
    }

    public function recordDrn(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);
        abort_unless(
            $assistanceRequest->lgu_dromic_validation_status === 'validated_no_findings'
                && $assistanceRequest->lgu_relief_validation_status === 'validated_no_findings'
                && filled($assistanceRequest->lgu_signed_report_path)
                && filled($assistanceRequest->lgu_signed_request_path),
            422,
            'The signed report and request must both pass validation before a DRN can be recorded.',
        );
        $data = $request->validate([
            'request_drn' => ['required', 'string', 'max:100'],
        ]);
        $operational = $assistanceRequest->reliefAugmentationRequest;
        abort_unless($operational, 422, 'The linked FNI Request has not been created yet.');
        $old = $operational->toArray();
        $previousDrn = $operational->request_drn;
        $operational->update(['request_drn' => trim($data['request_drn'])]);
        $fresh = $operational->fresh();
        $audit->log('lgu_relief_augmentation.drn_recorded', $operational, $old, $fresh->toArray());

        if ((string) $previousDrn !== (string) $fresh->request_drn) {
            $workflowNotifications->broadcastRequestUpdated($fresh, [
                'changed' => ['request_drn'],
                'source' => 'drmd_aa_drn',
            ]);
        }

        return back()->with('success', "DRN recorded for {$assistanceRequest->lgu_relief_request_reference}.");
    }

    public function routeToChief(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);
        abort(422, 'LGU-origin requests no longer require DRMD AA-to-Chief routing. DRRS receives them for validation, while AA and Chief receive only validated signed copies.');
        $this->ensureSubmittedReliefRequest($assistanceRequest);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:3000'],
        ]);

        $old = $assistanceRequest->toArray();
        $assistanceRequest->update([
            'lgu_routing_status' => 'for_drmd_chief_directive',
            'drmd_aa_remarks' => $data['remarks'],
            'drmd_aa_routed_by' => $request->user()->id,
            'drmd_aa_routed_at' => now(),
        ]);

        $audit->log('lgu_dromic.routed_to_chief', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());
        $workflowNotifications->notifyLguDromicRoutedToChief($assistanceRequest->fresh(['encoder', 'lguSubmitter']));

        return back()->with('success', "{$assistanceRequest->reference_number} routed to DRMD Chief.");
    }

    public function chiefIndex(): Response
    {
        return Inertia::render('DrmdChief/LguIntake', [
            'requests' => $this->sanitizeReportPaginator($this->baseQuery()
                ->whereNotNull('lgu_submitted_to_dswd_at')
                ->whereNotNull('lgu_signed_report_path')
                ->where('lgu_dromic_validation_status', 'validated_no_findings')
                ->where(function ($query): void {
                    $query->where(fn ($withoutRequest) => $withoutRequest
                        ->whereNull('lgu_relief_request_reference')
                        ->orWhere('lgu_dromic_payload->has_relief_request', false))
                        ->orWhere(fn ($withRequest) => $withRequest
                            ->whereNotNull('lgu_signed_request_path')
                            ->where('lgu_relief_validation_status', 'validated_no_findings'));
                })
                ->latest('lgu_dromic_reviewed_at')
                ->paginate(15)
                ->withQueryString()),
        ]);
    }

    public function chiefDirective(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);
        abort(422, 'LGU-origin requests no longer require a Chief routing directive. This workspace is a validated signed-copy registry.');
        $this->ensureSubmittedReliefRequest($assistanceRequest);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:3000'],
            'assigned_to' => ['required', 'exists:users,id'],
            'assigned_section' => ['required', 'in:DRRS,Concerned PDRC'],
        ]);
        $assignedUser = User::query()->findOrFail($data['assigned_to']);
        abort_unless(
            $assignedUser->is_active && ($assignedUser->office === 'DRRS' || $assignedUser->hasRole('DRRS')),
            422,
            'Select an active DRRS/PDRC personnel as the concerned recipient.',
        );

        $old = $assistanceRequest->toArray();
        $assistanceRequest->update([
            'lgu_routing_status' => 'for_drmd_aa_routing',
            'drmd_chief_remarks' => $data['remarks'],
            'drmd_chief_routed_by' => $request->user()->id,
            'drmd_chief_routed_at' => now(),
            'drmd_assigned_to' => $data['assigned_to'] ?? null,
            'drmd_assigned_section' => $data['assigned_section'],
        ]);

        $audit->log('lgu_dromic.chief_directive', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());
        $workflowNotifications->notifyLguDromicChiefDirective($assistanceRequest->fresh(['encoder', 'lguSubmitter', 'drmdAssignedUser']));

        return back()->with('success', "{$assistanceRequest->reference_number} returned to DRMD AA with directive.");
    }

    public function routeToDrrs(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);
        abort(422, 'LGU-origin requests are made available to DRRS immediately after successful request-letter validation.');
        $this->ensureSubmittedReliefRequest($assistanceRequest);
        abort_unless($assistanceRequest->lgu_routing_status === 'for_drmd_aa_routing', 422, 'The DRMD Chief must approve the relief augmentation request before it can be routed to DRRS.');

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:3000'],
        ]);

        $old = $assistanceRequest->toArray();
        $reliefRequest = DB::transaction(function () use ($request, $assistanceRequest, $data): AssistanceRequest {
            $payload = $assistanceRequest->lgu_dromic_payload ?? [];
            $requestedItemSummary = collect(data_get($payload, 'requested_fni_items', []))
                ->map(function (array $item): string {
                    $quantity = data_get($item, 'requested_quantity');
                    $unit = data_get($item, 'unit_of_measure');

                    return trim(data_get($item, 'item_name', 'FNI item').': '.$quantity.($unit ? ' '.$unit : ''));
                })
                ->filter()
                ->join('; ');
            $reliefRequest = AssistanceRequest::query()->firstOrCreate(
                ['source_lgu_dromic_request_id' => $assistanceRequest->id],
                [
                    'reference_number' => filled($assistanceRequest->lgu_relief_request_reference)
                        ? $assistanceRequest->lgu_relief_request_reference
                        : 'REQ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'submission_type' => 'fni_request',
                    'incident_id' => $assistanceRequest->incident_id,
                    'encoded_by' => $request->user()->id,
                    'lgu_submitted_by' => $assistanceRequest->lgu_submitted_by,
                    'requesting_agency' => $assistanceRequest->requesting_agency,
                    'lgu' => $assistanceRequest->lgu,
                    'lgu_level' => $assistanceRequest->lgu_level,
                    'lgu_psgc_code' => $assistanceRequest->lgu_psgc_code,
                    'province' => $assistanceRequest->province,
                    'municipality' => $assistanceRequest->municipality,
                    'barangay' => $assistanceRequest->barangay,
                    'requester' => $assistanceRequest->requester,
                    'requester_position' => $assistanceRequest->requester_position,
                    'requester_address' => $assistanceRequest->requester_address,
                    'contact_number' => $assistanceRequest->contact_number,
                    'date_requested' => $assistanceRequest->submitted_at?->toDateString() ?? now()->toDateString(),
                    'date_received_by_drmd' => now()->toDateString(),
                    'purpose' => 'Relief Augmentation',
                    'assessment_summary' => $requestedItemSummary !== ''
                        ? 'Advance requested FNI: '.$requestedItemSummary
                        : 'Advance FNI request submitted for social worker assessment.',
                    'assessment_form_data' => [
                        'response_purpose' => 'Relief Augmentation',
                        'requested_fni_items' => data_get($payload, 'requested_fni_items', []),
                        'source_lgu_dromic_reference' => $assistanceRequest->reference_number,
                    ],
                    'remarks' => $data['remarks'] ?? $assistanceRequest->drmd_chief_remarks,
                    'affected_families' => $assistanceRequest->affected_families,
                    'assigned_social_worker' => $assistanceRequest->drmdAssignedUser?->name,
                    'drmd_assigned_to' => $assistanceRequest->drmd_assigned_to,
                    'drmd_assigned_section' => $assistanceRequest->drmd_assigned_section,
                    'endorsed_to_drrs' => true,
                    'date_endorsed_to_drrs' => now()->toDateString(),
                    'status' => 'endorsed',
                    'submitted_at' => now(),
                ],
            );

            $assistanceRequest->update([
                'lgu_routing_status' => 'routed_to_drrs',
                'remarks' => $data['remarks'] ?? $assistanceRequest->remarks,
            ]);
            $this->syncRequestedItemsToReliefRequest($reliefRequest, $payload);

            return $reliefRequest;
        });

        $audit->log('lgu_dromic.routed_to_drrs', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());
        $audit->log('request.created_from_lgu_relief_augmentation', $reliefRequest, [], $reliefRequest->toArray());
        $workflowNotifications->notifyLguDromicRoutedToDrrs($reliefRequest->fresh(['encoder', 'lguSubmitter', 'drmdAssignedUser']));

        return back()->with('success', "{$reliefRequest->reference_number} was created as the DRRS relief augmentation request. {$assistanceRequest->reference_number} remains attached as its supporting DROMIC report.");
    }

    private function baseQuery()
    {
        return AssistanceRequest::query()
            ->with([
                'incident:id,name,incident_date,province,municipality,barangay',
                'lguSubmitter:id,name,email,lgu_name',
                'drmdAssignedUser:id,name,office',
                'reliefAugmentationRequest:id,source_lgu_dromic_request_id,reference_number,request_drn,status',
            ])
            ->where('submission_type', 'lgu_dromic_relief_request');
    }

    private function sanitizeReportPaginator($paginator)
    {
        return $paginator->through(function (AssistanceRequest $report): AssistanceRequest {
            $payload = $report->lgu_dromic_payload ?? [];

            foreach (['official_advisory_rows', 'photo_rows', 'photo_documentation_rows', 'photo_collage_rows'] as $field) {
                foreach (($payload[$field] ?? []) as $index => $row) {
                    $hasImage = filled($row['screenshot_data_url'] ?? $row['data_url'] ?? $row['image_data_url'] ?? null);
                    unset(
                        $payload[$field][$index]['screenshot_data_url'],
                        $payload[$field][$index]['data_url'],
                        $payload[$field][$index]['image_data_url'],
                    );
                    $payload[$field][$index]['image_attached'] = $hasImage;
                }
            }

            $report->setAttribute('lgu_dromic_payload', $payload);

            return $report;
        });
    }

    private function assignableUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('office', 'DRRS')
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'DRRS'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'office', 'position'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'office' => $user->office,
                'position' => $user->position,
            ])
            ->values()
            ->all();
    }

    private function ensureLguRequest(AssistanceRequest $assistanceRequest): void
    {
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);
    }

    private function ensureSubmittedReliefRequest(AssistanceRequest $assistanceRequest): void
    {
        abort_unless($assistanceRequest->lgu_submitted_to_dswd_at, 422, 'Only reports submitted to DSWD can enter the DRMD routing workflow.');
        abort_unless((bool) data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request'), 422, 'This DROMIC report does not contain a relief augmentation request.');
        abort_unless(
            filled($assistanceRequest->lgu_signed_report_path) && filled($assistanceRequest->lgu_signed_request_path),
            422,
            'DRMD routing starts only after both the signed DROMIC report and signed relief augmentation request are uploaded.',
        );
    }

    private function syncRequestedItemsToReliefRequest(AssistanceRequest $reliefRequest, array $payload): void
    {
        $rows = collect(data_get($payload, 'requested_fni_items', []));
        $library = FniLibraryItem::query()
            ->whereIn('id', $rows->pluck('fni_library_item_id')->filter())
            ->get()
            ->keyBy('id');
        $selectedIds = [];

        foreach ($rows as $row) {
            $fniItem = $library->get((int) data_get($row, 'fni_library_item_id'));
            if (! $fniItem) {
                continue;
            }

            $selectedIds[] = $fniItem->id;
            $reliefRequest->items()->updateOrCreate(
                ['fni_library_item_id' => $fniItem->id],
                [
                    'item_name' => trim($fniItem->item_name.($fniItem->brand_description ? ' - '.$fniItem->brand_description : '')),
                    'requested_quantity' => data_get($row, 'requested_quantity'),
                    'unit' => $fniItem->unit_of_measure ?: 'item',
                    'priority' => 'normal',
                    'status' => 'pending',
                ],
            );
        }

        $reliefRequest->items()
            ->whereNotNull('fni_library_item_id')
            ->when($selectedIds !== [], fn ($query) => $query->whereNotIn('fni_library_item_id', $selectedIds))
            ->delete();
    }
}
