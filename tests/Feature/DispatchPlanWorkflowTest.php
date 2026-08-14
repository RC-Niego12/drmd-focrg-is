<?php

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\WorkflowNotification;
use App\Services\InventoryBalanceService;
use App\Services\RisDrDocumentPdfService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

function prepareRisForDispatch(): array
{
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $warehouse = Warehouse::create([
        'name' => 'Dispatch Hub',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
        'warehouse_type' => 'Regional Warehouse',
        'ownership' => 'Owned',
    ]);

    $balances = Mockery::mock(InventoryBalanceService::class);
    $balances->shouldReceive('balanceRows')->andReturn(collect([[
        'warehouse_id' => $warehouse->id,
        'item' => 'Family Food Pack',
        'available_balance' => 100,
    ]]));
    app()->instance(InventoryBalanceService::class, $balances);

    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-DISPATCH-FLOW-001',
        'requesting_agency' => 'Butuan City LGU',
        'requester' => 'Representative',
        'date_requested' => now()->toDateString(),
        'purpose' => 'Relief Augmentation',
        'status' => 'acted',
        'assessment_status' => 'submitted',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);
    $item = $request->items()->create([
        'item_name' => 'Family Food Pack',
        'requested_quantity' => 10,
        'approved_quantity' => 8,
        'unit' => 'boxes',
        'status' => 'approved',
    ]);
    $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.now()->format('y-m').'-';

    test()->actingAs($rros)
        ->post("/rros/requests/{$request->id}/ris", [
            'ris_number' => 'RIS-DISPATCH-FLOW-1',
            'ris_date' => now()->toDateString(),
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Butuan City LGU',
            'delivery_site' => 'Butuan City Hall',
            'contact_number' => '09171234567',
            'status' => 'prepared',
            'tracking_data' => [
                'assessment_drn_for_ris' => 'ASSESS-DRN-FLOW',
                'purpose_of_request' => 'Relief Augmentation',
                'incident_type' => 'Fire Incident',
                'incident_specification' => 'Test fire',
                'dr_number' => 'DR#-08-9101',
                'ris_drn' => $prefix.'9101',
                'mode_of_transportation' => ['DSWD-Owned'],
                'vehicle_type' => ['Truck'],
                'number_of_vehicles' => 1,
                'driver_name' => 'Juan Driver',
                'driver_contact_number' => '09170001111',
                'vehicle_plate_number' => 'ABC-1234',
            ],
            'items' => [[
                'request_item_id' => $item->id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $request->requisitionIssuanceSlip()->update([
        'status' => 'approved',
        'approval_routing_mode' => 'manual',
        'ardo_endorsed_at' => now()->subDay()->toDateString(),
        'ardo_returned_at' => now()->toDateString(),
    ]);

    $inventoryItem = InventoryItem::firstOrCreate(
        ['name' => 'Family Food Pack'],
        ['category' => 'food', 'unit' => 'boxes', 'status' => 'active'],
    );
    $batch = InventoryBatch::create([
        'inventory_item_id' => $inventoryItem->id,
        'warehouse_id' => $warehouse->id,
        'batch_number' => 'TEST-FFP-2027-01',
        'brand_description' => 'Prepacked',
        'quantity' => 100,
        'reserved_quantity' => 0,
        'expiration_date' => now()->addYear()->endOfMonth()->toDateString(),
        'date_received' => now()->subMonth()->toDateString(),
        'source' => 'FO Stockpile/Prepo',
        'current_status' => 'available',
    ]);
    $batch->transactions()->create([
        'type' => 'receipt',
        'transaction_date' => now()->subMonth()->toDateString(),
        'quantity' => 100,
        'unit_cost' => 500,
        'total_cost' => 50000,
        'balance_after' => 100,
    ]);

    return [$rros, $request->fresh(['requisitionIssuanceSlip.allocationItems'])];
}

function plannedVehiclePayload(array $overrides = []): array
{
    $departure = now()->format('Y-m-d\TH:i');
    $arrival = now()->addHours(4)->format('Y-m-d\TH:i');

    return array_merge([
        'vehicle_type' => 'Truck',
        'driver' => 'Juan Driver',
        'driver_contact_number' => '09170001111',
        'driver_id_number' => '21-0001',
        'driver_position' => 'Administrative Assistant',
        'driver_office' => 'RROS',
        'vehicle_plate_number' => 'ABC-1234',
        'estimated_departure' => $departure,
        'estimated_arrival' => $arrival,
        'mode_of_transportation' => 'DSWD-Owned',
        'land_transportation_source' => 'DSWD OWNED - Field Office',
        'release_witness_contact_number' => '09170002222',
        'loaded_items' => [[
            'item_name' => 'Family Food Pack',
            'loaded_quantity' => 8,
            'remarks' => null,
        ]],
    ], $overrides);
}

it('prefills delivery receipt fields from legacy RIS tracking when creating a dispatch plan', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $slip = $request->requisitionIssuanceSlip;
    $slip->forceFill([
        'receiving_representative' => 'Rep. Ana Cruz',
        'contact_number' => '09171234567',
        'delivered_at' => now()->subDay()->toDateString(),
        'release_witnessed_by' => 'Legacy Witness',
        'fully_delivered' => true,
        'has_returned_items' => true,
        'returned_particulars' => 'Family Food Pack',
        'returned_quantity' => 2,
        'returned_reason' => 'Damaged packaging',
        'tracking_data' => [
            ...($slip->tracking_data ?? []),
            'delivered_at' => now()->subDay()->toDateString(),
            'release_witnessed_by' => 'Legacy Witness',
            'fully_delivered' => true,
            'has_returned_items' => true,
            'returned_particulars' => 'Family Food Pack',
            'returned_quantity' => 2,
            'returned_reason' => 'Damaged packaging',
        ],
    ])->save();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'draft',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'dispatch_officer' => 'Tampered Name',
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->delivered_at?->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($dispatch->release_witnessed_by)->toBe('Legacy Witness')
        ->and($dispatch->fully_delivered)->toBeTrue()
        ->and($dispatch->has_returned_items)->toBeTrue()
        ->and($dispatch->returned_particulars)->toBe('Family Food Pack')
        ->and($dispatch->returned_quantity)->toBe(2)
        ->and($dispatch->returned_reason)->toBe('Damaged packaging')
        ->and($dispatch->dispatcher)->toBe($rros->name);

    $this->actingAs($rros)
        ->get('/dispatches?bucket=still_for_action&dispatch_id='.$dispatch->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('selectedDispatch.ris.receiving_representative', 'Rep. Ana Cruz')
            ->where('selectedDispatch.ris.contact_number', '09171234567'));
});

it('resolves LGU receipt position and office from the LGU directory for the RIS receiving representative', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $request->forceFill([
        'lgu' => 'Tubod',
        'municipality' => 'Tubod',
        'province' => 'Surigao del Norte',
        'requesting_agency' => 'MLGU - Tubod, SDN',
    ])->save();

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'LSWDO',
        'lgu_name' => 'TUBOD',
        'override_lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'psgc_code' => '166725000',
        'lswd_contact_number' => '0909-989-9214',
        'is_active' => true,
    ]);
    $directory->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'MS. LEORAVEL DALES ESPIN, RSW',
        'position_designation' => 'MSWDO',
        'override_position_designation' => 'MSWDO',
    ]);

    $slip = $request->requisitionIssuanceSlip;
    $slip->forceFill([
        'receiving_representative' => 'MS. LEORAVEL DALES ESPIN, RSV',
        'contact_number' => '0909-989-9214',
        'recipient' => 'MLGU - Tubod, SDN',
    ])->save();

    OperationalLibraryValue::create([
        'library_type' => 'dispatch_received_by',
        'value' => 'MS. LEORAVEL DALES ESPIN, RSW',
        'context' => 'all',
        'metadata' => ['position' => '', 'office' => ''],
        'is_active' => true,
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'draft',
            'destination' => 'Tubod Municipal Hall',
            'receiving_agency_lgu' => 'MLGU - Tubod, SDN',
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($rros)
        ->get('/dispatches?bucket=still_for_action&dispatch_id='.$dispatch->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('selectedDispatch.ris.receiving_representative', 'MS. LEORAVEL DALES ESPIN, RSV')
            ->where('selectedDispatch.ris.receiving_representative_position', 'MSWDO')
            ->where('selectedDispatch.ris.receiving_representative_office', 'MLGU - Tubod, SDN')
            ->where('selectedDispatch.ris.contact_number', '0909-989-9214')
            ->where('dispatchContactLibraries.dispatch_received_by', fn ($rows) => collect($rows)->contains(
                fn ($row) => str_contains((string) data_get($row, 'value'), 'ESPIN')
                    && data_get($row, 'position') === 'MSWDO'
                    && filled(data_get($row, 'office'))
            )));
});

