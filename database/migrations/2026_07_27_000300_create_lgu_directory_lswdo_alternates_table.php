<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lgu_directory_lswdo_alternates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lgu_directory_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('name')->nullable();
            $table->string('position')->nullable();
            $table->text('contact_number')->nullable();
            $table->boolean('is_locally_updated')->default(false);
            $table->timestamps();
            $table->index(['lgu_directory_entry_id', 'sort_order'], 'lswdo_alt_entry_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_directory_lswdo_alternates');
    }
};
