<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('lets RROS open warehouses even when manage warehouses permission pivots are missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();

    Role::findByName('RROS', 'web')->syncPermissions(['view dashboards', 'manage inventory']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();

    expect($user->can('manage warehouses'))->toBeFalse()
        ->and($user->hasRole('RROS'))->toBeTrue();

    $this->actingAs($user)
        ->get('/warehouses')
        ->assertOk();
});

it('lets RROS AA open warehouses with seeded manage warehouses permission', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros-aa@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/warehouses')
        ->assertOk();
});

it('denies DRRS from warehouses', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/warehouses')
        ->assertForbidden();
});
