<?php

use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\RequestParty;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function drrsCompleteAssessmentPayload(AssistanceRequest $record, FniLibraryItem $fniItem, User $actor, ?int $requestPartyId = null): array
{
    return [
        'request_party_id' => $requestPartyId ?? $record->request_party_id,
        'requesting_agency' => $record->requesting_agency ?: 'Test LGU',
        'lgu' => $record->lgu,
        'lgu_level' => $record->lgu_level,
        'province' => $record->province ?: 'Surigao del Norte',
        'municipality' => $record->municipality ?: 'Tubod',
        'barangay' => $record->barangay,
        'requester' => $record->requester ?: 'CSWDO',
        'date_requested' => $record->date_requested?->toDateString() ?: '2026-08-03',
        'date_received_by_drmd' => $record->date_received_by_drmd?->toDateString() ?: '2026-08-03',
        'incident_name' => 'Flooding',
        'incident_date' => '2026-08-01',
        'purpose' => 'Relief Augmentation',
        'assessment_summary' => 'Validated flood impact requires FNI augmentation.',
        'recommendations' => str_repeat('Validated assessment supports timely relief augmentation for affected families. ', 2),
        'remarks' => null,
        'assigned_social_worker' => $actor->name,
        'assessment_drn' => 'FOCARAGA-DRMD-AF-26-08-0001',
        'assessment_form_data' => [
            'request_type' => 'Disaster',
            'response_purpose' => 'Relief Augmentation',
            'affected_areas' => ['Poblacion'],
            'information_source' => 'LGU DROMIC Report',
            'information_date' => '2026-08-01',
            'families_served' => null,
            'has_previous_augmentation' => false,
            'previous_augmentations' => [],
            'delivery_batches' => [],
            'provide_augmentation' => true,
            'prepared_by' => strtoupper($actor->name),
            'prepared_by_position' => $actor->position ?: 'Social Welfare Officer II',
            'prepared_by_designation' => $actor->designation,
            'prepared_at' => now()->format('Y-m-d\TH:i'),
            'reviewed_by' => 'Reviewer|OIC - DRMD Chief',
            'approved_by' => 'Approver|ARDO',
            'assessment_drn_prefix' => 'FOCARAGA-DRMD-AF',
            'assessment_drn_year' => '26',
            'assessment_drn_month' => '08',
            'assessment_drn_specified' => '0001',
            'affected_persons' => 40,
        ],
        'affected_families' => 10,
        'items' => [[
            'inventory_item_id' => null,
            'fni_library_item_id' => $fniItem->id,
            'source_warehouse_id' => null,
            'item_name' => $fniItem->item_name,
            'requested_quantity' => 50,
            'available_quantity' => 100,
            'unit' => $fniItem->unit_of_measure,
            'priority' => 'normal',
            'remarks' => null,
        ]],
    ];
}

it('lets DRRS complete assessment and load previous augmentations even when permission sync is missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drrs->forceFill([
        'position' => 'Social Welfare Officer II',
        'designation' => null,
    ])->save();

    $drrsRole = Role::findByName('DRRS', 'web');
    $drrsRole->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $drrs = $drrs->fresh();
    expect($drrs->can('encode requests'))->toBeFalse()
        ->and($drrs->hasRole('DRRS'))->toBeTrue();

    $party = RequestParty::create([
        'directory_key' => 'drrs-assessment-party',
        'requesting_party' => 'Municipality of Tubod',
        'office_agency_details' => 'MSWDO',
        'source' => 'test',
        'is_active' => true,
    ]);
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard',
        'unit_of_measure' => 'pack',
        'is_active' => true,
    ]);
    $inventoryBalances = Mockery::mock(InventoryBalanceService::class);
    $inventoryBalances->shouldReceive('availableTotalsByItem')->once()->andReturn(collect(['familyfoodpack' => 100]));
    app()->instance(InventoryBalanceService::class, $inventoryBalances);

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-DRRS-ASSESS-AUTH',
        'request_party_id' => $party->id,
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'CSWDO',
        'date_requested' => '2026-08-03',
        'date_received_by_drmd' => '2026-08-03',
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'province' => 'Surigao del Norte',
        'municipality' => 'Tubod',
        'submitted_at' => now(),
    ]);

    $this->actingAs($drrs)
        ->getJson("/requests/{$record->id}/previous-augmentations")
        ->assertOk()
        ->assertJsonStructure(['has_previous', 'rows']);

    $this->actingAs($drrs)
        ->patch("/requests/{$record->id}/complete-assessment", drrsCompleteAssessmentPayload($record, $fniItem, $drrs, $party->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($record->fresh()->assessment_status)->toBe('draft')
        ->and($record->fresh()->assessment_acted_by)->toBe($drrs->id);
});

