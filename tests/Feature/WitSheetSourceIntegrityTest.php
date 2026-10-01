<?php

use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\GoogleSheetCsvService;
use App\Services\InventoryBalanceService;
use App\Services\StandbyStockpileSummaryService;
use App\Services\WarehouseMasterSheetImportService;
use App\Services\WarehouseSheetImportService;
use App\Services\WitDropdownLibrarySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads the configured warehouse worksheet and maps current columns by header', function (): void {
    $csv = implode("\n", [
        '"WAREHOUSE ID","WAREHOUSE NAME","PROVINCE","MUNICIPALITY","PARTNERSHIP","OWNERSHIP","FFPs CAPACITY","FFPs CURRENT","FFPs COST","TOTAL","TOTAL COST","LONGITUDE (X)","LATITUDE (Y)","STATUS","ACTUAL WH TYPE","RTEF Current Stock","RTEF Total Cost","RTEF Full Capacity (Units)"',
        '"PH-001","Main Warehouse","SURIGAO DEL NORTE","Tubod","MOA","Government","1,000","125","250,000","140","280,000","125.5","9.5","Active","Regional Warehouse","80","120,000","500"',
    ]);
    $storekeepersCsv = implode("\n", [
        '"WAREHOUSE ID","WAREHOUSE NAME","Designated Storekeepers","ContactNum_Storekeepers"',
        '"PH-001","Main Warehouse","Juan Storekeeper","09171234567"',
    ]);
    $sheet = Mockery::mock(GoogleSheetCsvService::class);
    $sheet->shouldReceive('fetchWorksheet')->once()->with('sheet-id', 'Managed Warehouses')->andReturn($csv);
    $sheet->shouldReceive('fetchWorksheet')->once()->with('sheet-id', 'Managed Warehouses 2')->andReturn($storekeepersCsv);
    $districts = Mockery::mock(\App\Services\PsgcDistrictService::class);
    $districts->shouldReceive('resolveForLocality')->andReturn('Lone SDN');
    $lguPersonnel = Mockery::mock(\App\Services\LguWarehousePersonnelSyncService::class);
    $lguPersonnel->shouldReceive('syncFromWarehouses')->once()->andReturn([
        'lgus_updated' => 0,
        'focals_synced' => 0,
        'storekeepers_synced' => 0,
        'warehouses_matched' => 0,
    ]);

    config([
        'services.google_sheets.warehouse_storekeepers_worksheet' => 'Managed Warehouses 2',
    ]);

    $summary = (new WarehouseMasterSheetImportService($sheet, $districts, $lguPersonnel))->import(
        'https://docs.google.com/spreadsheets/d/sheet-id/edit?gid=wrong-tab',
        'Managed Warehouses',
    );

    $warehouse = Warehouse::where('external_warehouse_id', 'PH-001')->firstOrFail();
    expect($summary['warehouses_synced'])->toBe(1)
        ->and($summary['storekeepers_updated'])->toBe(1)
        ->and($warehouse->ownership)->toBe('Government')
        ->and($warehouse->partnership)->toBe('MOA')
        ->and((float) $warehouse->sheet_ffp_current)->toBe(125.0)
        ->and((float) $warehouse->sheet_total_items)->toBe(140.0)
        ->and((float) $warehouse->rtef_capacity)->toBe(500.0)
        ->and((float) data_get($warehouse->sheet_payload, 'rtef_current'))->toBe(80.0)
        ->and((float) data_get($warehouse->sheet_payload, 'rtef_cost'))->toBe(120000.0)
        ->and((float) data_get($warehouse->sheet_payload, 'rtef_capacity'))->toBe(500.0)
        ->and($warehouse->warehouse_type)->toBe('Regional Warehouse')
        ->and($warehouse->designated_storekeepers)->toBe('Juan Storekeeper')
        ->and($warehouse->storekeeper_contact_number)->toBe('09171234567');
});

it('supersedes WIT rows removed from the Google Sheet', function (): void {
    $header = array_fill(0, 43, 'Header');
    $row = array_fill(0, 43, '');
    $row[0] = '1';
    $row[1] = 'PH-001';
    $row[2] = 'SURIGAO DEL NORTE, Main Warehouse';
    $row[3] = 'Food Items';
    $row[4] = 'Family Food Pack';
    $row[6] = '09/03/2026';
    $row[12] = 'box';
    $row[15] = '10';

    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $header);
    fputcsv($stream, $row);
    rewind($stream);
    $withRow = stream_get_contents($stream);
    fclose($stream);

    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $header);
    rewind($stream);
    $withoutRow = stream_get_contents($stream);
    fclose($stream);

    $sheet = Mockery::mock(GoogleSheetCsvService::class);
    $sheet->shouldReceive('fetch')->twice()->andReturn($withRow, $withoutRow);
    $libraries = Mockery::mock(WitDropdownLibrarySyncService::class);
    $libraries->shouldReceive('sync')->twice()->andReturn([]);
    $service = new WarehouseSheetImportService($sheet, $libraries);

    $service->import('https://docs.google.com/spreadsheets/d/sheet-id/edit?gid=123');
    $summary = $service->import('https://docs.google.com/spreadsheets/d/sheet-id/edit?gid=123');

    expect($summary['rows_removed'])->toBe(1)
        ->and(WarehouseSheetImport::where('import_status', 'imported')->count())->toBe(0)
        ->and(WarehouseSheetImport::where('import_status', 'superseded')->count())->toBe(1);
});

it('builds the official standby summary from WIT rows without pending system-only transactions', function (): void {
    $balances = Mockery::mock(InventoryBalanceService::class);
    $balances->shouldReceive('balanceRows')->once()->with(null, null, false)->andReturn(collect([
        ['item' => 'Family Food Pack', 'category' => 'Food Items', 'warehouse_id' => null, 'current_balance' => 179331, 'cost' => 128683252.06],
        ['item' => 'Rice', 'category' => 'Food Items', 'warehouse_id' => null, 'current_balance' => 100, 'cost' => 19986990.00],
        ['item' => 'Family Clothing Kit', 'category' => 'Non Food Items', 'warehouse_id' => null, 'current_balance' => 50, 'cost' => 27017800.79],
    ]));

    $summary = (new StandbyStockpileSummaryService($balances))->current();

    expect($summary['ffp_quantity'])->toBe(179331.0)
        ->and($summary['ffp_cost'])->toBe(128683252.06)
        ->and($summary['other_food_non_food_cost'])->toBe(47004790.79)
        ->and($summary['total_standby_funds_stockpile'])->toBe(178688042.85);
});