it('exposes same-origin document preview props for prepared RIS rows on the dispatches page', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $source = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-DISPATCH-PREVIEW-001',
        'requesting_agency' => 'Butuan City LGU',
        'requester' => 'LGU Encoder',
        'date_requested' => now()->toDateString(),
        'purpose' => 'Relief Augmentation',
        'status' => 'submitted_with_signed_copies',
        'submission_type' => 'lgu_dromic_relief_request',
        'lgu_signed_request_path' => 'lgu-dromic/preview/signed-request.pdf',
        'lgu_submitted_to_dswd_at' => now(),
    ]);
    $request->forceFill([
        'source_lgu_dromic_request_id' => $source->id,
        'reference_number' => 'LGU-REQ-DISPATCH-PREVIEW-FNI',
    ])->save();

    $this->actingAs($rros)
        ->get('/dispatches?bucket=still_for_action')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->has('eligibleRequests', 1)
            ->where('eligibleRequests.0.id', $request->id)
            ->where('eligibleRequests.0.lgu_request_view_url', "/lgu/dromic-sitrep/{$source->id}/signed-copy/request")
            ->where('eligibleRequests.0.assessment_pdf_view_url', "/requests/{$request->id}/assessment-pdf?margin=18&inline=1")
            ->where('eligibleRequests.0.source_lgu_dromic_report.id', $source->id)
            ->where('eligibleRequests.0.source_lgu_dromic_report.lgu_signed_request_path', 'lgu-dromic/preview/signed-request.pdf')
            ->where('eligibleRequests.0.ris_preview.form.ris_number', 'RIS-DISPATCH-FLOW-1')
            ->where('eligibleRequests.0.ris_preview.has_dr', true)
            ->where('eligibleRequests.0.ris_advance_pdf_view_url', "/rros/ris/{$request->requisitionIssuanceSlip->id}/preview-pdf/ris?inline=1")
            ->where('eligibleRequests.0.dr_advance_pdf_view_url', "/rros/ris/{$request->requisitionIssuanceSlip->id}/preview-pdf/dr?inline=1"));
});

it('lists an e-PIRMA-approved RIS as ready for dispatch', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $request->requisitionIssuanceSlip->forceFill([
        'approval_routing_mode' => 'epirma',
        'ris_epirma_status' => 'signed',
        'ris_epirma_signed_at' => now(),
        'ardo_endorsed_at' => null,
        'ardo_returned_at' => null,
        'status' => 'approved',
    ])->save();

    $this->actingAs($rros)
        ->get('/dispatches?bucket=still_for_action')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('eligibleRequests', 1)
            ->where('eligibleRequests.0.id', $request->id)
            ->where('eligibleRequests.0.ris.status', 'approved')
            ->where('counts.still_for_action', 1));
});

it('rejects past estimated departure on create', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'draft',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'estimated_departure' => now()->subHour()->format('Y-m-d\TH:i'),
                'estimated_arrival' => now()->addHours(2)->format('Y-m-d\TH:i'),
            ])],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.estimated_departure' => 'Estimated departure cannot be earlier than the current date and time.',
        ]);

    expect(DispatchPlan::query()->where('request_id', $request->id)->exists())->toBeFalse();
});

it('allows same-day earlier clock times for actual departed and arrival', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    // Same Manila calendar day, earlier than "now" by the clock — must still be accepted.
    $earlierToday = now()->timezone('Asia/Manila')->startOfDay();
    $arrivalLater = $earlierToday->copy()->setTime(3, 0);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'in_transit',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'departed_at' => $earlierToday->format('Y-m-d\TH:i'),
                'actual_arrival' => $earlierToday->format('Y-m-d\TH:i'),
            ])],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.actual_arrival' => 'Actual arrival date and time must be after actual departure.',
        ]);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'in_transit',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'departed_at' => $earlierToday->format('Y-m-d\TH:i'),
                'actual_arrival' => $arrivalLater->format('Y-m-d\TH:i'),
            ])],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('in_transit')
        ->and(optional($dispatch->departed_at)?->format('Y-m-d H:i'))->toBe($earlierToday->format('Y-m-d H:i'))
        ->and(optional($dispatch->actual_arrival)?->format('Y-m-d H:i'))->toBe($arrivalLater->format('Y-m-d H:i'));
});

it('rejects actual departed and arrival on a prior calendar date', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $yesterday = now()->timezone('Asia/Manila')->subDay()->setTime(14, 0);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'in_transit',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'departed_at' => $yesterday->format('Y-m-d\TH:i'),
                'actual_arrival' => $yesterday->copy()->addHour()->format('Y-m-d\TH:i'),
            ])],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.departed_at' => 'Actual departure date cannot be earlier than today.',
            'vehicle_details.0.actual_arrival' => 'Actual arrival date cannot be earlier than today.',
        ]);

    // A departure already recorded on a prior date may be completed later using
    // that same calendar date, provided arrival is strictly after departure.
    $savedVehicle = plannedVehiclePayload([
        'departed_at' => $yesterday->format('Y-m-d\TH:i'),
        'actual_arrival' => null,
    ]);
    $dispatch->forceFill([
        'vehicle_details' => [$savedVehicle],
        'departed_at' => $yesterday,
    ])->save();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [[
                ...$savedVehicle,
                'actual_arrival' => $yesterday->copy()->addHour()->format('Y-m-d\TH:i'),
            ]],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

it('creates a draft dispatch plan from a prepared RIS and lists it under still for action', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'draft',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->first();
    expect($dispatch)->not->toBeNull()
        ->and($dispatch->status)->toBe('draft')
        ->and($dispatch->requisition_issuance_slip_id)->toBe($request->requisitionIssuanceSlip->id)
        ->and($dispatch->destination)->toBe('Butuan City Hall')
        // Mode of transportation is Dispatch Plan–owned (not copied from RIS tracking).
        ->and($dispatch->vehicle_details)->toHaveCount(1)
        ->and($dispatch->vehicle_details[0]['dr_number'] ?? null)->toBe('DR#-08-9101')
        ->and($dispatch->dispatcher)->toBe($rros->name)
        ->and($dispatch->items)->toHaveCount(1)
        ->and($dispatch->items->first()->allocated_quantity)->toBe(8)
        ->and($dispatch->bucket())->toBe('still_for_action');

    $this->actingAs($rros)
        ->get("/dispatches/{$dispatch->id}/vehicles/0/dr")
        ->assertStatus(422);

    $this->actingAs($rros)
        ->get('/dispatches?bucket=still_for_action')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('bucket', 'still_for_action')
            ->where('escortWorkspace', false)
            ->has('dispatches.data', 1)
            ->has('eligibleRequests', 0));

    // Dispatch/Delivery landing defaults to Still for Action.
    $this->actingAs($rros)
        ->get('/dispatches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('bucket', 'still_for_action')
            ->where('escortWorkspace', false));

    // Escort workspace has no Still for Action — remaps to In Progress (drafts stay off escort list).
    $this->actingAs($rros)
        ->get('/delivery-escort?bucket=still_for_action')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('bucket', 'in_progress')
            ->where('escortWorkspace', true)
            ->has('eligibleRequests', 0));
});

