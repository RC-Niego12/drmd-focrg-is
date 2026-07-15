<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouses')
            ->where('name', 'Regional Warehouse - Butuan')
            ->whereNull('external_warehouse_id')
            ->whereNull('master_synced_at')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'archived',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('warehouses')
            ->where('name', 'Regional Warehouse - Butuan')
            ->whereNull('external_warehouse_id')
            ->whereNull('master_synced_at')
            ->whereNotNull('deleted_at')
            ->update([
                'status' => 'active',
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
    }
};
