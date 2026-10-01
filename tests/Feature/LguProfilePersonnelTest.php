<?php

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryOfficial;
use App\Models\LguDirectoryStaffMember;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('saves optional office id numbers and operations personnel on the LGU profile', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'Butuan City',
        'psgc_code' => '1602020000',
        'lgu_level' => 'CLGU',
        'is_active' => true,
    ]);

    $lgu = User::create([
        'name' => 'Butuan LGU Encoder',
        'email' => 'butuan-lgu-staff@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602020000',
        'lgu_name' => 'Butuan City',
        'lgu_level' => 'CLGU',
        'position' => 'LSWDO',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $lgu->syncRoles(['LGU']);

    expect($lgu->canEditLguProfile($directory))->toBeTrue();

    $this->actingAs($lgu)
        ->post('/lgu/profile', [
            'lgu_name' => 'Butuan City',
            'province_name' => 'Agusan del Norte',
            'lce_name' => 'Mayor Test',
            'lce_position' => 'City Mayor',
            'lce_id_number' => 'LCE-001',
            'lswd_name' => 'LSWDO Test',
            'lswd_position' => 'City Social Welfare Officer',
            'lswd_id_number' => 'LSWDO-002',
            'lswdo_alternates' => [
                [
                    'name' => 'Alt LSWDO',
                    'position' => 'Alternate',
                    'contact_number' => '09171234567',
                    'id_number' => 'ALT-003',
                ],
            ],
            'ldrrmo_officers' => [
                [
                    'office' => 'CDRRMO Butuan',
                    'name' => 'LDRRMO Test',
                    'designation' => 'City DRRMO',
                    'id_number' => 'LDR-004',
                    'mobile_number' => '09180001111',
                ],
            ],
            'dromic_encoders' => [
                [
                    'office' => 'Butuan City LGU',
                    'name' => 'SitRep Encoder One',
                    'position' => 'DROMIC Encoder',
                    'id_number' => 'ENC-010',
                    'contact_number' => '09190001111',
                ],
            ],
            'warehouse_storekeepers' => [
                [
                    'office' => 'Butuan Relief Warehouse',
                    'name' => 'Storekeeper One',
                    'position' => 'Warehouse Storekeeper',
                    'id_number' => 'SK-020',
                    'contact_number' => '09190002222',
                ],
            ],
            'drivers' => [
                [
                    'office' => 'Butuan City LGU',
                    'name' => 'Driver One',
                    'position' => 'Driver',
                    'id_number' => 'DRV-030',
                    'contact_number' => '09190003333',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $directory->refresh();

    expect(LguDirectoryOfficial::query()->where('lgu_directory_entry_id', $directory->id)->where('role', 'lce')->value('id_number'))
        ->toBe('LCE-001');
    expect(LguDirectoryOfficial::query()->where('lgu_directory_entry_id', $directory->id)->where('role', 'lswd_officer')->value('id_number'))
        ->toBe('LSWDO-002');
    expect($directory->lswdoAlternates()->value('id_number'))->toBe('ALT-003');
    expect($directory->ldrrmoOfficers()->value('id_number'))->toBe('LDR-004');

    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_DROMIC_ENCODER)->count())->toBe(1);
    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER)->value('id_number'))
        ->toBe('SK-020');
    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_DRIVER)->value('name'))
        ->toBe('Driver One');

    expect(OperationalLibraryValue::query()->where('library_type', 'warehouse_storekeeper')->where('value', 'Storekeeper One')->count())
        ->toBe(0);

    $driver = OperationalLibraryValue::query()
        ->where('library_type', 'dispatch_driver')
        ->where('value', 'Driver One')
        ->first();
    expect($driver)->not->toBeNull()
        ->and(data_get($driver->metadata, 'id_number'))->toBe('DRV-030');

    $this->actingAs($lgu)
        ->post('/lgu/profile', [
            'lgu_name' => 'Butuan City',
            'lce_name' => 'Mayor Test',
            'lce_position' => 'City Mayor',
            'lce_id_number' => 'LCE-001',
            'warehouse_storekeepers' => [
                [
                    'office' => 'Butuan Relief Warehouse',
                    'name' => 'Storekeeper One',
                    'position' => 'Senior Storekeeper',
                    'id_number' => 'SK-021',
                    'contact_number' => '09190002222',
                ],
            ],
            'drivers' => [
                [
                    'office' => 'Butuan City LGU',
                    'name' => 'Driver One',
                    'position' => 'Senior Driver',
                    'id_number' => 'DRV-031',
                    'contact_number' => '09190003333',
                ],
            ],
            'dromic_encoders' => [],
            'lswdo_alternates' => [],
            'ldrrmo_officers' => [
                [
                    'office' => 'CDRRMO Butuan',
                    'name' => 'LDRRMO Test',
                    'designation' => 'City DRRMO',
                    'id_number' => 'LDR-004',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $driver->refresh();

    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER)->value('id_number'))
        ->toBe('SK-021');
    expect($directory->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER)->value('position'))
        ->toBe('Senior Storekeeper');
    expect(OperationalLibraryValue::query()->where('library_type', 'warehouse_storekeeper')->where('value', 'Storekeeper One')->count())
        ->toBe(0);
    expect(data_get($driver->metadata, 'id_number'))->toBe('DRV-031')
        ->and(data_get($driver->metadata, 'position'))->toBe('Senior Driver');
    expect(OperationalLibraryValue::query()->where('library_type', 'dispatch_driver')->where('value', 'Driver One')->count())
        ->toBe(1);
});

