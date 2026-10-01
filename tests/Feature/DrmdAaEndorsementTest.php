<?php

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\Incident;
use App\Models\RequestParty;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use App\Services\EpirmaWorkflowService;
use App\Services\WorkflowNotificationService;
use App\Support\AssessmentNarrative;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

it('keeps drafts under review and marks them final only after a verified assessment route callback', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $pdrc->forceFill(['id_number' => '16-11720'])->save();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-99999'])->save();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-ASSESSMENT-STATUS',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'endorsed_to_drrs' => true,
    ]);

    config()->set('services.epirma.sign_url', '');
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DRIMS');
    config()->set('services.epirma.verify_ssl', true);
    config()->set('services.epirma.allow_insecure_ssl', false);
    config()->set('services.epirma.public_app_url', null);
    config()->set('services.epirma.force_https_urls', false);
    config()->set('services.epirma.local_bypass', false);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'epirma-auth-token',
        ]),
    ]);

    $direct = $this->actingAs($pdrc)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'final']);
    $direct->assertStatus(422);
    expect($record->fresh()->assessment_status)->toBe('draft');

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertForbidden();

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.forward', $record))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($record->fresh()->epirma_aa_status)->toBe('pending')
        ->and($record->fresh()->epirma_forwarded_by)->toBe($pdrc->id)
        ->and($record->fresh()->assessment_drn)->toBeNull()
        ->and($record->fresh()->response_drn)->toBeNull()
        ->and($record->fresh()->lgu_response_letter_advance_path)->toBeNull()
        ->and($record->fresh()->lgu_response_letter_advance_sent_at)->toBeNull();

    $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422);

    $this->actingAs($aa)
        ->patchJson(route('requests.epirma.document-drns', $record), [
            'assessment_drn' => 'CARAGA-FO-DRMD-DRRMS-SS-REP-26-08-0001',
            'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0001',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $handoff = $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('open_in_new_tab', true);

    $location = $handoff->json('redirect_url');
    expect($location)->toContain('/microservice/document-routing');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['app_name', 'external_document_uuid', 'redirect_url', 'secret', 'token', 'document_url'])
        ->and($query['token'])->toBe('epirma-auth-token')
        ->and($query['app_name'])->toBe('DRIMS')
        ->and(urldecode($query['redirect_url']))->toContain('/integrations/epirma/requests/'.$record->id.'/callback');

    $this->actingAs($aa)
        ->withHeader('X-Inertia', '')
        ->get($query['redirect_url'].(str_contains($query['redirect_url'], '?') ? '&' : '?').'status=signed&signature_reference=EPIRMA-SIG-001')
        ->assertRedirect(route('drrs-aa.epirma.index', ['search' => $record->reference_number]));
    expect($record->fresh()->status)->toBe('acted')
        ->and($record->fresh()->assessment_status)->toBe('final')
        ->and($record->fresh()->epirma_status)->toBe('signed')
        ->and($record->fresh()->epirma_signature_reference)->toBe('EPIRMA-SIG-001')
        ->and($record->fresh()->epirma_assessment_signed_at)->not->toBeNull();

    // Once forwarded / signed, reopen is locked — e-PIRMA owns the assessment status.
    $this->actingAs($pdrc)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'draft'])
        ->assertStatus(422);
    expect($record->fresh()->assessment_status)->toBe('final');
});