it('requires per-vehicle receipt fields before marking a dispatch plan received', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->bucket())->toBe('in_progress');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'departed_at' => now()->format('Y-m-d\TH:i'),
                'receiver_contact' => '',
            ])],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.received_by',
            'vehicle_details.0.received_by_id_number',
            'vehicle_details.0.received_by_position',
            'vehicle_details.0.received_by_office',
            'vehicle_details.0.received_at',
            'vehicle_details.0.receiver_contact',
            'vehicle_details.0.receipt_acknowledged',
            'vehicle_details.0.actual_arrival',
            'vehicle_details.0.fully_delivered',
            'items.0.received_quantity',
        ]);

    Notification::fake();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'has_returned_items' => 'No',
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'departed_at' => now()->format('Y-m-d\TH:i'),
                'actual_arrival' => now()->format('Y-m-d\TH:i'),
                'fully_delivered' => 'No',
                'received_by' => 'LGU Receiver',
                'received_by_id_number' => 'LGU-ID-001',
                'received_by_position' => 'MSWDO',
                'received_by_office' => 'Butuan City LGU',
                'received_at' => now()->format('Y-m-d\TH:i'),
                'receiver_contact' => '09175556666',
                'receipt_acknowledged' => true,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => 6,
                'return_reason' => 'Two boxes were damaged and returned to the warehouse.',
            ]],
        ])
        ->assertRedirect();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('received')
        ->and($dispatch->receipt_acknowledged)->toBeTrue()
        ->and(optional($dispatch->actual_arrival)?->format('Y-m-d H:i'))->toBe(now()->format('Y-m-d H:i'))
        ->and($dispatch->delivered_at?->toDateString())->toBe(now()->toDateString())
        ->and($dispatch->fully_delivered)->toBeFalse()
        ->and($dispatch->has_returned_items)->toBeTrue()
        ->and($dispatch->returned_quantity)->toBe(2)
        ->and($dispatch->returned_particulars)->toBe('Family Food Pack')
        ->and($dispatch->items->first()->return_reason)->toBe('Two boxes were damaged and returned to the warehouse.')
        ->and($dispatch->release_witnessed_by)->toBe('Witness Staff')
        ->and($dispatch->dispatcher)->toBe($rros->name)
        ->and($dispatch->vehicle_details[0]['estimated_departure'] ?? null)->not->toBeEmpty()
        ->and($request->fresh()->status)->toBe('completed');

    Notification::assertSentTo(
        User::where('email', 'drims@example.test')->firstOrFail(),
        WorkflowNotification::class,
        fn (WorkflowNotification $notification): bool => ($notification->toArray(User::where('email', 'drims@example.test')->first())['action_key'] ?? null) === 'dispatch_plan_received'
    );
});

it('requires release witness MyPortal details before confirming release', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
            ])],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.release_witness_id_number',
            'vehicle_details.0.release_witness_position',
            'vehicle_details.0.release_witness_office',
        ]);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
            ])],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.release_witness_affiliation']);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'release_witness_affiliation' => 'dswd',
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
            ])],
        ])
        ->assertRedirect();

    $dispatch->refresh();
    $issuance = InventoryTransaction::query()
        ->with(['batch.item', 'batch.warehouse'])
        ->where('transactionable_type', DispatchPlan::class)
        ->where('transactionable_id', $dispatch->id)
        ->where('type', 'release')
        ->firstOrFail();
    expect($dispatch->status)->toBe('released')
        ->and($request->requisitionIssuanceSlip->fresh()->reservation_status)->toBe('released')
        ->and(collect($dispatch->status_timeline)->last()['note'] ?? null)->toContain('system inventory issuance')
        ->and((float) $issuance->quantity)->toBe(8.0)
          ->and($issuance->reconciliation_status)->toBe('pending_wit')
          ->and($issuance->source_of_goods)->toBe('FO Stockpile/Prepo')
          ->and($issuance->purpose)->toBe('2026 Fire Incident')
          ->and($issuance->reference_number)->toBe($dispatch->vehicle_details[0]['dr_number'])
        ->and($issuance->ris_if_stf)->toBe('RIS-DISPATCH-FLOW-1')
        ->and($issuance->batch->brand_description)->toBe('Prepacked')
        ->and($issuance->batch->expiration_date)->not->toBeNull()
        ->and((float) $issuance->batch->quantity)->toBe(92.0)
        ->and($dispatch->vehicle_details[0]['mode_of_transportation'] ?? null)->toBe('Partner')
        ->and($dispatch->vehicle_details[0]['release_witnessed_by'] ?? null)->toBe('Witness Staff')
        ->and($dispatch->vehicle_details[0]['release_witness_id_number'] ?? null)->toBe('21-001')
        ->and($dispatch->vehicle_details[0]['release_witness_position'] ?? null)->toBe('SWO III')
        ->and($dispatch->vehicle_details[0]['release_witness_office'] ?? null)->toBe('RROS')
        ->and($dispatch->vehicle_details[0]['warehouse_released_by'] ?? null)->toBeNull()
        ->and($dispatch->mode_of_transportation)->toBe(['Partner']);
});

it('gives the assigned DSWD escort a scoped workspace and release-to-receipt update access', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $escort = User::where('email', 'drims@example.test')->firstOrFail();
    $escort->forceFill(['id_number' => '16-12254'])->save();
    $warehouse = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();

    $plannedVehicle = plannedVehiclePayload([
        'source_warehouse_id' => $warehouse->id,
        'source_warehouse_name' => $warehouse->name,
        'has_dswd_escort' => true,
        'escort_name' => $escort->name,
        'escort_id_number' => $escort->id_number,
        'escort_contact_number' => '09700345594',
        'escort_position' => 'Administrative Aide IV',
        'escort_office' => 'Regional Resource Operations Section',
    ]);

    $this->actingAs($rros)->post('/dispatches', [
        'request_id' => $request->id,
        'status' => 'planned',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$plannedVehicle],
    ])->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($escort)
        ->get("/delivery-escort?bucket=in_progress&dispatch_id={$dispatch->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('escortWorkspace', true)
            ->where('bucket', 'in_progress')
            ->where('selectedDispatch.id', $dispatch->id)
            ->has('dispatches.data', 1)
            ->has('eligibleRequests', 0));

    // Escort landing defaults to In Progress (no Still for Action tab).
    $this->actingAs($escort)
        ->get('/delivery-escort')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('escortWorkspace', true)
            ->where('bucket', 'in_progress'));

    $this->actingAs($escort)->put("/dispatches/{$dispatch->id}", [
        'status' => 'released',
        'destination' => 'Attempted destination change',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [[
            ...$plannedVehicle,
            'driver' => 'Attempted driver change',
            'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
            'loaded_at' => now()->format('Y-m-d\TH:i'),
            'release_witnessed_by' => $escort->name,
            'release_witness_contact_number' => '09700345594',
            'release_witness_id_number' => $escort->id_number,
            'release_witness_position' => $escort->position ?: 'Administrative Aide IV',
            'release_witness_office' => $escort->office ?: 'RROS',
        ]],
    ])->assertRedirect(route('delivery-escort.index', [
        'bucket' => 'in_progress',
        'dispatch_id' => $dispatch->id,
    ]));

    $dispatch->refresh();
    expect($dispatch->status)->toBe('released')
        ->and($dispatch->destination)->toBe('Butuan City Hall')
        ->and($dispatch->vehicle_details[0]['driver'] ?? null)->toBe('Juan Driver')
        ->and($dispatch->dispatcher)->toBe($rros->name)
        ->and($escort->fresh()->notifications->contains(
            fn ($notification): bool => ($notification->data['action_key'] ?? null) === 'delivery_escort_released'
        ))->toBeTrue();

    $unassigned = User::where('email', 'drrs@example.test')->firstOrFail();
    $this->actingAs($unassigned)
        ->put("/dispatches/{$dispatch->id}", ['status' => 'in_transit'])
        ->assertForbidden();
});

