<?php

use App\Models\AssistanceRequest;
use App\Models\User;
use App\Services\ResponseLetterDocumentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEditableResponseLetterRequest(User $actor): AssistanceRequest
{
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RL-BODY-'.uniqid(),
        'requesting_agency' => 'Municipality of Tubod',
        'province' => 'SURIGAO DEL NORTE',
        'municipality' => 'TUBOD',
        'requester' => 'Mayor Romarate',
        'requester_position' => 'Municipal Mayor',
        'date_requested' => now()->toDateString(),
        'affected_families' => 3,
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $actor->id,
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'submitted_at' => now(),
        'response_drn' => 'CARAGA-FO-DRMD-DRRMS-SS-REP-26-09-00099-S',
        'assessment_form_data' => [
            'provide_augmentation' => true,
            'response_purpose' => 'Relief Augmentation',
            'prepared_by' => $actor->name,
        ],
    ]);

    $request->items()->create([
        'item_name' => 'Family Food Pack',
        'requested_quantity' => 3,
        'approved_quantity' => 3,
        'unit' => 'box',
        'priority' => 'normal',
    ]);

    return $request->fresh(['items']);
}

it('uses a saved custom opening paragraph when generating the response letter', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $request = makeEditableResponseLetterRequest($user);

    $customOpening = 'This is in reference to your letter requesting food items intended for custom-edited families in Tubod.';

    $this->actingAs($user)
        ->patch("/requests/{$request->id}/response-letter-body", [
            'opening' => $customOpening,
        ])
        ->assertRedirect();

    $request->refresh();
    expect(data_get($request->assessment_form_data, 'response_letter_body.opening'))->toBe($customOpening);

    $generated = app(ResponseLetterDocumentService::class)->generate($request->fresh(['items']));
    $zip = new ZipArchive;
    expect($zip->open($generated['path']))->toBeTrue();
    $documentXml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($generated['path']);

    expect($documentXml)->toContain($customOpening)
        ->and($documentXml)->toContain('custom-edited families');
});

it('resets a custom opening paragraph back to the auto-generated text', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $request = makeEditableResponseLetterRequest($user);

    $defaults = app(ResponseLetterDocumentService::class)->bodyParagraphs($request);
    $customOpening = 'Custom opening that should be cleared on reset.';

    $this->actingAs($user)
        ->patch("/requests/{$request->id}/response-letter-body", ['opening' => $customOpening])
        ->assertRedirect();

    $this->actingAs($user)
        ->patch("/requests/{$request->id}/response-letter-body", ['reset_fields' => ['opening']])
        ->assertRedirect();

    $request->refresh();
    expect(data_get($request->assessment_form_data, 'response_letter_body.opening'))->toBeNull();

    $effective = app(ResponseLetterDocumentService::class)->effectiveBodyParagraphs($request);
    expect($effective['paragraphs']['opening'])->toBe($defaults['opening'])
        ->and($effective['custom']['opening'])->toBeFalse();
});

it('forbids editing the response letter body after forward to DRRS AA', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $request = makeEditableResponseLetterRequest($user);
    $request->update(['epirma_forwarded_to_drrs_aa_at' => now()]);

    $this->actingAs($user)
        ->patch("/requests/{$request->id}/response-letter-body", [
            'opening' => 'Should not be saved after forward.',
        ])
        ->assertStatus(422);

    $request->refresh();
    expect(data_get($request->assessment_form_data, 'response_letter_body'))->toBeNull();
});

it('exposes editable response letter body props on the assessment documents page', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $request = makeEditableResponseLetterRequest($user);

    $this->actingAs($user)
        ->get("/requests/{$request->id}/assessment-form")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/AssessmentForm')
            ->has('responseLetterBody.paragraphs.opening')
            ->has('responseLetterBody.paragraphs.assessment')
            ->has('responseLetterBody.paragraphs.closing')
            ->where('canEditResponseLetterBody', true)
        );
});
