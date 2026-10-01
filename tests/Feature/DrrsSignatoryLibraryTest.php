<?php

use App\Models\OperationalLibraryValue;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores only the signatories required by each DRRS document type', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    OperationalLibraryValue::create([
        'library_type' => 'drrs_signatory',
        'context' => 'prepared_by',
        'value' => 'Old Preparer | Encoder',
        'metadata' => ['document_type' => 'assessment', 'employee_name' => 'Old Preparer'],
        'is_active' => true,
    ]);

    $details = fn (string $name, string $initials): array => [
        'name' => $name,
        'position' => 'Social Welfare Officer IV',
        'suffix' => 'RSW',
        'designation' => 'Designated Signatory',
        'initials' => $initials,
    ];

    $this->actingAs($user)->post('/operational-library/drrs-signatories', [
        'document_type' => 'assessment',
        'signatories' => [
            'reviewed_by' => $details('Assessment Reviewer', 'AR'),
            'approved_by' => $details('Assessment Approver', 'AA'),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(OperationalLibraryValue::where('library_type', 'drrs_signatory')->where('metadata->document_type', 'assessment')->pluck('context')->sort()->values()->all())
        ->toBe(['approved_by', 'reviewed_by']);

    $this->actingAs($user)->post('/operational-library/drrs-signatories', [
        'document_type' => 'response_letter',
        'signatories' => ['approved_by' => $details('Response Approver', 'RA')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $responseRows = OperationalLibraryValue::where('library_type', 'drrs_signatory')->where('metadata->document_type', 'response_letter')->get();
    expect($responseRows)->toHaveCount(1)
        ->and($responseRows->first()->context)->toBe('approved_by')
        ->and(data_get($responseRows->first()->metadata, 'initials'))->toBe('RA');
});

it('syncs designation and person fields across signatory libraries for the same employee', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $person = [
        'employee_name' => 'JANE D. SIGNATORY',
        'position' => 'Social Welfare Officer III',
        'suffix' => 'RSW',
        'designation' => 'Old Designation',
    ];

    $ris = OperationalLibraryValue::create([
        'library_type' => 'rros_ris_signatory',
        'context' => 'approved_by',
        'value' => 'JANE D. SIGNATORY, RSW | Old Designation',
        'metadata' => $person,
        'is_active' => true,
    ]);

    $dr = OperationalLibraryValue::create([
        'library_type' => 'rros_dr_signatory',
        'context' => 'released_by',
        'value' => 'JANE D. SIGNATORY, RSW | Old Designation',
        'metadata' => $person,
        'is_active' => true,
    ]);

    $drrs = OperationalLibraryValue::create([
        'library_type' => 'drrs_signatory',
        'context' => 'approved_by',
        'value' => 'JANE D. SIGNATORY, RSW | Old Designation',
        'metadata' => [...$person, 'document_type' => 'assessment', 'initials' => 'JDS'],
        'is_active' => true,
    ]);

    $other = OperationalLibraryValue::create([
        'library_type' => 'rros_stf_signatory',
        'context' => 'requested_by',
        'value' => 'OTHER PERSON | Different Role',
        'metadata' => [
            'employee_name' => 'OTHER PERSON',
            'position' => 'Admin Aide',
            'designation' => 'Different Role',
        ],
        'is_active' => true,
    ]);

    $this->actingAs($user)->put("/operational-library/{$ris->id}", [
        'library_type' => 'rros_ris_signatory',
        'value' => 'JANE D. SIGNATORY',
        'position' => 'Social Welfare Officer IV',
        'suffix' => 'RSW, MSSW',
        'designation' => 'OIC-DRMD Chief',
        'office' => 'DRMD- REGIONAL RESOURCE OPERATION SECTION',
        'context' => 'approved_by',
        'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $ris->refresh();
    $dr->refresh();
    $drrs->refresh();
    $other->refresh();

    expect($ris->value)->toBe('JANE D. SIGNATORY, RSW, MSSW | OIC-DRMD Chief')
        ->and(data_get($ris->metadata, 'designation'))->toBe('OIC-DRMD Chief')
        ->and(data_get($ris->metadata, 'position'))->toBe('Social Welfare Officer IV')
        ->and(data_get($ris->metadata, 'office'))->toBe('DRMD- REGIONAL RESOURCE OPERATION SECTION')
        ->and($dr->value)->toBe('JANE D. SIGNATORY, RSW, MSSW | OIC-DRMD Chief')
        ->and(data_get($dr->metadata, 'designation'))->toBe('OIC-DRMD Chief')
        ->and(data_get($dr->metadata, 'position'))->toBe('Social Welfare Officer IV')
        ->and(data_get($dr->metadata, 'office'))->toBe('DRMD- REGIONAL RESOURCE OPERATION SECTION')
        ->and($drrs->value)->toBe('JANE D. SIGNATORY, RSW, MSSW | OIC-DRMD Chief')
        ->and(data_get($drrs->metadata, 'designation'))->toBe('OIC-DRMD Chief')
        ->and(data_get($drrs->metadata, 'document_type'))->toBe('assessment')
        ->and(data_get($drrs->metadata, 'initials'))->toBe('JDS')
        ->and(data_get($drrs->metadata, 'office'))->toBe('DRMD- REGIONAL RESOURCE OPERATION SECTION')
        ->and($other->value)->toBe('OTHER PERSON | Different Role')
        ->and(data_get($other->metadata, 'designation'))->toBe('Different Role');
});

it('lets only super administrators configure the two DRIMS DROMIC principals', function (): void {
    $this->seed(DatabaseSeeder::class);
    $superAdmin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $details = fn (string $name, string $designation): array => [
        'name' => $name,
        'position' => 'Director',
        'suffix' => '',
        'designation' => $designation,
        'office' => 'DSWD Field Office Caraga',
    ];

    $payload = [
        'library_type' => 'drims_signatory',
        'signatories' => [
            'recommended_by' => $details('DROMIC DRMD CHIEF', 'OIC-Chief, DRMD'),
            'approved_by' => $details('DROMIC REGIONAL DIRECTOR', 'Regional Director'),
        ],
    ];

    $this->actingAs($superAdmin)->post('/operational-library/rros-signatories', $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(OperationalLibraryValue::where('library_type', 'drims_signatory')->pluck('context')->sort()->values()->all())
        ->toBe(['approved_by', 'recommended_by']);

    $nonAdmin = User::where('email', 'drrs@example.test')->firstOrFail();
    $this->actingAs($nonAdmin)->post('/operational-library/rros-signatories', $payload)->assertForbidden();
});
