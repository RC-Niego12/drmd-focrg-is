<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->text('return_reason')->nullable()->after('received_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->dropColumn('return_reason');
        });
    }
};