it('requires a same-day schedule to be saved as a plan revision before confirming release', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $departure = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
    $multiDayArrival = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');
    $sameDayArrival = now()->addDay()->setTime(14, 0)->format('Y-m-d\TH:i');

    $this->actingAs($rros)->post('/dispatches', [
        'request_id' => $request->id,
        'status' => 'planned',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [plannedVehiclePayload([
            'estimated_departure' => $departure,
            'estimated_arrival' => $multiDayArrival,
        ])],
    ])->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $releaseVehicle = plannedVehiclePayload([
        'estimated_departure' => $departure,
        'estimated_arrival' => $sameDayArrival,
        'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
        'release_witnessed_by' => 'Witness Staff',
        'release_witness_id_number' => '21-001',
        'release_witness_position' => 'SWO III',
        'release_witness_office' => 'RROS',
    ]);

    $this->actingAs($rros)->put("/dispatches/{$dispatch->id}", [
        'status' => 'released',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$releaseVehicle],
    ])->assertSessionHasErrors(['vehicle_details.0.estimated_arrival']);

    expect($dispatch->fresh()->status)->toBe('planned');

    $this->actingAs($rros)->put("/dispatches/{$dispatch->id}", [
        'status' => 'planned',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$releaseVehicle],
    ])->assertRedirect();

    $this->actingAs($rros)->put("/dispatches/{$dispatch->id}", [
        'status' => 'released',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$releaseVehicle],
    ])->assertRedirect();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('released')
        ->and(collect($dispatch->status_timeline)->pluck('type')->all())
        ->toContain('plan_update');
});

it('allows a saved multi-day run exception to pass the release gate', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $departure = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
    $arrival = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');
    $vehicle = plannedVehiclePayload([
        'estimated_departure' => $departure,
        'estimated_arrival' => $arrival,
        'allows_multi_day_run' => true,
    ]);

    $this->actingAs($rros)->post('/dispatches', [
        'request_id' => $request->id,
        'status' => 'planned',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$vehicle],
    ])->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $vehicle = array_merge($vehicle, [
        'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
        'release_witnessed_by' => 'Witness Staff',
        'release_witness_id_number' => '21-001',
        'release_witness_position' => 'SWO III',
        'release_witness_office' => 'RROS',
    ]);

    $this->actingAs($rros)->put("/dispatches/{$dispatch->id}", [
        'status' => 'released',
        'destination' => 'Butuan City Hall',
        'receiving_agency_lgu' => 'Butuan City LGU',
        'number_of_vehicles' => 1,
        'vehicle_details' => [$vehicle],
    ])->assertRedirect();

    expect($dispatch->fresh()->status)->toBe('released');
});

it('allows warehouse pickup plans to move released to received without departed_at', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'fulfillment_type' => 'warehouse_pickup',
            'destination' => '',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'release_witness_affiliation' => 'dswd',
                'driver' => null,
                'vehicle_plate_number' => null,
                'estimated_departure' => null,
                'estimated_arrival' => null,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->fulfillment_type)->toBe('warehouse_pickup')
        ->and($dispatch->destination)->toContain('Warehouse pickup')
        ->and($dispatch->status)->toBe('planned');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'fulfillment_type' => 'warehouse_pickup',
            'destination' => $dispatch->destination,
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'driver' => null,
                'vehicle_plate_number' => null,
                'estimated_departure' => null,
                'estimated_arrival' => null,
                'release_witness_affiliation' => 'dswd',
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('released');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'in_transit',
            'fulfillment_type' => 'warehouse_pickup',
            'destination' => $dispatch->destination,
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
            ])],
        ])
        ->assertSessionHasErrors(['status']);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'fulfillment_type' => 'warehouse_pickup',
            'destination' => $dispatch->destination,
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'has_returned_items' => 'No',
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Partner LGU',
                'driver' => null,
                'vehicle_plate_number' => null,
                'estimated_departure' => null,
                'estimated_arrival' => null,
                'release_witness_affiliation' => 'dswd',
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                // No departed_at — warehouse pickup skips In Transit.
                'actual_arrival' => now()->format('Y-m-d\TH:i'),
                'fully_delivered' => 'Yes',
                'received_by' => 'LGU Receiver',
                'received_by_id_number' => 'LGU-ID-001',
                'received_by_position' => 'MSWDO',
                'received_by_office' => 'Butuan City LGU',
                'received_at' => now()->format('Y-m-d\TH:i'),
                'receiver_contact' => '09175556666',
                'receipt_acknowledged' => true,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('received')
        ->and($dispatch->fulfillment_type)->toBe('warehouse_pickup')
        ->and($dispatch->departed_at)->toBeNull()
        ->and($request->fresh()->status)->toBe('completed');
});

it('still requires departed_at for field delivery before marking received', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'fulfillment_type' => 'field_delivery',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'fulfillment_type' => 'field_delivery',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'has_returned_items' => 'No',
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                // Intentionally omit departed_at.
                'actual_arrival' => now()->format('Y-m-d\TH:i'),
                'fully_delivered' => 'Yes',
                'received_by' => 'LGU Receiver',
                'received_by_id_number' => 'LGU-ID-001',
                'received_by_position' => 'MSWDO',
                'received_by_office' => 'Butuan City LGU',
                'received_at' => now()->format('Y-m-d\TH:i'),
                'receiver_contact' => '09175556666',
                'receipt_acknowledged' => true,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => 8,
            ]],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.departed_at']);

    expect($dispatch->fresh()->status)->toBe('planned');
});

