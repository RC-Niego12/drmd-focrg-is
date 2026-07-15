<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouses', 'rtef_capacity')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->decimal('rtef_capacity', 14, 2)->nullable();
            });
        }

        if (Schema::hasColumn('warehouses', 'capacity')) {
            DB::table('warehouses')
                ->whereNull('rtef_capacity')
                ->update(['rtef_capacity' => DB::raw('capacity')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouses', 'rtef_capacity')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->dropColumn('rtef_capacity');
            });
        }
    }
};
