<?php

use App\Models\AssistanceRequest;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryBalanceService;
use App\Services\RisReservationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('lets RROS create a RIS only for signed approved assessment items', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $rrosAa = User::where('email', 'rros-aa@example.test')->firstOrFail();
    Storage::fake('public');
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-TEST',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Test Representative',
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

    $this->actingAs($user)->post("/rros/requests/{$request->id}/ris", [
        'ris_number' => 'RIS-2026-00001',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'status' => 'draft',
        'rds_file' => UploadedFile::fake()->create('rds.pdf', 100, 'application/pdf'),
        'csmr_file' => UploadedFile::fake()->create('csmr.pdf', 100, 'application/pdf'),
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-001', 'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident', 'incident_specification' => 'Test fire',
            'ris_number_prefix' => 'RIS-CRG-2026-08-', 'dr_number_prefix' => 'DR#-08-', 'counter' => '0001',
            'dr_number' => 'DR#-08-0001', 'ris_drn' => '', 'item_category' => 'Family Food Packs',
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => null,
            'warehouse_name' => null,
            'quantity' => 8,
        ]],
    ])->assertRedirect()->assertSessionHas('success', 'Draft saved');

    $this->assertDatabaseHas('requisition_issuance_slips', [
        'request_id' => $request->id,
        'ris_number' => 'RIS-2026-00001',
        'status' => 'draft',
        'prepared_by' => $user->id,
    ]);
    $slip = $request->requisitionIssuanceSlip()->firstOrFail();
    expect(blank($slip->ris_drn))->toBeTrue();
    $this->actingAs($user)
        ->get("/rros/ris/{$slip->id}/documents/ris")
        ->assertStatus(403);
    // RROS (not only RROS AA) can assign the RIS DRN — no separate unused RROS tier required.
    $this->actingAs($user)
        ->patchJson(route('rros.ris.drn', $slip), [
            'ris_drn' => 'CARAGA-FO-DRMD-RROS-A-REQ-'.$slip->ris_date->format('y-m-').'0001',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
    expect($slip->fresh()->ris_drn)->toBe('CARAGA-FO-DRMD-RROS-A-REQ-'.$slip->ris_date->format('y-m-').'0001');
    $this->actingAs($rrosAa)
        ->patchJson(route('rros.ris.drn', $slip), [
            'ris_drn' => 'CARAGA-FO-DRMD-RROS-A-REQ-'.$slip->ris_date->format('y-m-').'0002',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
    expect($slip->fresh()->ris_drn)->toBe('CARAGA-FO-DRMD-RROS-A-REQ-'.$slip->ris_date->format('y-m-').'0002');
    Storage::disk('public')->assertExists($slip->rds_path);
    Storage::disk('public')->assertExists($slip->csmr_path);
    // Advance DomPDF preview works for draft slips (Document Preview iframe parity).
    $slip = $slip->fresh();
    $expectedPreviewName = $slip->ris_drn.'.pdf';
    $risPdf = $this->actingAs($user)->get("/rros/ris/{$slip->id}/preview-pdf/ris?inline=1");
    $risPdf->assertOk();
    expect(str_contains(strtolower((string) $risPdf->headers->get('content-type')), 'pdf'))->toBeTrue();
    expect(str_starts_with($risPdf->getContent(), '%PDF'))->toBeTrue();
    expect((string) $risPdf->headers->get('Content-Disposition'))
        ->toContain('inline')
        ->toContain($expectedPreviewName);
    $drPdf = $this->actingAs($user)->get("/rros/ris/{$slip->id}/preview-pdf/dr?inline=1");
    $drPdf->assertOk();
    expect(str_contains(strtolower((string) $drPdf->headers->get('content-type')), 'pdf'))->toBeTrue();
    expect(str_starts_with($drPdf->getContent(), '%PDF'))->toBeTrue();
    expect((string) $drPdf->headers->get('Content-Disposition'))
        ->toContain('inline')
        ->toContain($expectedPreviewName);
    // Draft slips cannot download uploaded documents; prepare first.
    $slip->forceFill(['status' => 'prepared'])->save();
    $this->actingAs($user)->get("/rros/ris/{$slip->id}/documents/rds")->assertOk();
});

it('persists a draft without transport fields and returns Draft saved flash', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-DRAFT-PARTIAL',
        'requesting_agency' => 'Test LGU',
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

    $this->actingAs($user)->post("/rros/requests/{$request->id}/ris", [
        'ris_number' => 'RIS-DRAFT-PARTIAL-0001',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'status' => 'draft',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-DRAFT',
            'purpose_of_request' => 'Relief Augmentation',
            'dr_number' => 'DR#-08-7701',
            'ris_drn' => '',
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => null,
            'quantity' => 4,
        ]],
    ])->assertRedirect()->assertSessionHas('success', 'Draft saved');

    $slip = $request->requisitionIssuanceSlip()->firstOrFail();
    expect($slip->status)->toBe('draft');

    // Updating an existing draft must not require post-RIS transport encoding.
    $this->actingAs($user)->post("/rros/requests/{$request->id}/ris", [
        'ris_number' => $slip->ris_number,
        'ris_date' => $slip->ris_date->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU Updated',
        'status' => 'draft',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-DRAFT',
            'purpose_of_request' => 'Relief Augmentation',
            'dr_number' => 'DR#-08-7701',
            'ris_drn' => '',
            'mode_of_transportation' => [],
            'number_of_vehicles' => null,
            'vehicle_type' => [],
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => null,
            'quantity' => 5,
        ]],
    ])->assertRedirect()->assertSessionHas('success', 'Draft saved');

    expect($slip->fresh()->recipient)->toBe('Test LGU Updated');
    expect((int) $slip->fresh()->allocationItems()->sum('quantity'))->toBe(5);
});

