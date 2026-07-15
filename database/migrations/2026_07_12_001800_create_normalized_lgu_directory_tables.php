<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lgu_directory_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_sheet', 10);
            $table->string('lgu_name');
            $table->string('psgc_code', 20)->nullable()->index();
            $table->string('congressional_district')->nullable();
            $table->text('office_address')->nullable();
            $table->string('source_updated_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['source_sheet', 'lgu_name']);
        });
        Schema::create('lgu_directory_officials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lgu_directory_entry_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('name');
            $table->string('position_designation')->nullable();
            $table->timestamps();
            $table->unique(['lgu_directory_entry_id', 'role']);
        });
        Schema::create('lgu_directory_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lgu_directory_entry_id')->constrained()->cascadeOnDelete();
            $table->string('owner_role', 40);
            $table->string('contact_type', 30);
            $table->text('value');
            $table->timestamps();
            $table->index(['owner_role', 'contact_type']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('lgu_directory_contacts');
        Schema::dropIfExists('lgu_directory_officials');
        Schema::dropIfExists('lgu_directory_entries');
    }
};
