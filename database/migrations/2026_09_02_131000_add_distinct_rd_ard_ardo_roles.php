<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'view dispatch delivery monitoring', 'guard_name' => 'web']);
        collect(['RD', 'ARD', 'ARDO'])->each(fn (string $name) =>
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->givePermissionTo($permission)
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->whereIn('name', ['RD', 'ARD', 'ARDO'])->get()
            ->each(fn (Role $role) => $role->revokePermissionTo('view dispatch delivery monitoring'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
