<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->boolean('compliance_target_na')->default(false)->after('compliance_target_on');
            $table->boolean('delivery_mode_na')->default(false)->after('delivery_mode');
            $table->boolean('delivery_target_na')->default(false)->after('delivery_target_end_on');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'compliance_target_na',
                'delivery_mode_na',
                'delivery_target_na',
            ]);
        });
    }
};
