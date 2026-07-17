<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use App\Support\AssessmentNarrative;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LguDromicRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isProvince = $user->lgu_level === 'province';
        $baseQuery = AssistanceRequest::query()
            ->with(['encoder:id,name', 'incident:id,name,incident_date,province,municipality,barangay'])
            ->where('submission_type', 'lgu_dromic_relief_request');

        if ($isProvince) {
            $baseQuery
                ->where('province', $user->lgu_name)
                ->where(function ($query) use ($user): void {
                    $query->whereNull('lgu_level')
                        ->orWhere('lgu_level', '!=', 'province')
                        ->orWhere('lgu_submitted_by', '!=', $user->id);
                });
        } else {
            $baseQuery->where(function ($query) use ($user): void {
                $query->where('lgu_submitted_by', $user->id)
                    ->orWhere('encoded_by', $user->id);
            });
        }

        $metricRows = (clone $baseQuery)->get(['id', 'municipality', 'affected_families', 'lgu_dromic_payload', 'lgu_routing_status']);

        return Inertia::render('Lgu/DromicRequests/Index', [
            'lguProfile' => [
                'name' => $user->lgu_name ?: $user->area_of_assignment ?: $user->name,
                'level' => $user->lgu_level ?: 'LGU',
                'psgc_code' => $user->lgu_psgc_code,
                'is_province' => $isProvince,
            ],
            'defaultIncidentDate' => now()->toDateString(),
            'monitoringSummary' => [
                'reports' => $metricRows->count(),
                'with_requests' => $metricRows->filter(fn (AssistanceRequest $row): bool => (bool) data_get($row->lgu_dromic_payload, 'has_relief_request'))->count(),
                'affected_families' => $metricRows->sum(fn (AssistanceRequest $row): int => (int) ($row->affected_families ?? data_get($row->lgu_dromic_payload, 'affected_families', 0))),
                'cities_municipalities' => $metricRows->pluck('municipality')->filter()->unique()->count(),
            ],
            'requests' => $baseQuery
                ->latest('submitted_at')
                ->paginate(12)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $data = $this->validatedPayload($request);
        $user = $request->user();
        abort_if($user->lgu_level === 'province', 403, 'PLGU accounts are for monitoring city/municipal LGU reports and cannot create separate reports.');
        $hasReliefRequest = (bool) ($data['has_relief_request'] ?? false);

        $record = DB::transaction(function () use ($data, $user, $hasReliefRequest): AssistanceRequest {
            $incident = Incident::create([
                'name' => $data['incident_name'],
                'incident_date' => $data['incident_date'],
                'province' => $data['province'],
                'municipality' => $data['municipality'],
                'barangay' => $data['barangay'] ?? null,
                'summary' => $data['incident_summary'] ?? null,
            ]);

            return AssistanceRequest::create([
                'reference_number' => 'LGU-DROMIC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'submission_type' => 'lgu_dromic_relief_request',
                'incident_id' => $incident->id,
                'encoded_by' => $user->id,
                'lgu_submitted_by' => $user->id,
                'requesting_agency' => $user->lgu_name ?: $data['requesting_lgu'],
                'lgu' => $user->lgu_name ?: $data['requesting_lgu'],
                'lgu_level' => $user->lgu_level ?: 'LGU',
                'province' => $data['province'],
                'municipality' => $data['municipality'],
                'barangay' => $data['barangay'] ?? null,
                'requester' => $data['requester_name'],
                'requester_position' => $data['requester_position'] ?? null,
                'requester_address' => $data['requester_address'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'date_requested' => now()->toDateString(),
                'purpose' => $hasReliefRequest ? 'DROMIC Report and Request for Relief Augmentation' : 'DROMIC Report',
                'assessment_summary' => $data['narrative'],
                'recommendations' => $data['recommendations'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'affected_families' => $data['affected_families'] ?? null,
                'assessment_form_data' => $data,
                'lgu_dromic_payload' => $data,
                'lgu_dromic_narrative' => $data['narrative'],
                'status' => $hasReliefRequest ? 'lgu_submitted' : 'dromic_report_submitted',
                'lgu_routing_status' => $hasReliefRequest ? 'for_drmd_aa_review' : 'report_only',
                'submitted_at' => now(),
            ]);
        });

        $audit->log('lgu_dromic.submitted', $record, [], $record->toArray());
        if ($hasReliefRequest) {
            $workflowNotifications->notifyLguDromicSubmitted($record->fresh(['encoder', 'lguSubmitter']));
        }

        return back()->with('success', $hasReliefRequest
            ? "{$record->reference_number} submitted with relief augmentation request. DRMD users have been notified for routing."
            : "{$record->reference_number} submitted as a DROMIC report. No relief request was routed.");
    }

    public function polish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:generate,polish'],
            'text' => ['nullable', 'string', 'max:12000'],
            'facts' => ['nullable', 'array'],
        ]);

        if ($data['mode'] === 'polish' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Write a draft narrative first, or use Auto-generate.'], 422);
        }

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') {
            return response()->json(['message' => 'Groq AI is not configured. Add GROQ_API_KEY, then clear config cache.'], 503);
        }

        $facts = collect($data['facts'] ?? [])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $key) => Str::headline((string) $key).': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value))
            ->implode("\n");

        try {
            $response = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(rtrim((string) config('services.groq.base_url'), '/').'/chat/completions', [
                'model' => config('services.groq.model'),
                'temperature' => $data['mode'] === 'generate' ? 0.25 : 0.12,
                'max_completion_tokens' => 800,
                'messages' => [
                    ['role' => 'system', 'content' => 'You help LGU focal persons prepare concise official DROMIC/SitRep narratives for DSWD Caraga. Use only supplied facts. Do not invent dates, population counts, barangays, damages, relief quantities, signatories, or approvals. Use formal disaster response tone. Return 2-4 cohesive paragraphs without headings or markdown.'],
                    ['role' => 'user', 'content' => ($data['mode'] === 'generate' ? 'Generate a narrative from these facts:' : 'Polish this narrative using only the facts below. Draft: '.($data['text'] ?? ''))."\n\nFacts:\n".$facts],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Groq AI could not be reached.'], 503);
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Groq API error '.$response->status().': '.$response->body()));
            return response()->json(['message' => 'Groq AI could not process the narrative.'], 502);
        }

        $polished = AssessmentNarrative::sanitize((string) data_get($response->json(), 'choices.0.message.content'));

        return $polished !== ''
            ? response()->json(['polished' => $polished, 'provider' => 'Groq', 'model' => config('services.groq.model')])
            : response()->json(['message' => 'Groq AI returned an empty result.'], 502);
    }

    public function pdf(Request $request, AssistanceRequest $assistanceRequest): HttpResponse
    {
        $user = $request->user();
        $canViewAsPlgu = $user->lgu_level === 'province' && $assistanceRequest->province === $user->lgu_name;
        abort_unless($user->can('route lgu dromic requests') || $assistanceRequest->lgu_submitted_by === $user->id || $canViewAsPlgu, 403);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $record = $assistanceRequest->load(['incident', 'lguSubmitter', 'drmdAssignedUser']);
        $filename = "LGU-DROMIC-{$record->reference_number}.pdf";
        $pdf = Pdf::loadView('documents.lgu-dromic', ['request' => $record])->setPaper('a4', 'portrait');

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'requesting_lgu' => ['required', 'string', 'max:255'],
            'requester_name' => ['required', 'string', 'max:255'],
            'requester_position' => ['nullable', 'string', 'max:255'],
            'requester_address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:80'],
            'has_relief_request' => ['nullable', 'boolean'],
            'incident_name' => ['required', 'string', 'max:255'],
            'incident_date' => ['required', 'date'],
            'incident_summary' => ['nullable', 'string', 'max:3000'],
            'province' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'affected_families' => ['nullable', 'integer', 'min:0'],
            'affected_persons' => ['nullable', 'integer', 'min:0'],
            'displaced_families' => ['nullable', 'integer', 'min:0'],
            'damaged_houses' => ['nullable', 'integer', 'min:0'],
            'casualties' => ['nullable', 'string', 'max:1000'],
            'needs' => ['nullable', 'string', 'max:3000'],
            'relief_requested' => ['nullable', 'required_if:has_relief_request,true', 'string', 'max:4000'],
            'narrative' => ['required', 'string', 'max:12000'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