it('prefers the official microservice document-routing handoff for Route after PDRC forward', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $pdrc->forceFill(['id_number' => '16-11720'])->save();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-99999'])->save();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-DOCS-FLOW',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0002',
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0002',
        'endorsed_to_drrs' => true,
    ]);

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.forward', $record))
        ->assertOk();

    config()->set('services.epirma.sign_url', 'https://epirma.example.test/sign');
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.local_bypass', false);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);
    config()->set('services.epirma.force_https_urls', false);
    config()->set('services.epirma.public_app_url', null);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'epirma-auth-token',
        ]),
    ]);

    $handoff = $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $location = $handoff->json('redirect_url');
    expect($location)->toContain('/microservice/document-routing')
        ->and($location)->not->toStartWith('https://epirma.example.test/sign?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['app_name', 'external_document_uuid', 'redirect_url', 'secret', 'token', 'document_url'])
        ->and($query['token'])->toBe('epirma-auth-token')
        ->and($query['app_name'])->toBe('DROMIS')
        ->and(urldecode($query['redirect_url']))->toContain('/integrations/epirma/requests/'.$record->id.'/callback');

    $doc = EpirmaSignedDocument::query()
        ->where('assistance_request_id', $record->id)
        ->where('action', 'route')
        ->latest('id')
        ->first();
    expect($doc)->not->toBeNull()
        ->and($doc->document_type)->toBe('assessment')
        ->and($doc->routing_status)->toBe('routed')
        ->and($doc->document_uuid)->toBe($query['external_document_uuid'])
        ->and($doc->handoff)->toBe('document_routing');

    $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('blocks continue from re-opening document-routing after a successful handoff', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $pdrc->forceFill(['id_number' => '16-11720'])->save();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-99999'])->save();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-CONTINUE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0010',
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0010',
        'endorsed_to_drrs' => true,
    ]);

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.forward', $record))
        ->assertOk();

    config()->set('services.epirma.sign_url', '');
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.local_bypass', false);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);
    config()->set('services.epirma.force_https_urls', false);
    config()->set('services.epirma.public_app_url', null);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'epirma-auth-token',
        ]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'message' => 'Not found',
        ], 404),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'message' => 'Not found',
        ], 404),
    ]);

    $handoff = $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertOk()
        ->assertJsonPath('success', true);

    parse_str((string) parse_url((string) $handoff->json('redirect_url'), PHP_URL_QUERY), $routeQuery);
    $externalUuid = $routeQuery['external_document_uuid'] ?? null;
    expect($externalUuid)->not->toBeNull();
    expect((string) $handoff->json('redirect_url'))->toContain('/microservice/document-routing');

    $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($caps['assessment']['can_route'])->toBeFalse()
        ->and($caps['assessment']['can_continue'])->toBeFalse()
        ->and($caps['assessment']['handoff_complete'])->toBeTrue()
        ->and($caps['assessment']['is_in_epirma'])->toBeTrue()
        ->and($caps['assessment']['is_signed'])->toBeFalse();

    $blocked = $this->actingAs($aa)
        ->postJson(route('requests.epirma.continue', $record), ['document_type' => 'assessment'])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('already_routed', true)
        ->assertJsonPath('continued', false);

    $blockedUrl = (string) ($blocked->json('redirect_url') ?? '');
    expect($blockedUrl)->not->toContain('/microservice/document-routing')
        ->and($blocked->json('message'))->toContain('Already routed in e-PIRMA');

    $this->actingAs($aa)
        ->withHeader('X-Inertia', '')
        ->get(urldecode((string) $routeQuery['redirect_url']).'&status=routed')
        ->assertRedirect(route('drrs-aa.epirma.index', ['search' => $record->reference_number]));

    $docs = EpirmaSignedDocument::query()
        ->where('assistance_request_id', $record->id)
        ->where('document_type', 'assessment')
        ->where('action', 'route')
        ->get();

    expect($docs)->toHaveCount(1)
        ->and($docs->first()->document_uuid)->toBe($externalUuid)
        ->and($docs->first()->routing_status)->toBe('routed');
});

it('opens remote e-PIRMA URL on continue when handoff already completed', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-CONTINUE-REMOTE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0012',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
    ]);

    $doc = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_name' => 'Assessment-'.$record->reference_number.'.pdf',
        'document_path' => 'epirma_signed_documents/Assessment-REQ-EPIRMA-CONTINUE-REMOTE.pdf',
        'document_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'routing_status' => 'routed',
        'remote_document_url' => 'https://epirma.example.test/documents/view/abc',
        'initiated_by' => $aa->id,
        'routed_at' => now(),
        'handoff' => 'document_routing',
        'encoded_by' => $aa->name,
        'timestamp' => now(),
    ]);

    $continued = $this->actingAs($aa)
        ->postJson(route('requests.epirma.continue', $record), ['document_type' => 'assessment'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('continued', false)
        ->assertJsonPath('already_routed', true)
        ->assertJsonPath('open_in_new_tab', true);

    $redirectUrl = (string) $continued->json('redirect_url');
    expect($redirectUrl)->toContain('https://epirma.example.test/documents/view/abc')
        ->and($redirectUrl)->not->toContain('/microservice/document-routing');

    expect(EpirmaSignedDocument::query()->where('assistance_request_id', $record->id)->count())->toBe(1)
        ->and($doc->fresh()->routing_status)->toBe('routed');
});

