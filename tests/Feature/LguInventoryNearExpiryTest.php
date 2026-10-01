<?php

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\LguDirectoryEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedLguInventoryFixture(): array
{
    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'TUBOD',
        'psgc_code' => '1606727000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $ownWarehouse = Warehouse::create([
        'name' => 'Surigao Del Norte, Tubod01',
        'external_warehouse_id' => 'PH1606727000-01LN',
        'province' => 'Surigao Del Norte',
        'municipality' => 'Tubod',
        'partnership' => 'LGU',
        'ownership' => 'Municipal',
        'warehouse_type' => 'Prepositioning Area',
        'status' => 'active',
    ]);

    $otherLguWarehouse = Warehouse::create([
        'name' => 'Surigao Del Norte, Surigao City01',
        'external_warehouse_id' => 'PH1667160000-01LN',
        'province' => 'Surigao Del Norte',
        'municipality' => 'Surigao City',
        'partnership' => 'LGU',
        'ownership' => 'City',
        'warehouse_type' => 'Prepositioning Area',
        'status' => 'active',
    ]);

    $dswdWarehouse = Warehouse::create([
        'name' => 'RROC Warehouse',
        'external_warehouse_id' => 'PH-RROC-01',
        'province' => 'Agusan Del Norte',
        'municipality' => 'Butuan City',
        'partnership' => 'DSWD',
        'ownership' => 'National',
        'warehouse_type' => 'Regional Warehouse',
        'status' => 'active',
    ]);

    $item = InventoryItem::create([
        'name' => 'Family Food Pack',
        'category' => 'food',
        'unit' => 'box',
        'status' => 'active',
    ]);

    $ownBatch = InventoryBatch::create([
        'warehouse_id' => $ownWarehouse->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'TUBOD-FFP-1',
        'brand_description' => 'Prepacked',
        'quantity' => 100,
        'reserved_quantity' => 0,
        'expiration_date' => now()->addMonths(2)->toDateString(),
        'date_received' => now()->subMonth()->toDateString(),
        'current_status' => 'available',
    ]);

    InventoryTransaction::create([
        'inventory_batch_id' => $ownBatch->id,
        'type' => 'receipt',
        'quantity' => 100,
        'unit_cost' => 500,
        'total_cost' => 50000,
        'transaction_date' => now()->subDay()->toDateString(),
    ]);

    $otherBatch = InventoryBatch::create([
        'warehouse_id' => $otherLguWarehouse->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'SC-FFP-1',
        'brand_description' => 'Prepacked',
        'quantity' => 50,
        'reserved_quantity' => 0,
        'expiration_date' => now()->addMonths(1)->toDateString(),
        'date_received' => now()->subMonth()->toDateString(),
        'current_status' => 'available',
    ]);

    InventoryTransaction::create([
        'inventory_batch_id' => $otherBatch->id,
        'type' => 'receipt',
        'quantity' => 50,
        'unit_cost' => 500,
        'total_cost' => 25000,
        'transaction_date' => now()->subDay()->toDateString(),
    ]);

    $dswdBatch = InventoryBatch::create([
        'warehouse_id' => $dswdWarehouse->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'RROC-FFP-1',
        'brand_description' => 'Prepacked',
        'quantity' => 200,
        'reserved_quantity' => 0,
        'expiration_date' => now()->addMonths(3)->toDateString(),
        'date_received' => now()->subMonth()->toDateString(),
        'current_status' => 'available',
    ]);

    InventoryTransaction::create([
        'inventory_batch_id' => $dswdBatch->id,
        'type' => 'receipt',
        'quantity' => 200,
        'unit_cost' => 500,
        'total_cost' => 100000,
        'transaction_date' => now()->subDay()->toDateString(),
    ]);

    $user = User::create([
        'name' => 'Tubod LGU Staff',
        'email' => 'tubod-lgu@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1606727000',
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $user->syncRoles(['LGU']);

    return compact('directory', 'ownWarehouse', 'otherLguWarehouse', 'dswdWarehouse', 'user');
}

it('resolves partnership=LGU warehouses only for the current LGU directory', function (): void {
    ['directory' => $directory, 'ownWarehouse' => $ownWarehouse, 'otherLguWarehouse' => $other, 'dswdWarehouse' => $dswd] = seedLguInventoryFixture();

    $ids = app(LguWarehousePersonnelSyncService::class)
        ->warehousesForDirectory($directory, partnershipLguOnly: true)
        ->pluck('id')
        ->all();

    expect($ids)->toContain($ownWarehouse->id)
        ->and($ids)->not->toContain($other->id)
        ->and($ids)->not->toContain($dswd->id);
});

it('shows only the current LGU partnership warehouses on LGU inventory', function (): void {
    ['ownWarehouse' => $ownWarehouse, 'otherLguWarehouse' => $other, 'dswdWarehouse' => $dswd, 'user' => $user] = seedLguInventoryFixture();

    $this->actingAs($user)
        ->get('/lgu/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('workspace', 'lgu')
            ->where('filterBasePath', '/lgu/inventory')
            ->has('warehouses', 1)
            ->where('warehouses.0.id', $ownWarehouse->id)
            ->where('balanceRows', fn ($rows) => collect($rows)->every(
                fn ($row) => (int) ($row['warehouse_id'] ?? 0) === (int) $ownWarehouse->id
            ))
            ->where('balanceRows', fn ($rows) => collect($rows)->doesntContain(
                fn ($row) => in_array((int) ($row['warehouse_id'] ?? 0), [(int) $other->id, (int) $dswd->id], true)
            )));
});

it('shows only the current LGU partnership warehouses on LGU near expiry', function (): void {
    ['ownWarehouse' => $ownWarehouse, 'otherLguWarehouse' => $other, 'dswdWarehouse' => $dswd, 'user' => $user] = seedLguInventoryFixture();

    $this->actingAs($user)
        ->get('/lgu/near-expiry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/NearExpiry')
            ->where('workspace', 'lgu')
            ->where('include_plans', false)
            ->where('monitoring.expiryRows', fn ($rows) => collect($rows)->every(
                fn ($row) => (int) ($row['warehouse_id'] ?? 0) === (int) $ownWarehouse->id
            ))
            ->where('monitoring.expiryRows', fn ($rows) => collect($rows)->doesntContain(
                fn ($row) => in_array((int) ($row['warehouse_id'] ?? 0), [(int) $other->id, (int) $dswd->id], true)
            )));
});

it('blocks non-LGU users from LGU inventory and near expiry', function (): void {
    seedLguInventoryFixture();

    $outsider = User::create([
        'name' => 'RROS Viewer',
        'email' => 'rros-viewer@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
    ]);
    $role = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
    $outsider->syncRoles([$role]);

    $this->actingAs($outsider)->get('/lgu/inventory')->assertForbidden();
    $this->actingAs($outsider)->get('/lgu/near-expiry')->assertForbidden();
});
