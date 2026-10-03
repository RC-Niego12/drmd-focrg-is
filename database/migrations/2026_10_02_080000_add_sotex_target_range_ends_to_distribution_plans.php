<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->date('work_schedule_target_end_on')->nullable()->after('work_schedule_target_on');
            $table->date('delivery_target_end_on')->nullable()->after('delivery_target_on');
            $table->date('distribution_target_end_on')->nullable()->after('distribution_target_on');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'work_schedule_target_end_on',
                'delivery_target_end_on',
                'distribution_target_end_on',
            ]);
        });
    }
};
