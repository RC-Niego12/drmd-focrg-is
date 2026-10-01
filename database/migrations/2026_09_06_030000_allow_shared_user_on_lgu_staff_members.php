<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow one LGU login to hold multiple staff slots (e.g. WH focal + storekeeper)
        // and to also be linked from officials / alternates / officers in other tables.
        Schema::table('lgu_directory_staff_members', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_staff_members', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });
    }
};
