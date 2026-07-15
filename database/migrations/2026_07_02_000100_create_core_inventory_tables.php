<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('province')->index();
            $table->string('municipality')->index();
            $table->unsignedInteger('capacity')->default(0);
            $table->string('contact_person')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('category', ['food', 'non_food'])->index();
            $table->string('unit');
            $table->string('status')->default('active')->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['name', 'unit']);
        });

        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('batch_number')->index();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->decimal('reserved_quantity', 14, 2)->default(0);
            $table->date('expiration_date')->nullable()->index();
            $table->date('date_received')->index();
            $table->string('source')->nullable();
            $table->string('current_status')->default('available')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['inventory_item_id', 'warehouse_id', 'batch_number']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->decimal('quantity', 14, 2);
            $table->decimal('balance_after', 14, 2)->default(0);
            $table->nullableMorphs('transactionable');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->string('status')->default('completed')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');
    }
};
