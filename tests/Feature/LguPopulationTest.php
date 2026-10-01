<?php

use App\Models\BarangayPopulation;
use App\Models\LguDirectoryEntry;
use App\Models\PsgcAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedLguPopulationFixture(): array
{
    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    PsgcAddress::query()->updateOrCreate(['code' => '1600000000'], [
        'name' => 'Region XIII (Caraga)',
        'short_name' => 'CARAGA',
        'level' => 'region',
        'parent_code' => null,
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600200000'], [
        'name' => 'Agusan Del Norte',
        'level' => 'province',
        'parent_code' => '1600000000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600300000'], [
        'name' => 'Agusan Del Sur',
        'level' => 'province',
        'parent_code' => '1600000000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600211000'], [
        'name' => 'Tubay',
        'level' => 'city_municipality',
        'parent_code' => '1600200000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600301000'], [
        'name' => 'Bayugan City',
        'level' => 'city_municipality',
        'parent_code' => '1600300000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600211001'], [
        'name' => 'Poblacion',
        'level' => 'barangay',
        'parent_code' => '1600211000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600211002'], [
        'name' => 'Doña Rosario',
        'level' => 'barangay',
        'parent_code' => '1600211000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1600301001'], [
        'name' => 'Poblacion',
        'level' => 'barangay',
        'parent_code' => '1600301000',
        'is_active' => true,
    ]);

    BarangayPopulation::create([
        'barangay_psgc_code' => '1600211001',
        'population' => 1200,
        'estimated_families' => 300,
        'census_year' => 2024,
        'source' => 'CPH 2024',
    ]);
    BarangayPopulation::create([
        'barangay_psgc_code' => '1600211002',
        'population' => 800,
        'estimated_families' => 200,
        'census_year' => 2024,
        'source' => 'CPH 2024',
    ]);
    BarangayPopulation::create([
        'barangay_psgc_code' => '1600301001',
        'population' => 5000,
        'estimated_families' => 1250,
        'census_year' => 2024,
        'source' => 'CPH 2024',
    ]);

    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'TUBAY',
        'psgc_code' => '1600211000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'Tubay LGU Staff',
        'email' => 'tubay-population@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1600211000',
        'lgu_name' => 'TUBAY',
        'lgu_level' => 'MLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $user->syncRoles(['LGU']);

    return compact('directory', 'user');
}

it('shows only the current LGU city population records view-only', function (): void {
    ['user' => $user] = seedLguPopulationFixture();

    $this->actingAs($user)
        ->get('/lgu/population')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Population/Index')
            ->where('workspace', 'lgu')
            ->where('readOnly', true)
            ->where('filterBasePath', '/lgu/population')
            ->where('lockedFilters.city_code', '1600211000')
            ->where('metrics.total_population', 2000)
            ->where('metrics.barangays_with_population', 2)
            ->where('caragaMetrics.total_population', 7000)
            ->where('caragaMetrics.barangays_with_population', 3)
            ->has('caragaProvinceSummary', 2)
            ->has('lguMapPoints', 2)
            ->where('lguMapPoints', fn ($points) => collect($points)->every(
                fn ($point) => filled($point['code'] ?? null)
                    && filled($point['name'] ?? null)
                    && isset($point['lat'], $point['lng'])
                    && abs((float) $point['lat'] - 9.167) < 0.05
                    && abs((float) $point['lng'] - 125.524) < 0.05
            ))
            ->has('records', 2)
            ->where('records', fn ($rows) => collect($rows)->every(
                fn ($row) => (string) ($row['city_code'] ?? '') === '1600211000'
            ))
            ->where('records', fn ($rows) => collect($rows)->doesntContain(
                fn ($row) => (string) ($row['city_code'] ?? '') === '1600301000'
            )));
});

it('scopes PLGU population to the province', function (): void {
    seedLguPopulationFixture();

    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    LguDirectoryEntry::create([
        'source_sheet' => 'ADS',
        'lgu_name' => 'Agusan del Sur',
        'psgc_code' => '1600300000',
        'lgu_level' => 'PLGU',
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'ADS PLGU Staff',
        'email' => 'ads-population@example.test',
        'password' => Hash::make('password'),
        'lgu_psgc_code' => '1600300000',
        'lgu_name' => 'Agusan del Sur',
        'lgu_level' => 'PLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
    ]);
    $user->syncRoles(['LGU']);

    $this->actingAs($user)
        ->get('/lgu/population')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace', 'lgu')
            ->where('lockedFilters.province_code', '1600300000')
            ->where('metrics.total_population', 5000)
            ->has('records', 1)
            ->where('records.0.city_code', '1600301000'));
});

it('places Tubod barangay markers on HDX barangay coordinates', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    PsgcAddress::query()->updateOrCreate(['code' => '1600000000'], [
        'name' => 'Region XIII (Caraga)',
        'short_name' => 'CARAGA',
        'level' => 'region',
        'parent_code' => null,
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1606700000'], [
        'name' => 'Surigao Del Norte',
        'level' => 'province',
        'parent_code' => '1600000000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1606727000'], [
        'name' => 'Tubod',
        'level' => 'city_municipality',
        'parent_code' => '1606700000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1606727001'], [
        'name' => 'Capayahan',
        'level' => 'barangay',
        'parent_code' => '1606727000',
        'is_active' => true,
    ]);
    PsgcAddress::query()->updateOrCreate(['code' => '1606727006'], [
        'name' => 'Poblacion',
        'level' => 'barangay',
        'parent_code' => '1606727000',
        'is_active' => true,
    ]);

    BarangayPopulation::create([
        'barangay_psgc_code' => '1606727001',
        'population' => 1214,
        'estimated_families' => 304,
        'census_year' => 2024,
        'source' => 'CPH 2024',
    ]);
    BarangayPopulation::create([
        'barangay_psgc_code' => '1606727006',
        'population' => 1646,
        'estimated_families' => 412,
        'census_year' => 2024,
        'source' => 'CPH 2024',
    ]);

    LguDirectoryEntry::create([
        'source_sheet' => 'SDN',
        'lgu_name' => 'TUBOD',
        'psgc_code' => '1606727000',
        'lgu_level' => 'MLGU',
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'Tubod LGU Staff',
        'email' => 'tubod-population@example.test',
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

    $this->actingAs($user)
        ->get('/lgu/population')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('lguMapPoints', 2)
            ->where('lguMapPoints', fn ($points) => collect($points)->contains(
                fn ($point) => ($point['code'] ?? null) === '1606727001'
                    && abs((float) $point['lat'] - 9.577355) < 0.0001
                    && abs((float) $point['lng'] - 125.55186) < 0.0001
            ))
            ->where('lguMapPoints', fn ($points) => collect($points)->contains(
                fn ($point) => ($point['code'] ?? null) === '1606727006'
                    && abs((float) $point['lat'] - 9.554705) < 0.0001
                    && abs((float) $point['lng'] - 125.570757) < 0.0001
            )));
});

it('blocks non-LGU users from LGU population', function (): void {
    seedLguPopulationFixture();

    $outsider = User::create([
        'name' => 'Admin Viewer',
        'email' => 'admin-pop@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
    ]);
    $outsider->syncRoles([Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web'])]);

    $this->actingAs($outsider)->get('/lgu/population')->assertForbidden();
});