it('redirects browser users away from raw 403 on invalid e-PIRMA callbacks', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-BAD-CALLBACK',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0011',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_callback_token' => hash('sha256', 'valid-token'),
    ]);

    $this->actingAs($aa)
        ->get(route('epirma.callback', ['assistanceRequest' => $record->id, 'token' => 'stale-token']))
        ->assertRedirect(route('drrs-aa.epirma.index', ['search' => $record->reference_number]))
        ->assertSessionHas('error', 'Invalid or expired e-PIRMA callback.');

    $this->actingAs($aa)
        ->getJson(route('epirma.callback', ['assistanceRequest' => $record->id, 'token' => 'stale-token']))
        ->assertForbidden();
});

it('surfaces invalid secret errors instead of locally signing assessments', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $pdrc->forceFill(['id_number' => '16-11720'])->save();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-99999'])->save();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-INVALID-SECRET',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0003',
        'endorsed_to_drrs' => true,
    ]);

    $this->actingAs($pdrc)->postJson(route('requests.epirma.forward', $record))->assertOk();

    config()->set('services.epirma.sign_url', '');
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'bad-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.local_bypass', false);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => false,
            'message' => 'Invalid secret',
        ], 401),
    ]);

    $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $fresh = $record->fresh();
    expect($fresh->assessment_status)->toBe('draft')
        ->and($fresh->epirma_aa_status)->toBe('pending');
});

it('returns the assessment to DRRS PDRC when both assessment and response letter routes are cancelled', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-BOTH-CANCELLED',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0200',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/both-cancel-a.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(20),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(20),
    ]);
    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'response_letter',
        'action' => 'route',
        'document_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa2',
        'document_name' => 'response-letter.pdf',
        'document_path' => 'epirma_signed_documents/both-cancel-rl.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    $status = app(EpirmaDocumentStatusService::class)->refreshAaStatus($record);
    $fresh = $record->fresh();
    expect($status)->toBe('')
        ->and($fresh->epirma_forwarded_to_drrs_aa_at)->toBeNull()
        ->and($fresh->epirma_forwarded_by)->toBeNull()
        ->and($fresh->epirma_aa_status)->toBeNull();

    $caps = app(\App\Services\EpirmaWorkflowService::class)->capabilitiesFor($fresh, $pdrc);
    expect($caps['forwarded'])->toBeFalse()
        ->and($caps['read_only'])->toBeFalse()
        ->and($caps['forward']['can_forward'])->toBeTrue();
});

it('returns the assessment to DRRS PDRC when only a cancelled response route remains', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-RESPONSE-CANCELLED-ONLY',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0200B',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'response_letter',
        'action' => 'route',
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb1',
        'document_name' => 'response-letter.pdf',
        'document_path' => 'epirma_signed_documents/only-rl-cancel.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    app(EpirmaDocumentStatusService::class)->refreshAaStatus($record);
    $fresh = $record->fresh();

    expect($fresh->epirma_forwarded_to_drrs_aa_at)->toBeNull()
        ->and($fresh->epirma_aa_status)->toBeNull();

    $this->actingAs($pdrc)
        ->get("/requests/{$fresh->id}/assessment-form")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/AssessmentForm')
            ->where('canEditResponseLetterBody', true)
            ->where('epirma.forwarded', false)
            ->where('epirma.read_only', false)
        );

    $this->actingAs($pdrc)
        ->patch("/requests/{$fresh->id}/response-letter-body", [
            'opening' => 'Custom opening after cancel revert.',
        ])
        ->assertRedirect();

    expect(data_get($fresh->fresh()->assessment_form_data, 'response_letter_body.opening'))
        ->toBe('Custom opening after cancel revert.');
});