it('lets dispatch planners quick-add a vehicle type to the operational library', function (): void {
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $typeName = 'Dispatch Midplan Wing Van '.uniqid();

    $this->actingAs($rros)
        ->postJson('/dispatches/vehicle-types', [
            'value' => "  {$typeName}  ",
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('value', $typeName)
        ->assertJsonPath('label', $typeName);

    $row = OperationalLibraryValue::query()
        ->where('library_type', 'vehicle_type')
        ->where('value', $typeName)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->library_type)->toBe('vehicle_type')
        ->and($row->context)->toBe('all')
        ->and($row->is_active)->toBeTrue();

    // Same query shape Dispatches uses for vehicle type dropdown options.
    expect(OperationalLibraryValue::groupedOptions()['vehicle_type'] ?? [])
        ->toContain($typeName);

    // Same listing source Libraries → Vehicle Type uses (all rows for type).
    $librariesVehicleTypes = OperationalLibraryValue::query()
        ->orderBy('library_type')
        ->orderBy('value')
        ->get()
        ->where('library_type', 'vehicle_type')
        ->pluck('value');
    expect($librariesVehicleTypes)->toContain($typeName);

    $this->actingAs($rros)
        ->get('/dispatches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('libraryOptions.vehicle_type', fn ($options) => collect($options)->contains($typeName)));

    $this->actingAs($rros)
        ->get('/libraries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/FniLibrary')
            ->has('operationalLibraries')
            ->where('operationalLibraries', fn ($rows) => collect($rows)->contains(
                fn ($entry) => ($entry['library_type'] ?? null) === 'vehicle_type'
                    && ($entry['value'] ?? null) === $typeName
                    && ($entry['context'] ?? null) === 'all'
            )));

    $this->actingAs($rros)
        ->postJson('/dispatches/vehicle-types', [
            'value' => $typeName,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['value']);

    $this->actingAs($rros)
        ->postJson('/dispatches/vehicle-types', [
            'value' => mb_strtoupper($typeName),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['value']);

    $this->actingAs($rros)
        ->postJson('/dispatches/vehicle-types', [
            'value' => '   ',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['value']);
});

it('lets dispatch planners quick-add drivers and received-by contacts to operational libraries', function (): void {
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $driverName = 'Dispatch Driver '.uniqid();
    $receiverName = 'Dispatch Receiver '.uniqid();

    $this->actingAs($rros)
        ->postJson('/dispatches/drivers', [
            'name' => "  {$driverName}  ",
            'contact_number' => '09171234567',
            'position' => 'Driver',
            'office' => 'Fleet Unit',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('value', $driverName)
        ->assertJsonPath('contact_number', '09171234567')
        ->assertJsonPath('position', 'Driver')
        ->assertJsonPath('office', 'Fleet Unit');

    $driver = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_driver')
        ->where('value', $driverName)
        ->first();
    expect($driver)->not->toBeNull()
        ->and(data_get($driver->metadata, 'contact_number'))->toBe('09171234567')
        ->and(data_get($driver->metadata, 'position'))->toBe('Driver')
        ->and(data_get($driver->metadata, 'office'))->toBe('Fleet Unit');

    $this->actingAs($rros)
        ->postJson('/dispatches/received-by', [
            'name' => $receiverName,
            'position' => 'MSWDO',
            'office' => 'Butuan City',
        ])
        ->assertCreated()
        ->assertJsonPath('value', $receiverName)
        ->assertJsonPath('position', 'MSWDO')
        ->assertJsonPath('office', 'Butuan City');

    $receiver = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_received_by')
        ->where('value', $receiverName)
        ->first();
    expect($receiver)->not->toBeNull()
        ->and(data_get($receiver->metadata, 'position'))->toBe('MSWDO')
        ->and(data_get($receiver->metadata, 'office'))->toBe('Butuan City');

    $this->actingAs($rros)
        ->get('/dispatches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('dispatchContactLibraries.dispatch_driver', fn ($rows) => collect($rows)->contains(
                fn ($entry) => ($entry['value'] ?? null) === $driverName
            ))
            ->where('dispatchContactLibraries.dispatch_received_by', fn ($rows) => collect($rows)->contains(
                fn ($entry) => ($entry['value'] ?? null) === $receiverName
            )));

    $this->actingAs($rros)
        ->postJson('/dispatches/drivers', [
            'name' => $driverName,
            'contact_number' => '09170000000',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($rros)
        ->postJson('/dispatches/drivers', [
            'name' => 'Another Driver '.uniqid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contact_number']);
});

it('persists DSWD MyPortal driver id and received-by position/office on vehicle details', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'mode_of_transportation' => 'DSWD-Owned',
                    'driver' => 'MyPortal Driver',
                    'driver_id_number' => '16-99999',
                    'driver_contact_number' => '09170001111',
                    'driver_position' => 'Driver',
                    'driver_office' => 'RROS',
                ]),
                plannedVehiclePayload([
                    'mode_of_transportation' => 'Service Provider',
                    'driver' => 'Contract Driver',
                    'driver_contact_number' => '09170002222',
                    'driver_position' => 'Hired Driver',
                    'driver_office' => 'ABC Trucking',
                    'driver_id_number' => null,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 0,
                        'remarks' => null,
                    ]],
                ]),
            ],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => null,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $vehicles = $dispatch->vehicle_details;
    expect($vehicles[0]['mode_of_transportation'] ?? null)->toBe('DSWD-Owned')
        ->and($vehicles[0]['driver'] ?? null)->toBe('MyPortal Driver')
        ->and($vehicles[0]['driver_id_number'] ?? null)->toBe('16-99999')
        ->and($vehicles[0]['driver_position'] ?? null)->toBe('Driver')
        ->and($vehicles[0]['driver_office'] ?? null)->toBe('RROS')
        ->and($vehicles[1]['mode_of_transportation'] ?? null)->toBe('Service Provider')
        ->and($vehicles[1]['driver'] ?? null)->toBe('Contract Driver')
        ->and($vehicles[1]['driver_contact_number'] ?? null)->toBe('09170002222')
        ->and($vehicles[1]['driver_position'] ?? null)->toBe('Hired Driver')
        ->and($vehicles[1]['driver_office'] ?? null)->toBe('ABC Trucking')
        ->and($vehicles[1]['driver_id_number'] ?? null)->toBeNull();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'mode_of_transportation' => 'Service Provider',
                'driver' => 'Contract Driver',
                'driver_contact_number' => '09170002222',
                'received_by' => 'LGU Receiver',
                'received_by_id_number' => 'LGU-ID-009',
                'received_by_position' => 'MSWDO',
                'received_by_office' => 'City Social Welfare',
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => null,
            ]],
        ])
        ->assertRedirect();

    $row = $dispatch->fresh()->vehicle_details[0] ?? [];
    expect($row['received_by'] ?? null)->toBe('LGU Receiver')
        ->and($row['received_by_id_number'] ?? null)->toBe('LGU-ID-009')
        ->and($row['received_by_position'] ?? null)->toBe('MSWDO')
        ->and($row['received_by_office'] ?? null)->toBe('City Social Welfare');
});

it('requires escort name when With DSWD Escort is Yes for planned status', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'has_dswd_escort' => true,
                'escort_name' => null,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => null,
            ]],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.escort_name']);
});

it('persists DSWD escort details and clears them when escort is No', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'has_dswd_escort' => true,
                'escort_name' => 'Escort Officer',
                'escort_contact_number' => '09175556666',
                'escort_id_number' => '16-88888',
                'escort_position' => 'SWO II',
                'escort_office' => 'DRMD',
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => null,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $row = $dispatch->vehicle_details[0] ?? [];
    expect($row['has_dswd_escort'] ?? null)->toBeTrue()
        ->and($row['escort_name'] ?? null)->toBe('Escort Officer')
        ->and($row['escort_contact_number'] ?? null)->toBe('09175556666')
        ->and($row['escort_id_number'] ?? null)->toBe('16-88888')
        ->and($row['escort_position'] ?? null)->toBe('SWO II')
        ->and($row['escort_office'] ?? null)->toBe('DRMD');

    $merged = $dispatch->printableMergedTransportFields();
    expect($merged['driver_name'])->toContain('Escort: Escort Officer');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'has_dswd_escort' => false,
                'escort_name' => 'Should Clear',
                'escort_contact_number' => '09170000000',
                'escort_id_number' => '00-00000',
                'escort_position' => 'Stale',
                'escort_office' => 'Stale Office',
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => null,
            ]],
        ])
        ->assertRedirect();

    $cleared = $dispatch->fresh()->vehicle_details[0] ?? [];
    expect($cleared['has_dswd_escort'] ?? null)->toBeFalse()
        ->and($cleared['escort_name'] ?? null)->toBeNull()
        ->and($cleared['escort_contact_number'] ?? null)->toBeNull()
        ->and($cleared['escort_id_number'] ?? null)->toBeNull()
        ->and($cleared['escort_position'] ?? null)->toBeNull()
        ->and($cleared['escort_office'] ?? null)->toBeNull();
});

it('keeps generated RIS/DR print data unchanged when dispatch details are added', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $slip = $request->requisitionIssuanceSlip;

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'driver' => 'Alpha Driver',
                    'driver_contact_number' => '09171110001',
                    'vehicle_plate_number' => 'AAA-1111',
                    'release_witnessed_by' => 'Witness One',
                ]),
                plannedVehiclePayload([
                    'driver' => 'Bravo Driver',
                    'driver_contact_number' => '09172220002',
                    'vehicle_plate_number' => 'BBB-2222',
                    'release_witnessed_by' => 'Witness Two',
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 0,
                        'remarks' => null,
                    ]],
                ]),
            ],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    $merged = $dispatch->printableMergedTransportFields();

    expect($merged['driver_name'])->toContain('Alpha Driver')
        ->and($merged['driver_name'])->toContain('Bravo Driver')
        ->and($merged['vehicle_plate_number'])->toContain('AAA-1111')
        ->and($merged['vehicle_plate_number'])->toContain('BBB-2222')
        ->and($merged['driver_contact_number'])->toContain('09171110001')
        ->and($merged['driver_contact_number'])->toContain('09172220002')
        ->and($merged['release_witnessed_by'])->toContain('Witness One')
        ->and($merged['release_witnessed_by'])->toContain('Witness Two');

    $payload = app(RisDrDocumentPdfService::class)
        ->payloadFromSlip($slip->fresh(['dispatchPlan', 'allocationItems']));

    expect($payload['tracking']['driver_name'])->toBeNull()
        ->and($payload['tracking']['vehicle_plate_number'])->toBeNull()
        ->and($payload['tracking']['mode_of_transportation'])->toBe([]);
});

