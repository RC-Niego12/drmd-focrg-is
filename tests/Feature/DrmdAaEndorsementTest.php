<?php

use App\Models\AssistanceRequest;
use App\Models\Incident;
use App\Models\RequestParty;
use App\Models\User;
use App\Support\AssessmentNarrative;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('allows DRMD AA to endorse an FNI request before DRRS location assessment', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $party = RequestParty::create([
        'directory_key' => 'test-proposing-party',
        'requesting_party' => 'Test Proposing Party',
        'office_agency_details' => 'Test Office',
        'source' => 'test',
        'is_active' => true,
    ]);
    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();

    $this->actingAs($user)->post('/drmd-aa/requests', [
        'date_received_by_drmd' => '2026-07-13',
        'request_drn' => 'DRN-TEST-001',
        'request_party_id' => $party->id,
        'office_agency_details' => '',
        'remarks' => 'Test endorsement',
        'document' => UploadedFile::fake()->create('request.pdf', 100, 'application/pdf'),
    ])->assertRedirect(route('drmd-aa.requests.index'))
        ->assertSessionHas('success');

    $record = AssistanceRequest::where('request_drn', 'DRN-TEST-001')->firstOrFail();
    expect($record->province)->toBeNull()
        ->and($record->municipality)->toBeNull()
        ->and($record->endorsed_to_drrs)->toBeTrue()
        ->and($record->submission_type)->toBe('fni_request');
    Storage::disk('public')->assertExists(str($record->source_document_url)->after('/storage/')->toString());
    $this->actingAs($user)
        ->get(route('requests.source-document', $record))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('keeps drafts under review and marks them final only after a verified e-PIRMA callback', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-ASSESSMENT-STATUS',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DRIMS');
    config()->set('services.epirma.verify_ssl', true);
    config()->set('services.epirma.allow_insecure_ssl', false);

    Illuminate\Support\Facades\Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Illuminate\Support\Facades\Http::response([
            'success' => true,
            'token' => 'epirma-auth-token',
        ]),
    ]);

    $direct = $this->actingAs($user)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'final']);
    $direct->assertStatus(422);
    expect($record->fresh()->assessment_status)->toBe('draft');

    $handoff = $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->post(route('requests.epirma.sign', $record))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    $location = $handoff->headers->get('X-Inertia-Location');
    expect($location)->toContain('/microservice/documents');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['app_name', 'external_document_uuid', 'redirect_url', 'secret', 'token', 'document_url'])
        ->and($query['token'])->toBe('epirma-auth-token')
        ->and($query['app_name'])->toBe('DRIMS')
        ->and(urldecode($query['redirect_url']))->toContain('/requests');

    $this->actingAs($user)
        ->withHeader('X-Inertia', '')
        ->get($query['redirect_url'].(str_contains($query['redirect_url'], '?') ? '&' : '?').'status=signed&signature_reference=EPIRMA-SIG-001')
        ->assertRedirect(url('/requests?default_tab=assessments'));
    expect($record->fresh()->status)->toBe('acted')
        ->and($record->fresh()->assessment_status)->toBe('final')
        ->and($record->fresh()->epirma_status)->toBe('signed')
        ->and($record->fresh()->epirma_signature_reference)->toBe('EPIRMA-SIG-001');

    $this->actingAs($user)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'draft'])
        ->assertRedirect();
    expect($record->fresh()->status)->toBe('under_review')
        ->and($record->fresh()->assessment_status)->toBe('draft');
});

it('allows a submitted or disapproved assessment to return to draft for correction', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-ASSESSMENT-REVISION',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'rejected',
        'assessment_status' => 'submitted',
    ]);

    $this->actingAs($user)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'draft'])
        ->assertRedirect();

    expect($record->fresh()->assessment_status)->toBe('draft')
        ->and($record->fresh()->status)->toBe('under_review');
});

it('keeps preparedness forms free of pseudo-disasters and narrative signature placeholders', function (): void {
    $incident = Incident::create(['name' => 'Prepositioning', 'incident_date' => '2026-07-15']);
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-PREPAREDNESS-FORM',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'date_received_by_drmd' => '2026-07-15',
        'purpose' => 'Preparedness for Response',
        'incident_id' => $incident->id,
        'recommendations' => "Preparedness stocks were validated.\nSignature: _____________________________________\nDate: ___________________________________________",
        'status' => 'under_review',
        'assessment_form_data' => [
            'request_type' => null,
            'response_purpose' => 'Preparedness for Response',
            'provide_augmentation' => false,
        ],
    ]);

    $html = view('documents.assessment', ['request' => $record->load(['items', 'incident']), 'pageMargin' => 18])->render();

    expect(AssessmentNarrative::sanitize($record->recommendations))->toBe('Preparedness stocks were validated.')
        ->and($html)->not->toContain('Signature: _____________________________________')
        ->and($html)->not->toContain('Date: ___________________________________________')
        ->and($html)->not->toContain('>Prepositioning<')
        ->and($html)->toContain('July 15, 2026');
});
