<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->string('return_condition', 40)->nullable()->after('variance_resolution');
            $table->string('return_stock_disposition', 40)->nullable()->after('return_condition');
            $table->dateTime('return_received_at')->nullable()->after('return_stock_disposition');
            $table->string('return_inspected_by')->nullable()->after('return_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plan_items', function (Blueprint $table): void {
            $table->dropColumn(['return_condition', 'return_stock_disposition', 'return_received_at', 'return_inspected_by']);
        });
    }
};
