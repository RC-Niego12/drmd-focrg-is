<?php

namespace App\Http\Controllers;

use App\Models\PreparednessActionPage;
use App\Models\PreparednessBriefingIntro;
use App\Models\PreparednessQrtCoverageArea;
use App\Models\PreparednessQrtSpecialization;
use App\Models\PreparednessReport;
use App\Models\PreparednessResponseAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class PreparednessReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('PreparednessForResponse/Reports', [
            'reports' => PreparednessReport::query()->with(['creator:id,name', 'finalizer:id,name'])->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['reporting_schedule' => ['required', 'date', 'after:now']]);
        $report = DB::transaction(function () use ($data, $request): PreparednessReport {
            $templateTitle = PreparednessBriefingIntro::query()->whereNull('preparedness_report_id')->value('content->title_event')
                ?: 'Preparedness for Response';
            $report = PreparednessReport::query()->create([
                'title' => $templateTitle,
                'status' => 'draft',
                'reporting_as_of' => $data['reporting_schedule'],
                'revision_deadline' => $data['reporting_schedule'],
                'created_by' => $request->user()?->id,
            ]);
            $this->cloneTemplate(PreparednessBriefingIntro::class, $report, ['content', 'synopsis_image_path', 'dashboard_snapshot_path'], true);
            $this->cloneTemplate(PreparednessActionPage::class, $report, ['actions', 'captions', 'image_path', 'image_paths', 'sort_order']);
            $this->cloneTemplate(PreparednessResponseAsset::class, $report, ['label', 'quantity', 'status', 'image_path', 'sort_order']);
            $this->cloneTemplate(PreparednessQrtCoverageArea::class, $report, ['area', 'coverage_type', 'members', 'sort_order']);
            $this->cloneTemplate(PreparednessQrtSpecialization::class, $report, ['specialization', 'members', 'sort_order']);
            return $report;
        });

        return redirect()->route('preparedness-for-response.reports.show', $report)->with('success', 'Draft report created from the sample template.');
    }

    public function finalize(Request $request, PreparednessReport $report): RedirectResponse
    {
        abort_unless($report->isDraft(), 422, 'Only draft reports can be finalized.');
        $data = $request->validate(['data_snapshot' => ['required', 'array']]);
        $snapshotKeys = ['asOf', 'summary', 'provinceRows', 'warehouseRows', 'itemRows', 'itemScopeRows', 'bottledWaterVariants', 'alerts', 'operations', 'ffpSummary', 'standbyStockpileSummary'];
        $report->update(['status' => 'finalized', 'data_snapshot' => Arr::only($data['data_snapshot'], $snapshotKeys), 'finalized_at' => now(), 'finalized_by' => $request->user()?->id]);
        return back()->with('success', 'Report finalized. Finalized reports are read-only.');
    }

    public function revise(Request $request, PreparednessReport $report): RedirectResponse
    {
        abort_unless($report->status === 'finalized', 422, 'Only finalized reports can be reopened for revision.');
        abort_unless($report->canBeRevised(), 422, 'The reporting schedule has passed. This report can no longer be revised.');
        $report->update(['status' => 'revised', 'data_snapshot' => null, 'finalized_at' => null, 'finalized_by' => null]);
        return back()->with('success', 'Report reopened for revision. Finalize it again before downloading.');
    }

    public function destroy(PreparednessReport $report): RedirectResponse
    {
        $report->delete();
        return redirect()->route('preparedness-for-response.index')->with('success', 'Preparedness report deleted.');
    }

    private function cloneTemplate(string $model, PreparednessReport $report, array $columns, bool $single = false): void
    {
        $templates = $model::query()->whereNull('preparedness_report_id')->orderBy('id')->get();
        if ($single && $templates->isEmpty()) $templates = collect([new $model(['content' => []])]);
        foreach ($templates as $template) {
            $model::query()->create([...$template->only($columns), 'preparedness_report_id' => $report->id]);
        }
    }
}
