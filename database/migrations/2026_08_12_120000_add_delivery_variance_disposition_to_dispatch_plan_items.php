<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->foreignId('parent_dispatch_plan_id')->nullable()->after('id')->constrained('dispatch_plans')->nullOnDelete();
            $table->unsignedInteger('delivery_sequence')->default(1)->after('parent_dispatch_plan_id');
            $table->unsignedInteger('dr_series_offset')->default(0)->after('delivery_sequence');
        });
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->string('variance_disposition', 40)->nullable()->after('received_quantity');
            $table->text('variance_resolution')->nullable()->after('variance_disposition');
            $table->index(['dispatch_plan_id', 'variance_disposition'], 'dispatch_item_variance_disposition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->dropIndex('dispatch_item_variance_disposition_idx');
            $table->dropColumn(['variance_disposition', 'variance_resolution']);
        });
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_dispatch_plan_id');
            $table->dropColumn(['delivery_sequence', 'dr_series_offset']);
        });
    }
};
