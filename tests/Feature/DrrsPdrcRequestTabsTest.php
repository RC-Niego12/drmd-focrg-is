<?php

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use App\Services\WorkflowNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function drrsWorkspaceBase()
{
    return AssistanceRequest::query()
        ->where('submission_type', '!=', 'lgu_dromic_relief_request')
        ->where('endorsed_to_drrs', true);
}

it('partitions DRRS PDRC workspace tabs and summary counts by assessment and signed state', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $stillForAction = AssistanceRequest::create([
        'reference_number' => 'REQ-TAB-STILL',
        'requesting_agency' => 'Still LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
    ]);

    $draftAssessment = AssistanceRequest::create([
        'reference_number' => 'REQ-TAB-DRAFT',
        'requesting_agency' => 'Draft LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
    ]);

    $finalAssessment = AssistanceRequest::create([
        'reference_number' => 'REQ-TAB-FINAL',
        'requesting_agency' => 'Final LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'assessment_status' => 'final',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_aa_status' => 'in_progress',
        'epirma_assessment_signed_at' => now(),
    ]);

    $approvedBothSigned = AssistanceRequest::create([
        'reference_number' => 'REQ-TAB-APPROVED',
        'requesting_agency' => 'Approved LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_aa_status' => 'completed',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);

    $expectedTotal = drrsWorkspaceBase()->count();
    $expectedStill = drrsWorkspaceBase()->whereNull('assessment_status')->count();
    $expectedCreated = drrsWorkspaceBase()
        ->whereNotNull('assessment_status')
        ->where(fn ($q) => $q->whereNull('epirma_assessment_signed_at')->orWhereNull('epirma_response_letter_signed_at'))
        ->count();
    $expectedApproved = drrsWorkspaceBase()
        ->whereNotNull('epirma_assessment_signed_at')
        ->whereNotNull('epirma_response_letter_signed_at')
        ->count();

    expect($expectedStill)->toBeGreaterThanOrEqual(1)
        ->and($expectedCreated)->toBeGreaterThanOrEqual(2)
        ->and($expectedApproved)->toBeGreaterThanOrEqual(1);

    $this->actingAs($user)
        ->get('/requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->where('workspaceSummary.requests', $expectedTotal)
            ->where('workspaceSummary.still_for_action', $expectedStill)
            ->where('workspaceSummary.created_assessments', $expectedCreated)
            ->where('workspaceSummary.approved', $expectedApproved)
            ->where('requests.data', fn ($rows) => collect($rows)->contains('id', $stillForAction->id)
                && ! collect($rows)->contains('id', $draftAssessment->id)
                && ! collect($rows)->contains('id', $finalAssessment->id)
                && ! collect($rows)->contains('id', $approvedBothSigned->id))
            ->where('assessments.data', fn ($rows) => collect($rows)->contains('id', $draftAssessment->id)
                && collect($rows)->contains('id', $finalAssessment->id)
                && ! collect($rows)->contains('id', $stillForAction->id)
                && ! collect($rows)->contains('id', $approvedBothSigned->id))
            ->where('approved.data', fn ($rows) => collect($rows)->contains('id', $approvedBothSigned->id)
                && ! collect($rows)->contains('id', $draftAssessment->id)
                && ! collect($rows)->contains('id', $finalAssessment->id)
                && ! collect($rows)->contains('id', $stillForAction->id)));
});

it('wires In Progress signed assessment preview through epirma view even when signed cache is missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    Storage::fake('public');

    $inProgress = AssistanceRequest::create([
        'reference_number' => 'REQ-INPROG-SIGNED-ASSESS',
        'requesting_agency' => 'In Progress LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'assessment_status' => 'final',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_aa_status' => 'in_progress',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => null,
    ]);

    $handoffPath = 'epirma_signed_documents/Assessment-REQ-INPROG-SIGNED-ASSESS.pdf';
    Storage::disk('public')->put($handoffPath, "%PDF-1.4\nhandoff\n%%EOF\n");

    $assessmentDoc = EpirmaSignedDocument::create([
        'assistance_request_id' => $inProgress->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => '33333333-3333-3333-3333-333333333333',
        'document_name' => 'Assessment-REQ-INPROG-SIGNED-ASSESS.pdf',
        'document_path' => $handoffPath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-assessment.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    $this->actingAs($user)
        ->get('/requests?search=REQ-INPROG-SIGNED-ASSESS')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->where('assessments.data', function ($rows) use ($inProgress, $assessmentDoc) {
                $row = collect($rows)->firstWhere('id', $inProgress->id);
                if (! $row) {
                    return false;
                }

                $assessmentUrl = (string) ($row['signed_assessment_view_url'] ?? '');

                return str_contains($assessmentUrl, "/requests/{$inProgress->id}/epirma/documents/{$assessmentDoc->id}/view")
                    && ($row['signed_assessment_preview_kind'] ?? null) === 'signed'
                    && blank($row['signed_response_letter_view_url'] ?? null)
                    && ! str_contains($assessmentUrl, '/assessment-pdf');
            })
            ->where('requests.data', fn ($rows) => collect($rows)->doesntContain('id', $inProgress->id))
            ->where('approved.data', fn ($rows) => collect($rows)->doesntContain('id', $inProgress->id)));
});