it('persists one assessment with multiple reconciled incident occurrences', function (): void {
    $this->seed(DatabaseSeeder::class);
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drrs->forceFill(['position' => 'Social Welfare Officer II'])->save();
    $party = RequestParty::create(['directory_key' => 'multi-incident-party', 'requesting_party' => 'Municipality of Tubod', 'source' => 'test', 'is_active' => true]);
    $fniItem = FniLibraryItem::query()->create(['item_category' => 'Food', 'item_name' => 'Family Food Pack', 'brand_description' => 'Standard', 'unit_of_measure' => 'pack', 'is_active' => true]);
    app()->instance(InventoryBalanceService::class, tap(Mockery::mock(InventoryBalanceService::class), fn ($mock) => $mock->shouldReceive('availableTotalsByItem')->once()->andReturn(collect(['familyfoodpack' => 100]))));
    $record = AssistanceRequest::create(['reference_number' => 'REQ-MULTI-INCIDENT', 'request_party_id' => $party->id, 'requesting_agency' => 'Municipality of Tubod', 'requester' => 'CSWDO', 'date_requested' => '2026-08-10', 'date_received_by_drmd' => '2026-08-10', 'status' => 'endorsed', 'endorsed_to_drrs' => true, 'submission_type' => 'fni_request', 'province' => 'Surigao del Norte', 'municipality' => 'Tubod']);
    $payload = drrsCompleteAssessmentPayload($record, $fniItem, $drrs, $party->id);
    $payload['affected_families'] = 5;
    $payload['assessment_form_data']['affected_persons'] = 20;
    $payload['assessment_form_data']['incidents'] = [
        ['incident_type' => 'Fire Incident', 'occurrence_at' => '2026-08-01', 'barangay' => 'Marga', 'affected_families' => 2, 'affected_persons' => 8],
        ['incident_type' => 'Fire Incident', 'occurrence_at' => '2026-08-09', 'barangay' => 'San Pablo', 'affected_families' => 3, 'affected_persons' => 12],
    ];

    $this->actingAs($drrs)->patch("/requests/{$record->id}/complete-assessment", $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $saved = $record->fresh();
    expect($saved->incident_count)->toBe(2)
        ->and(data_get($saved->assessment_form_data, 'incidents'))->toHaveCount(2)
        ->and($saved->affected_families)->toBe(5);
});

it('rejects a multi-incident assessment whose population does not reconcile', function (): void {
    $this->seed(DatabaseSeeder::class);
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drrs->forceFill(['position' => 'Social Welfare Officer II'])->save();
    $party = RequestParty::create(['directory_key' => 'multi-incident-invalid-party', 'requesting_party' => 'Municipality of Tubod', 'source' => 'test', 'is_active' => true]);
    $fniItem = FniLibraryItem::query()->create(['item_category' => 'Food', 'item_name' => 'Family Food Pack', 'brand_description' => 'Standard', 'unit_of_measure' => 'pack', 'is_active' => true]);
    $record = AssistanceRequest::create(['reference_number' => 'REQ-MULTI-INVALID', 'request_party_id' => $party->id, 'requesting_agency' => 'Municipality of Tubod', 'requester' => 'CSWDO', 'date_requested' => '2026-08-10', 'date_received_by_drmd' => '2026-08-10', 'status' => 'endorsed', 'endorsed_to_drrs' => true, 'submission_type' => 'fni_request']);
    $payload = drrsCompleteAssessmentPayload($record, $fniItem, $drrs, $party->id);
    $payload['affected_families'] = 10;
    $payload['assessment_form_data']['incidents'] = [
        ['incident_type' => 'Fire Incident', 'occurrence_at' => '2026-08-01', 'barangay' => 'Marga', 'affected_families' => 2, 'affected_persons' => 40],
    ];

    $this->actingAs($drrs)->patch("/requests/{$record->id}/complete-assessment", $payload)
        ->assertSessionHasErrors('assessment_form_data.incidents');
    expect($record->fresh()->assessment_status)->toBeNull();
});

it('blocks a DRRS assessment when requested stock is unavailable', function (): void {
    $this->seed(DatabaseSeeder::class);
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $party = RequestParty::create([
        'directory_key' => 'drrs-unavailable-stock-party',
        'requesting_party' => 'Municipality of Tubod',
        'office_agency_details' => 'MSWDO',
        'source' => 'test',
        'is_active' => true,
    ]);
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Unavailable Test Item',
        'brand_description' => 'Standard',
        'unit_of_measure' => 'box',
        'is_active' => true,
    ]);
    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-DRRS-NO-STOCK',
        'request_party_id' => $party->id,
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'CSWDO',
        'date_requested' => '2026-08-03',
        'date_received_by_drmd' => '2026-08-03',
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'province' => 'Surigao del Norte',
        'municipality' => 'Tubod',
    ]);

    $this->actingAs($drrs)
        ->patch("/requests/{$record->id}/complete-assessment", drrsCompleteAssessmentPayload($record, $fniItem, $drrs, $party->id))
        ->assertRedirect()
        ->assertSessionHasErrors('items');

    expect($record->fresh()->assessment_status)->toBeNull()
        ->and($record->items()->count())->toBe(0);
});

