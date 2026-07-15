<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('warehouse_number')->nullable()->after('external_warehouse_id');
            $table->string('office')->nullable()->after('warehouse_number');
            $table->string('district')->nullable()->after('municipality');
            $table->string('barangay_name')->nullable()->after('district');
            $table->string('barangay_code')->nullable()->after('barangay_name');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('distribution_network')->nullable()->after('email');
            $table->string('warehouse_type')->nullable()->after('distribution_network');
            $table->string('category')->nullable()->after('warehouse_type');
            $table->string('ownership')->nullable()->after('category');
            $table->string('partnership')->nullable()->after('ownership');
            $table->decimal('ffp_capacity', 14, 2)->nullable()->after('capacity');
            $table->decimal('sheet_ffp_current', 14, 2)->nullable()->after('ffp_capacity');
            $table->decimal('sheet_ffp_cost', 14, 2)->nullable()->after('sheet_ffp_current');
            $table->decimal('sheet_total_items', 14, 2)->nullable()->after('sheet_ffp_cost');
            $table->decimal('sheet_total_cost', 14, 2)->nullable()->after('sheet_total_items');
            $table->decimal('longitude', 11, 8)->nullable()->after('sheet_total_cost');
            $table->decimal('latitude', 11, 8)->nullable()->after('longitude');
            $table->date('rpa_start_date')->nullable()->after('latitude');
            $table->date('rpa_end_date')->nullable()->after('rpa_start_date');
            $table->string('validity')->nullable()->after('rpa_end_date');
            $table->unsignedInteger('population')->nullable()->after('validity');
            $table->unsignedInteger('poor_families')->nullable()->after('population');
            $table->unsignedInteger('poor_individuals')->nullable()->after('poor_families');
            $table->text('designated_storekeepers')->nullable()->after('poor_individuals');
            $table->string('storekeeper_contact_number')->nullable()->after('designated_storekeepers');
            $table->json('sheet_payload')->nullable()->after('storekeeper_contact_number');
            $table->timestamp('master_synced_at')->nullable()->after('sheet_payload');
            $table->index(['office', 'province', 'municipality']);
            $table->index('warehouse_type');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex(['office', 'province', 'municipality']);
            $table->dropIndex(['warehouse_type']);
            $table->dropColumn([
                'warehouse_number',
                'office',
                'district',
                'barangay_name',
                'barangay_code',
                'email',
                'distribution_network',
                'warehouse_type',
                'category',
                'ownership',
                'partnership',
                'ffp_capacity',
                'sheet_ffp_current',
                'sheet_ffp_cost',
                'sheet_total_items',
                'sheet_total_cost',
                'longitude',
                'latitude',
                'rpa_start_date',
                'rpa_end_date',
                'validity',
                'population',
                'poor_families',
                'poor_individuals',
                'designated_storekeepers',
                'storekeeper_contact_number',
                'sheet_payload',
                'master_synced_at',
            ]);
        });
    }
};
