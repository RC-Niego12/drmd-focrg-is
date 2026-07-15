<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_library_values', function (Blueprint $table): void {
            $table->id();
            $table->string('library_type')->index();
            $table->string('value');
            $table->enum('applicability', ['all', 'prepositioning', 'other'])->default('all')->index();
            $table->timestamps();
            $table->unique(['library_type', 'value', 'applicability'], 'warehouse_library_unique_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_library_values');
    }
};
