<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view dashboards', 'guard_name' => 'web']);
        $submit = Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
        $route = Permission::firstOrCreate(['name' => 'route lgu dromic requests', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web'])->syncPermissions([$submit]);
        Role::firstOrCreate(['name' => 'DRMD Chief', 'guard_name' => 'web'])->syncPermissions(['view dashboards', $route->name]);

        $drmdAa = Role::where('name', 'DRMD AA')->first();
        if ($drmdAa) {
            $drmdAa->givePermissionTo($route);
        }

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo([$submit, $route]);
        }
    }

    public function down(): void
    {
        foreach (Role::whereIn('name', ['LGU', 'DRMD Chief', 'DRMD AA', 'Super Admin'])->get() as $role) {
            $role->revokePermissionTo(['submit lgu dromic requests', 'route lgu dromic requests']);
        }
    }
};
