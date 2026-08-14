<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('lets RROS open alert acknowledgments even when view permission pivots are missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    Role::findByName('RROS', 'web')->syncPermissions(['view dashboards', 'manage inventory']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();

    expect($user->can('view regional alert acknowledgements'))->toBeFalse()
        ->and($user->hasRole('RROS'))->toBeTrue();

    $this->actingAs($user)
        ->get('/alert-acknowledgments')
        ->assertOk();

    $this->actingAs($user)
        ->get('/regional-alert-acknowledgements')
        ->assertOk();
});

it('lets RROS AA open alert acknowledgments with seeded view permission', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros-aa@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/alert-acknowledgments')
        ->assertOk();
});

it('lets DRRS open alert acknowledgments via role fallback', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    Role::findByName('DRRS', 'web')->syncPermissions(['view dashboards', 'encode requests']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())
        ->get('/alert-acknowledgments')
        ->assertOk();
});

it('denies LGU from alert acknowledgments', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::create([
        'name' => 'LGU Alert Board Denial',
        'email' => 'lgu-alert-denied@example.test',
        'password' => bcrypt('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole(Role::findByName('LGU', 'web'));

    expect($user->can('view regional alert acknowledgements'))->toBeFalse();

    $this->actingAs($user)
        ->get('/alert-acknowledgments')
        ->assertForbidden();
});
