<?php

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function risEpirmaSlip(User $preparer, string $suffix): RequisitionIssuanceSlip
{
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-EPIRMA-'.$suffix, 'requesting_agency' => 'Test LGU',
        'requester' => 'Requester', 'date_requested' => now()->toDateString(), 'status' => 'acted',
        'assessment_status' => 'submitted', 'endorsed_to_drrs' => true, 'submission_type' => 'fni_request',
        'epirma_assessment_signed_at' => now(), 'epirma_response_letter_signed_at' => now(),
    ]);

    return RequisitionIssuanceSlip::create([
        'request_id' => $request->id, 'prepared_by' => $preparer->id,
        'ris_number' => 'RIS-EPIRMA-'.$suffix, 'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation', 'recipient' => 'Test LGU',
        'delivery_site' => 'Test Site', 'items' => [], 'tracking_data' => [], 'status' => 'prepared',
    ]);
}

it('lets only the RROS preparer forward a RIS to the RROS AA queue', function (): void {
    config()->set('realtime.enabled', true);
    config()->set('realtime.public_url', 'http://127.0.0.1:6001');
    config()->set('realtime.internal_url', 'http://127.0.0.1:6002');
    config()->set('realtime.secret', 'test-secret');
    Http::fake(['http://127.0.0.1:6002/publish' => Http::response(['ok' => true])]);
    $this->seed(DatabaseSeeder::class);
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $rrosAa = User::where('email', 'rros-aa@example.test')->firstOrFail();
    $slip = risEpirmaSlip($rros, 'FORWARD');

    $this->actingAs($rros)->postJson(route('rros.ris.epirma.forward', $slip))
        ->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('workflow.status', 'forwarded');

    $slip->refresh();
    expect($slip->approval_routing_mode)->toBe('epirma')
        ->and($slip->ris_epirma_status)->toBe('forwarded')
        ->and($slip->ris_epirma_forwarded_by)->toBe($rros->id)
        ->and($slip->ris_epirma_forwarded_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'ris.epirma.status.changed'
        && $request['payload']['ris_id'] === $slip->id
        && $request['payload']['workflow']['status'] === 'forwarded');

    $this->actingAs($rrosAa)->get(route('rros-aa.epirma.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->component('RrosAa/Epirma/Index')
        ->where('summary.pending', 1)
        ->where('queue.data.0.ris_number', 'RIS-EPIRMA-FORWARD'));
});

it('prevents RROS AA preparers from forwarding and prevents ordinary RROS users from routing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $rrosAa = User::where('email', 'rros-aa@example.test')->firstOrFail();
    $aaSlip = risEpirmaSlip($rrosAa, 'DIRECT');
    $rrosSlip = risEpirmaSlip($rros, 'RESTRICTED');

    $this->actingAs($rrosAa)->postJson(route('rros.ris.epirma.forward', $aaSlip))
        ->assertStatus(422)
        ->assertSeeText('route it directly through e-PIRMA');

    $this->actingAs($rros)->postJson(route('rros.ris.epirma.route', $rrosSlip))->assertForbidden();
    $this->actingAs($rros)->get(route('rros-aa.epirma.index'))->assertForbidden();
});

it('uses the signed e-PIRMA artifact for RIS previews after signing completes', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $rrosAa = User::where('email', 'rros-aa@example.test')->firstOrFail();
    $slip = risEpirmaSlip($rros, 'SIGNED-PREVIEW');
    $uuid = (string) Str::uuid();
    $path = EpirmaDocumentStatusService::SIGNED_CACHE_DIR.'ris-signed.pdf';
    Storage::disk('public')->put($path, '%PDF-1.4 signed RIS');

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $slip->request_id,
        'document_type' => EpirmaSignedDocument::TYPE_RIS,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => $uuid,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'document_name' => 'Signed-RIS.pdf',
        'document_path' => $path,
        'completed_at' => now(),
    ]);
    $slip->update([
        'approval_routing_mode' => 'epirma',
        'ris_epirma_status' => 'signed',
        'ris_epirma_transaction_id' => $uuid,
        'ris_epirma_signed_at' => now(),
    ]);

    $this->actingAs($rrosAa)->get(route('rros-aa.epirma.index', ['status' => 'completed']))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('queue.data.0.preview_kind', 'signed')
        ->where('queue.data.0.preview_url', route('rros.ris.epirma.signed-preview', $slip)));

    $this->actingAs($rros)->get(route('rros.ris.epirma.signed-preview', $slip))
        ->assertRedirect(route('requests.epirma.documents.view', [$slip->request_id, $document->id]));

    $this->actingAs($rros)->get(route('requests.epirma.documents.view', [$slip->request_id, $document->id]))
        ->assertOk()
        ->assertHeader('X-Epirma-Preview-Kind', 'signed');
});

it('reconciles a terminal e-PIRMA document when the RIS parent is still routed', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $slip = risEpirmaSlip($rros, 'TERMINAL-RECONCILE');
    $uuid = (string) Str::uuid();
    $path = EpirmaDocumentStatusService::SIGNED_CACHE_DIR.'terminal-ris.pdf';
    Storage::disk('public')->put($path, '%PDF-1.4 signed RIS');

    EpirmaSignedDocument::create([
        'assistance_request_id' => $slip->request_id,
        'document_type' => EpirmaSignedDocument::TYPE_RIS,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => $uuid,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'document_name' => 'Signed-RIS.pdf',
        'document_path' => $path,
        'signers' => [['fullname' => 'Test Signatory', 'status' => 'signed']],
        'completed_at' => now(),
    ]);
    $slip->update([
        'approval_routing_mode' => 'epirma',
        'ris_epirma_status' => 'routed',
        'ris_epirma_transaction_id' => $uuid,
        'ris_epirma_routed_at' => now(),
    ]);

    $this->actingAs($rros)->getJson(route('rros.ris.epirma.status', $slip))
        ->assertOk()
        ->assertJsonPath('workflow.status', 'signed')
        ->assertJsonPath('workflow.complete', true);

    expect($slip->fresh()->ris_epirma_status)->toBe('signed')
        ->and($slip->fresh()->status)->toBe('approved')
        ->and($slip->fresh()->ris_epirma_signed_at)->not->toBeNull();
});

it('keeps DRRS tracking documents separate from RIS tracking for a multi-role test user', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros-aa@example.test')->firstOrFail();
    $user->assignRole('DRRS AA');
    $slip = risEpirmaSlip($user, 'SEPARATE-TRACKING');

    $assessment = EpirmaSignedDocument::create([
        'assistance_request_id' => $slip->request_id,
        'document_type' => EpirmaSignedDocument::TYPE_ASSESSMENT,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => (string) Illuminate\Support\Str::uuid(),
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'document_name' => 'Assessment.pdf',
        'document_path' => 'epirma_signed_cache/assessment.pdf',
        'completed_at' => now(),
    ]);
    EpirmaSignedDocument::create([
        'assistance_request_id' => $slip->request_id,
        'document_type' => EpirmaSignedDocument::TYPE_RIS,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => (string) Illuminate\Support\Str::uuid(),
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'document_name' => 'RIS.pdf',
        'document_path' => 'epirma_signed_cache/ris.pdf',
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson(route('requests.epirma.documents', $slip->request_id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assessment->id)
        ->assertJsonPath('data.0.document_type', EpirmaSignedDocument::TYPE_ASSESSMENT);
});
