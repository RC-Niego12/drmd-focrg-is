<?php

use App\Models\OperationalLibraryValue;
use App\Services\DispatchContactSheetSyncService;
use App\Services\GoogleSheetCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports driver and received-by libraries from RIS sheet AU/AV/AX columns', function (): void {
    $csv = <<<'CSV'
col0,col1,col2,col3,col4,col5,col6,col7,col8,col9,col10,col11,col12,col13,col14,col15,col16,col17,col18,col19,col20,col21,col22,col23,col24,col25,col26,col27,col28,col29,col30,col31,col32,col33,col34,col35,col36,col37,col38,col39,col40,col41,col42,col43,col44,col45,Name of Driver,Contact Number of Driver,Plate Number of Vehicle Driven,Received By (Name of LGU Representative)
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,Roque P. Sayson,09260411906,1312106,Deza Mary Y. Bongo
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,-,-,-,Mirasol Zerda
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,Sergio Lupot,09073721746,ZAJ-764,Rosefred Dalup
CSV;

    $sheet = Mockery::mock(GoogleSheetCsvService::class);
    $sheet->shouldReceive('fetch')
        ->once()
        ->with('sheet-id', '1905199506')
        ->andReturn($csv);

    config([
        'services.google_sheets.ris_tracking_spreadsheet_id' => 'sheet-id',
        'services.google_sheets.ris_tracking_gid' => '1905199506',
    ]);

    $result = (new DispatchContactSheetSyncService($sheet))->sync();

    expect($result['drivers_created'])->toBe(2)
        ->and($result['received_by_created'])->toBe(3);

    $driver = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_driver')
        ->where('value', 'Roque P. Sayson')
        ->first();
    expect($driver)->not->toBeNull()
        ->and(data_get($driver->metadata, 'contact_number'))->toBe('09260411906');

    $receiver = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_received_by')
        ->where('value', 'Mirasol Zerda')
        ->first();
    expect($receiver)->not->toBeNull()
        ->and(data_get($receiver->metadata, 'position'))->toBe('')
        ->and(data_get($receiver->metadata, 'office'))->toBe('');
});

it('stores one library value per normalized name when sheet rows duplicate', function (): void {
    $csv = <<<'CSV'
col0,col1,col2,col3,col4,col5,col6,col7,col8,col9,col10,col11,col12,col13,col14,col15,col16,col17,col18,col19,col20,col21,col22,col23,col24,col25,col26,col27,col28,col29,col30,col31,col32,col33,col34,col35,col36,col37,col38,col39,col40,col41,col42,col43,col44,col45,Name of Driver,Contact Number of Driver,Plate Number of Vehicle Driven,Received By (Name of LGU Representative)
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,Juan Dela Cruz,,ABC-111,Ana Santos
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,  juan   dela   cruz  ,09171234567,XYZ-999,ANA SANTOS
,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,JUAN DELA CRUZ,09998887777,ZZZ-000,ana santos
CSV;

    $sheet = Mockery::mock(GoogleSheetCsvService::class);
    $sheet->shouldReceive('fetch')
        ->once()
        ->with('sheet-id', '1905199506')
        ->andReturn($csv);

    config([
        'services.google_sheets.ris_tracking_spreadsheet_id' => 'sheet-id',
        'services.google_sheets.ris_tracking_gid' => '1905199506',
    ]);

    // Seed pre-existing case-variant duplicates that sync must collapse.
    OperationalLibraryValue::create([
        'library_type' => 'dispatch_driver',
        'value' => 'Juan Dela Cruz',
        'context' => 'all',
        'metadata' => null,
        'is_active' => true,
    ]);
    OperationalLibraryValue::create([
        'library_type' => 'dispatch_driver',
        'value' => 'JUAN DELA CRUZ',
        'context' => 'all',
        'metadata' => ['contact_number' => '09001112222'],
        'is_active' => true,
    ]);
    OperationalLibraryValue::create([
        'library_type' => 'dispatch_received_by',
        'value' => 'Ana Santos',
        'context' => 'all',
        'metadata' => ['position' => '', 'office' => ''],
        'is_active' => true,
    ]);
    OperationalLibraryValue::create([
        'library_type' => 'dispatch_received_by',
        'value' => 'ANA SANTOS',
        'context' => 'all',
        'metadata' => ['position' => 'MSWDO', 'office' => ''],
        'is_active' => true,
    ]);

    $result = (new DispatchContactSheetSyncService($sheet))->sync();

    expect($result['drivers_duplicates_removed'])->toBe(1)
        ->and($result['received_by_duplicates_removed'])->toBe(1)
        ->and($result['drivers_created'] + $result['drivers_updated'])->toBe(1)
        ->and($result['received_by_created'] + $result['received_by_updated'])->toBe(1);

    $drivers = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_driver')
        ->get();
    expect($drivers)->toHaveCount(1)
        ->and($drivers->first()->value)->toBe('Juan Dela Cruz')
        // First non-empty contact from sheet rows wins (second CSV row).
        ->and(data_get($drivers->first()->metadata, 'contact_number'))->toBe('09171234567');

    $receivers = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_received_by')
        ->get();
    expect($receivers)->toHaveCount(1)
        ->and($receivers->first()->value)->toBe('Ana Santos')
        // Pre-existing position kept when sheet AX has no position/office columns.
        ->and(data_get($receivers->first()->metadata, 'position'))->toBe('MSWDO');

    $catalogDrivers = OperationalLibraryValue::catalogEntries('dispatch_driver');
    $catalogReceivers = OperationalLibraryValue::catalogEntries('dispatch_received_by');
    expect($catalogDrivers)->toHaveCount(1)
        ->and($catalogReceivers)->toHaveCount(1);
});
