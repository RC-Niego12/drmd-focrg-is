<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', fn (Blueprint $table) => $table->string('assessment_status', 20)->nullable()->after('assessment_form_data')->index());
        Schema::table('request_items', function (Blueprint $table): void {
            $table->foreignId('source_warehouse_id')->nullable()->after('fni_library_item_id')->constrained('warehouses')->nullOnDelete();
            $table->decimal('available_quantity', 14, 2)->nullable()->after('requested_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_warehouse_id');
            $table->dropColumn('available_quantity');
        });
        Schema::table('requests', fn (Blueprint $table) => $table->dropColumn('assessment_status'));
    }
};
