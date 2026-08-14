<?php

use App\Models\AssistanceRequest;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Services\GoogleSheetCsvService;
use App\Services\RisSheetSyncService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a JSON result when an RROS user synchronizes RIS and DR data', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(RisSheetSyncService::class);
    $sync->shouldReceive('run')->once()->with('manual', $user->id)->andReturn([
        'records_created' => 1,
        'records_updated' => 2,
        'items_synced' => 3,
    ]);
    app()->instance(RisSheetSyncService::class, $sync);

    $this->actingAs($user)
        ->postJson('/rros/ris/sync')
        ->assertOk()
        ->assertJsonPath('message', 'RIS/DR sync completed: 1 created, 2 updated, 3 FNI rows synced.');
});

it('returns the useful RIS sync error without invalidating the workspace', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(RisSheetSyncService::class);
    $sync->shouldReceive('run')->once()->andThrow(new RuntimeException(
        'RIS/DR sync could not reach Google Sheets after several attempts. No data was changed. Please try again shortly.'
    ));
    app()->instance(RisSheetSyncService::class, $sync);

    $this->actingAs($user)
        ->postJson('/rros/ris/sync')
        ->assertStatus(503)
        ->assertJsonPath('message', 'RIS/DR sync could not reach Google Sheets after several attempts. No data was changed. Please try again shortly.');
});

it('never overlays a system RIS when a Google Sheet row reuses its RIS number', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.google_sheets.ris_tracking_spreadsheet_id', 'test-sheet');
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-SYSTEM-RIS', 'requesting_agency' => 'MLGU - Tubod, SDN',
        'requester' => 'Requester', 'date_requested' => now()->toDateString(), 'purpose' => 'Relief Augmentation',
        'status' => 'acted', 'assessment_status' => 'submitted', 'submission_type' => 'fni_request',
    ]);
    $slip = RequisitionIssuanceSlip::create([
        'request_id' => $request->id, 'prepared_by' => $rros->id, 'ris_number' => 'RIS-COLLISION-0001',
        'ris_date' => '2026-08-10', 'purpose_of_release' => 'Relief Augmentation',
        'recipient' => 'MLGU - Tubod, SDN', 'delivery_site' => 'Tubod, SDN',
        'items' => [], 'tracking_data' => [], 'status' => 'prepared', 'sync_source' => 'system',
    ]);

    $tracking = array_fill(0, 65, '');
    $tracking[28] = '2026-08-01';
    $tracking[33] = 'RIS-COLLISION-0001';
    $tracking[26] = 'Food for work in Dapa, SDN.';
    $tracking[38] = 'MLGU - Dapa, SDN';
    $tracking[37] = 'Dapa, SDN';
    $item = array_fill(0, 16, '');
    $item[2] = 'Family Food Pack';
    $item[3] = '999';
    $item[4] = 'RIS-COLLISION-0001';
    $csvLine = static fn (array $row): string => implode(',', array_map(static fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row));
    $csv = Mockery::mock(GoogleSheetCsvService::class);
    $csv->shouldReceive('fetch')->twice()->andReturn(
        $csvLine(array_fill(0, 65, 'header'))."\n".$csvLine($tracking),
        $csvLine(array_fill(0, 16, 'header'))."\n".$csvLine($item),
    );
    app()->instance(GoogleSheetCsvService::class, $csv);

    app(RisSheetSyncService::class)->run('manual', $rros->id);

    $slip->refresh();
    expect($slip->request_id)->toBe($request->id)
        ->and($slip->recipient)->toBe('MLGU - Tubod, SDN')
        ->and($slip->delivery_site)->toBe('Tubod, SDN')
        ->and($slip->purpose_of_release)->toBe('Relief Augmentation')
        ->and($slip->allocationItems()->count())->toBe(0);
});
