<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LguDirectoryEntry;
use App\Models\User;
use App\Services\LguDirectorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('preserves multiple LDRRMO entries for the same LGU and keeps a stable primary snapshot', function (): void {
    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'PDI',
        'lgu_name' => 'Dinagat Islands',
        'psgc_code' => '1608500000',
        'lgu_level' => 'PLGU',
        'managed_district_code' => null,
        'managed_district_name' => null,
        'congressional_district' => null,
        'office_address' => null,
        'source_updated_label' => null,
        'is_active' => true,
        'missing_from_source' => false,
        'source_hash' => 'hash',
        'source_seen_at' => now(),
    ]);

    $service = app(LguDirectorySyncService::class);
    $method = new ReflectionMethod($service, 'syncLdrrmoRecords');
    $method->setAccessible(true);

    $records = [
        [
            'raw' => ['lgu_name' => 'Dinagat Islands', 'name' => 'Rosario Alon', 'designation' => 'LDRRMO', 'contact' => '0917-111-2222', 'email' => 'rosario.alon@example.test'],
            'lgu_name' => 'Dinagat Islands',
            'province' => 'Dinagat Islands',
            'district' => null,
            'office' => 'PDRRMO',
            'psgc_code' => '1608500000',
            'name' => 'Rosario Alon',
            'position' => 'LDRRMO',
            'contact' => '0917-111-2222',
            'email' => 'rosario.alon@example.test',
        ],
        [
            'raw' => ['lgu_name' => 'Dinagat Islands', 'name' => 'Rafael Ramos', 'designation' => 'Assistant LDRRMO', 'contact' => '0917-333-4444', 'email' => 'rafael.ramos@example.test'],
            'lgu_name' => 'Dinagat Islands',
            'province' => 'Dinagat Islands',
            'district' => null,
            'office' => 'PDRRMO',
            'psgc_code' => '1608500000',
            'name' => 'Rafael Ramos',
            'position' => 'Assistant LDRRMO',
            'contact' => '0917-333-4444',
            'email' => 'rafael.ramos@example.test',
        ],
    ];

    $summary = $method->invoke($service, $records);

    $entry->refresh();

    expect($summary['matched'])->toBe(2)
        ->and($entry->ldrrmo_payload['source_values'])->toHaveCount(2)
        ->and($entry->ldrrmo_payload['source_values'][0])->toHaveKeys(['name', 'position', 'contact', 'email'])
        ->and($entry->ldrrmo_name)->toBe('Rosario Alon')
        ->and($entry->ldrrmoOfficers)->toHaveCount(2)
        ->and($entry->ldrrmoOfficers[0]->mobile_number)->toBe('0917-111-2222')
        ->and($entry->ldrrmoOfficers[0]->email_address)->toBe('rosario.alon@example.test')
        ->and($entry->ldrrmoOfficers[0]->is_primary)->toBeTrue()
        ->and($entry->ldrrmoOfficers[1]->is_primary)->toBeFalse();
});

it('keeps each line of the LSWDO alternate cell in its proper field', function (): void {
    $service = app(LguDirectorySyncService::class);
    $method = new ReflectionMethod($service, 'parseLswdAlternates');
    $method->setAccessible(true);

    expect($method->invoke($service, "MARIA D. SANTOS\nSWO III\n0917 123 4567"))
        ->toBe([[
            'name' => 'MARIA D. SANTOS',
            'position' => 'SWO III',
            'contact' => '0917 123 4567',
        ]])
        ->and($method->invoke($service, 'GENO P. MATONDO SWO III 09171377747'))
        ->toBe([[
            'name' => 'GENO P. MATONDO',
            'position' => 'SWO III',
            'contact' => '09171377747',
        ]])
        ->and($method->invoke($service, "Myrna B. Cortez\nCAO IV\n\nLoida V. Antonio\nSWO IV"))
        ->toBe([
            ['name' => 'Myrna B. Cortez', 'position' => 'CAO IV', 'contact' => null],
            ['name' => 'Loida V. Antonio', 'position' => 'SWO IV', 'contact' => null],
        ]);
});

it('resolves RTR to the single full Remedios T. Romualdez directory record', function (): void {
    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'REMEDIOS T. ROMUALDEZ',
        'psgc_code' => '1600212000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);
    $service = app(LguDirectorySyncService::class);
    $method = new ReflectionMethod($service, 'findDirectoryEntry');
    $method->setAccessible(true);

    expect($method->invoke($service, null, 'RTR', 'Agusan del Norte')?->id)
        ->toBe($entry->id);
});

