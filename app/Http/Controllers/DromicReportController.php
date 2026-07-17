<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DromicReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard/Dromic', [
            'reports' => DromicReport::with(['request', 'request.items'])->latest()->paginate(15),
            'eligibleRequests' => AssistanceRequest::with(['items', 'incident', 'assessmentType'])
                ->whereIn('status', ['approved', 'partially_approved', 'released', 'completed'])
                ->whereHas('assessmentType', fn ($q) => $q->where('name', 'Relief Augmentation'))
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        abort_unless($request->user()?->can('manage dromic reports'), 403);

        $data = $request->validate([
            'request_id' => ['required', 'exists:requests,id'],
            'google_sheet_url' => ['nullable', 'url'],
            'worksheet_name' => ['nullable', 'string', 'max:255'],
        ]);

        $assistanceRequest = AssistanceRequest::with(['items', 'incident', 'assessmentType'])->findOrFail($data['request_id']);

        $report = DromicReport::create([
            'report_number' => 'DROMIC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
            'request_id' => $assistanceRequest->id,
            'incident_id' => $assistanceRequest->incident_id,
            'affected_lgu' => trim($assistanceRequest->province.' '.$assistanceRequest->municipality),
            'date_released' => now()->toDateString(),
            'purpose' => $assistanceRequest->purpose,
            'assessment' => $assistanceRequest->assessment_summary,
            'released_items' => $assistanceRequest->items->map(fn ($item) => [
                'name' => $item->item_name,
                'quantity' => $item->approved_quantity,
                'unit' => $item->unit,
            ])->values(),
            'google_sheet_url' => $data['google_sheet_url'] ?? config('services.google_sheets.url'),
            'worksheet_name' => $data['worksheet_name'] ?? config('services.google_sheets.worksheet'),
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        $audit->log('dromic_report.created', $report, [], $report->toArray());
        $workflowNotifications->notifyDromicCreated($assistanceRequest->fresh(['encoder']));

        return back()->with('success', 'DROMIC report created.');
    }
}
