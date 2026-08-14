<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_delivery_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('vehicle_index');
            $table->foreignId('reported_by')->constrained('users');
            $table->string('reporter_role', 60);
            $table->string('stage', 40);
            $table->dateTime('occurred_at');
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('message');
            $table->json('photo_paths')->nullable();
            $table->timestamps();
            $table->index(['dispatch_plan_id', 'vehicle_index', 'occurred_at'], 'dispatch_delivery_update_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_delivery_updates');
    }
};
