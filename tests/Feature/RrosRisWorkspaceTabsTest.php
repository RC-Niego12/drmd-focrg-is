<?php

use App\Models\AssistanceRequest;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('partitions RROS RIS/DR workspace tabs and summary by slip status', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    $makeSigned = function (string $reference) {
        return AssistanceRequest::create([
            'reference_number' => $reference,
            'requesting_agency' => 'Test LGU',
            'requester' => 'Requester',
            'date_requested' => now()->toDateString(),
            'status' => 'acted',
            'assessment_status' => 'submitted',
            'endorsed_to_drrs' => true,
            'submission_type' => 'fni_request',
            'submitted_at' => now(),
            'epirma_assessment_signed_at' => now(),
            'epirma_response_letter_signed_at' => now(),
        ]);
    };

    $stillNone = $makeSigned('REQ-RROS-STILL-NONE');

    $inProgressDraft = $makeSigned('REQ-RROS-IN-PROGRESS-DRAFT');
    RequisitionIssuanceSlip::create([
        'request_id' => $inProgressDraft->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-IN-PROGRESS-DRAFT',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'draft',
    ]);

    $inProgressPrepared = $makeSigned('REQ-RROS-IN-PROGRESS');
    RequisitionIssuanceSlip::create([
        'request_id' => $inProgressPrepared->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-IN-PROGRESS',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'prepared',
    ]);

    $approved = $makeSigned('REQ-RROS-APPROVED');
    $approvedSlip = RequisitionIssuanceSlip::create([
        'request_id' => $approved->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-APPROVED',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'approved',
        'approval_routing_mode' => 'epirma',
        'ris_epirma_status' => 'signed',
        'ris_epirma_signed_at' => now(),
    ]);

    $completed = $makeSigned('REQ-RROS-COMPLETED');
    RequisitionIssuanceSlip::create([
        'request_id' => $completed->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-COMPLETED',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'completed',
    ]);

    $this->actingAs($user)
        ->get('/rros/requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->where('workspaceMode', 'rros')
            ->where('defaultTab', 'still_for_action')
            ->where('workspaceSummary.ris_still_for_action', 2)
            ->where('workspaceSummary.ris_in_progress', 1)
            ->where('workspaceSummary.ris_approved', 1)
            ->where('workspaceSummary.ris_completed', 1)
            ->has('approved.data', 2)
            ->has('inProgress.data', 1)
            ->has('risApproved.data', 1)
            ->has('risCompleted.data', 1)
            ->where('approved.data', fn ($rows) => collect($rows)->pluck('reference_number')->sort()->values()->all() === [
                'REQ-RROS-IN-PROGRESS-DRAFT',
                'REQ-RROS-STILL-NONE',
            ])
            ->where('inProgress.data.0.reference_number', 'REQ-RROS-IN-PROGRESS')
            ->where('risApproved.data.0.reference_number', 'REQ-RROS-APPROVED')
            ->where('risApproved.data.0.signed_ris_view_url', url("/rros/ris/{$approvedSlip->id}/epirma/signed-preview"))
            ->where('risCompleted.data.0.reference_number', 'REQ-RROS-COMPLETED')
        );
});