it('requires a complete RIS DRN sequence when generating a prepared slip', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-DRN-REQUIRED',
        'requesting_agency' => 'Test LGU',
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

    $payload = [
        'ris_number' => 'RIS-DRN-REQ-0001',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'status' => 'prepared',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-REQ',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-0101',
            'ris_drn' => '',
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => null,
            'warehouse_name' => null,
            'quantity' => 8,
        ]],
    ];

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", $payload)
        ->assertSessionHasErrors(['tracking_data.ris_drn']);

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            ...$payload,
            'tracking_data' => [
                ...$payload['tracking_data'],
                'ris_drn' => $prefix,
            ],
        ])
        ->assertSessionHasErrors(['tracking_data.ris_drn']);

    // RROS may assign a full DRN on draft create; prepare still requires warehouse allocation separately.
    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            ...$payload,
            'status' => 'draft',
            'tracking_data' => [
                ...$payload['tracking_data'],
                'ris_drn' => $prefix.'0001',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($request->requisitionIssuanceSlip()->firstOrFail()->ris_drn)->toBe($prefix.'0001');
});

it('rejects an RIS quantity above the signed assessment approval', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-LIMIT',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Representative',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'assessment_status' => 'submitted',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);
    $item = $request->items()->create(['item_name' => 'Family Food Pack', 'requested_quantity' => 10, 'approved_quantity' => 5, 'unit' => 'boxes']);

    $this->actingAs($user)->post("/rros/requests/{$request->id}/ris", [
        'ris_number' => 'RIS-2026-00002', 'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation', 'recipient' => 'Test LGU', 'status' => 'draft',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-002', 'purpose_of_request' => 'Relief Augmentation',
            'ris_number_prefix' => 'RIS-CRG-2026-08-', 'dr_number_prefix' => 'DR#-08-', 'counter' => '0002',
            'dr_number' => 'DR#-08-0002', 'ris_drn' => '', 'item_category' => 'Family Food Packs',
        ],
        'items' => [
            ['request_item_id' => $item->id, 'item_name' => $item->item_name, 'unit' => $item->unit, 'quantity' => 3],
            ['request_item_id' => $item->id, 'item_name' => $item->item_name, 'unit' => $item->unit, 'quantity' => 3],
        ],
    ])->assertStatus(422);

    $this->assertDatabaseMissing('requisition_issuance_slips', ['request_id' => $request->id]);
});

