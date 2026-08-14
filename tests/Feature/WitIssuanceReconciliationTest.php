<?php

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\GoogleSheetCsvService;
use App\Services\WarehouseSheetImportService;
use App\Services\WitDropdownLibrarySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reconciles a manual WIT issuance with the dispatch deduction without deducting twice', function (): void {
    $warehouse = Warehouse::create([
        'external_warehouse_id' => 'ADN-KIT-01',
        'name' => 'Kitcharao 01',
        'province' => 'Agusan del Norte',
        'municipality' => 'Kitcharao',
        'warehouse_type' => 'Regional Warehouse',
        'ownership' => 'Owned',
    ]);
    $item = InventoryItem::create([
        'name' => 'Family Food Pack',
        'category' => 'food',
        'unit' => 'boxes',
        'status' => 'active',
    ]);
    $batch = InventoryBatch::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'batch_number' => 'ADN-KIT-01-family-food-pack-prepacked-202709',
        'brand_description' => 'Prepacked',
        'quantity' => 92,
        'reserved_quantity' => 0,
        'expiration_date' => '2027-09-01',
        'date_received' => '2026-07-01',
        'source' => 'FO Stockpile/Prepo',
        'current_status' => 'available',
    ]);
    $pending = $batch->transactions()->create([
        'type' => 'release',
        'transaction_date' => '2026-08-10',
        'quantity' => 8,
        'unit_cost' => 500,
        'total_cost' => 4000,
        'balance_after' => 92,
        'ris_if_stf' => 'RIS-2026-001',
        'reference_number' => 'DR-2026-001',
        'reconciliation_status' => 'pending_wit',
    ]);

    $columns = array_fill(0, 43, '');
    foreach ([
        0 => '1', 1 => 'ADN-KIT-01', 2 => 'Agusan del Norte, Kitcharao 01',
        3 => 'Food Items', 4 => 'Family Food Pack', 5 => 'Prepacked',
        6 => '08/10/2026', 7 => 'FO Stockpile/Prepo', 8 => 'Fire Incident',
        9 => 'RIS-2026-001', 11 => 'DR-2026-001', 12 => 'boxes',
        18 => 'Sep 2027', 19 => '92', 20 => '8', 21 => '500', 22 => '4000',
        23 => 'MLGU Tubod', 24 => 'Tubod, Surigao del Norte', 25 => '08/10/2026',
        26 => 'Truck', 27 => 'DSWD OWNED - Field Office', 28 => 'ABC-123',
        29 => 'Juan Driver', 30 => '09170000000', 37 => 'wit@example.test',
        38 => '08/10/2026 10:00 AM', 41 => 'POSTED', 42 => 'Manual WIT entry',
    ] as $index => $value) {
        $columns[$index] = $value;
    }
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, array_fill(0, 43, 'Header'));
    fputcsv($stream, $columns);
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    $sheet = Mockery::mock(GoogleSheetCsvService::class);
    $sheet->shouldReceive('fetch')->once()->andReturn($csv);
    $libraries = Mockery::mock(WitDropdownLibrarySyncService::class);
    $libraries->shouldReceive('sync')->once()->with('test-sheet')->andReturn(['source_of_goods' => 1]);
    $summary = (new WarehouseSheetImportService($sheet, $libraries))->import(
        'https://docs.google.com/spreadsheets/d/test-sheet/edit?gid=1672613362',
        'Data Entry',
    );

    $pending->refresh();
    expect($summary['issuances'])->toBe(1)
        ->and($pending->reconciliation_status)->toBe('reconciled')
        ->and($pending->external_status)->toBe('POSTED')
        ->and($pending->batch->fresh()->quantity)->toEqual(92)
        ->and(InventoryTransaction::where('type', 'release')->count())->toBe(1)
        ->and(WarehouseSheetImport::firstOrFail()->inventory_transaction_id)->toBe($pending->id);
});
