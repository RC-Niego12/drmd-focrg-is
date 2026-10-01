<?php

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function makeLguResponseLetterUser(string $email): User
{
    $lguRole = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $lgu = User::create([
        'name' => 'LGU Viewer',
        'email' => $email,
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1606727000',
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
        'position' => 'DROMIC Focal',
    ]);
    $lgu->syncRoles([$lguRole]);

    return $lgu;
}

it('serves only page 2 of the signed e-PIRMA response letter to the LGU', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $lgu = makeLguResponseLetterUser('lgu-rl-viewer@example.test');

    $advancePath = 'lgu_response_letters/advance-LGU-REQ-SIGNED-VIEW.pdf';
    $unsignedRoutePath = 'epirma_signed_documents/Response-Letter-LGU-REQ-SIGNED-VIEW-unsigned.pdf';
    $advanceBytes = "%PDF-1.4\n% advance unsigned response letter\n%%EOF\n";
    $unsignedBytes = "%PDF-1.4\n% unsigned route handoff payload\n%%EOF\n";

    $signedSource = storage_path('app/generated-documents/'.uniqid('signed-source-', true).'.pdf');
    if (! is_dir(dirname($signedSource))) {
        mkdir(dirname($signedSource), 0775, true);
    }
    $process = new Process(['node', base_path('scripts/create-blank-pdf.mjs'), $signedSource, '2']);
    $process->setTimeout(30);
    $process->run();
    expect($process->isSuccessful())->toBeTrue();
    $signedBytes = file_get_contents($signedSource);

    Storage::disk('public')->put($advancePath, $advanceBytes);
    Storage::disk('public')->put($unsignedRoutePath, $unsignedBytes);

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-SIGNED-VIEW',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'LGU Officer',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'lgu_psgc_code' => '1606727000',
        'lgu_response_letter_advance_path' => $advancePath,
        'lgu_response_letter_advance_name' => 'Response-Letter-LGU-REQ-SIGNED-VIEW.pdf',
        'lgu_response_letter_advance_sent_at' => now()->subHour(),
        'epirma_response_letter_signed_at' => now(),
        'lgu_response_letter_sent_at' => now(),
    ]);

    $document = EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'document_name' => 'Response-Letter-LGU-REQ-SIGNED-VIEW.pdf',
        'document_path' => $unsignedRoutePath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'remote_document_url' => 'https://epirma.example.test/files/signed-response-letter.pdf',
        'remote_base_path' => 'https://epirma.example.test/files/signed-response-letter.pdf',
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    config()->set('services.epirma.document_token', 'view-token');

    Http::fake([
        'https://epirma.example.test/files/signed-response-letter.pdf*' => Http::response($signedBytes, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $signedUrl = route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'signed']);
    $advanceUrl = route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'advance']);

    expect($signedUrl)->not->toBe($advanceUrl);

    $response = $this->actingAs($lgu)->get($signedUrl);
    $response->assertOk()
        ->assertHeader('X-Epirma-Preview-Kind', 'signed')
        ->assertHeader('X-Lgu-Response-Letter-Page', '2');

    $servedPath = $response->baseResponse->getFile()->getPathname();
    $servedBytes = file_get_contents($servedPath);
    $pageCountProbe = storage_path('app/generated-documents/'.uniqid('lgu-page-count-', true).'.pdf');
    file_put_contents($pageCountProbe, $servedBytes);
    $countProcess = new Process([
        'node',
        '-e',
        "const {PDFDocument}=require('pdf-lib');const fs=require('fs');PDFDocument.load(fs.readFileSync(process.argv[1])).then(d=>process.stdout.write(String(d.getPageCount())));",
        $pageCountProbe,
    ]);
    $countProcess->setTimeout(30);
    $countProcess->run();
    @unlink($pageCountProbe);
    expect($countProcess->isSuccessful())->toBeTrue()
        ->and(trim($countProcess->getOutput()))->toBe('1')
        ->and(substr($servedBytes, 0, 4))->toBe('%PDF')
        ->and($servedBytes)->not->toBe($advanceBytes)
        ->and($servedBytes)->not->toBe($unsignedBytes)
        ->and($servedBytes)->not->toBe($signedBytes);

    $fresh = $document->fresh();
    expect($fresh->document_path)->toStartWith(EpirmaDocumentStatusService::SIGNED_CACHE_DIR)
        ->and($fresh->document_path)->not->toBe($unsignedRoutePath)
        ->and($fresh->document_path)->not->toBe($advancePath)
        ->and(Storage::disk('public')->get($fresh->document_path))->toBe($signedBytes)
        ->and(Storage::disk('public')->exists($advancePath))->toBeTrue();

    @unlink($signedSource);

    $this->actingAs($lgu)
        ->get(route('lgu.response-letters.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lgu/ResponseLetters/Index')
            ->where('letters.0.id', $record->id)
            ->where('letters.0.has_signed', true)
            ->where('letters.0.view_url', $signedUrl)
            ->where('letters.0.advance_view_url', $advanceUrl));
});

it('does not treat an unsigned route handoff PDF as a signed LGU artifact', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);

    $lgu = makeLguResponseLetterUser('lgu-rl-viewer-2@example.test');

    $unsignedRoutePath = 'epirma_signed_documents/Response-Letter-no-remote-unsigned.pdf';
    Storage::disk('public')->put($unsignedRoutePath, "%PDF-1.4\n% unsigned only\n%%EOF\n");

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-NO-REMOTE',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'LGU Officer',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'lgu_psgc_code' => '1606727000',
        'epirma_response_letter_signed_at' => now(),
        'lgu_response_letter_sent_at' => now(),
    ]);

    EpirmaSignedDocument::create([
        'assistance_request_id' => $record->id,
        'document_type' => EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
        'action' => EpirmaSignedDocument::ACTION_ROUTE,
        'document_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'document_name' => 'Response-Letter-no-remote.pdf',
        'document_path' => $unsignedRoutePath,
        'routing_status' => EpirmaSignedDocument::STATUS_SIGNED,
        'completed_at' => now(),
        'timestamp' => now(),
        'handoff' => 'document_routing',
    ]);

    Http::fake();

    $this->actingAs($lgu)
        ->get(route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'signed']))
        ->assertNotFound();

    $this->actingAs($lgu)
        ->get(route('lgu.response-letters.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lgu/ResponseLetters/Index')
            ->where('letters.0.has_signed', false)
            ->where('letters.0.view_url', null));
});
