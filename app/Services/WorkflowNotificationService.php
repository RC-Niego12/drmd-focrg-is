<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;

class WorkflowNotificationService
{
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
        $this->notifyRoles(['RROS'], [
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
        $hasReliefRequest = (bool) data_get($request->lgu_dromic_payload, 'has_relief_request');
        $copyLabel = $signedComplete ? 'with complete signed copies' : 'as an advance copy';

        $this->notifyRoles(['DRRS', 'DRIMS', 'OCD Caraga', 'Super Admin'], [
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
}
