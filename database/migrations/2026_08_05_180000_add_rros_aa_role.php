<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => 'assign ris drn',
            'guard_name' => 'web',
        ]);
        $role = Role::firstOrCreate([
            'name' => 'RROS AA',
            'guard_name' => 'web',
        ]);

        $rros = Role::query()->where('name', 'RROS')->where('guard_name', 'web')->first();
        if ($rros) {
            $role->syncPermissions($rros->permissions->pluck('name')->push($permission->name)->unique()->all());
        } else {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('name', 'RROS AA')->where('guard_name', 'web')->delete();
        Permission::query()->where('name', 'assign ris drn')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
