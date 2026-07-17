<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DrmdLguRoutingController extends Controller
{
    public function aaIndex(): Response
    {
        return Inertia::render('DrmdAa/LguIntake', [
            'requests' => $this->baseQuery()
                ->whereIn('lgu_routing_status', ['for_drmd_aa_review', 'for_drmd_aa_routing', 'routed_to_drrs'])
                ->latest('submitted_at')
                ->paginate(15)
                ->withQueryString(),
            'assignableUsers' => $this->assignableUsers(),
        ]);
    }

    public function routeToChief(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:3000'],
        ]);

        $old = $assistanceRequest->toArray();
        $assistanceRequest->update([
            'status' => 'for_drmd_chief_directive',
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
            'requests' => $this->baseQuery()
                ->whereIn('lgu_routing_status', ['for_drmd_chief_directive', 'for_drmd_aa_routing'])
                ->latest('drmd_aa_routed_at')
                ->paginate(15)
                ->withQueryString(),
            'assignableUsers' => $this->assignableUsers(),
        ]);
    }

    public function chiefDirective(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $this->ensureLguRequest($assistanceRequest);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:3000'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'assigned_section' => ['required', 'string', 'max:120'],
        ]);

        $old = $assistanceRequest->toArray();
        $assistanceRequest->update([
            'status' => 'for_drmd_aa_routing',
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

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:3000'],
        ]);

        $old = $assistanceRequest->toArray();
        $assistanceRequest->update([
            'status' => 'endorsed',
            'lgu_routing_status' => 'routed_to_drrs',
            'endorsed_to_drrs' => true,
            'date_endorsed_to_drrs' => now()->toDateString(),
            'remarks' => $data['remarks'] ?? $assistanceRequest->remarks,
        ]);

        $audit->log('lgu_dromic.routed_to_drrs', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());
        $workflowNotifications->notifyLguDromicRoutedToDrrs($assistanceRequest->fresh(['encoder', 'lguSubmitter', 'drmdAssignedUser']));

        return back()->with('success', "{$assistanceRequest->reference_number} routed to DRRS and concerned response units.");
    }

    private function baseQuery()
    {
        return AssistanceRequest::query()
            ->with(['incident:id,name,incident_date,province,municipality,barangay', 'lguSubmitter:id,name,email,lgu_name', 'drmdAssignedUser:id,name,office'])
            ->where('submission_type', 'lgu_dromic_relief_request');
    }

    private function assignableUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('office', ['DRMD', 'DRMD AA', 'DRRS', 'DRIMS', 'RROS'])
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
}