it('allows skipping in_transit when marking received if departed_at is present', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->status)->toBe('released');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'has_returned_items' => 'No',
            'vehicle_details' => [plannedVehiclePayload([
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'departed_at' => now()->format('Y-m-d\TH:i'),
                'actual_arrival' => now()->format('Y-m-d\TH:i'),
                'fully_delivered' => 'Yes',
                'received_by' => 'LGU Receiver',
                'received_by_id_number' => 'LGU-ID-001',
                'received_by_position' => 'MSWDO',
                'received_by_office' => 'Butuan City LGU',
                'received_at' => now()->format('Y-m-d\TH:i'),
                'receiver_contact' => '09175556666',
                'receipt_acknowledged' => true,
            ])],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_name' => 'Dispatch Hub',
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
                'received_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    expect($dispatch->fresh()->status)->toBe('received');
});

it('requires source warehouse on vehicles when a plan has multiple allocated warehouses', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $satellite = Warehouse::create([
        'name' => 'Satellite Depot',
        'province' => 'Surigao del Norte',
        'municipality' => 'Surigao City',
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
            'items' => [
                [
                    'item_name' => 'Family Food Pack',
                    'unit' => 'boxes',
                    'warehouse_id' => $hub->id,
                    'warehouse_name' => $hub->name,
                    'allocated_quantity' => 5,
                ],
                [
                    'item_name' => 'Hygiene Kit',
                    'unit' => 'kits',
                    'warehouse_id' => $satellite->id,
                    'warehouse_name' => $satellite->name,
                    'allocated_quantity' => 3,
                ],
            ],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.source_warehouse_id']);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'source_warehouse_id' => $hub->id,
                    'source_warehouse_name' => $hub->name,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 5,
                    ]],
                ]),
                plannedVehiclePayload([
                    'driver' => 'Second Driver',
                    'vehicle_plate_number' => 'XYZ-9876',
                    'source_warehouse_id' => $satellite->id,
                    'source_warehouse_name' => $satellite->name,
                    'loaded_items' => [[
                        'item_name' => 'Hygiene Kit',
                        'loaded_quantity' => 3,
                    ]],
                ]),
            ],
            'items' => [
                [
                    'item_name' => 'Family Food Pack',
                    'unit' => 'boxes',
                    'warehouse_id' => $hub->id,
                    'warehouse_name' => $hub->name,
                    'allocated_quantity' => 5,
                ],
                [
                    'item_name' => 'Hygiene Kit',
                    'unit' => 'kits',
                    'warehouse_id' => $satellite->id,
                    'warehouse_name' => $satellite->name,
                    'allocated_quantity' => 3,
                ],
            ],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->vehicle_details)->toHaveCount(2)
        ->and($dispatch->vehicle_details[0]['source_warehouse_id'] ?? null)->toBe($hub->id)
        ->and($dispatch->vehicle_details[1]['source_warehouse_id'] ?? null)->toBe($satellite->id)
        ->and($dispatch->vehicle_details[0]['dr_number'] ?? null)->toBe('DR#-08-9101-A')
        ->and($dispatch->vehicle_details[1]['dr_number'] ?? null)->toBe('DR#-08-9101-B');

    $this->actingAs($rros)->get("/dispatches/{$dispatch->id}/vehicles/0/dr")->assertOk();
    $this->actingAs($rros)->get("/dispatches/{$dispatch->id}/vehicles/1/dr")->assertOk();
});

it('rejects loaded quantities from a different source warehouse than the vehicle assignment', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $satellite = Warehouse::create([
        'name' => 'Satellite Depot B',
        'province' => 'Agusan del Sur',
        'municipality' => 'Bayugan City',
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'loaded_items' => [[
                    'item_name' => 'Hygiene Kit',
                    'loaded_quantity' => 2,
                ]],
            ])],
            'items' => [
                [
                    'item_name' => 'Family Food Pack',
                    'unit' => 'boxes',
                    'warehouse_id' => $hub->id,
                    'warehouse_name' => $hub->name,
                    'allocated_quantity' => 5,
                ],
                [
                    'item_name' => 'Hygiene Kit',
                    'unit' => 'kits',
                    'warehouse_id' => $satellite->id,
                    'warehouse_name' => $satellite->name,
                    'allocated_quantity' => 3,
                ],
            ],
        ])
        ->assertSessionHasErrors([
            'vehicle_details.0.loaded_items.0.loaded_quantity',
        ]);
});

it('auto-assigns the sole source warehouse onto planned vehicles', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload()],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->vehicle_details[0]['source_warehouse_id'] ?? null)->toBe($hub->id)
        ->and($dispatch->vehicle_details[0]['source_warehouse_name'] ?? null)->toBe($hub->name);
});

it('allows planned status without vehicles when all allocations are receiving-LGU local stock', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $localWh = Warehouse::create([
        'name' => 'Butuan City LGU Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
        'warehouse_type' => 'LGU Prepositioned',
        'ownership' => 'LGU',
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 0,
            'vehicle_details' => [],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_id' => $localWh->id,
                'warehouse_name' => $localWh->name,
                'allocated_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->status)->toBe('planned')
        ->and($dispatch->vehicle_details ?? [])->toBe([]);

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 0,
            'vehicle_details' => [],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_id' => $localWh->id,
                'warehouse_name' => $localWh->name,
                'allocated_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    expect($dispatch->fresh()->status)->toBe('released');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'received',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 0,
            'vehicle_details' => [],
            'has_returned_items' => 'No',
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_id' => $localWh->id,
                'warehouse_name' => $localWh->name,
                'allocated_quantity' => 8,
                'received_quantity' => 8,
            ]],
        ])
        ->assertRedirect();

    expect($dispatch->fresh()->status)->toBe('received');
});

it('requires vehicles for remote DSWD warehouse allocations', function (): void {
    [$rros, $request] = prepareRisForDispatch();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 0,
            'vehicle_details' => [],
        ])
        ->assertSessionHasErrors(['vehicle_details']);
});

it('rejects vehicles assigned to a receiving-LGU warehouse', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $localWh = Warehouse::create([
        'name' => 'Butuan City LGU Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
        'warehouse_type' => 'LGU Prepositioned',
        'ownership' => 'LGU',
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $localWh->id,
                'source_warehouse_name' => $localWh->name,
            ])],
            'items' => [
                [
                    'item_name' => 'Family Food Pack',
                    'unit' => 'boxes',
                    'warehouse_id' => $hub->id,
                    'warehouse_name' => $hub->name,
                    'allocated_quantity' => 5,
                ],
                [
                    'item_name' => 'Hygiene Kit',
                    'unit' => 'kits',
                    'warehouse_id' => $localWh->id,
                    'warehouse_name' => $localWh->name,
                    'allocated_quantity' => 3,
                ],
            ],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.source_warehouse_id']);
});

it('requires a vehicle only for the remote warehouse on mixed local/remote plans', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $localWh = Warehouse::create([
        'name' => 'Butuan City LGU Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
        'warehouse_type' => 'LGU Prepositioned',
        'ownership' => 'LGU',
    ]);

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'loaded_items' => [[
                    'item_name' => 'Family Food Pack',
                    'loaded_quantity' => 5,
                ]],
            ])],
            'items' => [
                [
                    'item_name' => 'Family Food Pack',
                    'unit' => 'boxes',
                    'warehouse_id' => $hub->id,
                    'warehouse_name' => $hub->name,
                    'allocated_quantity' => 5,
                ],
                [
                    'item_name' => 'Hygiene Kit',
                    'unit' => 'kits',
                    'warehouse_id' => $localWh->id,
                    'warehouse_name' => $localWh->name,
                    'allocated_quantity' => 3,
                ],
            ],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->status)->toBe('planned')
        ->and($dispatch->vehicle_details)->toHaveCount(1)
        ->and($dispatch->vehicle_details[0]['source_warehouse_id'] ?? null)->toBe($hub->id);
});

it('hard-blocks Confirm Release when estimated departure and arrival are different Manila calendar dates', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();

    $departure = now()->timezone('Asia/Manila')->format('Y-m-d\TH:i');
    $arrival = now()->timezone('Asia/Manila')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'estimated_departure' => $departure,
                'estimated_arrival' => $arrival,
            ])],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->status)->toBe('planned');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'estimated_departure' => $departure,
                'estimated_arrival' => $arrival,
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'loaded_items' => [[
                    'item_name' => 'Family Food Pack',
                    'loaded_quantity' => 8,
                ]],
            ])],
        ])
        ->assertSessionHasErrors(['vehicle_details.0.estimated_arrival']);

    expect($dispatch->fresh()->status)->toBe('planned');
});

