<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparedness_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['preparedness_briefing_intros', 'preparedness_action_pages', 'preparedness_response_assets', 'preparedness_qrt_coverage_areas', 'preparedness_qrt_specializations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('preparedness_report_id')->nullable()->after('id')->constrained('preparedness_reports')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['preparedness_briefing_intros', 'preparedness_action_pages', 'preparedness_response_assets', 'preparedness_qrt_coverage_areas', 'preparedness_qrt_specializations'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('preparedness_report_id'));
        }
        Schema::dropIfExists('preparedness_reports');
    }
};