it('keeps the forward handoff when an open e-PIRMA route still exists after a cancel', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-PARTIAL-CANCEL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0200C',
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0200C',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'cccccccc-cccc-cccc-cccc-ccccccccccc1',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/partial-a.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinutes(20),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(20),
    ]);
    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'response_letter',
        'action' => 'route',
        'document_uuid' => 'cccccccc-cccc-cccc-cccc-ccccccccccc2',
        'document_name' => 'response-letter.pdf',
        'document_path' => 'epirma_signed_documents/partial-rl.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    $status = app(EpirmaDocumentStatusService::class)->refreshAaStatus($record);
    $fresh = $record->fresh();

    expect($status)->toBe('in_progress')
        ->and($fresh->epirma_forwarded_to_drrs_aa_at)->not->toBeNull()
        ->and($fresh->epirma_aa_status)->toBe('in_progress');
});

it('rejects route when employee id_number is missing and does not bump aa_status', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => null])->save();

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-NO-ID',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0201',
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0201',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'pending',
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.local_bypass', false);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake();

    $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Your employee ID number is not set or not registered in e-PIRMA.');

    expect($record->fresh()->epirma_aa_status)->toBe('pending');
    Http::assertNothingSent();
});

it('keeps aa_status pending when e-PIRMA reports user not found on re-route', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-11772'])->save();

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-USER-NOT-FOUND',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0202',
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0202',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'pending',
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb1',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/user-not-found-a.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(5),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(5),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.local_bypass', false);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => false,
            'message' => 'User not found with provided ID number',
        ], 422),
    ]);

    $response = $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonFragment(['id_number' => '16-11772']);

    $message = (string) $response->json('message');
    expect($message)->toContain('not set or not registered in e-PIRMA')
        ->and($message)->toContain('16-11772')
        ->and($record->fresh()->epirma_aa_status)->toBe('pending');
});

it('tracks partial e-PIRMA signer progress without finalizing the assessment', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-PARTIAL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'epirma_status' => 'pending',
        'epirma_transaction_id' => '11111111-1111-1111-1111-111111111111',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => '11111111-1111-1111-1111-111111111111',
        'document_name' => 'Assessment-REQ-EPIRMA-PARTIAL.pdf',
        'document_path' => 'epirma_signed_documents/partial.pdf',
        'routing_status' => 'routed',
        'handoff' => 'document_routing',
        'initiated_by' => $user->id,
        'routed_at' => now(),
        'timestamp' => now(),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'signers' => [
                ['fullname' => 'DRRS AA', 'username' => 'aa', 'status' => 'signed', 'date_signed' => '2026-08-02T10:00:00+08:00'],
                ['fullname' => 'DRMD Chief', 'username' => 'chief', 'status' => 'pending', 'date_signed' => null],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->getJson(route('requests.epirma.status', $record))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.routing_status', 'partially_signed');

    expect($document->fresh()->routing_status)->toBe('partially_signed')
        ->and($record->fresh()->assessment_status)->toBe('draft')
        ->and($record->fresh()->epirma_status)->toBe('pending');
});

it('finalizes the assessment when e-PIRMA status poll reports all route signers complete', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-COMPLETE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'epirma_status' => 'pending',
        'epirma_transaction_id' => '22222222-2222-2222-2222-222222222222',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => '22222222-2222-2222-2222-222222222222',
        'document_name' => 'Assessment-REQ-EPIRMA-COMPLETE.pdf',
        'document_path' => 'epirma_signed_documents/complete.pdf',
        'routing_status' => 'partially_signed',
        'handoff' => 'document_routing',
        'initiated_by' => $user->id,
        'routed_at' => now(),
        'timestamp' => now(),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.document_token', 'view-token');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'base_path' => 'https://epirma.example.test/files/signed.pdf',
            'signers' => [
                ['fullname' => 'DRRS AA', 'username' => 'aa', 'status' => 'signed'],
                ['fullname' => 'DRMD Chief', 'username' => 'chief', 'status' => 'completed'],
            ],
            'signed_document' => [
                'document_url' => 'https://epirma.example.test/files/signed.pdf',
                'signed_filename' => 'signed-complete.pdf',
                'signed_at' => '2026-08-02T12:00:00+08:00',
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->getJson(route('requests.epirma.status', $record))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.routing_status', 'signed')
        ->assertJsonPath('data.view_url', 'https://epirma.example.test/files/signed.pdf?token=view-token');

    expect($document->fresh()->routing_status)->toBe('signed')
        ->and($record->fresh()->assessment_status)->toBe('final')
        ->and($record->fresh()->status)->toBe('acted')
        ->and($record->fresh()->epirma_status)->toBe('signed');
});