it('allows Confirm Release across calendar dates when Multi-day run is checked', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();

    $departure = now()->timezone('Asia/Manila')->format('Y-m-d\TH:i');
    $arrival = now()->timezone('Asia/Manila')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'estimated_departure' => $departure,
                'estimated_arrival' => $arrival,
                'allows_multi_day_run' => true,
            ])],
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
                'estimated_departure' => $departure,
                'estimated_arrival' => $arrival,
                'allows_multi_day_run' => true,
                'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                'release_witnessed_by' => 'Witness Staff',
                'release_witness_id_number' => '21-001',
                'release_witness_position' => 'SWO III',
                'release_witness_office' => 'RROS',
                'loaded_items' => [[
                    'item_name' => 'Family Food Pack',
                    'loaded_quantity' => 8,
                ]],
            ])],
        ])
        ->assertRedirect();

    expect($dispatch->fresh()->status)->toBe('released')
        ->and($dispatch->fresh()->vehicle_details[0]['allows_multi_day_run'] ?? false)->toBeTrue();
});

it('records created_by and status history with from→to on create and update', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'draft',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
        ])
        ->assertRedirect();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->created_by)->toBe($rros->id)
        ->and($dispatch->updated_by)->toBe($rros->id)
        ->and($dispatch->status_timeline)->not->toBeEmpty();

    $created = collect($dispatch->status_timeline)->first();
    expect($created['status'] ?? null)->toBe('draft')
        ->and($created['to_status'] ?? null)->toBe('draft')
        ->and($created['by'] ?? null)->toBe($rros->id)
        ->and($created['by_name'] ?? null)->toBe($rros->name)
        ->and($created['note'] ?? null)->toBe('Dispatch plan created');

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'number_of_vehicles' => 1,
            'vehicle_details' => [plannedVehiclePayload([
                'source_warehouse_id' => $hub->id,
                'source_warehouse_name' => $hub->name,
            ])],
        ])
        ->assertRedirect();

    $dispatch = $dispatch->fresh();
    $last = collect($dispatch->status_timeline)->last();
    expect($dispatch->status)->toBe('planned')
        ->and($last['from_status'] ?? null)->toBe('draft')
        ->and($last['to_status'] ?? null)->toBe('planned')
        ->and($last['by'] ?? null)->toBe($rros->id)
        ->and($last['note'] ?? null)->toBe('Status updated');

    $this->actingAs($rros)
        ->get("/dispatches?bucket=in_progress&dispatch_id={$dispatch->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Dispatches')
            ->where('selectedDispatch.created_by', $rros->id)
            ->where('selectedDispatch.created_by_name', $rros->name)
            ->has('selectedDispatch.history')
            ->where('selectedDispatch.history.0.by_name', $rros->name));
});

it('persists LGU witness office as receiving LGU identity and shared DR remarks across vehicles', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $request->forceFill([
        'lgu' => 'Tubod',
        'municipality' => 'Tubod',
        'province' => 'Surigao del Norte',
        'lgu_level' => 'MLGU',
        'requesting_agency' => 'MLGU - Tubod, SDN',
    ])->save();
    $request->requisitionIssuanceSlip()->update([
        'recipient' => 'MLGU - Tubod, SDN',
    ]);

    $hub = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $hub->forceFill([
        'office' => 'CARAGA',
        'municipality' => 'Butuan City',
        'province' => 'Agusan del Norte',
    ])->save();

    $sharedRemarks = 'Shared DR remarks for all vehicles';
    $lguOffice = 'MLGU - Tubod, SDN';

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Tubod Municipal Hall',
            'receiving_agency_lgu' => $lguOffice,
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'source_warehouse_id' => $hub->id,
                    'source_warehouse_name' => $hub->name,
                    'vehicle_plate_number' => 'AAA-1111',
                    'mode_of_transportation' => 'Partner LGU',
                    'land_transportation_source' => 'PARTNER - LGU VEHICLE',
                    'release_witness_affiliation' => 'lgu',
                    'loading_remarks' => $sharedRemarks,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 4,
                        'planned_quantity' => 4,
                    ]],
                ]),
                plannedVehiclePayload([
                    'source_warehouse_id' => $hub->id,
                    'source_warehouse_name' => $hub->name,
                    'vehicle_plate_number' => 'BBB-2222',
                    'driver' => 'Pedro Driver',
                    'mode_of_transportation' => 'Partner LGU',
                    'land_transportation_source' => 'PARTNER - LGU VEHICLE',
                    'release_witness_affiliation' => 'lgu',
                    'loading_remarks' => $sharedRemarks,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 4,
                        'planned_quantity' => 4,
                    ]],
                ]),
            ],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_id' => $hub->id,
                'warehouse_name' => $hub->name,
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();

    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'released',
            'destination' => 'Tubod Municipal Hall',
            'receiving_agency_lgu' => $lguOffice,
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'source_warehouse_id' => $hub->id,
                    'source_warehouse_name' => $hub->name,
                    'vehicle_plate_number' => 'AAA-1111',
                    'mode_of_transportation' => 'Partner LGU',
                    'land_transportation_source' => 'PARTNER - LGU VEHICLE',
                    'release_witness_affiliation' => 'lgu',
                    'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                    'release_witnessed_by' => 'LGU Witness',
                    'release_witness_id_number' => 'LGU-001',
                    'release_witness_position' => 'MSWDO',
                    'release_witness_office' => $lguOffice,
                    'loading_remarks' => $sharedRemarks,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 4,
                        'planned_quantity' => 4,
                    ]],
                ]),
                plannedVehiclePayload([
                    'source_warehouse_id' => $hub->id,
                    'source_warehouse_name' => $hub->name,
                    'vehicle_plate_number' => 'BBB-2222',
                    'driver' => 'Pedro Driver',
                    'mode_of_transportation' => 'Partner LGU',
                    'land_transportation_source' => 'PARTNER - LGU VEHICLE',
                    'release_witness_affiliation' => 'lgu',
                    'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
                    'release_witnessed_by' => 'LGU Witness',
                    'release_witness_id_number' => 'LGU-001',
                    'release_witness_position' => 'MSWDO',
                    'release_witness_office' => $lguOffice,
                    'loading_remarks' => $sharedRemarks,
                    'loaded_items' => [[
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 4,
                        'planned_quantity' => 4,
                    ]],
                ]),
            ],
            'items' => [[
                'item_name' => 'Family Food Pack',
                'unit' => 'boxes',
                'warehouse_id' => $hub->id,
                'warehouse_name' => $hub->name,
                'allocated_quantity' => 8,
                'loaded_quantity' => 8,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch->refresh();
    expect($dispatch->status)->toBe('released')
        ->and($dispatch->vehicle_details[0]['release_witness_office'] ?? null)->toBe($lguOffice)
        ->and($dispatch->vehicle_details[0]['release_witness_office'] ?? null)->not->toBe('CARAGA')
        ->and($dispatch->vehicle_details[1]['release_witness_office'] ?? null)->toBe($lguOffice)
        ->and($dispatch->vehicle_details[0]['loading_remarks'] ?? null)->toBe($sharedRemarks)
        ->and($dispatch->vehicle_details[1]['loading_remarks'] ?? null)->toBe($sharedRemarks);
});

