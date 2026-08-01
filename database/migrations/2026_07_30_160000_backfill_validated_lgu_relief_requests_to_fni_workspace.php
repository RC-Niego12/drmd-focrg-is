<?php

use App\Models\AssistanceRequest;
use App\Services\LguReliefRequestHandoffService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $handoff = app(LguReliefRequestHandoffService::class);

        AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_relief_request_reference')
            ->whereNotNull('lgu_signed_request_path')
            ->where('lgu_relief_validation_status', 'validated_no_findings')
            ->oldest('id')
            ->eachById(function (AssistanceRequest $report) use ($handoff): void {
                $handoff->handoff($report, $report->lgu_relief_reviewed_by);
            });
    }

    public function down(): void
    {
        // Operational FNI records may already contain DRNs, assessments, or actions.
        // They are deliberately retained to avoid destroying request-processing history.
    }
};