it('lists historical e-PIRMA documents for a request and keeps draft on routing callback', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $pdrc->forceFill(['id_number' => '16-11720'])->save();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $aa->forceFill(['id_number' => '16-99999'])->save();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-HISTORY',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'assessment_drn' => 'FOCARAGA-DRMD-AS-26-08-0004',
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0004',
        'endorsed_to_drrs' => true,
    ]);

    $this->actingAs($pdrc)->postJson(route('requests.epirma.forward', $record))->assertOk();

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'document_name' => 'old.pdf',
        'document_path' => 'epirma_signed_documents/old.pdf',
        'routing_status' => 'cancelled',
        'handoff' => 'document_routing',
        'timestamp' => now()->subHour(),
    ]);

    config()->set('services.epirma.sign_url', '');
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.app_name', 'DROMIS');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'epirma-auth-token',
        ]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'status' => 'routed',
            'signers' => [],
        ]),
    ]);

    $handoff = $this->actingAs($aa)
        ->postJson(route('requests.epirma.route', $record), ['document_type' => 'assessment'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $redirect = $handoff->json('redirect_url');
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    $this->actingAs($aa)
        ->get($query['redirect_url'].(str_contains($query['redirect_url'], '?') ? '&' : '?').'status=routed')
        ->assertRedirect(route('drrs-aa.epirma.index', ['search' => $record->reference_number]));

    expect($record->fresh()->assessment_status)->toBe('draft')
        ->and($record->fresh()->epirma_status)->toBe('pending');

    $this->actingAs($aa)
        ->getJson(route('requests.epirma.documents', $record))
        ->assertOk()
        ->assertJsonPath('success', true)
        // Cancelled/failed routes are omitted from Track history; only the live route remains.
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.routing_status', 'routed');

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($caps['documents'])->toHaveCount(1)
        ->and($caps['documents'][0]['routing_status'])->toBe('routed')
        // Cancelled rows stay available privately for re-route capability checks.
        ->and($caps['assessment']['can_route'])->toBeFalse();

    $current = EpirmaSignedDocument::query()
        ->where('assistance_request_id', $record->id)
        ->where('document_uuid', $record->fresh()->epirma_transaction_id)
        ->firstOrFail();

    $this->actingAs($aa)
        ->deleteJson(route('requests.epirma.documents.destroy', [$record, $current]))
        ->assertOk()
        ->assertJsonPath('success', true)
        // Cancelled history row remains in DB but is still excluded from Track.
        ->assertJsonCount(0, 'data');
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
        'assessment_acted_by' => $user->id,
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

it('marks cancelled e-PIRMA routes as returned to DRRS PDRC and broadcasts status changes', function (): void {
    $this->seed(DatabaseSeeder::class);
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-CANCEL-REROUTE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0099',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_status' => 'pending',
        'epirma_transaction_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'epirma_callback_token' => hash('sha256', 'cancel-callback-token'),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinutes(5),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(5),
    ]);

    $blocked = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($blocked['assessment']['can_route'])->toBeFalse()
        ->and($blocked['assessment']['handoff_complete'])->toBeTrue();

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'message' => 'Not found',
        ], 404),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'message' => 'Not found',
        ], 404),
    ]);
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    $this->actingAs($aa)
        ->getJson(route('epirma.callback', [
            'assistanceRequest' => $record->id,
            'token' => 'cancel-callback-token',
            'status' => 'cancelled',
        ]))
        ->assertOk()
        ->assertJsonPath('routing_status', 'cancelled');

    expect($document->fresh()->routing_status)->toBe('cancelled')
        ->and($record->fresh()->assessment_status)->toBe('draft')
        ->and($record->fresh()->epirma_forwarded_to_drrs_aa_at)->toBeNull()
        ->and($record->fresh()->epirma_aa_status)->toBeNull();

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($caps['forwarded'])->toBeFalse()
        ->and($caps['assessment']['can_route'])->toBeFalse()
        ->and($caps['assessment']['route_blocked_reason'])->toContain('forward')
        ->and($caps['assessment']['can_continue'])->toBeFalse()
        ->and($caps['assessment']['handoff_complete'])->toBeFalse()
        ->and($caps['assessment']['is_in_epirma'])->toBeFalse()
        ->and($caps['assessment']['is_signed'])->toBeFalse();

    $pdrcCaps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $pdrc);
    expect($pdrcCaps['forward']['can_forward'])->toBeTrue()
        ->and($pdrcCaps['read_only'])->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'epirma.status.changed'
        && ($request['payload']['routing_status'] ?? null) === 'cancelled'
        && ($request['payload']['request_id'] ?? null) === $record->id);
});

