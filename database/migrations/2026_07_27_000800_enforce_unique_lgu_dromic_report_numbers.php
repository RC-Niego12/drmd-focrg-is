<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->unique(
                ['lgu_dromic_series_key', 'lgu_dromic_report_number'],
                'requests_lgu_dromic_series_report_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropUnique('requests_lgu_dromic_series_report_unique');
        });
    }
};
