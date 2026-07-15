<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_library_values', function (Blueprint $table): void {
            $table->id();
            $table->string('library_type')->index();
            $table->string('value');
            $table->string('context')->default('all')->index();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['library_type', 'value', 'context'], 'operational_library_unique_value');
        });
    }
    public function down(): void { Schema::dropIfExists('operational_library_values'); }
};