it('releases one warehouse at a time without blocking the other, then flips to released', function (): void {
    [$rros, $request] = prepareRisForDispatch();
    $hubA = Warehouse::query()->where('name', 'Dispatch Hub')->firstOrFail();
    $hubA->forceFill(['office' => 'CARAGA'])->save();

    $hubB = Warehouse::create([
        'name' => 'Satellite Hub',
        'province' => 'Surigao del Norte',
        'municipality' => 'Surigao City',
        'warehouse_type' => 'Satellite Warehouse',
        'ownership' => 'Owned',
        'office' => 'CARAGA',
    ]);

    $inventoryItem = InventoryItem::firstOrCreate(
        ['name' => 'Family Food Pack'],
        ['category' => 'food', 'unit' => 'boxes', 'status' => 'active'],
    );
    $batchB = InventoryBatch::create([
        'inventory_item_id' => $inventoryItem->id,
        'warehouse_id' => $hubB->id,
        'batch_number' => 'TEST-FFP-SAT-01',
        'brand_description' => 'Prepacked',
        'quantity' => 100,
        'reserved_quantity' => 0,
        'expiration_date' => now()->addYear()->endOfMonth()->toDateString(),
        'date_received' => now()->subMonth()->toDateString(),
        'source' => 'FO Stockpile/Prepo',
        'current_status' => 'available',
    ]);
    $batchB->transactions()->create([
        'type' => 'receipt',
        'transaction_date' => now()->subMonth()->toDateString(),
        'quantity' => 100,
        'unit_cost' => 500,
        'total_cost' => 50000,
        'balance_after' => 100,
    ]);

    $slip = $request->requisitionIssuanceSlip()->with('allocationItems')->firstOrFail();
    $allocA = $slip->allocationItems->firstOrFail();
    $allocA->forceFill([
        'quantity' => 4,
        'warehouse_id' => $hubA->id,
        'warehouse_name' => $hubA->name,
    ])->save();
    $allocB = $slip->allocationItems()->create([
        'request_item_id' => $allocA->request_item_id,
        'item_name' => 'Family Food Pack',
        'unit' => 'boxes',
        'quantity' => 4,
        'warehouse_id' => $hubB->id,
        'warehouse_name' => $hubB->name,
        'brand_description' => 'Prepacked',
        'expiry' => $allocA->expiry,
    ]);

    $sharedRemarks = 'Augmentation for Tubod';
    $releaseA = plannedVehiclePayload([
        'source_warehouse_id' => $hubA->id,
        'source_warehouse_name' => $hubA->name,
        'vehicle_plate_number' => 'WH-A-001',
        'mode_of_transportation' => 'Partner LGU',
        'land_transportation_source' => 'PARTNER - LGU VEHICLE',
        'release_witness_affiliation' => 'dswd',
        'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
        'release_witnessed_by' => 'Witness A',
        'release_witness_id_number' => '21-A01',
        'release_witness_position' => 'SWO III',
        'release_witness_office' => 'RROS',
        'loading_remarks' => $sharedRemarks,
        'loaded_items' => [[
            'requisition_issuance_item_id' => $allocA->id,
            'item_name' => 'Family Food Pack',
            'loaded_quantity' => 4,
            'planned_quantity' => 4,
        ]],
    ]);
    $plannedB = plannedVehiclePayload([
        'source_warehouse_id' => $hubB->id,
        'source_warehouse_name' => $hubB->name,
        'vehicle_plate_number' => 'WH-B-002',
        'driver' => 'Driver B',
        'mode_of_transportation' => 'Partner LGU',
        'land_transportation_source' => 'PARTNER - LGU VEHICLE',
        'release_witness_affiliation' => 'dswd',
        'loading_remarks' => $sharedRemarks,
        'loaded_items' => [[
            'requisition_issuance_item_id' => $allocB->id,
            'item_name' => 'Family Food Pack',
            'loaded_quantity' => 4,
            'planned_quantity' => 4,
        ]],
    ]);

    $itemsPayload = [
        [
            'requisition_issuance_item_id' => $allocA->id,
            'item_name' => 'Family Food Pack',
            'unit' => 'boxes',
            'warehouse_id' => $hubA->id,
            'warehouse_name' => $hubA->name,
            'allocated_quantity' => 4,
            'loaded_quantity' => 4,
        ],
        [
            'requisition_issuance_item_id' => $allocB->id,
            'item_name' => 'Family Food Pack',
            'unit' => 'boxes',
            'warehouse_id' => $hubB->id,
            'warehouse_name' => $hubB->name,
            'allocated_quantity' => 4,
            'loaded_quantity' => 4,
        ],
    ];

    $this->actingAs($rros)
        ->post('/dispatches', [
            'request_id' => $request->id,
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'vehicle_details' => [
                plannedVehiclePayload([
                    'source_warehouse_id' => $hubA->id,
                    'source_warehouse_name' => $hubA->name,
                    'vehicle_plate_number' => 'WH-A-001',
                    'mode_of_transportation' => 'Partner LGU',
                    'land_transportation_source' => 'PARTNER - LGU VEHICLE',
                    'loading_remarks' => $sharedRemarks,
                    'loaded_items' => [[
                        'requisition_issuance_item_id' => $allocA->id,
                        'item_name' => 'Family Food Pack',
                        'loaded_quantity' => 4,
                        'planned_quantity' => 4,
                    ]],
                ]),
                $plannedB,
            ],
            'items' => $itemsPayload,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch = DispatchPlan::query()->where('request_id', $request->id)->firstOrFail();
    expect($dispatch->status)->toBe('planned')
        ->and($dispatch->vehicle_details)->toHaveCount(2);

    // Release warehouse A only — plan stays planned; inventory only for A.
    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'release_vehicle_indexes' => [0],
            'vehicle_details' => [$releaseA, $plannedB],
            'items' => $itemsPayload,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch->refresh();
    $issuances = InventoryTransaction::query()
        ->where('transactionable_type', DispatchPlan::class)
        ->where('transactionable_id', $dispatch->id)
        ->where('type', 'release')
        ->get();

    expect($dispatch->status)->toBe('planned')
        ->and(filled($dispatch->vehicle_details[0]['warehouse_released_at'] ?? null))->toBeTrue()
        ->and(filled($dispatch->vehicle_details[1]['warehouse_released_at'] ?? null))->toBeFalse()
        ->and($issuances)->toHaveCount(1)
        ->and($issuances->first()->reference_number)->toBe($dispatch->vehicle_details[0]['dr_number'])
        ->and((float) $issuances->first()->quantity)->toBe(4.0)
        ->and($request->requisitionIssuanceSlip->fresh()->reservation_status)->not->toBe('released');

    // Re-confirming warehouse A while still planned must not double-post inventory.
    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'release_vehicle_indexes' => [0],
            'vehicle_details' => [$releaseA, $plannedB],
            'items' => $itemsPayload,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(
        InventoryTransaction::query()
            ->where('transactionable_type', DispatchPlan::class)
            ->where('transactionable_id', $dispatch->id)
            ->where('type', 'release')
            ->count()
    )->toBe(1);

    $releaseB = [
        ...$plannedB,
        'warehouse_released_at' => now()->format('Y-m-d\TH:i'),
        'release_witnessed_by' => 'Witness B',
        'release_witness_id_number' => '21-B02',
        'release_witness_position' => 'SWO II',
        'release_witness_office' => 'RROS',
    ];

    // Completing warehouse B promotes the plan to released and posts B's issuance.
    $this->actingAs($rros)
        ->put("/dispatches/{$dispatch->id}", [
            'status' => 'planned',
            'destination' => 'Butuan City Hall',
            'receiving_agency_lgu' => 'Butuan City LGU',
            'source_of_goods' => 'FO Stockpile/Prepo',
            'purpose' => 'Relief Augmentation',
            'number_of_vehicles' => 2,
            'release_vehicle_indexes' => [1],
            'vehicle_details' => [$releaseA, $releaseB],
            'items' => $itemsPayload,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dispatch->refresh();
    $issuances = InventoryTransaction::query()
        ->where('transactionable_type', DispatchPlan::class)
        ->where('transactionable_id', $dispatch->id)
        ->where('type', 'release')
        ->orderBy('id')
        ->get();

    expect($dispatch->status)->toBe('released')
        ->and(filled($dispatch->vehicle_details[1]['warehouse_released_at'] ?? null))->toBeTrue()
        ->and($issuances)->toHaveCount(2)
        ->and($issuances->pluck('reference_number')->all())->toEqual([
            $dispatch->vehicle_details[0]['dr_number'],
            $dispatch->vehicle_details[1]['dr_number'],
        ])
        ->and($request->requisitionIssuanceSlip->fresh()->reservation_status)->toBe('released');
});
