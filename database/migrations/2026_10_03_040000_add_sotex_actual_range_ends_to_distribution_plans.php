<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->date('work_schedule_end_on')->nullable()->after('work_schedule_on');
            $table->date('delivered_end_on')->nullable()->after('delivered_on');
            $table->date('distributed_end_on')->nullable()->after('distributed_on');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'work_schedule_end_on',
                'delivered_end_on',
                'distributed_end_on',
            ]);
        });
    }
};
