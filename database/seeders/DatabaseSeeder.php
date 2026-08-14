<?php

namespace Database\Seeders;

use App\Models\AssessmentType;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view dashboards',
            'manage inventory',
            'manage warehouses',
            'process requests',
            'encode requests',
            'monitor requests',
            'manage dispatches',
            'manage near expiry',
            'manage dromic reports',
            'export reports',
            'view audit logs',
            'manage users',
            'manage psgc addresses',
            'manage population',
            'manage standby funds',
            'submit drmd aa requests',
            'submit lgu dromic requests',
            'route lgu dromic requests',
            'route epirma documents',
            'assign ris drn',
            'manage regional alerts',
            'view regional alert acknowledgements',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $rros = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
        $rrosAa = Role::firstOrCreate(['name' => 'RROS AA', 'guard_name' => 'web']);
        $drrs = Role::firstOrCreate(['name' => 'DRRS', 'guard_name' => 'web']);
        $drrsAa = Role::firstOrCreate(['name' => 'DRRS AA', 'guard_name' => 'web']);
        $drims = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
        $drmdAa = Role::firstOrCreate(['name' => 'DRMD AA', 'guard_name' => 'web']);
        $drmdChief = Role::firstOrCreate(['name' => 'DRMD Chief', 'guard_name' => 'web']);
        $lgu = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
        $financialAnalyst = Role::firstOrCreate(['name' => 'DRMD Financial Analyst', 'guard_name' => 'web']);
        $ocdCaraga = Role::firstOrCreate(['name' => 'OCD Caraga', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'guest', 'guard_name' => 'web']);

        $superAdmin->syncPermissions($permissions);
        $rros->syncPermissions(['view dashboards', 'manage inventory', 'manage warehouses', 'process requests', 'monitor requests', 'manage dispatches', 'manage near expiry', 'export reports', 'view regional alert acknowledgements', 'assign ris drn']);
        $rrosAa->syncPermissions(['view dashboards', 'manage inventory', 'manage warehouses', 'process requests', 'monitor requests', 'manage dispatches', 'manage near expiry', 'export reports', 'view regional alert acknowledgements', 'assign ris drn']);
        $drrs->syncPermissions(['view dashboards', 'encode requests', 'monitor requests', 'manage near expiry', 'export reports', 'view regional alert acknowledgements']);
        $drrsAa->syncPermissions(['view dashboards', 'route epirma documents', 'view regional alert acknowledgements']);
        $drims->syncPermissions(['view dashboards', 'monitor requests', 'manage dromic reports', 'export reports', 'view regional alert acknowledgements']);
        $drmdAa->syncPermissions(['submit drmd aa requests', 'route lgu dromic requests', 'view regional alert acknowledgements']);
        $drmdChief->syncPermissions(['view dashboards', 'route lgu dromic requests', 'view regional alert acknowledgements']);
        $lgu->syncPermissions(['submit lgu dromic requests']);
        $financialAnalyst->syncPermissions(['view dashboards', 'manage standby funds', 'export reports', 'view regional alert acknowledgements']);
        $ocdCaraga->syncPermissions(['manage regional alerts', 'view regional alert acknowledgements']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $users = [
            ['name' => 'Super Admin', 'email' => 'superadmin@example.test', 'office' => 'Super Admin', 'role' => $superAdmin],
            ['name' => 'RROS Inventory Admin', 'email' => 'rros@example.test', 'office' => 'RROS', 'role' => $rros],
            ['name' => 'RROS Administrative Assistant', 'email' => 'rros-aa@example.test', 'office' => 'RROS AA', 'role' => $rrosAa],
            ['name' => 'DRRS Request Encoder', 'email' => 'drrs@example.test', 'office' => 'DRRS', 'role' => $drrs],
            ['name' => 'DRRS AA Officer', 'email' => 'drrs-aa@example.test', 'office' => 'DRRS AA', 'role' => $drrsAa],
            ['name' => 'DRIMS Monitoring Officer', 'email' => 'drims@example.test', 'office' => 'DRIMS', 'role' => $drims],
            ['name' => 'DRMD AA User', 'email' => 'drmd-aa@example.test', 'office' => 'DRMD AA', 'role' => $drmdAa],
            ['name' => 'DRMD Chief', 'email' => 'drmd-chief@example.test', 'office' => 'DRMD', 'role' => $drmdChief],
            ['name' => 'DRMD Financial Analyst', 'email' => 'financial@example.test', 'office' => 'DRMD Financial Analyst', 'role' => $financialAnalyst],
            ['name' => 'OCD Caraga', 'email' => 'ocd-caraga@dromis.local', 'username' => 'ocd-caraga', 'office' => 'Office of Civil Defense Caraga', 'role' => $ocdCaraga],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'username' => $data['username'] ?? null,
                    'office' => $data['office'],
                    'position' => 'System User',
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'access_status' => 'approved',
                    'access_approved_at' => now(),
                ]
            );
            $user->forceFill([
                'access_status' => 'approved',
                'access_approved_at' => $user->access_approved_at ?: now(),
            ])->save();
            $user->syncRoles([$data['role']]);
        }

        $this->call(JulietoUserSeeder::class);

        foreach (['Relief Augmentation', 'Preparedness for Response', 'Stock Replenishment', 'Prepositioning', 'Emergency Response', 'Other'] as $type) {
            AssessmentType::firstOrCreate(['name' => $type], ['is_active' => true]);
        }

        SystemSetting::setValue('default_region_code', '1600000000');

        $this->call(RequestSeeder::class);
    }
}
