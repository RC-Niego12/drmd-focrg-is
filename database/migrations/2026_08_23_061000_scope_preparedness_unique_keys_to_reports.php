<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_response_assets', function (Blueprint $table): void {
            $table->dropUnique(['label']);
            $table->unique(['preparedness_report_id', 'label'], 'preparedness_assets_report_label_unique');
        });
        Schema::table('preparedness_qrt_coverage_areas', function (Blueprint $table): void {
            $table->dropUnique(['area']);
            $table->unique(['preparedness_report_id', 'area'], 'preparedness_coverage_report_area_unique');
        });
        Schema::table('preparedness_qrt_specializations', function (Blueprint $table): void {
            $table->dropUnique(['specialization']);
            $table->unique(['preparedness_report_id', 'specialization'], 'preparedness_specializations_report_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('preparedness_response_assets', function (Blueprint $table): void {
            $table->dropUnique('preparedness_assets_report_label_unique');
            $table->unique('label');
        });
        Schema::table('preparedness_qrt_coverage_areas', function (Blueprint $table): void {
            $table->dropUnique('preparedness_coverage_report_area_unique');
            $table->unique('area');
        });
        Schema::table('preparedness_qrt_specializations', function (Blueprint $table): void {
            $table->dropUnique('preparedness_specializations_report_name_unique');
            $table->unique('specialization');
        });
    }
};
