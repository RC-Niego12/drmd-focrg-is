<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'submit drmd aa requests', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'DRMD AA', 'guard_name' => 'web'])->syncPermissions(['submit drmd aa requests']);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'DRMD AA')->first();
        if ($role) {
            $role->syncPermissions([]);
            $role->delete();
        }

        $permission = Permission::where('name', 'submit drmd aa requests')->first();
        if ($permission) {
            $permission->delete();
        }
    }
};
