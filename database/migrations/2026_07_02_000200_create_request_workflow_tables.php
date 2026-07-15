<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('incident_date')->nullable()->index();
            $table->string('province')->nullable()->index();
            $table->string('municipality')->nullable()->index();
            $table->string('barangay')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requesting_agency');
            $table->string('lgu')->nullable();
            $table->string('province')->index();
            $table->string('municipality')->index();
            $table->string('barangay')->nullable();
            $table->string('requester');
            $table->date('date_requested')->index();
            $table->string('purpose')->nullable();
            $table->text('assessment_summary')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->decimal('requested_quantity', 14, 2);
            $table->decimal('approved_quantity', 14, 2)->nullable();
            $table->string('unit');
            $table->string('priority')->default('normal')->index();
            $table->string('status')->default('pending')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision')->index();
            $table->text('remarks')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('plate_number')->unique();
            $table->string('description')->nullable();
            $table->decimal('capacity', 12, 2)->nullable();
            $table->string('status')->default('available')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('agency');
            $table->string('contact_person');
            $table->string('contact_number')->nullable();
            $table->string('province')->nullable()->index();
            $table->string('municipality')->nullable()->index();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dispatch_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('dispatch_number')->unique();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('receiver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination');
            $table->string('receiving_agency_lgu');
            $table->string('driver')->nullable();
            $table->string('dispatcher')->nullable();
            $table->date('dispatch_date')->index();
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('actual_arrival')->nullable();
            $table->string('status')->default('scheduled')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_plans');
        Schema::dropIfExists('receivers');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('request_items');
        Schema::dropIfExists('requests');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('assessment_types');
    }
};