it('keeps a re-forward after cancelled e-PIRMA routes instead of silently reverting', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-REFORWARD',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0200',
        'endorsed_to_drrs' => true,
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-reforward.pdf',
        'routing_status' => 'cancelled',
        'completed_at' => now()->subMinutes(5),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(5),
    ]);

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.forward', $record))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('epirma.forwarded', true);

    $fresh = $record->fresh();
    expect($fresh->epirma_forwarded_to_drrs_aa_at)->not->toBeNull()
        ->and($fresh->epirma_aa_status)->toBe('pending');

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($fresh, $pdrc);
    expect($caps['forwarded'])->toBeTrue()
        ->and($caps['forward']['can_forward'])->toBeFalse()
        ->and($record->fresh()->epirma_forwarded_to_drrs_aa_at)->not->toBeNull();
});

it('syncs cancelled remote status and returns the handoff to DRRS PDRC', function (): void {
    $this->seed(DatabaseSeeder::class);
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-SYNC-CANCEL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0100',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_transaction_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-sync.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'status' => 'cancelled',
            'signers' => [],
        ]),
    ]);

    $result = app(EpirmaDocumentStatusService::class)->syncDocument($document, false);
    expect($result['success'])->toBeTrue()
        ->and($document->fresh()->routing_status)->toBe('cancelled')
        ->and($record->fresh()->epirma_forwarded_to_drrs_aa_at)->toBeNull()
        ->and($record->fresh()->epirma_aa_status)->toBeNull();

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($caps['forwarded'])->toBeFalse()
        ->and($caps['assessment']['can_route'])->toBeFalse()
        ->and($caps['assessment']['route_blocked_reason'])->toContain('forward')
        ->and($caps['assessment']['handoff_complete'])->toBeFalse()
        ->and($caps['assessment']['is_in_epirma'])->toBeFalse()
        // Track history omits cancelled/failed.
        ->and($caps['documents'])->toHaveCount(0);

    $this->actingAs($aa)
        ->getJson(route('requests.epirma.documents', $record))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'epirma.status.changed'
        && ($request['payload']['routing_status'] ?? null) === 'cancelled');

    // Ambiguous remote "routed" must not reopen a cancelled local document.
    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'status' => 'routed',
            'signers' => [],
        ]),
    ]);

    app(EpirmaDocumentStatusService::class)->syncDocument($document->fresh(), false);
    expect($document->fresh()->routing_status)->toBe('cancelled');

    // Soft-sync after revert still leaves the route cancelled and unforwarded.
    $this->actingAs($aa)
        ->postJson(route('drrs-aa.epirma.sync-open'), ['ids' => [$record->id]])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($document->fresh()->routing_status)->toBe('cancelled')
        ->and($record->fresh()->epirma_forwarded_to_drrs_aa_at)->toBeNull();
});

