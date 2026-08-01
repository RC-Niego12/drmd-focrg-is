<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_dromic_series_key', 64)->nullable()->after('lgu_report_status')->index();
            $table->unsignedInteger('lgu_dromic_report_number')->nullable()->after('lgu_dromic_series_key');
            $table->string('lgu_dromic_report_classification', 30)->default('regular')->after('lgu_dromic_report_number');
            $table->unsignedInteger('lgu_dromic_draft_save_count')->default(0)->after('lgu_dromic_report_classification');
            $table->timestamp('lgu_dromic_terminal_at')->nullable()->after('lgu_dromic_draft_save_count');
        });

        DB::table('requests')
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->orderBy('id')
            ->each(function (object $report): void {
                $isDraft = ($report->lgu_report_status ?: $report->status) === 'draft';
                DB::table('requests')->where('id', $report->id)->update([
                    'lgu_dromic_series_key' => hash('sha256', 'legacy-lgu-dromic-'.$report->id),
                    'lgu_dromic_report_number' => $isDraft ? null : 1,
                    'lgu_dromic_report_classification' => 'regular',
                    'lgu_dromic_draft_save_count' => $isDraft ? 1 : 0,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropIndex(['lgu_dromic_series_key']);
            $table->dropColumn([
                'lgu_dromic_series_key',
                'lgu_dromic_report_number',
                'lgu_dromic_report_classification',
                'lgu_dromic_draft_save_count',
                'lgu_dromic_terminal_at',
            ]);
        });
    }
};
