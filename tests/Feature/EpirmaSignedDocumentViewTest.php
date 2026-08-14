<?php

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use App\Services\EpirmaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

uses(RefreshDatabase::class);

it('serves a signed assessment through viewDocument as a BinaryFileResponse', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $unsignedPath = 'epirma_signed_documents/Assessment-REQ-VIEW-UNSIGNED.pdf';
    $unsignedBytes = "%PDF-1.4\n% unsigned assessment handoff\n%%EOF\n";
    $signedBytes = "%PDF-1.4\n% SIGNED assessment with signature appearance\n%%EOF\n";
    Storage::disk('public')->put($unsignedPath, $unsignedBytes);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-VIEW-ASSESSMENT-SIGNED',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_assessment_signed_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'document_name' => 'Assessment-REQ-VIEW-ASSESSMENT-SIGNED.pdf',
        'document_path' => $unsignedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-assessment.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/signed-assessment.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', 'view-token');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/files/signed-assessment.pdf*' => Http::response($signedBytes, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $response = $this->actingAs($user)
        ->get(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]));

    $response->assertOk()
        ->assertHeader('X-Epirma-Preview-Kind', 'signed');

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);

    $servedPath = $response->baseResponse->getFile()->getPathname();
    expect(file_get_contents($servedPath))->toBe($signedBytes)
        ->and(file_get_contents($servedPath))->not->toBe($unsignedBytes);

    $fresh = $document->fresh();
    expect($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and($fresh->document_path)->not->toBe($unsignedPath)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes);
});

it('serves a signed response letter through viewDocument without return-type errors', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $cachedPath = EpirmaDocumentStatusService::SIGNED_CACHE_DIR.'99-cached.pdf';
    $signedBytes = "%PDF-1.4\n% SIGNED response letter cache\n%%EOF\n";
    Storage::disk('public')->put($cachedPath, $signedBytes);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-VIEW-RL-SIGNED',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'epirma_response_letter_signed_at' => now(),
        'lgu_response_letter_sent_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'document_name' => 'Response-Letter-REQ-VIEW-RL-SIGNED.pdf',
        'document_path' => $cachedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-rl.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/signed-rl.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    $response = $this->actingAs($user)
        ->get(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]));

    $response->assertOk()
        ->assertHeader('X-Epirma-Preview-Kind', 'signed');

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($signedBytes);
});

it('allows RROS and RROS AA to preview signed assessment and response-letter files', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-RROS-SIGNED-PREVIEWS',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-06',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);

    $documents = collect([
        EpirmaSignedDocument::TYPE_ASSESSMENT,
        EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
    ])->map(function (string $type) use ($record): EpirmaSignedDocument {
        $path = EpirmaDocumentStatusService::SIGNED_CACHE_DIR.$type.'-rros-preview.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4\n% signed {$type}\n%%EOF\n");

        return EpirmaSignedDocument::create([
            'assistance_request_id' => $record->id,
            'document_type' => $type,
            'action' => EpirmaSignedDocument::ACTION_ROUTE,
            'document_uuid' => (string) str()->uuid(),
            'document_name' => $type.'.pdf',
            'document_path' => $path,
            'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
            'completed_at' => now(),
            'timestamp' => now(),
            'handoff' => 'document_routing',
        ]);
    });

    foreach (['rros@example.test', 'rros-aa@example.test'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        foreach ($documents as $document) {
            $this->actingAs($user)->get(route('requests.epirma.documents.view', [
                'assistanceRequest' => $record,
                'document' => $document,
            ]))->assertOk()->assertHeader('X-Epirma-Preview-Kind', 'signed');
        }
    }
});

