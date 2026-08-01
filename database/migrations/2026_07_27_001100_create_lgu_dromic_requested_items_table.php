<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_dromic_requested_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('fni_library_item_id')->constrained('fni_library_items')->restrictOnDelete();
            $table->decimal('requested_quantity', 14, 2);
            $table->timestamps();

            $table->unique(['request_id', 'fni_library_item_id'], 'lgu_dromic_requested_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_dromic_requested_items');
    }
};