it('shares same-origin LSWDO photo URLs with the LGU profile modal', function (): void {
    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'TUBOD',
        'psgc_code' => '1606714000',
        'lgu_level' => 'MLGU',
        'lswd_photo_path' => 'lgu-officials/sheet-lswdo/tubod.jpg',
        'is_active' => true,
    ]);
    $entry->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'MS. LEORAVEL DALES ESPIN, RSW',
        'position_designation' => 'MSWDO',
    ]);
    $user = User::query()->create([
        'name' => 'MLGU Tubod',
        'email' => 'tubod-photo@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
        'lgu_name' => 'TUBOD',
        'lgu_psgc_code' => '1606714000',
        'lgu_level' => 'MLGU',
    ]);

    $middleware = app(HandleInertiaRequests::class);
    $method = new ReflectionMethod($middleware, 'lguProfileData');
    $method->setAccessible(true);
    $profile = $method->invoke($middleware, $user);

    expect($entry->lswd_photo_url)
        ->toBe('/storage/lgu-officials/sheet-lswdo/tubod.jpg')
        ->and($profile['officials']['lswd_officer']['photo_url'])
        ->toBe('/storage/lgu-officials/sheet-lswdo/tubod.jpg')
        ->and($profile['official_photos']['lswd_officer'])
        ->toBe('/storage/lgu-officials/sheet-lswdo/tubod.jpg');
});

it('maps the live regional workbook columns and preserves complete official names', function (): void {
    $csv = static function (string $sheet): string {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ["{$sheet} DIRECTORY"]);
        fputcsv($handle, [
            'NAME OF RECEIVER (MAYOR)',
            $sheet === 'ADN' ? 'LCE' : 'POSITION',
            'LGU/LCE Office EMAIL ADDRESS',
            'LGU',
            'CONGRESSIONAL DISTRICT',
            'LSWD Officer',
            'POSITION / DESIGNATION',
            'PHOTO',
            'LSWD Office EMAIL ADDRESS',
            'Alternate EMAIL/COPY FURNISH',
            $sheet === 'ADN' ? 'CONTACT NUMBER and HOTLINE' : 'CONTACT NUMBER',
            'ALTERNATE',
            'Updated',
            '',
            $sheet === 'SDN' ? 'Facebook Account Link of LSWDO' : 'Facebook Account Link',
            'LSWD Office Complete Address',
        ]);
        foreach (range(1, 15) as $index) {
            fputcsv($handle, [
                'HON. JUAN D. DELA CRUZ, JR., CPA',
                'Municipal Mayor',
                'mayor@example.test',
                "{$sheet} TEST LGU {$index}",
                '1st District',
                'MS. MARIA L. SANTOS-DELA CRUZ, RSW, MSSW',
                'MSWDO',
                '',
                'lswdo@example.test',
                $index === 2 ? '-' : 'copy@example.test',
                "0917 000 00{$index}",
                "ANA P. REYES\nSWO III\n0998 111 2233",
                'UPDATED July 27, 2026',
                '',
                'https://facebook.com/maria.santos',
                'Municipal Hall Compound',
            ]);
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    };

    $sources = collect(LguDirectorySyncService::SHEETS)
        ->mapWithKeys(fn (string $sheet): array => [$sheet => $csv($sheet)])
        ->all();
    $service = app(LguDirectorySyncService::class);
    $method = new ReflectionMethod($service, 'recordsFromRegionalCsvSources');
    $method->setAccessible(true);
    $records = $method->invoke($service, $sources);
    $record = $records['ADN|ADNTESTLGU1'];

    expect($records)->toHaveCount(75)
        ->and($record['officials']['lce']['name'])->toBe('HON. JUAN D. DELA CRUZ, JR., CPA')
        ->and($record['officials']['lswd_officer']['name'])->toBe('MS. MARIA L. SANTOS-DELA CRUZ, RSW, MSSW')
        ->and($record['officials']['lswd_officer']['position'])->toBe('MSWDO')
        ->and($record['contacts']['lce|email'])->toBe('mayor@example.test')
        ->and($record['lswd_facebook'])->toBe('https://facebook.com/maria.santos')
        ->and($record['office_address'])->toBe('Municipal Hall Compound')
        ->and($record['lswdo_alternates'][0])->toBe([
            'name' => 'ANA P. REYES',
            'position' => 'SWO III',
            'contact' => '0998 111 2233',
        ])
        ->and($records['ADN|ADNTESTLGU2']['lswd_alternate_email'])->toBeNull();
});