it('generates sequential RIS numbers per month and preserves an existing number', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    $makeRequest = fn (string $reference) => AssistanceRequest::create([
        'reference_number' => $reference,
        'requesting_agency' => 'Test LGU',
        'requester' => 'Representative',
        'date_requested' => '2026-08-01',
        'status' => 'acted',
        'assessment_status' => 'submitted',
        'endorsed_to_drrs' => true,
        'submission_type' => 'fni_request',
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);

    $first = $makeRequest('REQ-RIS-NUMBER-1');
    $second = $makeRequest('REQ-RIS-NUMBER-2');
    $nextRequest = $makeRequest('REQ-RIS-NUMBER-3');

    foreach ([[$first, '0001'], [$second, '0004']] as [$assistanceRequest, $counter]) {
        RequisitionIssuanceSlip::create([
            'request_id' => $assistanceRequest->id,
            'prepared_by' => $user->id,
            'ris_number' => "RIS-CRG-2026-08-{$counter}",
            'dr_number' => "DR#-08-{$counter}",
            'ris_date' => '2026-08-04',
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Test LGU',
            'items' => [],
            'tracking_data' => [],
            'status' => 'draft',
        ]);
    }

    $this->actingAs($user)
        ->getJson("/rros/requests/{$nextRequest->id}/ris-next-number?date=2026-08-05")
        ->assertOk()
        ->assertJson(['ris_number' => 'RIS-CRG-2026-08-0005', 'dr_number' => 'DR#-08-0005']);

    $this->actingAs($user)
        ->getJson("/rros/requests/{$nextRequest->id}/ris-next-number?date=2026-09-01")
        ->assertOk()
        ->assertJson(['ris_number' => 'RIS-CRG-2026-09-0001', 'dr_number' => 'DR#-09-0001']);

    $this->actingAs($user)
        ->getJson("/rros/requests/{$first->id}/ris-next-number?date=2026-08-10")
        ->assertOk()
        ->assertJson(['ris_number' => 'RIS-CRG-2026-08-0001', 'dr_number' => 'DR#-08-0001']);
});

it('holds RIS allocations for planning until WIT issuance is confirmed and released', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $warehouse = Warehouse::create([
        'name' => 'Test Reservation Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-RESERVATION',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Representative',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'submission_type' => 'fni_request',
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-RESERVATION-1',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'prepared',
        'reservation_status' => 'active',
        'fully_delivered' => true,
    ]);
    $slip->allocationItems()->create([
        'warehouse_id' => $warehouse->id,
        'item_name' => 'Family Food Pack',
        'unit' => 'box',
        'quantity' => 25,
    ]);

    $key = $warehouse->id.'|familyfoodpack';
    expect(app(RisReservationService::class)->totals()->get($key))->toBe(25.0);

    $this->actingAs($user)
        ->patch("/rros/ris/{$slip->id}/cancel")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(app(RisReservationService::class)->totals()->has($key))->toBeFalse();
    expect($slip->fresh()->status)->toBe('prepared');
    expect($slip->fresh()->reservation_status)->toBe('released');
});

it('does not require transport fields on post-RIS updates (dispatch owns logistics)', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-TRANSPORT',
        'requesting_agency' => 'Test LGU',
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
    $warehouse = Warehouse::create([
        'name' => 'Transport Test Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $balances = Mockery::mock(InventoryBalanceService::class);
    $balances->shouldReceive('balanceRows')->andReturn(collect([[
        'warehouse_id' => $warehouse->id,
        'item' => 'Family Food Pack',
        'available_balance' => 100,
    ]]));
    app()->instance(InventoryBalanceService::class, $balances);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-TRANSPORT-0001',
        'dr_number' => 'DR#-08-9001',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9001',
            'ris_drn' => '',
        ],
        'status' => 'prepared',
        'reservation_status' => 'released',
    ]);
    $slip->allocationItems()->create([
        'request_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_name' => $warehouse->name,
        'item_name' => $item->item_name,
        'unit' => $item->unit,
        'quantity' => 8,
    ]);

    $payload = [
        'ris_number' => $slip->ris_number,
        'ris_date' => $slip->ris_date->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'status' => 'draft',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9001',
            'ris_drn' => '',
            'mode_of_transportation' => [],
            'number_of_vehicles' => null,
            'vehicle_type' => [],
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
    ];

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", $payload)
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionDoesntHaveErrors([
            'tracking_data.mode_of_transportation',
            'tracking_data.number_of_vehicles',
            'tracking_data.vehicle_type',
        ]);

    // Client may still send transport keys; RIS create/update must ignore them.
    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            ...$payload,
            'tracking_data' => [
                ...$payload['tracking_data'],
                'mode_of_transportation' => ['DSWD-Owned', 'Partner LGU'],
                'number_of_vehicles' => 2,
                'vehicle_type' => ['6 Wheeler Wing Van', 'Elf'],
                'driver_name' => 'Should Not Persist',
                'vehicle_plate_number' => 'ZZZ-9999',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $updated = $slip->fresh();
    expect(data_get($updated->tracking_data, 'mode_of_transportation'))->toBeNull();
    expect(data_get($updated->tracking_data, 'number_of_vehicles'))->toBeNull();
    expect(data_get($updated->tracking_data, 'vehicle_type'))->toBeNull();
    expect(data_get($updated->tracking_data, 'driver_name'))->toBeNull();
    expect(data_get($updated->tracking_data, 'vehicle_plate_number'))->toBeNull();
    expect($updated->driver_name)->toBeNull();
    expect($updated->vehicle_plate_number)->toBeNull();
});

