<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lgu_directory_officials', function (Blueprint $table): void {
            $table->string('id_number', 80)->nullable()->after('override_position_designation');
        });

        Schema::table('lgu_directory_lswdo_alternates', function (Blueprint $table): void {
            $table->string('id_number', 80)->nullable()->after('contact_number');
        });

        Schema::table('lgu_directory_ldrrmo_officers', function (Blueprint $table): void {
            $table->string('id_number', 80)->nullable()->after('designation');
        });

        Schema::create('lgu_directory_staff_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lgu_directory_entry_id')->constrained()->cascadeOnDelete();
            $table->string('staff_type', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('office')->nullable();
            $table->string('name')->nullable();
            $table->string('position')->nullable();
            $table->string('id_number', 80)->nullable();
            $table->string('contact_number', 500)->nullable();
            $table->boolean('is_locally_updated')->default(true);
            $table->timestamps();

            $table->index(['lgu_directory_entry_id', 'staff_type', 'sort_order'], 'lgu_staff_entry_type_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_directory_staff_members');

        Schema::table('lgu_directory_ldrrmo_officers', function (Blueprint $table): void {
            $table->dropColumn('id_number');
        });

        Schema::table('lgu_directory_lswdo_alternates', function (Blueprint $table): void {
            $table->dropColumn('id_number');
        });

        Schema::table('lgu_directory_officials', function (Blueprint $table): void {
            $table->dropColumn('id_number');
        });
    }
};
