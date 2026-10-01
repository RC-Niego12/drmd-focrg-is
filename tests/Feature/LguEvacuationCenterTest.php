<?php

use App\Models\LguDirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('shows LGU-scoped evacuation centers from the inventory snapshot', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'MAINIT',
        'psgc_code' => '1606714000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'Mainit LGU Staff',
        'email' => 'mainit-evac@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1606714000',
        'lgu_name' => 'MAINIT',
        'lgu_level' => 'MLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $user->syncRoles(['LGU']);

    $this->actingAs($user)
        ->get('/lgu/evacuation-centers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('EvacuationCenters/Index')
            ->where('workspace', 'lgu')
            ->where('lgu.name', 'MAINIT')
            ->where('metrics.total', fn ($total) => (int) $total > 0)
            ->where('centers', fn ($centers) => collect($centers)->every(
                fn ($center) => strcasecmp((string) ($center['municipality'] ?? ''), 'Mainit') === 0
            )));
});

it('shows the full Caraga DRIMS evacuation center inventory by default', function (): void {
    $role = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRIMS Staff',
        'email' => 'drims-evac@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'DRIMS',
    ]);
    $user->syncRoles([$role]);

    $snapshotTotal = count(json_decode(
        file_get_contents(database_path('data/caraga_evacuation_centers.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    )['centers'] ?? []);

    $this->actingAs($user)
        ->get('/evacuation-centers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('EvacuationCenters/Index')
            ->where('workspace', 'drims')
            ->where('scopeLabel', 'All Caraga inventory')
            ->where('metrics.total', $snapshotTotal)
            ->where('metrics.with_photos', fn ($photos) => (int) $photos > 0)
            ->where('featuredDashboards', fn ($dashboards) => collect($dashboards)->every(
                fn ($dashboard) => (int) ($dashboard['count'] ?? 0) === (int) ($dashboard['photo_count'] ?? -1)
            )));
});

it('can narrow DRIMS evacuation centers to featured LGUs', function (): void {
    $role = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRIMS Staff Featured',
        'email' => 'drims-evac-featured@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'DRIMS',
    ]);
    $user->syncRoles([$role]);

    $this->actingAs($user)
        ->get('/evacuation-centers?municipality=featured')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('EvacuationCenters/Index')
            ->where('scopeLabel', 'Featured LGUs (Mainit, Alegria, Sison)')
            ->where('centers', fn ($centers) => collect($centers)->every(
                fn ($center) => in_array((string) ($center['municipality'] ?? ''), ['Mainit', 'Alegria', 'Sison'], true)
            )));
});

it('keeps DRIMS featured LGU EC and photo counts aligned per municipality', function (): void {
    $role = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRIMS Staff 2',
        'email' => 'drims-evac-2@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'DRIMS',
    ]);
    $user->syncRoles([$role]);

    foreach (['Mainit', 'Alegria', 'Sison'] as $municipality) {
        $this->actingAs($user)
            ->get('/evacuation-centers?municipality='.$municipality)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('EvacuationCenters/Index')
                ->where('metrics.total', fn ($total) => (int) $total > 0)
                ->where('metrics', fn ($metrics) => (int) ($metrics['total'] ?? 0) === (int) ($metrics['with_photos'] ?? -1)));
    }
});

it('blocks non-authorized users from evacuation center pages', function (): void {
    $outsider = User::create([
        'name' => 'RROS Viewer',
        'email' => 'rros-evac@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
    ]);
    $outsider->syncRoles([Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web'])]);

    $this->actingAs($outsider)->get('/lgu/evacuation-centers')->assertForbidden();
    $this->actingAs($outsider)->get('/evacuation-centers')->assertForbidden();
});