it('does not overwrite legacy RIS transport tracking from form payload', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-TRANSPORT-PARTNER',
        'requesting_agency' => 'Test LGU',
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
    $warehouse = Warehouse::create([
        'name' => 'Partner Transport Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-TRANSPORT-0003',
        'dr_number' => 'DR#-08-9003',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'driver_name' => 'Legacy Driver',
        'vehicle_plate_number' => 'LEG-0001',
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT-PARTNER',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9003',
            'ris_drn' => '',
            'mode_of_transportation' => ['Partner'],
            'number_of_vehicles' => 1,
            'vehicle_type' => ['Elf'],
        ],
        'status' => 'prepared',
        'reservation_status' => 'released',
    ]);
    $slip->allocationItems()->create([
        'request_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_name' => $warehouse->name,
        'item_name' => $item->item_name,
        'unit' => $item->unit,
        'quantity' => 8,
    ]);

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            'ris_number' => $slip->ris_number,
            'ris_date' => $slip->ris_date->toDateString(),
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Test LGU',
            'delivery_site' => 'Butuan City',
            'status' => 'draft',
            'tracking_data' => [
                'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT-PARTNER',
                'purpose_of_request' => 'Relief Augmentation',
                'incident_type' => 'Fire Incident',
                'incident_specification' => 'Test fire',
                'dr_number' => 'DR#-08-9003',
                'ris_drn' => '',
                'mode_of_transportation' => ['DSWD-Owned'],
                'number_of_vehicles' => 9,
                'vehicle_type' => ['Wing Van'],
                'driver_name' => 'Form Driver',
                'vehicle_plate_number' => 'NEW-9999',
            ],
            'items' => [[
                'request_item_id' => $item->id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_id' => null,
                'warehouse_name' => null,
                'quantity' => 8,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $fresh = $slip->fresh();
    // Legacy tracking keys preserved; form payload ignored for transport.
    expect(data_get($fresh->tracking_data, 'mode_of_transportation'))->toBe(['Partner']);
    expect(data_get($fresh->tracking_data, 'number_of_vehicles'))->toBe(1);
    expect(data_get($fresh->tracking_data, 'vehicle_type'))->toBe(['Elf']);
    expect($fresh->driver_name)->toBe('Legacy Driver');
    expect($fresh->vehicle_plate_number)->toBe('LEG-0001');
});

it('ignores legacy single-string transport fields on RIS update', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-TRANSPORT-LEGACY',
        'requesting_agency' => 'Test LGU',
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
    $warehouse = Warehouse::create([
        'name' => 'Transport Legacy Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-TRANSPORT-LEGACY-0001',
        'dr_number' => 'DR#-08-9002',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT-LEGACY',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9002',
            'ris_drn' => '',
        ],
        'status' => 'prepared',
        'reservation_status' => 'released',
    ]);
    $slip->allocationItems()->create([
        'request_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_name' => $warehouse->name,
        'item_name' => $item->item_name,
        'unit' => $item->unit,
        'quantity' => 8,
    ]);

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            'ris_number' => $slip->ris_number,
            'ris_date' => $slip->ris_date->toDateString(),
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Test LGU',
            'delivery_site' => 'Butuan City',
            'status' => 'draft',
            'tracking_data' => [
                'assessment_drn_for_ris' => 'ASSESS-DRN-TRANSPORT-LEGACY',
                'purpose_of_request' => 'Relief Augmentation',
                'incident_type' => 'Fire Incident',
                'incident_specification' => 'Test fire',
                'dr_number' => 'DR#-08-9002',
                'ris_drn' => '',
                'mode_of_transportation' => 'DSWD-Owned',
                'no_of_vehicles' => 1,
                'vehicle_type' => '6 Wheeler Wing Van',
            ],
            'items' => [[
                'request_item_id' => $item->id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_id' => null,
                'warehouse_name' => null,
                'quantity' => 8,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $updated = $slip->fresh();
    expect(data_get($updated->tracking_data, 'mode_of_transportation'))->toBeNull();
    expect(data_get($updated->tracking_data, 'number_of_vehicles'))->toBeNull();
    expect(data_get($updated->tracking_data, 'vehicle_type'))->toBeNull();
});