it('lets DRRS mark linked sitrep request letter as viewed when creating assessment outside AOR', function (): void {
    $this->seed(DatabaseSeeder::class);
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drrs->forceFill([
        'aor_cities_municipalities' => ['1606801000'],
        'aor_districts' => [],
        'aor_provinces' => [],
        'position' => 'Social Welfare Officer II',
    ])->save();

    $drrsRole = Role::findByName('DRRS', 'web');
    $drrsRole->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $drrs = $drrs->fresh();
    expect($drrs->can('encode requests'))->toBeFalse();

    $sitrep = AssistanceRequest::create([
        'reference_number' => 'LGU-SITREP-DRRS-VIEWED',
        'requesting_agency' => 'Municipality of Tubod',
        'lgu' => 'Tubod',
        'requester' => 'CSWDO',
        'date_requested' => '2026-08-03',
        'status' => 'submitted',
        'submission_type' => 'lgu_dromic_relief_request',
        'lgu_relief_request_reference' => 'LGU-REQ-LETTER-1',
        'lgu_submitted_to_dswd_at' => now(),
        'lgu_dromic_payload' => [
            'has_relief_request' => true,
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ],
        'province' => 'Surigao del Norte',
        'municipality' => 'Tubod',
        'lgu_psgc_code' => '1606727000',
        'submitted_at' => now(),
    ]);

    $assessment = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-DRRS-VIEWED',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'CSWDO',
        'date_requested' => '2026-08-03',
        'status' => 'endorsed',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'source_lgu_dromic_request_id' => $sitrep->id,
        'province' => 'Surigao del Norte',
        'municipality' => 'Tubod',
        'submitted_at' => now(),
    ]);

    $this->actingAs($drrs)
        ->patchJson("/lgu/dromic-sitrep/{$sitrep->id}/document-viewed", ['kind' => 'request'])
        ->assertOk()
        ->assertJsonPath('message', 'Request receipt acknowledged.');

    expect($sitrep->fresh()->lgu_relief_seen_by)->toBe($drrs->id)
        ->and($assessment->fresh()->source_lgu_dromic_request_id)->toBe($sitrep->id);
});
