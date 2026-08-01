<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->unsignedInteger('lgu_dromic_revision_number')
                ->default(0)
                ->after('lgu_dromic_report_number');
            $table->dropUnique('requests_lgu_dromic_series_report_unique');
            $table->unique(
                ['lgu_dromic_series_key', 'lgu_dromic_report_number', 'lgu_dromic_revision_number'],
                'requests_lgu_dromic_series_report_revision_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropUnique('requests_lgu_dromic_series_report_revision_unique');
            $table->dropColumn('lgu_dromic_revision_number');
            $table->unique(
                ['lgu_dromic_series_key', 'lgu_dromic_report_number'],
                'requests_lgu_dromic_series_report_unique',
            );
        });
    }
};
