<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('lets RROS open core workspace pages when permission pivots are missing', function (): void {
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    Role::findByName('RROS', 'web')->syncPermissions(['view dashboards']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();

    expect($user->can('manage inventory'))->toBeFalse()
        ->and($user->can('manage near expiry'))->toBeFalse()
        ->and($user->can('manage dispatches'))->toBeFalse()
        ->and($user->hasRole('RROS'))->toBeTrue();

    foreach ([
        '/inventory',
        '/inventory/e-stock-card',
        '/near-expiry',
        '/fni-issuances',
        '/dispatches',
        '/rros/requests',
        '/warehouses',
        '/alert-acknowledgments',
    ] as $path) {
        $this->actingAs($user)
            ->get($path)
            ->assertOk();
    }
});

it('lets RROS AA open inventory and dispatch workspaces with seeded permissions', function (): void {
    $user = User::where('email', 'rros-aa@example.test')->firstOrFail();

    foreach (['/inventory', '/near-expiry', '/dispatches', '/rros/requests'] as $path) {
        $this->actingAs($user)
            ->get($path)
            ->assertOk();
    }
});

it('lets DRRS open near-expiry via role fallback without manage near expiry permission', function (): void {
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    Role::findByName('DRRS', 'web')->syncPermissions(['view dashboards', 'encode requests']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())
        ->get('/near-expiry')
        ->assertOk();

    $this->actingAs($user->fresh())
        ->get('/inventory')
        ->assertOk();
});

it('denies LGU from RROS/DSWD inventory and dispatch modules', function (): void {
    $user = User::create([
        'name' => 'LGU Workspace Denial',
        'email' => 'lgu-workspace-denied@example.test',
        'password' => bcrypt('password'),
        'is_active' => true,
        'access_status' => 'approved',
        'access_approved_at' => now(),
    ]);
    $user->assignRole(Role::findByName('LGU', 'web'));

    foreach (['/inventory', '/warehouses', '/dispatches', '/near-expiry', '/access-management', '/dromic'] as $path) {
        $this->actingAs($user)
            ->get($path)
            ->assertForbidden();
    }
});

it('lets Super Admin open admin and operational workspaces', function (): void {
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    // Gate::before already grants Super Admin every can(); role_or_permission still
    // keeps routes open if that bypass were removed.
    foreach (['/access-management', '/standby-funds', '/dromic', '/preparedness-for-response', '/inventory', '/warehouses'] as $path) {
        $this->actingAs($user)
            ->get($path)
            ->assertOk();
    }
});

it('denies RROS from access management', function (): void {
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/access-management')
        ->assertForbidden();
});

it('lets DRIMS open the RROS near-expiry workspace', function (): void {
    $user = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/near-expiry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/NearExpiry')
            ->where('workspace', 'rros')
            ->has('monitoring.expiryRows'));

    $this->actingAs($user)
        ->post('/near-expiry/plans', [
            'location' => 'Butuan City',
            'quantity' => 1,
            'priority' => 'normal',
            'status' => 'for_distribution',
        ])
        ->assertForbidden();
});

it('lets DRIMS open DROMIC reports via role fallback', function (): void {
    $user = User::where('email', 'drims@example.test')->firstOrFail();

    Role::findByName('DRIMS', 'web')->syncPermissions(['view dashboards', 'monitor requests']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()->can('manage dromic reports'))->toBeFalse();

    $this->actingAs($user->fresh())
        ->get('/dromic')
        ->assertOk();
});

it('lets DRMD Financial Analyst open standby funds via role fallback', function (): void {
    $user = User::where('email', 'financial@example.test')->firstOrFail();

    Role::findByName('DRMD Financial Analyst', 'web')->syncPermissions(['view dashboards']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()->can('manage standby funds'))->toBeFalse();

    $this->actingAs($user->fresh())
        ->get('/standby-funds')
        ->assertOk();
});