it('marks routed docs cancelled after repeated latest-document-base-path not found', function (): void {
    $this->seed(DatabaseSeeder::class);
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-SYNC-NOTFOUND',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0101',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_transaction_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-notfound.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'error' => 'Document not found.',
        ], 404),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'error' => 'Signed document not found.',
        ], 404),
    ]);

    $service = app(EpirmaDocumentStatusService::class);

    $first = $service->syncDocument($document, false);
    expect($first['success'])->toBeFalse()
        ->and($document->fresh()->routing_status)->toBe('routed');

    $second = $service->syncDocument($document->fresh(), false);
    expect($second['success'])->toBeFalse()
        ->and($document->fresh()->routing_status)->toBe('routed');

    $third = $service->syncDocument($document->fresh(), false);
    expect($third['success'])->toBeTrue()
        ->and($third['cancelled_via'] ?? null)->toBe('remote_not_found')
        ->and($document->fresh()->routing_status)->toBe('cancelled')
        ->and($record->fresh()->epirma_forwarded_to_drrs_aa_at)->toBeNull()
        ->and($record->fresh()->epirma_aa_status)->toBeNull();

    $caps = app(EpirmaWorkflowService::class)->capabilitiesFor($record->fresh(), $aa);
    expect($caps['forwarded'])->toBeFalse()
        ->and($caps['assessment']['can_route'])->toBeFalse()
        ->and($caps['assessment']['is_in_epirma'])->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'epirma.status.changed'
        && ($request['payload']['routing_status'] ?? null) === 'cancelled'
        && ($request['payload']['source'] ?? null) === 'remote_not_found');
});

it('does not cancel on generic not-found payloads or during handoff grace', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-SYNC-GRACE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0103',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_transaction_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-grace.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinute(),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinute(),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'message' => 'Not Found',
        ], 404),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'message' => 'Not Found',
        ], 404),
    ]);

    $service = app(EpirmaDocumentStatusService::class);

    // Generic gateway 404 is not a document-gone signal.
    $generic = $service->syncDocument($document, false);
    expect($generic['cancelled_via'] ?? null)->toBeNull()
        ->and($document->fresh()->routing_status)->toBe('routed');

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'error' => 'Document not found.',
        ], 404),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'error' => 'Signed document not found.',
        ], 404),
    ]);

    // Explicit document-not-found still waits for the handoff grace window.
    foreach (range(1, 5) as $ignored) {
        $service->syncDocument($document->fresh(), false);
    }
    expect($document->fresh()->routing_status)->toBe('routed');
});

it('recovers a false cancelled assessment when remote still has pending signers and broadcasts', function (): void {
    $this->seed(DatabaseSeeder::class);
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-FALSE-CANCEL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0104',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'pending',
        'epirma_transaction_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-false-cancel.pdf',
        'routing_status' => 'cancelled',
        'routed_at' => now()->subMinutes(20),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(20),
        'last_synced_at' => now()->subMinutes(5),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'base_path' => 'https://epirma.example.test/public-secure-file/temp_pdf/live.pdf',
            'signers' => [[
                'fullname' => 'Signer One',
                'username' => 'signer1',
                'type' => 'approval',
                'status' => 'pending',
                'date_signed' => null,
            ]],
        ]),
    ]);

    $result = app(EpirmaDocumentStatusService::class)->syncDocument($document, false);
    expect($result['success'])->toBeTrue()
        ->and($document->fresh()->routing_status)->toBe('routed')
        ->and($document->fresh()->remote_document_url)->toContain('live.pdf')
        ->and($record->fresh()->epirma_aa_status)->toBe('in_progress');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'epirma.status.changed'
        && ($request['payload']['routing_status'] ?? null) === 'routed'
        && ($request['payload']['previous_routing_status'] ?? null) === 'cancelled'
        && ($request['payload']['source'] ?? null) === 'status_sync');
});

