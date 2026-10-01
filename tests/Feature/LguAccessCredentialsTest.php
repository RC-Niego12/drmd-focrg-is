<?php

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryStaffMember;
use App\Models\User;
use App\Services\LguPersonnelAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lists LGU access rows per directory with full personnel credential coverage', function (): void {
    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    Permission::findOrCreate('manage users', 'web');

    $admin = User::create([
        'name' => 'Super Admin SA',
        'email' => 'sa-access@example.test',
        'username' => 'sa-access',
        'password' => 'password',
        'access_status' => 'approved',
        'is_active' => true,
    ]);
    $admin->assignRole('Super Admin');
    $admin->givePermissionTo('manage users');

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'CARMEN',
        'psgc_code' => '1600204000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);
    $directory->officials()->create([
        'role' => 'lce',
        'name' => 'Mayor Carmen',
        'position_designation' => 'Municipal Mayor',
        'login_username' => 'mlgu-carmen-adn-lce',
    ]);
    $directory->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'LSWDO Carmen',
        'position_designation' => 'MSWDO',
        'login_username' => 'mlgu-carmen-adn-lswdo',
    ]);
    $directory->ldrrmoOfficers()->create([
        'sort_order' => 0,
        'is_primary' => true,
        'name' => 'LDRRMO Carmen',
        'designation' => 'MDRRMO',
        'login_username' => 'mlgu-carmen-adn-ldrrmo',
    ]);
    $directory->lswdoAlternates()->create([
        'sort_order' => 0,
        'name' => 'Alternate LSWDO Carmen',
        'position' => 'Alternate MSWDO',
    ]);
    $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL,
        'sort_order' => 0,
        'name' => 'Focal Carmen',
        'position' => 'Warehouse Focal',
    ]);
    $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER,
        'sort_order' => 0,
        'name' => 'Storekeeper Carmen',
        'position' => 'Warehouse Storekeeper',
    ]);
    $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_DRIVER,
        'sort_order' => 0,
        'name' => 'Driver Carmen',
        'position' => 'Driver',
    ]);

    $credentials = app(LguPersonnelAccountService::class)->portalCredentialsForDirectory($directory->fresh());
    $labels = collect($credentials)->pluck('label')->all();

    expect($labels)->toContain('LCE')
        ->and($labels)->toContain('LSWDO')
        ->and($labels)->toContain('Alternate LSWDO')
        ->and($labels)->toContain('LDRRMO')
        ->and($labels)->toContain('Warehouse Focal')
        ->and($labels)->toContain('Warehouse Storekeeper')
        ->and($labels)->toContain('Driver');

    $this->actingAs($admin)
        ->get('/access-management')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('AccessManagement/Index')
            ->has('lguDirectories', 1)
            ->where('lguDirectories.0.lgu_name', 'CARMEN')
            ->where('metrics.lgu', 1)
            ->has('lguDirectories.0.portal_logins'));
});
