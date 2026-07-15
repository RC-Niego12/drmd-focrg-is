<?php

namespace Database\Seeders;

use App\Models\AssessmentType;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $rros = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
        $drrs = Role::firstOrCreate(['name' => 'DRRS', 'guard_name' => 'web']);
        $drims = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
        $drmdAa = Role::firstOrCreate(['name' => 'DRMD AA', 'guard_name' => 'web']);
        $financialAnalyst = Role::firstOrCreate(['name' => 'DRMD Financial Analyst', 'guard_name' => 'web']);

        $superAdmin->syncPermissions($permissions);
        $rros->syncPermissions(['view dashboards', 'manage inventory', 'manage warehouses', 'process requests', 'monitor requests', 'manage dispatches', 'manage near expiry', 'export reports']);
        $drrs->syncPermissions(['view dashboards', 'encode requests', 'monitor requests', 'manage near expiry', 'export reports']);
        $drims->syncPermissions(['view dashboards', 'monitor requests', 'manage dromic reports', 'export reports']);
        $drmdAa->syncPermissions(['submit drmd aa requests']);
        $financialAnalyst->syncPermissions(['view dashboards', 'manage standby funds', 'export reports']);

        $users = [
            ['name' => 'Super Admin', 'email' => 'superadmin@example.test', 'office' => 'Super Admin', 'role' => $superAdmin],
            ['name' => 'RROS Inventory Admin', 'email' => 'rros@example.test', 'office' => 'RROS', 'role' => $rros],
            ['name' => 'DRRS Request Encoder', 'email' => 'drrs@example.test', 'office' => 'DRRS', 'role' => $drrs],
            ['name' => 'DRIMS Monitoring Officer', 'email' => 'drims@example.test', 'office' => 'DRIMS', 'role' => $drims],
            ['name' => 'DRMD AA User', 'email' => 'drmd-aa@example.test', 'office' => 'DRMD AA', 'role' => $drmdAa],
            ['name' => 'DRMD Financial Analyst', 'email' => 'financial@example.test', 'office' => 'DRMD Financial Analyst', 'role' => $financialAnalyst],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
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

        foreach (['Relief Augmentation', 'Preparedness for Response', 'Stock Replenishment', 'Prepositioning', 'Emergency Response', 'Other'] as $type) {
            AssessmentType::firstOrCreate(['name' => $type], ['is_active' => true]);
        }

        SystemSetting::setValue('default_region_code', '1600000000');
    }
}
