<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'view dispatch delivery monitoring',
            'guard_name' => 'web',
        ]);

        collect(['Regional Director', 'Assistant Regional Director'])
            ->each(function (string $name) use ($permission): void {
                Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                    ->givePermissionTo($permission);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve roles and user assignments during rollback. Only the feature
        // permission is detached; the earlier monitoring migration owns it.
        Role::query()->whereIn('name', ['Regional Director', 'Assistant Regional Director'])
            ->get()->each(fn (Role $role) => $role->revokePermissionTo('view dispatch delivery monitoring'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