it('wires Approved signed assessment preview through epirma view like response letter when signed cache is missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    Storage::fake('public');

    $approved = AssistanceRequest::create([
        'reference_number' => 'REQ-APPROVED-PREVIEW',
        'requesting_agency' => 'Preview LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'assessment_status' => 'final',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_aa_status' => 'completed',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);

    $handoffPath = 'epirma_signed_documents/Assessment-REQ-APPROVED-PREVIEW.pdf';
    Storage::disk('public')->put($handoffPath, "%PDF-1.4\nhandoff\n%%EOF\n");

    $assessmentDoc = EpirmaSignedDocument::create([
        'assistance_request_id' => $approved->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => '11111111-1111-1111-1111-111111111111',
        'document_name' => 'Assessment-REQ-APPROVED-PREVIEW.pdf',
        'document_path' => $handoffPath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-assessment.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    $responseCache = EpirmaDocumentStatusService::SIGNED_CACHE_DIR.'99-rl.pdf';
    Storage::disk('public')->put($responseCache, "%PDF-1.4\nsigned-rl\n%%EOF\n");

    $responseDoc = EpirmaSignedDocument::create([
        'assistance_request_id' => $approved->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => '22222222-2222-2222-2222-222222222222',
        'document_name' => 'Response-Letter-REQ-APPROVED-PREVIEW.pdf',
        'document_path' => $responseCache,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-rl.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    $this->actingAs($user)
        ->get('/requests?search=REQ-APPROVED-PREVIEW')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->where('approved.data', function ($rows) use ($approved, $assessmentDoc, $responseDoc) {
                $row = collect($rows)->firstWhere('id', $approved->id);
                if (! $row) {
                    return false;
                }

                $assessmentUrl = (string) ($row['signed_assessment_view_url'] ?? '');
                $responseUrl = (string) ($row['signed_response_letter_view_url'] ?? '');

                return str_contains($assessmentUrl, "/requests/{$approved->id}/epirma/documents/{$assessmentDoc->id}/view")
                    && ($row['signed_assessment_preview_kind'] ?? null) === 'signed'
                    && str_contains($responseUrl, "/requests/{$approved->id}/epirma/documents/{$responseDoc->id}/view")
                    && ! str_contains($assessmentUrl, '/assessment-pdf');
            }));
});

it('excludes draft and final assessed requests from Still for Action', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    AssistanceRequest::create([
        'reference_number' => 'REQ-STILL-ONLY',
        'requesting_agency' => 'Open LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
    ]);

    $draftId = AssistanceRequest::create([
        'reference_number' => 'REQ-DRAFT-EXCLUDED',
        'requesting_agency' => 'Draft LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
    ])->id;

    $this->actingAs($user)
        ->get('/requests?status=actionable&search=REQ-STILL-ONLY')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.reference_number', 'REQ-STILL-ONLY')
            ->where('requests.data', fn ($rows) => count($rows) === 1));

    $this->actingAs($user)
        ->get('/requests?status=actionable&search=REQ-DRAFT-EXCLUDED')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data', fn ($rows) => collect($rows)->doesntContain('id', $draftId))
            ->where('assessments.data', fn ($rows) => collect($rows)->contains('reference_number', 'REQ-DRAFT-EXCLUDED')));
});

it('keeps assessments tab query for In Progress', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/requests?tab=assessments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->where('defaultTab', 'assessments')
            ->has('assessments'));
});

it('broadcasts request.updated when DRMD AA records a Request DRN', function (): void {
    $this->seed(DatabaseSeeder::class);
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.public_url' => 'http://127.0.0.1:6001',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
    ]);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-DRN-LIVE',
        'requesting_agency' => 'DRN LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'request_drn' => null,
        'submitted_at' => now(),
    ]);

    $record->update(['request_drn' => 'DRN-LIVE-001']);
    app(WorkflowNotificationService::class)->broadcastRequestUpdated($record->fresh(), [
        'changed' => ['request_drn'],
        'source' => 'drmd_aa_drn',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'request.updated'
        && ($request['payload']['request_id'] ?? null) === $record->id
        && ($request['payload']['request_drn'] ?? null) === 'DRN-LIVE-001'
        && in_array('request_drn', $request['payload']['changed'] ?? [], true));
});
