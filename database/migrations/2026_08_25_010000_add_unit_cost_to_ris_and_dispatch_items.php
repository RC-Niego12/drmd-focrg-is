<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('quantity');
        });
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('received_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plan_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('requisition_issuance_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
    }
};
