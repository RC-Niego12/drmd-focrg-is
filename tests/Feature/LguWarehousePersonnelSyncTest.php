<?php

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryStaffMember;
use App\Models\Warehouse;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds warehouse focals and storekeepers into LGU profiles that have no local edits', function (): void {
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'TUBOD',
        'psgc_code' => '1606727000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    Warehouse::create([
        'name' => 'Surigao Del Norte, Tubod01',
        'external_warehouse_id' => 'PH1606727000-01LN',
        'province' => 'Surigao Del Norte',
        'municipality' => 'Tubod',
        'partnership' => 'LGU',
        'ownership' => 'Municipal',
        'warehouse_type' => 'Prepositioning Area',
        'contact_person' => 'Tubod Focal Person',
        'contact_number' => '09170001111',
        'email' => 'focal@tubod.test',
        'designated_storekeepers' => 'Tubod Storekeeper',
        'storekeeper_contact_number' => '09170002222',
        'status' => 'active',
    ]);

    $summary = app(LguWarehousePersonnelSyncService::class)->syncFromWarehouses();

    expect($summary['lgus_updated'])->toBe(1)
        ->and($summary['focals_synced'])->toBe(1)
        ->and($summary['storekeepers_synced'])->toBe(1)
        ->and($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL)->value('name'))
        ->toBe('Tubod Focal Person')
        ->and($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER)->value('name'))
        ->toBe('Tubod Storekeeper')
        ->and(app(LguWarehousePersonnelSyncService::class)->directoryHasWarehouses($directory))->toBeTrue();
});

it('keeps LGU-updated focals and storekeepers ahead of warehouse sync', function (): void {
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'TUBOD',
        'psgc_code' => '1606727000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER,
        'sort_order' => 0,
        'office' => 'LGU office',
        'name' => 'LGU Storekeeper',
        'position' => 'Senior Storekeeper',
        'contact_number' => '09111111111',
        'is_locally_updated' => true,
    ]);
    $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL,
        'sort_order' => 0,
        'office' => 'LGU office',
        'name' => 'LGU Focal',
        'position' => 'Warehouse Focal',
        'contact_number' => '09112223333',
        'is_locally_updated' => true,
    ]);

    Warehouse::create([
        'name' => 'Surigao Del Norte, Tubod01',
        'external_warehouse_id' => 'PH1606727000-01LN',
        'province' => 'Surigao Del Norte',
        'municipality' => 'Tubod',
        'partnership' => 'LGU',
        'ownership' => 'Municipal',
        'warehouse_type' => 'Prepositioning Area',
        'contact_person' => 'Sheet Focal Person',
        'contact_number' => '09170001111',
        'designated_storekeepers' => 'Sheet Storekeeper',
        'storekeeper_contact_number' => '09170002222',
        'status' => 'active',
    ]);

    app(LguWarehousePersonnelSyncService::class)->syncFromWarehouses();

    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL)->value('name'))
        ->toBe('LGU Focal')
        ->and($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER)->value('name'))
        ->toBe('LGU Storekeeper')
        ->and($directory->staffMembers()->where('name', 'Sheet Focal Person')->exists())->toBeFalse()
        ->and($directory->staffMembers()->where('name', 'Sheet Storekeeper')->exists())->toBeFalse();
});
