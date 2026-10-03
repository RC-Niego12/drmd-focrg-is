<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->string('lgu')->nullable()->after('beneficiary');
            $table->string('item_name')->nullable()->after('lgu');
            $table->string('brand')->nullable()->after('item_name');
            $table->string('expiry_month')->nullable()->after('brand');
            $table->foreignId('warehouse_id')->nullable()->after('expiry_month')->constrained()->nullOnDelete();
            $table->string('source_warehouse_name')->nullable()->after('warehouse_id');
            $table->string('district')->nullable()->after('source_warehouse_name');
            $table->string('partnership')->nullable()->after('district');
            $table->decimal('allocated_quantity', 14, 2)->default(0)->after('quantity');
            $table->decimal('released_quantity', 14, 2)->default(0)->after('allocated_quantity');
        });

        DB::table('distribution_plans')->update([
            'allocated_quantity' => DB::raw('quantity'),
            'lgu' => DB::raw('COALESCE(beneficiary, location)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn([
                'lgu',
                'item_name',
                'brand',
                'expiry_month',
                'source_warehouse_name',
                'district',
                'partnership',
                'allocated_quantity',
                'released_quantity',
            ]);
        });
    }
};
