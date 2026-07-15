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
            $table->string('item_category')->index();
            $table->string('item_name')->index();
            $table->string('brand_description')->default('');
            $table->timestamps();

            $table->unique(['item_category', 'item_name', 'brand_description'], 'fni_library_unique_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fni_library_items');
    }
};