it('blocks LGU encoders from editing the LGU profile and allows LDRRMO alternates', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'Cabadbaran City',
        'psgc_code' => '1602030000',
        'lgu_level' => 'CLGU',
        'is_active' => true,
    ]);
    $directory->ldrrmoOfficers()->create([
        'sort_order' => 1,
        'is_primary' => false,
        'office' => 'CDRRMO',
        'name' => 'Alternate LDRRMO Person',
        'designation' => 'Alternate LDRRMO',
        'email_address' => 'alt-ldrrmo@example.test',
        'is_locally_updated' => true,
    ]);

    $encoder = User::create([
        'name' => 'SitRep Encoder',
        'email' => 'cabadbaran-encoder@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602030000',
        'lgu_name' => 'Cabadbaran City',
        'lgu_level' => 'CLGU',
        'position' => 'DROMIC / SitRep Encoder',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $encoder->syncRoles(['LGU']);

    expect($encoder->canEditLguProfile($directory))->toBeFalse();

    $this->actingAs($encoder)
        ->post('/lgu/profile', [
            'lgu_name' => 'Cabadbaran City',
            'lce_name' => 'Should Not Save',
        ])
        ->assertForbidden();

    $alternate = User::create([
        'name' => 'Alternate LDRRMO Person',
        'email' => 'alt-ldrrmo@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602030000',
        'lgu_name' => 'Cabadbaran City',
        'lgu_level' => 'CLGU',
        'position' => 'Staff',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $alternate->syncRoles(['LGU']);

    expect($alternate->canEditLguProfile($directory->fresh()))->toBeTrue();

    $this->actingAs($alternate)
        ->post('/lgu/profile', [
            'lgu_name' => 'Cabadbaran City',
            'lce_name' => 'Mayor Allowed',
            'lce_position' => 'City Mayor',
            'ldrrmo_officers' => [
                [
                    'office' => 'CDRRMO',
                    'name' => 'Alternate LDRRMO Person',
                    'designation' => 'Alternate LDRRMO',
                    'email_address' => 'alt-ldrrmo@example.test',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('provisions linked personnel logins and scopes self-edit for operations staff', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'Nasipit',
        'psgc_code' => '1602100000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $lswdo = User::create([
        'name' => 'Nasipit LSWDO',
        'email' => 'nasipit-lswdo@example.test',
        'username' => 'nasipit-lswdo-editor',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602100000',
        'lgu_name' => 'Nasipit',
        'lgu_level' => 'MLGU',
        'position' => 'LSWDO',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $lswdo->syncRoles(['LGU']);

    $this->actingAs($lswdo)
        ->post('/lgu/profile', [
            'lgu_name' => 'Nasipit',
            'lswd_name' => 'Nasipit LSWDO',
            'lswd_position' => 'MSWDO',
            'lswd_login_username' => 'nasipit.lswdo',
            'lswd_login_password' => 'SecretPass1',
            'dromic_encoders' => [
                [
                    'office' => 'Nasipit LGU',
                    'name' => 'Encoder Linked',
                    'position' => 'DROMIC Encoder',
                    'id_number' => 'ENC-100',
                    'contact_number' => '09171112222',
                    'login_username' => 'nasipit.encoder',
                    'login_password' => 'EncoderPass1',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $encoderAccount = User::query()->where('username', 'nasipit.encoder')->first();
    expect($encoderAccount)->not->toBeNull()
        ->and($encoderAccount->canAccessLguPortalLogin())->toBeTrue()
        ->and($encoderAccount->lguProfileEditCapabilities()['scope'])->toBe('self');

    $staff = $directory->fresh()->staffMembers()->where('staff_type', LguDirectoryStaffMember::TYPE_DROMIC_ENCODER)->first();
    expect($staff?->user_id)->toBe($encoderAccount->id)
        ->and($staff?->login_username)->toBe('nasipit.encoder');

    $this->actingAs($encoderAccount)
        ->post('/lgu/profile', [
            'self_name' => 'Encoder Linked Updated',
            'self_position' => 'Senior Encoder',
            'self_id_number' => 'ENC-101',
            'self_contact_number' => '09173334444',
            'self_office' => 'Nasipit LGU',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($staff->fresh()->name)->toBe('Encoder Linked Updated')
        ->and($staff->fresh()->id_number)->toBe('ENC-101');

    $this->actingAs($encoderAccount)
        ->post('/lgu/profile', [
            'lgu_name' => 'Hacked LGU Name',
            'lce_name' => 'Should Not Persist',
        ])
        ->assertSessionHasErrors();

    expect($directory->fresh()->override_lgu_name)->not->toBe('Hacked LGU Name');
});

it('rejects unlinked generic LGU accounts from the LGU portal login', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $generic = User::create([
        'name' => 'Generic LGU Account',
        'email' => 'generic-lgu@example.test',
        'username' => 'mlgu-generic-test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602990000',
        'lgu_name' => 'Generic Town',
        'lgu_level' => 'MLGU',
        'position' => 'LGU Account',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $generic->syncRoles(['LGU']);

    expect($generic->canAccessLguPortalLogin())->toBeFalse();

    $this->post('/login-lgu', [
        'username' => 'mlgu-generic-test',
        'password' => 'password',
    ])->assertSessionHasErrors('username');

    expect(auth()->check())->toBeFalse();
});

it('allows unlinked soft-matched LSWDO accounts to log in for profile bootstrap', function (): void {
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'Buenavista',
        'psgc_code' => '1602010000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $lswdo = User::create([
        'name' => 'Buenavista LSWDO Bootstrap',
        'email' => 'buenavista-lswdo@example.test',
        'username' => 'buenavista-lswdo',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1602010000',
        'lgu_name' => 'Buenavista',
        'lgu_level' => 'MLGU',
        'position' => 'LSWDO',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $lswdo->syncRoles(['LGU']);

    expect($lswdo->canAccessLguPortalLogin())->toBeTrue();

    $this->post('/login-lgu', [
        'username' => 'buenavista-lswdo',
        'password' => 'password',
    ])->assertRedirect();

    expect(auth()->id())->toBe($lswdo->id);
});
