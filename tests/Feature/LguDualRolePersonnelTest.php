<?php

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryStaffMember;
use App\Models\User;
use App\Services\LguPersonnelAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets an LSWDO reuse the same login when also assigned as warehouse focal', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'CARMEN',
        'psgc_code' => '1600204000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $lswdoUser = User::create([
        'name' => 'LSWDO Carmen',
        'email' => 'mlgu-carmen-adn-lswdo@lgu.dromis.local',
        'username' => 'mlgu-carmen-adn-lswdo',
        'password' => Hash::make('password'),
        'office' => 'LGU',
        'is_active' => true,
        'access_status' => 'approved',
        'lgu_psgc_code' => '1600204000',
        'lgu_level' => 'MLGU',
        'lgu_name' => 'CARMEN',
        'lgu_directory_role' => 'lswd_officer',
    ]);
    $lswdoUser->assignRole('LGU');

    $official = $directory->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'LSWDO Carmen',
        'position_designation' => 'MSWDO',
        'user_id' => $lswdoUser->id,
        'login_username' => 'mlgu-carmen-adn-lswdo',
    ]);

    $focal = $directory->staffMembers()->create([
        'staff_type' => LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL,
        'sort_order' => 0,
        'name' => 'LSWDO Carmen',
        'position' => 'Warehouse Focal',
        'office' => 'Carmen Warehouse',
    ]);

    $accounts = app(LguPersonnelAccountService::class);
    $result = $accounts->syncAccountForPersonnel($directory, $focal, LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL, [
        'name' => 'LSWDO Carmen',
        'link_user_id' => $lswdoUser->id,
        'login_username' => 'mlgu-carmen-adn-lswdo',
    ]);

    expect($result['created'])->toBeFalse()
        ->and($result['user']->id)->toBe($lswdoUser->id)
        ->and($focal->fresh()->user_id)->toBe($lswdoUser->id)
        ->and($official->fresh()->user_id)->toBe($lswdoUser->id)
        ->and(User::query()->where('lgu_psgc_code', '1600204000')->count())->toBe(1);

    $links = $accounts->findAllLinkedPersonnel($lswdoUser->fresh());
    $roles = collect($links)->pluck('role')->all();

    expect($roles)->toContain('lswd_officer')
        ->and($roles)->toContain('warehouse_focal')
        ->and($accounts->isFullProfileEditor($lswdoUser->fresh()))->toBeTrue()
        ->and($accounts->primaryDirectoryRole($roles))->toBe('lswd_officer');

    $credentials = $accounts->portalCredentialsForDirectory($directory->fresh());
    $combined = collect($credentials)->first(
        fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $lswdoUser->id
    );

    expect($combined)->not->toBeNull()
        ->and($combined['label'])->toContain('LSWDO')
        ->and($combined['label'])->toContain('Warehouse Focal');
});