it('requires complete post RIS fields before approving', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    Storage::fake('public');
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-POST-REQUIRED',
        'requesting_agency' => 'Test LGU',
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
    $warehouse = Warehouse::create([
        'name' => 'Post Required Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $balances = Mockery::mock(InventoryBalanceService::class);
    $balances->shouldReceive('balanceRows')->andReturn(collect([[
        'warehouse_id' => $warehouse->id,
        'item' => 'Family Food Pack',
        'available_balance' => 100,
    ]]));
    app()->instance(InventoryBalanceService::class, $balances);
    $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.now()->format('y-m').'-';
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-POST-REQUIRED-0001',
        'dr_number' => 'DR#-08-9100',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-POST-REQ',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9100',
            'ris_drn' => $prefix.'9100',
        ],
        'ris_drn' => $prefix.'9100',
        'status' => 'prepared',
        'reservation_status' => 'active',
    ]);
    $slip->allocationItems()->create([
        'request_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_name' => $warehouse->name,
        'item_name' => $item->item_name,
        'unit' => $item->unit,
        'quantity' => 8,
    ]);

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            'ris_number' => $slip->ris_number,
            'ris_date' => $slip->ris_date->toDateString(),
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Test LGU',
            'delivery_site' => 'Butuan City',
            'status' => 'approved',
            'tracking_data' => [
                'assessment_drn_for_ris' => 'ASSESS-DRN-POST-REQ',
                'purpose_of_request' => 'Relief Augmentation',
                'incident_type' => 'Fire Incident',
                'incident_specification' => 'Test fire',
                'dr_number' => 'DR#-08-9100',
                'ris_drn' => $prefix.'9100',
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
        ->assertSessionHasErrors([
            'tracking_data.ardo_endorsed_at',
            'tracking_data.ardo_returned_at',
            'tracking_data.forwarded_to_accounting',
            'remarks',
        ]);

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            'ris_number' => $slip->ris_number,
            'ris_date' => $slip->ris_date->toDateString(),
            'purpose_of_release' => 'Relief augmentation',
            'recipient' => 'Test LGU',
            'delivery_site' => 'Butuan City',
            'status' => 'approved',
            'post_ris_section' => 'endorsement',
            'tracking_data' => [
                'assessment_drn_for_ris' => 'ASSESS-DRN-POST-REQ',
                'purpose_of_request' => 'Relief Augmentation',
                'incident_type' => 'Fire Incident',
                'incident_specification' => 'Test fire',
                'dr_number' => 'DR#-08-9100',
                'ris_drn' => $prefix.'9100',
                'ardo_endorsed_at' => now()->subDay()->toDateString(),
                'ardo_returned_at' => now()->toDateString(),
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
        ->assertSessionHasErrors(['tracking_data.ardo_endorsed_at']);

    expect($slip->fresh()->status)->toBe('prepared');
});

