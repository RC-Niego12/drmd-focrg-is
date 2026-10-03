<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->date('proposal_target_on')->nullable()->after('released_quantity');
            $table->date('proposal_received_on')->nullable()->after('proposal_target_on');
            $table->date('proposal_reviewed_on')->nullable()->after('proposal_received_on');
            $table->date('compliance_on')->nullable()->after('proposal_reviewed_on');
            $table->date('compliance_target_on')->nullable()->after('compliance_on');
            $table->date('proposal_approved_on')->nullable()->after('compliance_target_on');
            $table->date('work_schedule_target_on')->nullable()->after('proposal_approved_on');
            $table->date('work_schedule_on')->nullable()->after('work_schedule_target_on');
            $table->string('delivery_mode')->nullable()->after('work_schedule_on');
            $table->date('delivery_target_on')->nullable()->after('delivery_mode');
            $table->date('delivered_on')->nullable()->after('delivery_target_on');
            $table->date('distribution_target_on')->nullable()->after('delivered_on');
            $table->date('distributed_on')->nullable()->after('distribution_target_on');
            $table->string('progress_status')->nullable()->after('distributed_on');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'proposal_target_on',
                'proposal_received_on',
                'proposal_reviewed_on',
                'compliance_on',
                'compliance_target_on',
                'proposal_approved_on',
                'work_schedule_target_on',
                'work_schedule_on',
                'delivery_mode',
                'delivery_target_on',
                'delivered_on',
                'distribution_target_on',
                'distributed_on',
                'progress_status',
            ]);
        });
    }
};
