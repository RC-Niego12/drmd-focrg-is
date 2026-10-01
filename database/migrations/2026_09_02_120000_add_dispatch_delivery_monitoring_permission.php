<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'view dispatch delivery monitoring', 'guard_name' => 'web']);
        Role::query()->whereIn('name', [
            'Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA',
            'DRMD Chief', 'DRMD Financial Analyst', 'QRT', 'Quick Response Team',
            'Regional Director', 'RD', 'Assistant Regional Director', 'ARDO',
        ])->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view dispatch delivery monitoring')->delete();
    }
};