it('caches the signed assessment PDF when status sync promotes a completed route', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $unsignedPath = 'epirma_signed_documents/Assessment-REQ-CACHE-ON-PROMOTE.pdf';
    Storage::disk('public')->put($unsignedPath, "%PDF-1.4\n% unsigned\n%%EOF\n");
    $signedBytes = "%PDF-1.4\n% signed on promote\n%%EOF\n";

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-CACHE-ON-PROMOTE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $user->id,
        'endorsed_to_drrs' => true,
        'epirma_status' => 'pending',
        'epirma_transaction_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'document_name' => 'Assessment-REQ-CACHE-ON-PROMOTE.pdf',
        'document_path' => $unsignedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
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
            'base_path' => 'https://epirma.example.test/files/signed-promote.pdf',
            'signers' => [
                ['fullname' => 'DRRS AA', 'username' => 'aa', 'status' => 'signed'],
                ['fullname' => 'DRMD Chief', 'username' => 'chief', 'status' => 'completed'],
            ],
            'signed_document' => [
                'document_url' => 'https://epirma.example.test/files/signed-promote.pdf',
                'signed_filename' => 'signed-promote.pdf',
                'signed_at' => '2026-08-02T12:00:00+08:00',
            ],
        ]),
        'https://epirma.example.test/files/signed-promote.pdf*' => Http::response($signedBytes, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $this->actingAs($user)
        ->getJson(route('requests.epirma.status', $record))
        ->assertOk()
        ->assertJsonPath('data.routing_status', 'signed');

    $fresh = $document->fresh();
    expect($fresh->routing_status)->toBe('signed')
        ->and($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes);
});

it('caches the signed assessment PDF when sync runs without request finalize (callback path)', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $unsignedPath = 'epirma_signed_documents/Assessment-REQ-CACHE-NO-FINALIZE.pdf';
    Storage::disk('public')->put($unsignedPath, "%PDF-1.4\n% unsigned\n%%EOF\n");
    $signedBytes = "%PDF-1.4\n% signed without finalize\n%%EOF\n";

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-CACHE-NO-FINALIZE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'epirma_status' => 'pending',
        'epirma_transaction_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'document_name' => 'Assessment-REQ-CACHE-NO-FINALIZE.pdf',
        'document_path' => $unsignedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
        'handoff' => 'document_routing',
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
            'base_path' => 'https://epirma.example.test/files/signed-no-finalize.pdf',
            'signers' => [
                ['fullname' => 'DRRS AA', 'username' => 'aa', 'status' => 'signed'],
                ['fullname' => 'DRMD Chief', 'username' => 'chief', 'status' => 'completed'],
            ],
            'signed_document' => [
                'document_url' => 'https://epirma.example.test/files/signed-no-finalize.pdf',
                'signed_filename' => 'signed-no-finalize.pdf',
                'signed_at' => '2026-08-02T12:00:00+08:00',
            ],
        ]),
        'https://epirma.example.test/files/signed-no-finalize.pdf*' => Http::response($signedBytes, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $result = app(EpirmaDocumentStatusService::class)->syncDocument($document, false);

    expect($result['success'] ?? false)->toBeTrue()
        ->and($result['data']['routing_status'] ?? null)->toBe('signed');

    $fresh = $document->fresh();
    expect($fresh->routing_status)->toBe('signed')
        ->and($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes)
        // Callback path still finalizes the request separately — do not force it here.
        ->and($record->fresh()->assessment_status)->toBe('draft');
});

it('ignores EPIRMA_DOCUMENT_TOKEN when it equals this app APP_KEY', function (): void {
    $svc = app(EpirmaDocumentStatusService::class);
    $appKey = (string) config('app.key');
    config()->set('services.epirma.document_token', $appKey);
    config()->set('services.epirma.host_fallbacks', []);

    $url = 'https://epirma.example.test/files/signed.pdf';
    expect($svc->usableDocumentToken())->toBe('')
        ->and($svc->buildViewUrl($url))->toBe($url)
        ->and($svc->signedDownloadUrlCandidates($url))->toBe([$url]);
});

it('allows other base64 EPIRMA_DOCUMENT_TOKEN values when building view URLs', function (): void {
    $svc = app(EpirmaDocumentStatusService::class);
    $token = 'base64:4qbMoJ5oUWxGn5n4yiOn8CwGYaz/KMAlxNiZYnb1J0A=';
    config()->set('services.epirma.document_token', $token);
    expect($token)->not->toBe((string) config('app.key'));

    $url = 'https://epirma.example.test/files/signed.pdf';
    expect($svc->usableDocumentToken())->toBe($token)
        ->and($svc->buildViewUrl($url))->toBe($url.'?token='.rawurlencode($token));
});

