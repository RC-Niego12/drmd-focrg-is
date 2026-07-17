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
