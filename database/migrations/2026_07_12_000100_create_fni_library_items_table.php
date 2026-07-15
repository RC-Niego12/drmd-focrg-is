<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fni_library_items', function (Blueprint $table): void {
            $table->id();
            // Lengths leave room under MySQL utf8mb4's 3072-byte unique-key limit once UOM is added.
            $table->string('item_category', 100)->index();
            $table->string('item_name', 191)->index();
            $table->string('brand_description', 191)->default('');
            $table->timestamps();

            $table->unique(['item_category', 'item_name', 'brand_description'], 'fni_library_unique_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fni_library_items');
    }
};