it('selects Rosario Alon as the PDI primary officer and retains the other officers as alternates', function (): void {
    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'PDI',
        'lgu_name' => 'Province of Dinagat Islands',
        'psgc_code' => '1608500000',
        'lgu_level' => 'PLGU',
        'is_active' => true,
    ]);

    $service = app(LguDirectorySyncService::class);
    $method = new ReflectionMethod($service, 'syncLdrrmoRecords');
    $method->setAccessible(true);
    $base = [
        'lgu_name' => 'Province of Dinagat Islands',
        'province' => 'Dinagat Islands',
        'district' => null,
        'office' => 'PDRRMO',
        'psgc_code' => '1608500000',
    ];

    $method->invoke($service, [
        $base + ['raw' => ['name' => 'ROSARIO JRA. R. ALON'], 'name' => 'ROSARIO JRA. R. ALON', 'position' => 'PDRRMO'],
        $base + ['raw' => ['name' => 'MARICEL P. LARIOS'], 'name' => 'MARICEL P. LARIOS', 'position' => 'LDRRMO IV'],
        $base + ['raw' => ['name' => 'RODERICH M. GUINITA'], 'name' => 'RODERICH M. GUINITA', 'position' => 'LDRRMO II'],
    ]);

    $entry->refresh()->load('ldrrmoOfficers');

    expect($entry->ldrrmo_name)->toBe('ROSARIO JRA. R. ALON')
        ->and($entry->ldrrmoOfficers)->toHaveCount(3)
        ->and($entry->ldrrmoOfficers->firstWhere('is_primary', true)?->name)->toBe('ROSARIO JRA. R. ALON')
        ->and($entry->ldrrmoOfficers->where('is_primary', false)->pluck('name')->all())
        ->toBe(['MARICEL P. LARIOS', 'RODERICH M. GUINITA']);
});

it('updates the normalized LGU profile without validation or persistence errors', function (): void {
    Storage::fake('public');
    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'PDI',
        'lgu_name' => 'LORETO',
        'psgc_code' => '1608505000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'MLGU Loreto',
        'email' => 'loreto@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
        'lgu_name' => 'LORETO',
        'lgu_psgc_code' => '1608505000',
        'lgu_level' => 'MLGU',
    ]);
    $user->assignRole($role);

    $this->actingAs($user)->post(route('lgu.profile.update'), [
        'lgu_name' => 'LORETO',
        'province_name' => 'Dinagat Islands',
        'managed_district_name' => 'Dinagat District',
        'lce_name' => 'Mayor Example',
        'lce_position' => 'Municipal Mayor',
        'lce_email' => 'mayor@example.test',
        'lswd_name' => 'LSWDO Example',
        'lswd_position' => 'MSWDO',
        'lswd_email' => 'lswdo@example.test',
        'lswd_alt_email' => 'copy@example.test',
        'lswd_phone' => '09170000001',
        'lswd_facebook' => 'https://facebook.com/lswdo.example',
        'lswdo_alternates' => [
            ['name' => 'Alternate One', 'position' => 'SWO III', 'contact_number' => '09170000002'],
            ['name' => 'Alternate Two', 'position' => 'SWO II', 'contact_number' => '09170000003'],
        ],
        'ldrrmo_officers' => [
            [
                'name' => 'LDRRMO Main',
                'designation' => 'MDRRMO',
                'mobile_number' => '09170000004',
                'hotline_number' => '911',
                'landline_number' => '086-000-0000',
                'email_address' => 'ldrrmo@example.test',
                'alternate_email_address' => 'ldrrmo-copy@example.test',
                'vhf_radio_frequency' => '155.57',
                'facebook' => 'https://facebook.com/ldrrmo.example',
            ],
        ],
        'ldrrmc_logo' => UploadedFile::fake()->image('loreto-ldrrmc.png', 300, 300),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->fresh()->lswd_alternate_email)->toBe('copy@example.test')
        ->and($entry->lswdoAlternates()->count())->toBe(2)
        ->and($entry->ldrrmoOfficers()->first()?->vhf_radio_frequency)->toBe('155.57')
        ->and($entry->fresh()->ldrrmc_logo_path)->not->toBeNull()
        ->and($entry->fresh()->ldrrmc_logo_url)->toStartWith('/storage/lgu-logos/');
    Storage::disk('public')->assertExists($entry->fresh()->ldrrmc_logo_path);
});

it('integrates the same normalized fields into the superadmin directory update flow', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'manage users', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $admin = User::query()->create([
        'name' => 'Directory Admin',
        'email' => 'directory-admin@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $admin->assignRole($role);
    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'TEST LGU',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch(route('lgu-library.update', $entry), [
        'lgu_name' => 'TEST LGU',
        'lswd_name' => 'Admin LSWDO',
        'lswd_alt_email' => 'admin-copy@example.test',
        'lswdo_alternates' => [
            ['name' => 'Admin Alternate', 'position' => 'SWO III', 'contact_number' => '09171111111'],
        ],
        'ldrrmo_officers' => [
            ['name' => 'Admin LDRRMO', 'designation' => 'MDRRMO', 'mobile_number' => '09172222222', 'vhf_radio_frequency' => '155.57'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->fresh()->lswd_alternate_email)->toBe('admin-copy@example.test')
        ->and($entry->lswdoAlternates()->first()?->name)->toBe('Admin Alternate')
        ->and($entry->ldrrmoOfficers()->first()?->name)->toBe('Admin LDRRMO');
});