it('does not cancel from a single signed-document 404 while latest still answers', function (): void {
    $this->seed(DatabaseSeeder::class);

    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-SIGNED-404',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0102',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_transaction_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => 'assessment',
        'action' => 'route',
        'document_uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        'document_name' => 'assessment.pdf',
        'document_path' => 'epirma_signed_documents/assessment-signed404.pdf',
        'routing_status' => 'routed',
        'routed_at' => now()->subMinutes(10),
        'handoff' => 'document_routing',
        'initiated_by' => $aa->id,
        'timestamp' => now()->subMinutes(10),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'document_id' => 1,
            'base_path' => 'https://epirma.example.test/file.pdf',
            'signers' => [
                ['fullname' => 'A', 'username' => 'a', 'type' => 'approval', 'status' => 'pending', 'date_signed' => null],
            ],
        ]),
        'https://epirma.example.test/api/signed-document/*' => Http::response([
            'error' => 'Signed document not found.',
        ], 404),
    ]);

    $result = app(EpirmaDocumentStatusService::class)->syncDocument($document, false);
    expect($result['success'])->toBeTrue()
        ->and($document->fresh()->routing_status)->toBe('routed');
});

it('locks assessment edits and reopen after forward to DRRS AA', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-EPIRMA-LOCK-FORWARD',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0300',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'pending',
    ]);

    $this->actingAs($pdrc)
        ->patch(route('requests.assessment.status', $record), ['assessment_status' => 'draft'])
        ->assertStatus(422);

    $this->actingAs($pdrc)
        ->patchJson(route('requests.response-drn.update', $record), [
            'prefix' => 'FOCARAGA-DRMD-RL',
            'year' => '26',
            'month' => '08',
            'specified' => '0301',
        ])
        ->assertForbidden();

    expect($record->fresh()->response_drn)->toBe('FOCARAGA-DRMD-RL-26-08-0300');
});

it('shows completed forwarded assessments on the completed AA tab only', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $aa = User::where('email', 'drrs-aa@example.test')->firstOrFail();

    $active = AssistanceRequest::create([
        'reference_number' => 'REQ-AA-ACTIVE-TAB',
        'requesting_agency' => 'Active LGU',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0400',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
    ]);

    $completed = AssistanceRequest::create([
        'reference_number' => 'REQ-AA-COMPLETED-TAB',
        'requesting_agency' => 'Completed LGU',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'acted',
        'assessment_status' => 'final',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0401',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now()->subHour(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'completed',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);

    $this->actingAs($aa)
        ->get(route('drrs-aa.epirma.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DrrsAa/Epirma/Index')
            ->where('filters.status', 'active')
            ->has('queue.data', 1)
            ->where('queue.data.0.reference_number', $active->reference_number)
        );

    $this->actingAs($aa)
        ->get(route('drrs-aa.epirma.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DrrsAa/Epirma/Index')
            ->where('filters.status', 'completed')
            ->has('queue.data', 1)
            ->where('queue.data.0.reference_number', $completed->reference_number)
            ->where('queue.data.0.is_completed', true)
        );

    // Legacy "all" query falls back to active (exactly two tabs).
    $this->actingAs($aa)
        ->get(route('drrs-aa.epirma.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.status', 'active'));
});

it('notifies the DRRS PDRC forwarder when an assessment document is signed', function (): void {
    $this->seed(DatabaseSeeder::class);
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-PDRC-SIGNED-NOTIFY',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-07-15',
        'status' => 'acted',
        'assessment_status' => 'final',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0500',
        'endorsed_to_drrs' => true,
        'epirma_forwarded_to_drrs_aa_at' => now(),
        'epirma_forwarded_by' => $pdrc->id,
        'epirma_aa_status' => 'in_progress',
        'epirma_assessment_signed_at' => now(),
    ]);

    app(WorkflowNotificationService::class)
        ->notifyPdrcEpirmaDocumentSigned($record, EpirmaSignedDocument::TYPE_ASSESSMENT);

    $notification = $pdrc->fresh()->notifications()->latest()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['action_key'])->toBe('drrs_pdrc_epirma_document_signed')
        ->and($notification->data['reference_number'])->toBe('REQ-PDRC-SIGNED-NOTIFY')
        ->and($notification->data['meta']['document_type'])->toBe('assessment');
});
