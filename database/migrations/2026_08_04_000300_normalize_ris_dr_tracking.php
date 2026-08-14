<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->foreignId('request_id')->nullable()->change();
            $table->string('assessment_drn_for_ris')->nullable()->index();
            $table->string('prepared_by_name')->nullable();
            $table->string('purpose_of_request')->nullable();
            $table->string('incident_type')->nullable();
            $table->string('incident_specification')->nullable();
            $table->string('dr_number')->nullable()->unique();
            $table->string('ris_drn')->nullable();
            $table->string('item_category')->nullable();
            $table->date('ardo_endorsed_at')->nullable();
            $table->date('ardo_returned_at')->nullable();
            $table->date('delivered_at')->nullable();
            $table->string('release_witnessed_by')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_contact_number', 80)->nullable();
            $table->string('vehicle_plate_number', 80)->nullable();
            $table->string('received_by')->nullable();
            $table->date('date_received')->nullable();
            $table->boolean('fully_delivered')->nullable();
            $table->boolean('has_returned_items')->nullable();
            $table->text('returned_particulars')->nullable();
            $table->decimal('returned_quantity', 14, 2)->nullable();
            $table->text('returned_reason')->nullable();
            $table->boolean('forwarded_to_accounting')->nullable();
            $table->date('forwarded_to_accounting_at')->nullable();
            $table->string('accounting_received_by')->nullable();
            $table->text('assessment_link')->nullable();
            $table->text('ris_link')->nullable();
            $table->text('rds_link')->nullable();
            $table->text('csmr_link')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('sync_source', 30)->default('system');
            $table->timestamp('sheet_synced_at')->nullable();
        });

        Schema::create('requisition_issuance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requisition_issuance_slip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_item_id')->nullable()->constrained('request_items')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('unit')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 14, 2);
            $table->string('warehouse_name')->nullable();
            $table->string('warehouse_type')->nullable();
            $table->decimal('allocation_guide', 14, 2)->nullable();
            $table->decimal('wit_stock_balance', 14, 2)->nullable();
            $table->decimal('remaining_balance', 14, 2)->nullable();
            $table->string('allocation_status')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamps();
            $table->index(['requisition_issuance_slip_id', 'item_name']);
        });

        Schema::create('ris_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 30);
            $table->string('status', 30)->index();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('items_synced')->default(0);
            $table->json('changes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ris_sync_runs');
        Schema::dropIfExists('requisition_issuance_items');
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->dropColumn([
                'assessment_drn_for_ris', 'prepared_by_name', 'purpose_of_request', 'incident_type', 'incident_specification',
                'dr_number', 'ris_drn', 'item_category', 'ardo_endorsed_at', 'ardo_returned_at', 'delivered_at',
                'release_witnessed_by', 'driver_name', 'driver_contact_number', 'vehicle_plate_number',
                'received_by', 'date_received', 'fully_delivered', 'has_returned_items', 'returned_particulars',
                'returned_quantity', 'returned_reason', 'forwarded_to_accounting', 'forwarded_to_accounting_at',
                'accounting_received_by', 'assessment_link', 'ris_link', 'rds_link', 'csmr_link',
                'source_row_number', 'sync_source', 'sheet_synced_at',
            ]);
            $table->foreignId('request_id')->nullable(false)->change();
        });
    }
};