it('saves accounting without uploads and completes only after all uploads are supplied', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    Storage::fake('public');
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-POST-APPROVED',
        'requesting_agency' => 'Test LGU',
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
    $warehouse = Warehouse::create([
        'name' => 'Post Approve Warehouse',
        'province' => 'Agusan del Norte',
        'municipality' => 'Butuan City',
    ]);
    $balances = Mockery::mock(InventoryBalanceService::class);
    $balances->shouldReceive('balanceRows')->andReturn(collect([[
        'warehouse_id' => $warehouse->id,
        'item' => 'Family Food Pack',
        'available_balance' => 100,
    ]]));
    app()->instance(InventoryBalanceService::class, $balances);
    $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.now()->format('y-m').'-';
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-POST-APPROVE-0001',
        'dr_number' => 'DR#-08-9101',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-POST',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9101',
            'ris_drn' => $prefix.'9101',
            'ardo_endorsed_at' => now()->toDateString(),
            'ardo_returned_at' => now()->toDateString(),
        ],
        'ris_drn' => $prefix.'9101',
        'ardo_endorsed_at' => now()->toDateString(),
        'ardo_returned_at' => now()->toDateString(),
        'status' => 'prepared',
        'reservation_status' => 'active',
    ]);
    $slip->allocationItems()->create([
        'request_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_name' => $warehouse->name,
        'item_name' => $item->item_name,
        'unit' => $item->unit,
        'quantity' => 8,
    ]);

    $postPayload = [
        'ris_number' => $slip->ris_number,
        'ris_date' => $slip->ris_date->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'delivery_site' => 'Butuan City',
        'remarks' => 'Post RIS complete',
        'status' => 'approved',
        'tracking_data' => [
            'assessment_drn_for_ris' => 'ASSESS-DRN-POST',
            'purpose_of_request' => 'Relief Augmentation',
            'incident_type' => 'Fire Incident',
            'incident_specification' => 'Test fire',
            'dr_number' => 'DR#-08-9101',
            'ris_drn' => $prefix.'9101',
            'ardo_endorsed_at' => now()->toDateString(),
            'ardo_returned_at' => now()->toDateString(),
            'forwarded_to_accounting' => 'Yes',
            'forwarded_to_accounting_at' => now()->toDateString(),
            'accounting_received_by' => 'Accounting Staff',
        ],
        'items' => [[
            'request_item_id' => $item->id,
            'item_name' => $item->item_name,
            'unit' => $item->unit,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'quantity' => 8,
        ]],
    ];

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", $postPayload)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($slip->fresh()->status)->toBe('approved');
    expect($slip->fresh()->reservation_status)->toBe('active');
    expect($slip->fresh()->hasCompletePostRisData())->toBeFalse();

    $this->actingAs($user)
        ->post("/rros/requests/{$request->id}/ris", [
            ...$postPayload,
            'status' => 'completed',
            'ris_dr_file' => UploadedFile::fake()->create('signed-ris-dr.pdf', 100, 'application/pdf'),
            'rds_file' => UploadedFile::fake()->create('rds.pdf', 100, 'application/pdf'),
            'csmr_file' => UploadedFile::fake()->create('csmr.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($slip->fresh()->status)->toBe('completed');
    expect($slip->fresh()->hasCompletePostRisData())->toBeTrue();
});

it('blocks WIT complete when post RIS data is incomplete', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-COMPLETE-BLOCK',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Representative',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'submission_type' => 'fni_request',
        'endorsed_to_drrs' => true,
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-COMPLETE-BLOCK-1',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'items' => [],
        'tracking_data' => [],
        'status' => 'approved',
        'reservation_status' => 'active',
    ]);

    $this->actingAs($user)
        ->patch("/rros/ris/{$slip->id}/cancel")
        ->assertStatus(422);

    expect($slip->fresh()->status)->toBe('approved');
    expect($slip->fresh()->reservation_status)->toBe('active');
});

it('completes an approved RIS when WIT reservation is released and post RIS is complete', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-RIS-COMPLETE',
        'requesting_agency' => 'Test LGU',
        'requester' => 'Representative',
        'date_requested' => now()->toDateString(),
        'status' => 'acted',
        'submission_type' => 'fni_request',
        'endorsed_to_drrs' => true,
        'epirma_assessment_signed_at' => now(),
        'epirma_response_letter_signed_at' => now(),
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id,
        'prepared_by' => $user->id,
        'ris_number' => 'RIS-COMPLETE-1',
        'ris_date' => now()->toDateString(),
        'purpose_of_release' => 'Relief augmentation',
        'recipient' => 'Test LGU',
        'remarks' => 'Ready for WIT complete',
        'items' => [],
        'tracking_data' => [
            'ardo_endorsed_at' => now()->subDays(3)->toDateString(),
            'ardo_returned_at' => now()->subDays(2)->toDateString(),
            'forwarded_to_accounting' => false,
        ],
        'ardo_endorsed_at' => now()->subDays(3)->toDateString(),
        'ardo_returned_at' => now()->subDays(2)->toDateString(),
        'forwarded_to_accounting' => false,
        'ris_dr_path' => 'ris-documents/1/ris-dr.pdf',
        'rds_path' => 'ris-documents/1/rds.pdf',
        'csmr_path' => 'ris-documents/1/csmr.pdf',
        'status' => 'approved',
        'reservation_status' => 'active',
    ]);

    expect($slip->hasCompletePostRisData())->toBeTrue();

    $this->actingAs($user)
        ->patch("/rros/ris/{$slip->id}/cancel")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($slip->fresh()->status)->toBe('completed');
    expect($slip->fresh()->reservation_status)->toBe('released');
});
