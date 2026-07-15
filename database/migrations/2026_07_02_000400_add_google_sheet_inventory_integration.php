<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('external_warehouse_id')->nullable()->unique()->after('id');
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->date('transaction_date')->nullable()->after('type')->index();
            $table->string('source_of_goods')->nullable()->after('transaction_date');
            $table->string('purpose')->nullable()->after('source_of_goods')->index();
            $table->string('reference_number')->nullable()->after('purpose')->index();
            $table->string('ris_if_stf')->nullable()->after('reference_number');
            $table->string('call_off_number')->nullable()->after('ris_if_stf');
            $table->string('sender_supplier')->nullable()->after('call_off_number');
            $table->decimal('unit_cost', 14, 2)->nullable()->after('quantity');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
            $table->string('recipient')->nullable()->after('total_cost');
            $table->string('delivery_site')->nullable()->after('recipient');
            $table->date('expected_delivery_date')->nullable()->after('delivery_site');
            $table->json('transport_details')->nullable()->after('expected_delivery_date');
            $table->string('external_status')->nullable()->after('transport_details');
            $table->string('encoded_by_email')->nullable()->after('external_status');
            $table->timestamp('encoded_at')->nullable()->after('encoded_by_email');
            $table->string('edited_by_email')->nullable()->after('encoded_at');
            $table->timestamp('edited_at')->nullable()->after('edited_by_email');
        });

        Schema::create('warehouse_sheet_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_id')->index();
            $table->string('gid')->index();
            $table->unsignedInteger('sheet_row_number');
            $table->string('row_hash')->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->json('raw_payload');
            $table->string('import_status')->default('imported')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'gid', 'sheet_row_number', 'row_hash'], 'sheet_import_unique_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sheet_imports');

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'transaction_date',
                'source_of_goods',
                'purpose',
                'reference_number',
                'ris_if_stf',
                'call_off_number',
                'sender_supplier',
                'unit_cost',
                'total_cost',
                'recipient',
                'delivery_site',
                'expected_delivery_date',
                'transport_details',
                'external_status',
                'encoded_by_email',
                'encoded_at',
                'edited_by_email',
                'edited_at',
            ]);
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn('external_warehouse_id');
        });
    }
};
