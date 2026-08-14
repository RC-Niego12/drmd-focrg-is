<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Support\LguFniProcessingStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkflowNotificationService
{
    public function notifyRisEpirmaForwarded(RequisitionIssuanceSlip $slip, AssistanceRequest $request): void
    {
        $this->notifyRoles(['RROS AA'], [
            'workflow' => 'RIS e-PIRMA approval', 'action_key' => 'ris_epirma_route_required', 'action_required' => true,
            'title' => 'RIS ready for e-PIRMA routing',
            'message' => "{$slip->ris_number} for {$request->reference_number} was forwarded to the RROS Administrative Assistant and is ready for e-PIRMA routing to the concerned signatories, including the ARDO as Approving Authority.",
            'request_id' => $request->id, 'reference_number' => $request->reference_number,
            'url' => route('rros.requests.index', ['status' => 'in_progress', 'search' => $request->reference_number]),
            'meta' => ['ris_id' => $slip->id, 'ris_number' => $slip->ris_number, 'approval_mode' => 'epirma'],
        ], $slip->ris_epirma_forwarded_by);
    }

    public function notifyRisEpirmaCompleted(RequisitionIssuanceSlip $slip, AssistanceRequest $request): void
    {
        $payload = [
            'workflow' => 'RIS e-PIRMA approval', 'action_key' => 'ris_epirma_completed', 'action_required' => false,
            'title' => 'RIS approved through e-PIRMA',
            'message' => "{$slip->ris_number} for {$request->reference_number} is approved and ready for Dispatch Plan. The printable RIS / DR remains unsigned to prevent combining electronic and wet signatures.",
            'request_id' => $request->id, 'reference_number' => $request->reference_number,
            'url' => route('rros.requests.index', ['status' => 'approved', 'search' => $request->reference_number]),
            'meta' => ['ris_id' => $slip->id, 'ris_number' => $slip->ris_number, 'approval_mode' => 'epirma'],
        ];
        $this->notifyRoles(['RROS', 'RROS AA', 'Super Admin'], $payload);
        $this->notifyUsers($this->lguRecipients($request), [...$payload, 'url' => route('lgu.dromic-requests.index', ['tab' => 'requests', 'focus' => $request->source_lgu_dromic_request_id ?: $request->id])]);
    }

    public function notifyRisPostMonitoringAssigned(RequisitionIssuanceSlip $slip, AssistanceRequest $request): void
    {
        $staff = User::query()->whereKey($slip->prepared_by)->where('is_active', true)->get();
        if ($staff->isEmpty()) {
            // Prefer RROS holders; keep RROS AA only as a legacy fallback, not a required tier.
            $staff = User::query()->role(['RROS'])->where('is_active', true)->get();
            if ($staff->isEmpty()) {
                $staff = User::query()->role(['RROS AA'])->where('is_active', true)->get();
            }
        }

        $this->notifyUsers($staff, [
            'workflow' => 'RROS RIS post-monitoring',
            'action_key' => 'ris_post_monitoring_required',
            'action_required' => true,
            'title' => 'Complete RIS delivery and accounting details',
            'message' => "{$slip->ris_number} for {$request->reference_number} was created. Encode delivery, receipt, accounting, RDS, and CSMR details as soon as they become available.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('rros.requests.index', ['search' => $request->reference_number]),
            'meta' => ['ris_id' => $slip->id, 'ris_number' => $slip->ris_number],
        ]);
    }

    public function notifyRisReadyForSigning(RequisitionIssuanceSlip $slip, AssistanceRequest $request): void
    {
        $staff = User::query()
            ->permission('manage dispatches')
            ->where('is_active', true)
            ->get();

        if ($staff->isEmpty()) {
            $staff = User::query()->role(['RROS', 'Super Admin'])->where('is_active', true)->get();
        }

        $this->notifyUsers($staff, [
            'workflow' => 'RROS RIS signing',
            'action_key' => 'ris_ready_for_signing',
            'action_required' => true,
            'title' => 'RIS ready for signing',
            'message' => "RIS / DR {$slip->ris_number} for {$request->reference_number} was generated and is ready for signing by the dispatch officer (print/sign — e-PIRMA is not used for RIS / DR).",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('rros.requests.index', ['search' => $request->reference_number]),
            'meta' => [
                'ris_id' => $slip->id,
                'ris_number' => $slip->ris_number,
                'next_process' => 'ris_signing',
            ],
        ]);
    }

    public function notifyDrmdAaEndorsed(AssistanceRequest $request): void
    {
        $label = $request->submission_type === 'proposal' ? 'proposal' : 'FNI request';

        $this->notifyRoles(['DRRS'], [
            'workflow' => 'DRMD AA to DRRS',
            'action_key' => 'drrs_assessment_required',
            'action_required' => true,
            'title' => 'New endorsement from DRMD AA',
            'message' => "DRMD AA endorsed {$request->reference_number} for DRRS assessment.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.index', ['status' => 'actionable']),
            'meta' => ['submission_type' => $label],
        ], $request->encoded_by);
    }

    public function notifyDrrsAssessmentSubmitted(AssistanceRequest $request): void
    {
        $this->notifyRoles(['RROS', 'RROS AA'], [
            'workflow' => 'DRRS to RROS',
            'action_key' => 'rros_decision_required',
            'action_required' => true,
            'title' => 'Assessment submitted for RROS action',
            'message' => "DRRS submitted {$request->reference_number} for request decision and stock action.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.index', ['status' => 'actionable']),
        ]);
    }

    public function notifyDrrsAaEpirmaForwarded(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS AA', 'Super Admin'], [
            'workflow' => 'DRRS PDRC to DRRS AA',
            'action_key' => 'drrs_aa_epirma_required',
            'action_required' => true,
            'title' => 'Assessment forwarded for e-PIRMA routing',
            'message' => "{$request->reference_number} was forwarded by DRRS PDRC for e-PIRMA routing of the assessment and response letter.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('drrs-aa.epirma.index', ['search' => $request->reference_number]),
        ], $request->epirma_forwarded_by);
    }

    public function notifyLguAdvanceResponseLetter(AssistanceRequest $request): void
    {
        $this->notifyUsers($this->lguRecipients($request), [
            'workflow' => 'DSWD to LGU',
            'action_key' => 'lgu_response_letter_advance_ack_required',
            'action_required' => true,
            'title' => 'Acknowledge advance response letter',
            'message' => "DSWD FO Caraga released an advance copy of the response letter for {$request->reference_number}. Please open it and acknowledge receipt. A signed copy will follow after e-PIRMA routing.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index', ['tab' => 'requests', 'focus' => $request->source_lgu_dromic_request_id ?: $request->id]),
            'meta' => ['copy' => 'advance'],
        ]);

        $this->broadcastLguFniProcessingUpdated($request, [
            'reason' => 'advance_response_letter',
            'copy' => 'advance',
        ]);
    }

    public function notifyRrosEpirmaDocumentSigned(AssistanceRequest $request, string $documentType): void
    {
        $label = $documentType === 'response_letter' ? 'response letter' : 'assessment';

        $this->notifyRoles(['RROS', 'RROS AA', 'Super Admin'], [
            'workflow' => 'DRRS AA e-PIRMA to RROS',
            'action_key' => 'rros_epirma_document_ready',
            'action_required' => false,
            'title' => 'Signed '.ucfirst($label).' ready for review',
            'message' => "The signed {$label} for {$request->reference_number} is available after e-PIRMA routing.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('rros.requests.index', ['search' => $request->reference_number]),
            'meta' => ['document_type' => $documentType],
        ]);
    }

    /**
     * Notify the DRRS PDRC forwarder / assessment actor when an e-PIRMA document finishes signing.
     */
    public function notifyPdrcEpirmaDocumentSigned(AssistanceRequest $request, string $documentType): void
    {
        $label = $documentType === 'response_letter' ? 'response letter' : 'assessment';
        $bothComplete = filled($request->epirma_assessment_signed_at)
            && filled($request->epirma_response_letter_signed_at);
        $documentPhrase = $bothComplete
            ? 'assessment and response letter'
            : $label;
        $title = $bothComplete
            ? 'e-PIRMA routing completed'
            : 'Signed '.ucfirst($label).' ready';

        $recipients = collect([
            $request->epirma_forwarded_by,
            $request->assessment_acted_by,
        ])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $users = $recipients->isNotEmpty()
            ? User::query()->whereIn('id', $recipients->all())->where('is_active', true)->get()
            : collect();

        if ($users->isEmpty()) {
            $aor = app(AorCoverageService::class)->ownersForRequest($request, 'DRRS');
            $users = $aor->isNotEmpty()
                ? $aor
                : User::query()->role(['DRRS'])->where('is_active', true)->get();
        }

        $this->notifyUsers($users, [
            'workflow' => 'DRRS AA e-PIRMA to DRRS PDRC',
            'action_key' => 'drrs_pdrc_epirma_document_signed',
            'action_required' => false,
            'title' => $title,
            'message' => "The signed {$documentPhrase} for {$request->reference_number} "
                .($bothComplete ? 'completed e-PIRMA routing.' : 'is available after e-PIRMA routing.'),
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.assessment', $request),
            'meta' => [
                'document_type' => $documentType,
                'both_complete' => $bothComplete,
            ],
        ]);
    }

    /**
     * Push live e-PIRMA document/queue updates to DRRS AA and related actors.
     *
     * @param  array<string, mixed>  $meta
     */
    public function broadcastEpirmaStatusChanged(AssistanceRequest $request, array $meta = []): void
    {
        $userIds = User::role(['DRRS AA', 'Super Admin', 'DRRS', 'RROS', 'RROS AA'])
            ->where('is_active', true)
            ->pluck('id')
            ->merge(
                User::permission('route epirma documents')
                    ->where('is_active', true)
                    ->pluck('id')
            )
            ->merge([
                $request->epirma_forwarded_by,
                $request->assessment_acted_by,
                $request->encoded_by,
            ])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        app(RealtimePublisher::class)->usersChanged($userIds, 'epirma.status.changed', array_merge([
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'epirma_aa_status' => $request->epirma_aa_status,
            'epirma_status' => $request->epirma_status,
            'assessment_status' => $request->assessment_status,
        ], $meta));
    }

    /**
     * Push live FNI request field updates (e.g. Request DRN) to DRRS / related actors.
     *
     * @param  array<string, mixed>  $meta
     */
    public function broadcastRequestUpdated(AssistanceRequest $request, array $meta = []): void
    {
        $userIds = User::query()
            ->role(['DRRS', 'DRRS AA', 'DRMD AA', 'Super Admin', 'RROS'])
            ->where('is_active', true)
            ->pluck('id')
            ->merge([
                $request->encoded_by,
                $request->assessment_acted_by,
                $request->epirma_forwarded_by,
                $request->drmd_assigned_to ?? null,
            ])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        app(RealtimePublisher::class)->usersChanged($userIds, 'request.updated', array_merge([
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'request_drn' => $request->request_drn,
            'status' => $request->status,
            'assessment_status' => $request->assessment_status,
            'endorsed_to_drrs' => (bool) $request->endorsed_to_drrs,
        ], $meta));

        // Status / field changes that affect LGU FNI PROCESSING without a dedicated notification.
        $this->broadcastLguFniProcessingUpdated($request, array_merge([
            'reason' => 'request_updated',
        ], $meta));
    }

    /**
     * Push live FNI processing / response-letter column updates to the concerned LGU users.
     *
     * @param  array<string, mixed>  $meta
     */
    public function broadcastLguFniProcessingUpdated(AssistanceRequest $request, array $meta = []): void
    {
        $userIds = $this->lguRecipients($request)
            ->pluck('id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $hasAdvance = filled($request->lgu_response_letter_advance_sent_at)
            && filled($request->lgu_response_letter_advance_path);
        $hasSigned = filled($request->epirma_response_letter_signed_at)
            && filled($request->lgu_response_letter_sent_at);
        $processing = LguFniProcessingStatus::resolve($request, $hasAdvance, $hasSigned);

        app(RealtimePublisher::class)->usersChanged($userIds, 'lgu.fni.processing.updated', array_merge([
            'request_id' => $request->id,
            'source_lgu_dromic_request_id' => $request->source_lgu_dromic_request_id,
            'reference_number' => $request->reference_number,
            'lgu_psgc_code' => $request->lgu_psgc_code,
            'status' => $request->status,
            'processing_key' => $processing['key'],
            'processing_label' => $processing['label'],
            'has_advance' => $hasAdvance,
            'has_signed' => $hasSigned,
            'advance_acked_at' => optional($request->lgu_response_letter_advance_acked_at)?->toIso8601String(),
            'signed_acked_at' => optional($request->lgu_response_letter_acked_at)?->toIso8601String(),
        ], $meta));
    }

    public function notifyLguSignedResponseLetter(AssistanceRequest $request): void
    {
        $this->notifyUsers($this->lguRecipients($request), [
            'workflow' => 'DSWD to LGU',
            'action_key' => 'lgu_response_letter_ack_required',
            'action_required' => true,
            'title' => 'Acknowledge signed response letter',
            'message' => "DSWD FO Caraga released the signed response letter for {$request->reference_number}. Please open it and acknowledge receipt.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index', ['tab' => 'requests', 'focus' => $request->source_lgu_dromic_request_id ?: $request->id]),
            'meta' => ['copy' => 'signed'],
        ]);

        $this->broadcastLguFniProcessingUpdated($request, [
            'reason' => 'signed_response_letter',
            'copy' => 'signed',
        ]);
    }

    public function notifyDrrsAaLguAdvanceAcknowledged(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS AA', 'DRRS', 'Super Admin'], [
            'workflow' => 'LGU acknowledgement',
            'action_key' => 'lgu_response_letter_advance_acked',
            'action_required' => false,
            'title' => 'LGU acknowledged advance response letter',
            'message' => "{$request->requesting_agency} acknowledged receipt of the advance response letter for {$request->reference_number}.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.assessment', $request),
        ], $request->lgu_response_letter_advance_acked_by);
    }

    public function notifyDrrsAaLguAcknowledged(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS AA', 'DRRS', 'RROS', 'Super Admin'], [
            'workflow' => 'LGU acknowledgement',
            'action_key' => 'lgu_response_letter_acked',
            'action_required' => false,
            'title' => 'LGU acknowledged signed response letter',
            'message' => "{$request->requesting_agency} acknowledged receipt of the signed response letter for {$request->reference_number}.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.assessment', $request),
        ], $request->lgu_response_letter_acked_by);
    }

    public function notifyRrosDecisionRecorded(AssistanceRequest $request): void
    {
        $decision = str($request->status)->replace('_', ' ')->title();

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'RROS to DRMD AA/DRRS',
            'action_key' => 'rros_decision_recorded',
            'action_required' => false,
            'title' => 'RROS decision recorded',
            'message' => "RROS recorded a {$decision} decision for {$request->reference_number}.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.index', ['search' => $request->reference_number]),
        ]);

        if (in_array($request->status, ['approved', 'partially_approved'], true)) {
            $this->notifyRoles(['DRIMS'], [
                'workflow' => 'RROS to DRIMS',
                'action_key' => 'drims_dromic_required',
                'action_required' => true,
                'title' => 'Approved request ready for DRIMS monitoring',
                'message' => "{$request->reference_number} was {$decision}. Prepare DROMIC monitoring when applicable.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => route('dromic.index'),
            ]);
        }
    }

    public function notifyDispatchCreated(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRIMS'], [
            'workflow' => 'RROS to DRIMS',
            'action_key' => 'drims_dromic_required',
            'action_required' => true,
            'title' => 'Dispatch released for DRIMS monitoring',
            'message' => "RROS released {$request->reference_number}. Update DROMIC monitoring if this is a disaster augmentation.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.index'),
        ]);
    }

    public function notifyDispatchStatusChanged(
        AssistanceRequest $request,
        DispatchPlan $dispatch,
        ?string $previousStatus,
        array $previousData = [],
    ): void {
        $status = (string) $dispatch->status;
        $url = route('dispatches.index', [
            'dispatch_id' => $dispatch->id,
            'bucket' => $dispatch->bucket(),
        ]);

        if ($previousStatus === null || $previousStatus === $status) {
            $staff = User::query()->permission('manage dispatches')->where('is_active', true)->get();
            if ($staff->isEmpty()) {
                $staff = User::query()->role(['RROS', 'Super Admin'])->where('is_active', true)->get();
            }

            $this->notifyUsers($staff, [
                'workflow' => 'RROS Dispatch Plan',
                'action_key' => 'dispatch_plan_updated',
                'action_required' => ! in_array($status, DispatchPlan::COMPLETED, true),
                'title' => $previousStatus === null ? 'Dispatch Plan created' : 'Dispatch Plan updated',
                'message' => "{$dispatch->dispatch_number} for {$request->reference_number} is now {$this->dispatchStatusLabel($status)}.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => $url,
                'meta' => [
                    'dispatch_id' => $dispatch->id,
                    'status' => $status,
                ],
            ]);
        } else {
            $staff = User::query()->permission('manage dispatches')->where('is_active', true)->get();
            if ($staff->isEmpty()) {
                $staff = User::query()->role(['RROS', 'Super Admin'])->where('is_active', true)->get();
            }

            $this->notifyUsers($staff, [
                'workflow' => 'RROS Dispatch Plan',
                'action_key' => 'dispatch_plan_status_changed',
                'action_required' => ! in_array($status, DispatchPlan::COMPLETED, true),
                'title' => 'Dispatch Plan status changed',
                'message' => "{$dispatch->dispatch_number} for {$request->reference_number}: {$this->dispatchStatusLabel($previousStatus)} → {$this->dispatchStatusLabel($status)}.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => $url,
                'meta' => [
                    'dispatch_id' => $dispatch->id,
                    'status' => $status,
                    'previous_status' => $previousStatus,
                ],
            ]);
        }

        if (in_array($status, [DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT], true)
            && ! in_array((string) $previousStatus, [DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT, DispatchPlan::STATUS_RECEIVED], true)
        ) {
            $this->notifyDispatchCreated($request);
        }

        if ($status === DispatchPlan::STATUS_RECEIVED && $previousStatus !== DispatchPlan::STATUS_RECEIVED) {
            $replacementRequired = $dispatch->items()
                ->whereIn('variance_disposition', DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS)
                ->whereRaw('COALESCE(allocated_quantity, 0) > COALESCE(received_quantity, 0)')
                ->exists();
            $this->notifyRoles(['DRIMS', 'DRRS'], [
                'workflow' => 'RROS Dispatch Plan',
                'action_key' => 'dispatch_plan_received',
                'action_required' => $replacementRequired,
                'title' => $replacementRequired ? 'Partial delivery requires replacement' : 'Goods received by LGU',
                'message' => $replacementRequired
                    ? "{$dispatch->dispatch_number} for {$request->reference_number} was partially received. Deferred, returned, or cancelled quantities must be delivered through a follow-up dispatch before the RIS can be completed."
                    : "{$dispatch->dispatch_number} for {$request->reference_number} was acknowledged as received by the LGU.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => $url,
                'meta' => ['dispatch_id' => $dispatch->id, 'status' => $status],
            ]);
        }

        $notifiableStatuses = [
            DispatchPlan::STATUS_PLANNED,
            DispatchPlan::STATUS_RELEASED,
            DispatchPlan::STATUS_IN_TRANSIT,
            DispatchPlan::STATUS_RECEIVED,
        ];
        $returnsChanged = $status === DispatchPlan::STATUS_RECEIVED
            && $this->dispatchReturnsChanged($dispatch, $previousData);
        $returnsOnlyUpdate = $previousStatus === $status && $returnsChanged;
        if (in_array($status, $notifiableStatuses, true)
            && ($previousStatus !== $status || $returnsChanged)
        ) {
            $escorts = $this->assignedDispatchEscorts($dispatch);
            $title = $returnsOnlyUpdate
                ? 'Dispatch returns/cancellations updated'
                : match ($status) {
                    DispatchPlan::STATUS_PLANNED => 'Delivery assignment ready for release',
                    DispatchPlan::STATUS_RELEASED => 'Assigned delivery released',
                    DispatchPlan::STATUS_IN_TRANSIT => 'Assigned delivery is in transit',
                    DispatchPlan::STATUS_RECEIVED => 'Assigned delivery received by LGU',
                    default => 'Assigned delivery updated',
                };
            $message = $returnsOnlyUpdate
                ? "{$dispatch->dispatch_number} for {$request->reference_number} has updated returned/cancelled item details."
                : "{$dispatch->dispatch_number} for {$request->reference_number} is now {$this->dispatchStatusLabel($status)}."
                    .($returnsChanged ? ' Returned/cancelled item details are included.' : '');

            $this->notifyUsers($escorts, [
                'workflow' => 'Delivery Escort Workspace',
                'action_key' => $returnsOnlyUpdate
                    ? 'dispatch_returns_updated'
                    : "delivery_escort_{$status}",
                'action_required' => ! in_array($status, DispatchPlan::COMPLETED, true),
                'title' => $title,
                'message' => $message,
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => route('delivery-escort.index', [
                    'dispatch_id' => $dispatch->id,
                    'bucket' => $dispatch->bucket(),
                ]),
                'meta' => [
                    'dispatch_id' => $dispatch->id,
                    'status' => $status,
                    'has_returns_or_cancellations' => $returnsChanged,
                ],
            ]);
        }
    }

    private function assignedDispatchEscorts(DispatchPlan $dispatch): Collection
    {
        $vehicles = collect($dispatch->resolvedVehicleDetails())
            ->filter(fn (array $vehicle): bool => (bool) ($vehicle['has_dswd_escort'] ?? false));
        $idNumbers = $vehicles->pluck('escort_id_number')->filter()->map(
            fn ($value): string => Str::lower(trim((string) $value))
        )->unique();
        $names = $vehicles->pluck('escort_name')->filter()->map(
            fn ($value): string => Str::lower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '')
        )->unique();

        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($idNumbers, $names): bool {
            $userId = Str::lower(trim((string) $user->id_number));
            $userName = Str::lower(preg_replace('/\s+/u', ' ', trim((string) $user->name)) ?? '');

            return ($userId !== '' && $idNumbers->contains($userId))
                || ($userName !== '' && $names->contains($userName));
        })->values();
    }

    private function dispatchReturnsChanged(DispatchPlan $dispatch, array $previousData): bool
    {
        foreach (['has_returned_items', 'returned_particulars', 'returned_quantity', 'returned_reason'] as $field) {
            if (($previousData[$field] ?? null) != $dispatch->{$field}) {
                return true;
            }
        }

        return false;
    }

    private function dispatchStatusLabel(string $status): string
    {
        return match ($status) {
            DispatchPlan::STATUS_DRAFT => 'Draft',
            DispatchPlan::STATUS_PLANNED => 'Planned',
            DispatchPlan::STATUS_RELEASED => 'Released',
            DispatchPlan::STATUS_IN_TRANSIT => 'In Transit',
            DispatchPlan::STATUS_RECEIVED => 'Received',
            default => Str::headline($status),
        };
    }

    public function notifyDromicCreated(AssistanceRequest $request): void
    {
        $this->notifyUsers($this->originators($request), [
            'workflow' => 'DRIMS update',
            'action_key' => 'drims_dromic_created',
            'action_required' => false,
            'title' => 'DROMIC report created',
            'message' => "DRIMS created a DROMIC report for {$request->reference_number}.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.index'),
        ]);
    }

    public function notifyLguDromicSubmitted(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRMD AA', 'DRMD Chief', 'DRRS', 'DRIMS', 'RROS', 'Super Admin'], [
            'workflow' => 'LGU to DRMD',
            'action_key' => 'lgu_dromic_aa_review',
            'action_required' => true,
            'title' => 'New LGU DROMIC and relief request',
            'message' => "{$request->requesting_agency} submitted {$request->reference_number} for DRMD AA routing.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('drmd-aa.lgu-intake.index'),
            'meta' => ['lgu_routing_status' => $request->lgu_routing_status],
        ], $request->lgu_submitted_by);
    }

    public function notifyLguReportSubmitted(AssistanceRequest $request, bool $signedComplete): void
    {
        $hasReliefRequest = (bool) data_get($request->lgu_dromic_payload, 'has_relief_request')
            || filled($request->lgu_relief_request_reference);
        $copyLabel = $signedComplete ? 'with complete signed copies' : 'as an advance copy';
        $aor = app(AorCoverageService::class);

        $drimsRecipients = $aor->ownersForRequest($request, 'DRIMS');
        if ($drimsRecipients->isEmpty()) {
            $drimsRecipients = User::query()->role(['DRIMS', 'Super Admin'])->where('is_active', true)->get();
        }
        $this->notifyUsers($drimsRecipients, [
            'workflow' => 'LGU DROMIC reporting',
            'action_key' => 'drims_dromic_ack_required',
            'action_required' => blank($request->lgu_dromic_acked_at),
            'title' => $signedComplete ? 'Acknowledge signed LGU DROMIC report' : 'Acknowledge LGU DROMIC advance copy',
            'message' => "{$request->requesting_agency} sent {$request->reference_number} {$copyLabel}. Please open and acknowledge receipt (AOR).",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.lgu-reports', ['tab' => 'reports', 'search' => $request->reference_number]),
            'meta' => [
                'has_relief_request' => $hasReliefRequest,
                'signed_copy_complete' => $signedComplete,
                'document' => 'dromic_report',
            ],
        ]);

        if ($hasReliefRequest) {
            $drrsRecipients = $aor->ownersForRequest($request, 'DRRS');
            if ($drrsRecipients->isEmpty()) {
                $drrsRecipients = User::query()->role(['DRRS', 'Super Admin'])->where('is_active', true)->get();
            }
            $this->notifyUsers($drrsRecipients, [
                'workflow' => 'LGU relief request letter',
                'action_key' => 'drrs_relief_request_ack_required',
                'action_required' => blank($request->lgu_relief_acked_at),
                'title' => $signedComplete ? 'Acknowledge signed LGU request letter' : 'Acknowledge LGU request letter (advance)',
                'message' => "{$request->requesting_agency} submitted a relief augmentation request letter with {$request->reference_number}. Please open and acknowledge receipt (AOR).",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => route('dromic.lgu-reports', ['tab' => 'requests', 'search' => $request->lgu_relief_request_reference ?: $request->reference_number]),
                'meta' => [
                    'has_relief_request' => true,
                    'signed_copy_complete' => $signedComplete,
                    'document' => 'request_letter',
                ],
            ]);
        }

        $this->notifyRoles(['OCD Caraga', 'Super Admin'], [
            'workflow' => 'LGU DROMIC reporting',
            'action_key' => $signedComplete ? 'lgu_dromic_submitted_complete' : 'lgu_dromic_signed_copy_pending',
            'action_required' => ! $signedComplete,
            'title' => $signedComplete ? 'LGU DROMIC report submitted with signed copies' : 'LGU DROMIC advance copy received',
            'message' => "{$request->requesting_agency} sent {$request->reference_number} {$copyLabel} to DSWD and OCD Caraga.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.lgu-reports'),
            'meta' => [
                'has_relief_request' => $hasReliefRequest,
                'signed_copy_complete' => $signedComplete,
            ],
        ], $request->lgu_submitted_by);
    }

    public function notifyLguDromicReviewComment(AssistanceRequest $request, string $reviewer): void
    {
        $this->notifyUsers($this->originators($request), [
            'workflow' => 'LGU DROMIC signed-report review',
            'action_key' => 'lgu_dromic_review_comment',
            'action_required' => true,
            'title' => 'Comment received on your DROMIC report',
            'message' => "{$reviewer} posted a review comment on {$request->reference_number}. Review the signed report and correct or re-upload it when needed.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
        ]);
    }

    public function notifyLguDromicValidationOutcome(AssistanceRequest $request): void
    {
        $status = $request->lgu_dromic_validation_status;
        $reviewer = $request->lguDromicReviewer?->name ?: 'DSWD reviewer';
        $correctionInstruction = match ($request->lgu_dromic_correction_scope) {
            'encoding' => 'Open the returned report and create an editable correction draft for the encoded entries.',
            'both' => 'Create an editable correction draft, correct the encoded entries, regenerate the report, and upload the corrected PDF.',
            default => 'Review the finding and replace the identified PDF document.',
        };
        [$title, $message, $actionRequired] = match ($status) {
            'validated_no_findings' => [
                filled($request->lgu_signed_report_path)
                    ? 'DROMIC signed PDF validated — no findings'
                    : 'DROMIC advance copy validated — no findings',
                filled($request->lgu_signed_report_path)
                    ? "{$request->reference_number} passed DSWD signed-report validation. This result is separate from any relief augmentation decision."
                    : "{$request->reference_number} passed DSWD advance-copy validation. Upload the signed PDF when ready so DSWD can validate the signed report separately.",
                false,
            ],
            'needs_lgu_action' => [
                'Action needed on your DROMIC report',
                "{$reviewer} marked {$request->reference_number} as needing LGU action. {$correctionInstruction}",
                true,
            ],
            default => [
                'DROMIC report is under DSWD review',
                "{$request->reference_number} is now being reviewed by DSWD personnel.",
                false,
            ],
        };

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'LGU DROMIC report validation',
            'action_key' => 'lgu_dromic_validation_status',
            'action_required' => $actionRequired,
            'title' => $title,
            'message' => $message,
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
            'meta' => ['validation_status' => $status],
        ]);
    }

    public function notifyLguReliefValidationOutcome(AssistanceRequest $request): void
    {
        $status = $request->lgu_relief_validation_status;
        $reviewer = $request->lguReliefReviewer?->name ?: 'DRRS reviewer';
        $correctionInstruction = match ($request->lgu_relief_correction_scope) {
            'encoding' => 'Create a correction draft and update the encoded requested FNI entries.',
            'both' => 'Create a correction draft, update the encoded requested FNI entries, and upload the corrected request-letter PDF.',
            default => 'Review the remark and upload the corrected request-letter PDF.',
        };
        [$title, $message, $actionRequired] = match ($status) {
            'validated_no_findings' => [
                'Relief request validated — no findings',
                "The signed relief augmentation request attached to {$request->reference_number} passed DRRS document validation and is now locked.",
                false,
            ],
            'needs_lgu_action' => [
                'Action needed on your relief request',
                "{$reviewer} found an issue in the relief augmentation request attached to {$request->reference_number}. {$correctionInstruction}",
                true,
            ],
            default => [
                'Relief request is under DRRS review',
                "The signed relief augmentation request attached to {$request->reference_number} is now under DRRS document review.",
                false,
            ],
        };

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'LGU relief augmentation request validation',
            'action_key' => 'lgu_relief_validation_status',
            'action_required' => $actionRequired,
            'title' => $title,
            'message' => $message,
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
            'meta' => ['validation_status' => $status],
        ]);
    }

    public function notifyValidatedLguRequestReadyForAssessment(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS', 'Super Admin'], [
            'workflow' => 'Validated LGU relief request to DRRS',
            'action_key' => 'drrs_assessment_required',
            'action_required' => true,
            'title' => 'Validated LGU request ready for assessment',
            'message' => "{$request->reference_number} is now available in FNI Requests. DRMD AA routing is not required for this LGU-origin request.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.index', ['status' => 'actionable', 'search' => $request->reference_number]),
            'meta' => ['source' => 'lgu_dromic'],
        ]);

        $source = $request->sourceLguDromicReport;
        if ($source
            && $source->lgu_dromic_validation_status === 'validated_no_findings'
            && $source->lgu_relief_validation_status === 'validated_no_findings') {
            $this->notifyValidatedLguDocumentsReceived($source, $request);
        }
    }

    public function notifyValidatedLguDocumentsReceived(AssistanceRequest $source, ?AssistanceRequest $operational = null): void
    {
        $hasRequest = filled($source->lgu_relief_request_reference);
        $documents = $hasRequest
            ? "{$source->reference_number} and {$source->lgu_relief_request_reference}"
            : $source->reference_number;
        $nextStep = $hasRequest
            ? 'DRMD AA may now record the DRN; the Chief may view the validated copies.'
            : 'DRMD AA and the Chief may now view the validated signed report.';
        $this->notifyRoles(['DRMD AA', 'DRMD Chief', 'Super Admin'], [
            'workflow' => 'Validated LGU signed-copy registry',
            'action_key' => 'validated_lgu_documents_received',
            'action_required' => false,
            'title' => 'Validated LGU signed documents received',
            'message' => "{$documents} passed document validation. {$nextStep}",
            'request_id' => $source->id,
            'reference_number' => $source->reference_number,
            'url' => route('drmd-aa.lgu-intake.index'),
            'meta' => ['fni_request_reference' => $operational?->reference_number],
        ]);
    }

    public function notifyLguSignedCopiesRequired(AssistanceRequest $request): void
    {
        $hasReliefRequest = (bool) data_get($request->lgu_dromic_payload, 'has_relief_request');
        $requirement = $hasReliefRequest
            ? 'the signed report and signed relief augmentation request letter'
            : 'the signed report';

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'LGU signed-copy compliance',
            'action_key' => 'lgu_dromic_upload_signed_copies',
            'action_required' => true,
            'title' => 'Signed copy submission required',
            'message' => "{$request->reference_number} was accepted as an advance copy. Upload {$requirement} to complete the submission.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
            'meta' => ['has_relief_request' => $hasReliefRequest],
        ]);
    }

    public function notifyDromicSignedCopiesStillPending(AssistanceRequest $request): void
    {
        $hasReliefRequest = (bool) data_get($request->lgu_dromic_payload, 'has_relief_request');
        $this->notifyRoles(['DRMD AA', 'DRRS', 'DRIMS', 'Super Admin'], [
            'workflow' => 'LGU signed-copy compliance',
            'action_key' => 'lgu_dromic_signed_copy_pending',
            'action_required' => true,
            'title' => 'LGU signed copies remain pending',
            'message' => "{$request->reference_number} from {$request->requesting_agency} remains an advance copy pending the required signed document(s).",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.lgu-reports'),
            'meta' => ['has_relief_request' => $hasReliefRequest],
        ], $request->lgu_submitted_by);

        if ($hasReliefRequest) {
            $this->notifyRoles(['DRMD AA'], [
                'workflow' => 'LGU signed-copy compliance',
                'action_key' => 'lgu_dromic_signed_copy_pending',
                'action_required' => true,
                'title' => 'LGU signed copies remain pending',
                'message' => "{$request->reference_number} from {$request->requesting_agency} remains an advance copy pending the required signed documents.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => route('drmd-aa.lgu-intake.index'),
            ], $request->lgu_submitted_by);
        }
    }

    public function notifyLguSignedCopiesCompleted(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS', 'DRIMS', 'Super Admin'], [
            'workflow' => 'LGU signed-copy compliance',
            'action_key' => 'lgu_dromic_signed_copies_completed',
            'action_required' => false,
            'title' => 'LGU signed-copy requirement completed',
            'message' => "{$request->requesting_agency} completed the signed-copy requirements for {$request->reference_number}. DRIMS and DRRS must validate the applicable documents before they appear in the DRMD AA and Chief registries.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('dromic.lgu-reports'),
        ], $request->lgu_submitted_by);
    }

    public function notifyLguDromicRoutedToChief(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRMD Chief', 'Super Admin'], [
            'workflow' => 'DRMD AA to DRMD Chief',
            'action_key' => 'lgu_dromic_chief_directive',
            'action_required' => true,
            'title' => 'LGU request needs Chief directive',
            'message' => "DRMD AA routed {$request->reference_number} for processing directive.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('drmd-chief.lgu-intake.index'),
        ]);
    }

    public function notifyLguDromicChiefDirective(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRMD AA', 'Super Admin'], [
            'workflow' => 'DRMD Chief to DRMD AA',
            'action_key' => 'lgu_dromic_aa_final_route',
            'action_required' => true,
            'title' => 'Chief directive returned to DRMD AA',
            'message' => "DRMD Chief returned {$request->reference_number} for routing to DRRS / concerned PDRC.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('drmd-aa.lgu-intake.index'),
        ]);

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'DRMD processing update',
            'action_key' => 'lgu_dromic_processing_update',
            'action_required' => false,
            'title' => 'Your LGU request is being processed',
            'message' => "{$request->reference_number} received a DRMD Chief directive and is being routed for action.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
        ]);
    }

    public function notifyLguDromicRoutedToDrrs(AssistanceRequest $request): void
    {
        $this->notifyRoles(['DRRS', 'RROS', 'DRIMS', 'Super Admin'], [
            'workflow' => 'DRMD AA to DRRS/PDRC',
            'action_key' => 'drrs_assessment_required',
            'action_required' => true,
            'title' => 'LGU request routed for assessment/action',
            'message' => "{$request->reference_number} was routed to DRRS and concerned response units for assessment and response letter preparation.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('requests.index', ['status' => 'actionable']),
            'meta' => ['assigned_section' => $request->drmd_assigned_section],
        ]);

        if ($request->drmdAssignedUser) {
            $this->notifyUsers(collect([$request->drmdAssignedUser]), [
                'workflow' => 'DRMD AA to concerned DRRS/PDRC personnel',
                'action_key' => 'assigned_lgu_relief_request',
                'action_required' => true,
                'title' => 'Relief augmentation request formally assigned to you',
                'message' => "{$request->reference_number} was formally routed to you under the DRMD Chief’s directive. Open the request even if you previously received its documents as an advance copy.",
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
                'url' => route('requests.index', ['status' => 'actionable', 'search' => $request->reference_number]),
                'meta' => ['assigned_section' => $request->drmd_assigned_section],
            ]);
        }

        $this->notifyUsers($this->originators($request), [
            'workflow' => 'DRMD processing update',
            'action_key' => 'lgu_dromic_routed_to_drrs',
            'action_required' => false,
            'title' => 'Your LGU request was routed for action',
            'message' => "{$request->reference_number} is now endorsed for DRRS / concerned response unit action.",
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'url' => route('lgu.dromic-requests.index'),
        ]);
    }

    private function notifyRoles(array $roles, array $payload, ?int $exceptUserId = null): void
    {
        User::query()
            ->role($roles)
            ->where('is_active', true)
            ->when($exceptUserId, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->get()
            ->each(fn (User $user) => $user->notify(new WorkflowNotification($payload)));
    }

    private function notifyUsers(Collection $users, array $payload): void
    {
        $users
            ->filter(fn (?User $user): bool => $user !== null && $user->is_active)
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new WorkflowNotification($payload)));
    }

    private function originators(AssistanceRequest $request): Collection
    {
        return collect([$request->encoder, $request->lguSubmitter]);
    }

    private function lguRecipients(AssistanceRequest $request): Collection
    {
        $users = collect();

        $source = $request->relationLoaded('sourceLguDromicReport')
            ? $request->sourceLguDromicReport
            : $request->sourceLguDromicReport()->with('lguSubmitter')->first();

        if ($source) {
            $users->push($source->lguSubmitter ?: $source->encoder);
            if (filled($source->lgu_psgc_code)) {
                $users = $users->merge(
                    User::query()
                        ->role('LGU')
                        ->where('is_active', true)
                        ->where('lgu_psgc_code', $source->lgu_psgc_code)
                        ->get()
                );
            }
        }

        if (filled($request->lgu_psgc_code)) {
            $users = $users->merge(
                User::query()
                    ->role('LGU')
                    ->where('is_active', true)
                    ->where('lgu_psgc_code', $request->lgu_psgc_code)
                    ->get()
            );
        }

        $users = $users->merge($this->originators($request)->filter(
            fn (?User $user): bool => $user !== null && $user->hasRole('LGU')
        ));

        return $users->filter()->unique('id')->values();
    }
}