it('retries signed PDF download on configured host fallbacks', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $signedBytes = "%PDF-1.4\n% signed via host fallback\n%%EOF\n";

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-VIEW-HOST-FALLBACK',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_assessment_signed_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'document_name' => 'Assessment-REQ-VIEW-HOST-FALLBACK.pdf',
        'document_path' => 'epirma_signed_documents/Assessment-REQ-VIEW-HOST-FALLBACK.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma-dead.example.test/files/signed-assessment.pdf',
        'remote_base_path' => 'https://epirma-dead.example.test/files/signed-assessment.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', '');
    config()->set('services.epirma.host_fallbacks', ['epirma-live.example.test']);
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma-dead.example.test/*' => Http::response('nope', 404),
        'https://epirma-live.example.test/files/signed-assessment.pdf' => Http::response($signedBytes, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $response = $this->actingAs($user)
        ->get(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]));

    $response->assertOk()
        ->assertHeader('X-Epirma-Preview-Kind', 'signed');

    $fresh = $document->fresh();
    expect($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes);
});

it('returns a clear JSON error when signed assessment bytes cannot be fetched', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-VIEW-UNAVAILABLE',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_assessment_signed_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        'document_name' => 'Assessment-REQ-VIEW-UNAVAILABLE.pdf',
        'document_path' => 'epirma_signed_documents/Assessment-REQ-VIEW-UNAVAILABLE.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/missing-signed.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/missing-signed.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', 'base64:fake-app-key');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/*' => Http::response('Invalid or missing token.', 403),
    ]);

    $this->actingAs($user)
        ->getJson(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]))
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('document_id', $document->id)
        ->assertJsonPath('cached_signed', false);
});

it('returns a clear error for browser preview when signed assessment cache cannot be fetched', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-VIEW-BROWSER-FALLBACK',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_assessment_signed_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        'document_name' => 'Assessment-REQ-VIEW-BROWSER-FALLBACK.pdf',
        'document_path' => 'epirma_signed_documents/Assessment-REQ-VIEW-BROWSER-FALLBACK.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/missing-signed.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/missing-signed.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', '');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/*' => Http::response('gone', 404),
    ]);

    $this->actingAs($user)
        ->get(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]))
        ->assertStatus(502)
        ->assertHeader('X-Epirma-Preview-Kind', 'signed-unavailable')
        ->assertSee('Signed file unavailable from e-PIRMA', false);
});

it('does not redirect signed response letters to DomPDF when the signed file is unavailable', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $unsignedPath = 'epirma_signed_documents/Response-Letter-REQ-NO-DOMPDF.pdf';
    Storage::disk('public')->put($unsignedPath, "%PDF-1.4\n% unsigned handoff\n%%EOF\n");

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-NO-DOMPDF-RL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-03',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_response_letter_signed_at' => now(),
        'response_drn' => 'DRN-NO-DOMPDF',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'document_name' => 'Response-Letter-REQ-NO-DOMPDF.pdf',
        'document_path' => $unsignedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/missing-signed-rl.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/missing-signed-rl.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', '');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);
    config()->set('services.epirma.connect_bearer', '');
    config()->set('services.epirma.connect_base_url', 'https://connect.example.test');

    Http::fake([
        'https://epirma.example.test/*' => Http::response(['message' => 'Invalid or missing token.'], 403),
        'https://connect.example.test/*' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->actingAs($user)
        ->get(route('requests.epirma.documents.view', [
            'assistanceRequest' => $record,
            'document' => $document,
        ]))
        ->assertStatus(502)
        ->assertHeader('X-Epirma-Preview-Kind', 'signed-unavailable')
        ->assertSee('Signed file unavailable from e-PIRMA', false)
        ->assertDontSee('response-letter-pdf', false);
});

it('includes build-authorize JWT in signed download URL candidates', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $user->forceFill(['id_number' => '16-11720'])->save();

    $document = new EpirmaSignedDocument([
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'initiated_by' => $user->id,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
    ]);
    $document->setRelation('initiator', $user);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.document_token', 'static-doc-token');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'jwt-from-build-authorize',
        ], 200),
    ]);

    $svc = app(EpirmaDocumentStatusService::class);
    $raw = 'https://epirma.example.test/public-secure-file/signed_forwarded_documents/doc_signed.pdf';
    $candidates = $svc->signedDownloadUrlCandidates($raw, $document);

    expect($candidates)->toContain($raw)
        ->and($candidates)->toContain($raw.'?token=static-doc-token')
        ->and($candidates)->toContain($raw.'?token=jwt-from-build-authorize');
});

it('enriches remote URL and caches PDF from Connect forwarded-documents when UUID poll has no path', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $user->forceFill(['username' => 'rlongue'])->save();

    $unsignedPath = 'epirma_signed_documents/Assessment-LGU-REQ-20260803-0QR4S.pdf';
    Storage::disk('public')->put($unsignedPath, "%PDF-1.4\n% unsigned handoff\n%%EOF\n");
    $signedBytes = "%PDF-1.4\n% signed via forwarded-documents\n%%EOF\n";
    $signedUrl = 'https://caraga-epirma-dev.example.test/public-secure-file/signed_forwarded_documents/assessment_signed.pdf';

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-20260803-0QR4S',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-03',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'epirma_status' => 'pending',
        'epirma_transaction_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'document_name' => 'Assessment-LGU-REQ-20260803-0QR4S.pdf',
        'document_path' => $unsignedPath,
        'routing_status' => EpirmaSignedDocument::STATUS_ROUTED,
        'handoff' => 'document_routing',
        'initiated_by' => $user->id,
        'routed_at' => now()->subMinutes(10),
        'timestamp' => now(),
    ]);

    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'test-secret');
    config()->set('services.epirma.connect_base_url', 'https://connect.example.test');
    config()->set('services.epirma.connect_bearer', 'connect-test-bearer');
    config()->set('services.epirma.connect_csrf_token', 'connect-csrf');
    config()->set('services.epirma.forwarded_documents_username', '');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);
    config()->set('services.epirma.document_token', '');

    Http::fake([
        'https://epirma.example.test/api/latest-document-base-path/*' => Http::response([
            'signers' => [
                ['fullname' => 'Approver', 'username' => 'aa', 'status' => 'signed'],
            ],
            // Completed locally via signers but no document_url / base_path.
        ], 200),
        'https://epirma.example.test/api/signed-document/*' => Http::response(['error' => 'Signed document not found.'], 404),
        'https://connect.example.test/api/v1/staff/epirma/forwarded-documents*' => Http::response([
            'status' => 'success',
            'data' => [
                'documents' => [
                    [
                        'id' => 95,
                        'original_filename' => 'Response-Letter-LGU-REQ-20260803-0QR4S.pdf',
                        'path' => 'https://caraga-epirma-dev.example.test/public-secure-file/signed_forwarded_documents/rl_signed.pdf',
                        'document_subject' => 'response-letter',
                        'status' => 'completed',
                        'signatories' => [
                            ['is_signed' => true, 'signed_path' => 'https://caraga-epirma-dev.example.test/public-secure-file/signed_forwarded_documents/rl_signed.pdf'],
                        ],
                    ],
                    [
                        'id' => 96,
                        'original_filename' => 'Assessment-LGU-REQ-20260803-0QR4S.pdf',
                        'path' => $signedUrl,
                        'document_subject' => 'assessment',
                        'status' => 'completed',
                        'signatories' => [
                            ['is_signed' => true, 'signed_path' => $signedUrl, 'username' => 'chief'],
                        ],
                    ],
                ],
            ],
        ], 200),
        $signedUrl => Http::response($signedBytes, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $result = app(EpirmaDocumentStatusService::class)->syncDocument($document, true);

    expect($result['success'] ?? false)->toBeTrue()
        ->and($result['data']['routing_status'] ?? null)->toBe('signed');

    $fresh = $document->fresh();
    expect($fresh->routing_status)->toBe(EpirmaSignedDocument::STATUS_SIGNED)
        ->and($fresh->remote_document_url)->toBe($signedUrl)
        ->and($fresh->remote_base_path)->toBe($signedUrl)
        ->and($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'forwarded-documents')
            && $request->hasHeader('Authorization', 'Bearer connect-test-bearer')
            && $request->hasHeader('X-CSRF-TOKEN', 'connect-csrf')
            && ($request['username'] ?? null) === 'rlongue';
    });
});

it('does not match an older completed transaction when a filename is reused', function (): void {
    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-REUSED-RIS',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
    ]);
    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RIS,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => (string) Illuminate\Support\Str::uuid(),
        'document_name' => 'RIS-REUSED.pdf',
        'document_path' => 'epirma_signed_documents/ris/reused.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_ROUTED,
        'routed_at' => now(),
    ]);

    $matched = app(EpirmaDocumentStatusService::class)->matchForwardedDocument($document, [
        [
            'id' => 10,
            'original_filename' => 'RIS-REUSED.pdf',
            'document_subject' => 'ris',
            'status' => 'completed',
            'created_at' => now()->subHours(4)->toIso8601String(),
            'path' => 'https://epirma.example.test/signed_forwarded_documents/old_signed.pdf',
            'signatories' => [['is_signed' => true]],
        ],
        [
            'id' => 11,
            'original_filename' => 'RIS-REUSED.pdf',
            'document_subject' => 'ris',
            'status' => 'pending',
            'created_at' => now()->addMinute()->toIso8601String(),
            'signatories' => [['username' => 'approver', 'is_signed' => false]],
        ],
    ]);

    expect($matched['id'] ?? null)->toBe(11)
        ->and($matched['status'] ?? null)->toBe('pending');
});

it('does not mark a document signed while an assigned signatory is pending', function (): void {
    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-PENDING-SIGNER',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Requester',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
    ]);
    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RIS,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => (string) Illuminate\Support\Str::uuid(),
        'document_name' => 'RIS-PENDING.pdf',
        'document_path' => 'epirma_signed_documents/ris/pending.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_ROUTED,
        'routed_at' => now(),
    ]);

    $normalized = app(EpirmaDocumentStatusService::class)->normalizePayload($document, [
        'status' => 'completed',
        'signers' => [[
            'fullname' => 'Assigned Approver',
            'username' => 'approver',
            'status' => 'pending',
        ]],
    ]);

    expect($normalized['routing_status'])->toBe(EpirmaSignedDocument::STATUS_ROUTED);
});

it('maps response-letter subject and enriches when download of stale remote URL fails', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $signedBytes = "%PDF-1.4\n% response letter via connect\n%%EOF\n";
    $staleUrl = 'https://epirma.example.test/files/stale-missing.pdf';
    $signedUrl = 'https://caraga-epirma-dev.example.test/public-secure-file/signed_forwarded_documents/rl_signed.pdf';

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-FWD-RL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-03',
        'status' => 'acted',
        'assessment_status' => 'final',
        'epirma_status' => 'signed',
        'epirma_assessment_signed_at' => now(),
        'response_drn' => 'DRN-FWD-1',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'document_name' => 'Response-Letter-LGU-REQ-FWD-RL.pdf',
        'document_path' => 'epirma_signed_documents/Response-Letter-LGU-REQ-FWD-RL.pdf',
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => $staleUrl,
        'remote_base_path' => $staleUrl,
        'completed_at' => now(),
        'initiated_by' => $user->id,
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.connect_base_url', 'https://connect.example.test');
    config()->set('services.epirma.connect_bearer', 'connect-test-bearer');
    config()->set('services.epirma.forwarded_documents_username', 'rlongue');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);
    config()->set('services.epirma.document_token', '');

    Http::fake([
        $staleUrl => Http::response('gone', 404),
        'https://connect.example.test/api/v1/staff/epirma/forwarded-documents*' => Http::response([
            'status' => 'success',
            'data' => [
                'documents' => [
                    [
                        'id' => 101,
                        'original_filename' => 'Response-Letter-LGU-REQ-FWD-RL.pdf',
                        'document_subject' => 'response-letter',
                        'status' => 'completed',
                        'path' => $signedUrl,
                        'signatories' => [
                            ['is_signed' => true, 'signed_path' => $signedUrl],
                        ],
                    ],
                ],
            ],
        ], 200),
        $signedUrl => Http::response($signedBytes, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $svc = app(EpirmaDocumentStatusService::class);
    expect($svc->documentSubjectMatchesType('response-letter', EpirmaSignedDocument::TYPE_RESPONSE_LETTER))->toBeTrue();

    $cached = $svc->ensureCachedSignedPdf($document);

    expect($cached)->not->toBeNull()
        ->and($cached)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and($document->fresh()->remote_document_url)->toBe($signedUrl)
        ->and(Storage::disk('public')->get($cached))->toBe($signedBytes);
});

it('posts build-authorize with secret not client_secret (ePIRMA tip §9.1)', function (): void {
    config()->set('services.epirma.base_url', 'https://epirma.example.test');
    config()->set('services.epirma.client_secret', 'tip9-secret');
    config()->set('services.epirma.verify_ssl', false);
    config()->set('services.epirma.allow_insecure_ssl', true);

    Http::fake([
        'https://epirma.example.test/api/microservice/build-authorize' => Http::response([
            'success' => true,
            'token' => 'jwt-token',
        ]),
    ]);

    $result = app(EpirmaService::class)->buildAuthorize('16-11720');

    expect($result['success'] ?? false)->toBeTrue();

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://epirma.example.test/api/microservice/build-authorize') {
            return false;
        }

        $data = $request->data();

        return ($data['secret'] ?? null) === 'tip9-secret'
            && ($data['id_number'] ?? null) === '16-11720'
            && ! array_key_exists('client_secret', $data);
    });
});

it('serves inbound signed PDFs to ePIRMA ignoring filename and keeps CORS on 403/404 (tip §9.4)', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $pdfBytes = "%PDF-1.4\n% handoff for ePIRMA fetch\n%%EOF\n";
    $path = 'epirma_signed_documents/Assessment-TIP94.pdf';
    Storage::disk('public')->put($path, $pdfBytes);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-TIP94-SIGNED-URL',
        'requesting_agency' => 'Test Proposing Party',
        'requester' => 'Test Requester',
        'date_requested' => '2026-08-02',
        'status' => 'under_review',
        'assessment_status' => 'draft',
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'document_name' => 'Assessment-TIP94.pdf',
        'document_path' => $path,
        'routing_status' => EpirmaSignedDocument::STATUS_PENDING,
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    // Mirror handoff URL builder: relative signed route + absolute url() + appended filename=.
    $documentUrl = URL::temporarySignedRoute(
        'epirma.signed-document',
        now()->addHours(6),
        ['document' => $document->id],
        absolute: false
    );
    $documentUrl = url($documentUrl);
    $separator = str_contains($documentUrl, '?') ? '&' : '?';
    $documentUrl .= $separator.'filename='.rawurlencode((string) $document->document_name);

    $ok = $this->get($documentUrl);
    $ok->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    expect($ok->headers->get('Content-Disposition'))->toContain('Assessment-TIP94.pdf');

    $tampered = preg_replace('/signature=[^&]+/', 'signature=deadbeef', $documentUrl) ?? $documentUrl;
    $forbidden = $this->get($tampered);
    $forbidden->assertForbidden()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertSee('Invalid or expired document link.');

    Storage::disk('public')->delete($path);
    $missing = $this->get($documentUrl);
    $missing->assertNotFound()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertSee('Document file not found.');
});
