<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->foreignId('requisition_issuance_slip_id')
                ->nullable()
                ->after('request_id')
                ->constrained('requisition_issuance_slips')
                ->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->json('mode_of_transportation')->nullable()->after('dispatcher');
            $table->json('vehicle_types')->nullable()->after('mode_of_transportation');
            $table->unsignedInteger('number_of_vehicles')->nullable()->after('vehicle_types');
            $table->string('driver_contact_number')->nullable()->after('driver');
            $table->string('vehicle_plate_number')->nullable()->after('driver_contact_number');

            $table->timestamp('warehouse_released_at')->nullable()->after('actual_arrival');
            $table->string('warehouse_released_by')->nullable()->after('warehouse_released_at');
            $table->timestamp('loaded_at')->nullable()->after('warehouse_released_by');
            $table->text('loading_remarks')->nullable()->after('loaded_at');
            $table->timestamp('departed_at')->nullable()->after('loading_remarks');

            $table->string('received_by')->nullable()->after('departed_at');
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->string('receiver_contact')->nullable()->after('received_at');
            $table->boolean('receipt_acknowledged')->default(false)->after('receiver_contact');
            $table->text('receipt_remarks')->nullable()->after('receipt_acknowledged');
            $table->json('status_timeline')->nullable()->after('receipt_remarks');
        });

        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->string('destination')->nullable()->change();
            $table->string('receiving_agency_lgu')->nullable()->change();
            $table->date('dispatch_date')->nullable()->change();
        });

        DB::table('dispatch_plans')
            ->where('status', 'scheduled')
            ->update(['status' => 'planned']);

        Schema::create('dispatch_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_plan_id')->constrained('dispatch_plans')->cascadeOnDelete();
            $table->foreignId('requisition_issuance_item_id')->nullable()->constrained('requisition_issuance_items')->nullOnDelete();
            $table->foreignId('request_item_id')->nullable()->constrained('request_items')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->unsignedInteger('allocated_quantity')->default(0);
            $table->unsignedInteger('loaded_quantity')->nullable();
            $table->unsignedInteger('received_quantity')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['dispatch_plan_id', 'item_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_plan_items');

        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requisition_issuance_slip_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'mode_of_transportation',
                'vehicle_types',
                'number_of_vehicles',
                'driver_contact_number',
                'vehicle_plate_number',
                'warehouse_released_at',
                'loaded_at',
                'loading_remarks',
                'warehouse_released_by',
                'departed_at',
                'received_by',
                'received_at',
                'receiver_contact',
                'receipt_acknowledged',
                'receipt_remarks',
                'status_timeline',
            ]);
        });

        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->string('destination')->nullable(false)->change();
            $table->string('receiving_agency_lgu')->nullable(false)->change();
            $table->date('dispatch_date')->nullable(false)->change();
        });

        DB::table('dispatch_plans')
            ->where('status', 'planned')
            ->update(['status' => 'scheduled']);
    }
};
