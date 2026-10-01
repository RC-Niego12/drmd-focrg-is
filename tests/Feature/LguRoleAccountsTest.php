<?php

use App\Models\LguDirectoryEntry;
use App\Models\User;
use App\Services\LguAccountUsernameBuilder;
use App\Services\LguPersonnelAccountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
});

it('builds default LCE LSWDO and LDRRMO usernames from the LGU profile', function (): void {
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'Tubod',
        'psgc_code' => null,
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $builder = app(LguAccountUsernameBuilder::class);
    $base = $builder->baseUsernameForDirectory($directory);

    expect($base)->toBe('mlgu-tubod-sdn')
        ->and($builder->roleUsername($base, 'lce'))->toBe('mlgu-tubod-sdn-lce')
        ->and($builder->roleUsername($base, 'lswd_officer'))->toBe('mlgu-tubod-sdn-lswdo')
        ->and($builder->roleUsername($base, 'ldrrmo'))->toBe('mlgu-tubod-sdn-ldrrmo');
});

it('provisions default manager accounts with password and first-login review flag', function (): void {
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'Tubod',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $results = app(LguPersonnelAccountService::class)->ensureDefaultManagerAccounts($directory);

    expect($results)->toHaveCount(3);

    $lce = User::query()->where('username', 'mlgu-tubod-sdn-lce')->first();
    expect($lce)->not->toBeNull()
        ->and($lce->must_change_password)->toBeTrue()
        ->and($lce->password_is_default)->toBeTrue()
        ->and(Hash::check('password', $lce->password))->toBeTrue()
        ->and($lce->canAccessLguPortalLogin())->toBeTrue();

    $this->post('/login-lgu', [
        'username' => 'mlgu-tubod-sdn-lce',
        'password' => 'password',
    ])->assertRedirect(route('lgu.credentials.edit'));

    $this->actingAs($lce->fresh())
        ->post('/lgu/credentials/retain')
        ->assertRedirect(route('dashboard'));

    expect($lce->fresh()->must_change_password)->toBeFalse()
        ->and($lce->fresh()->password_is_default)->toBeTrue();

    $credentials = app(LguPersonnelAccountService::class)->portalCredentialsForDirectory($directory->fresh());
    $lceCredential = collect($credentials)->firstWhere('role', 'lce');
    expect($lceCredential['default_password'])->toBe('password')
        ->and($lceCredential['status'])->toBe('default_retained');
});

it('allows changing default credentials on first login', function (): void {
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'Tubod',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    app(LguPersonnelAccountService::class)->ensureDefaultManagerAccounts($directory);
    $lswdo = User::query()->where('username', 'mlgu-tubod-sdn-lswdo')->first();

    $this->actingAs($lswdo)
        ->post('/lgu/credentials', [
            'username' => 'mlgu-tubod-sdn-lswdo',
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ])
        ->assertRedirect(route('dashboard'));

    expect($lswdo->fresh()->must_change_password)->toBeFalse()
        ->and($lswdo->fresh()->password_is_default)->toBeFalse()
        ->and(Hash::check('NewSecurePass1!', $lswdo->fresh()->password))->toBeTrue();
});
