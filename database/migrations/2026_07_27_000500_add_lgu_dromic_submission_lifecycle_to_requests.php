<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_report_status')->nullable()->after('lgu_routing_status')->index();
            $table->timestamp('lgu_finalized_at')->nullable()->after('lgu_report_status');
            $table->timestamp('lgu_submitted_to_dswd_at')->nullable()->after('lgu_finalized_at');
            $table->string('lgu_signed_report_path')->nullable()->after('lgu_submitted_to_dswd_at');
            $table->string('lgu_signed_report_name')->nullable()->after('lgu_signed_report_path');
            $table->timestamp('lgu_signed_report_uploaded_at')->nullable()->after('lgu_signed_report_name');
            $table->string('lgu_signed_request_path')->nullable()->after('lgu_signed_report_uploaded_at');
            $table->string('lgu_signed_request_name')->nullable()->after('lgu_signed_request_path');
            $table->timestamp('lgu_signed_request_uploaded_at')->nullable()->after('lgu_signed_request_name');
            $table->timestamp('lgu_signed_copy_reminder_sent_at')->nullable()->after('lgu_signed_request_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropIndex(['lgu_report_status']);
            $table->dropColumn([
                'lgu_report_status',
                'lgu_finalized_at',
                'lgu_submitted_to_dswd_at',
                'lgu_signed_report_path',
                'lgu_signed_report_name',
                'lgu_signed_report_uploaded_at',
                'lgu_signed_request_path',
                'lgu_signed_request_name',
                'lgu_signed_request_uploaded_at',
                'lgu_signed_copy_reminder_sent_at',
            ]);
        });
    }
};
