<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'manage population', 'guard_name' => 'web']);
        $superAdmin = Role::where('name', 'Super Admin')->where('guard_name', 'web')->first();

        if ($superAdmin && ! $superAdmin->hasPermissionTo($permission)) {
            $superAdmin->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $superAdmin = Role::where('name', 'Super Admin')->where('guard_name', 'web')->first();
        $permission = Permission::where('name', 'manage population')->where('guard_name', 'web')->first();

        if ($superAdmin && $permission) {
            $superAdmin->revokePermissionTo($permission);
        }
    }
};
