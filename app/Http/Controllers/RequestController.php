<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistanceRequestRequest;
use App\Models\AssessmentType;
use App\Models\AssistanceRequest;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\FniLibraryItem;
use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Models\RequestParty;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\InventoryBalanceService;
use App\Services\RequestPartySheetService;
use App\Services\ResponseLetterDocumentService;
use App\Services\WorkflowNotificationService;
use App\Services\WordToPdfService;
use App\Support\DocumentReferenceNumber;
use App\Support\AssessmentNarrative;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;

class RequestController extends Controller
{
    public function drrsRequests(Request $request): Response
    {
        $request->merge(['status' => 'actionable', 'default_tab' => 'tracker']);

        return $this->index($request);
    }

    public function index(Request $request, InventoryBalanceService $inventoryBalanceService): Response|RedirectResponse
    {
        if ($request->filled(['epirma_request', 'token', 'status'])) {
            $assistanceRequest = AssistanceRequest::query()->findOrFail($request->integer('epirma_request'));

            return app(EpirmaSigningController::class)->callback(
                $request,
                $assistanceRequest,
                app(AuditLogger::class)
            );
        }

        if (RequestParty::query()->doesntExist()) {
            try {
                app(RequestPartySheetService::class)->sync();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $regionCode = SystemSetting::getValue('default_region_code', '1600000000');
        $provinces = PsgcAddress::query()->where('is_active', true)->where('level', 'province')->where('parent_code', $regionCode)->orderBy('name')->get(['code', 'name']);
        $provinceCodes = $provinces->pluck('code');
        $municipalities = PsgcAddress::query()
            ->where('is_active', true)
            ->whereIn('level', ['city', 'municipality', 'city_municipality'])
            ->where(fn ($query) => $query->whereIn('parent_code', $provinceCodes)->orWhere('parent_code', $regionCode))
            ->orderBy('name')
            ->get(['code', 'parent_code', 'name', 'type']);
        $barangays = PsgcAddress::query()->where('is_active', true)->where('level', 'barangay')->whereIn('parent_code', $municipalities->pluck('code'))->orderBy('name')->get(['code', 'parent_code', 'name']);
        $warehouseStock = $inventoryBalanceService->balanceRows()
            ->map(fn (array $row): array => [
                'warehouse_id' => $row['warehouse_id'] ?? null,
                'warehouse' => $row['warehouse'] ?? 'Unnamed Warehouse',
                'item' => $row['item'] ?? '',
                'uom' => $row['uom'] ?? '',
                'available' => max(0, (float) ($row['available_balance'] ?? 0)),
            ])->filter(fn (array $row): bool => filled($row['warehouse_id']) && filled($row['item']))->values();

        return Inertia::render('Requests/Index', [
            'requests' => AssistanceRequest::query()
                ->with(['items', 'assessmentType', 'incident', 'encoder'])
                ->when($request->status === 'actionable', fn ($q) => $q->whereIn('status', ['endorsed', 'submitted', 'under_review', 'acted']))
                ->when($request->status && $request->status !== 'actionable', fn ($q, $status) => $q->where('status', $status))
                ->when($request->search, function ($q, $search): void {
                    $q->where(function ($query) use ($search): void {
                        $query->where('reference_number', 'like', "%{$search}%")
                            ->orWhere('requesting_agency', 'like', "%{$search}%")
                            ->orWhere('requester', 'like', "%{$search}%")
                            ->orWhere('province', 'like', "%{$search}%")
                            ->orWhere('municipality', 'like', "%{$search}%");
                    });
                })
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'assessments' => AssistanceRequest::query()
                ->with(['items.sourceWarehouse:id,name,province', 'incident', 'encoder:id,name'])
                ->whereNotNull('assessment_status')
                ->latest('updated_at')
                ->paginate(15, ['*'], 'assessments_page')
                ->withQueryString(),
            'filters' => $request->only(['search', 'status']),
            'assessmentTypes' => AssessmentType::where('is_active', true)->orderBy('name')->get(),
            'inventoryItems' => InventoryItem::where('status', 'active')->orderBy('name')->get(),
            'fniLibraryItems' => FniLibraryItem::query()->orderBy('item_category')->orderBy('item_name')->orderBy('brand_description')->get(),
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
            'drrsSignatories' => OperationalLibraryValue::query()->where('library_type', 'drrs_signatory')->where('is_active', true)->orderBy('context')->get(['id', 'value', 'context']),
            'drnPrefixes' => OperationalLibraryValue::query()->where('library_type', 'drn_prefix')->where('is_active', true)->orderBy('context')->orderBy('value')->get(['id', 'value', 'context']),
            'requestParties' => RequestParty::query()->with('lguDirectoryEntry:id,psgc_code,lgu_name,override_lgu_name')->where('is_active', true)->orderBy('requesting_party')->orderBy('office_agency_details')->get(),
            'psgc' => ['provinces' => $provinces, 'municipalities' => $municipalities, 'barangays' => $barangays],
            'socialWorkers' => User::query()->role('DRRS')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouseStock' => $warehouseStock,
            'defaultTab' => $request->get('default_tab', $request->get('tab', 'tracker')),
        ]);
    }

    public function store(AssistanceRequestRequest $request, AuditLogger $audit): RedirectResponse
    {
        $assistanceRequest = DB::transaction(function () use ($request): AssistanceRequest {
            $incident = $request->input('purpose') === 'Relief Augmentation' ? Incident::create([
                'name' => $request->string('incident_name'),
                'incident_date' => $request->date('incident_date'),
                'province' => $request->province,
                'municipality' => $request->municipality,
                'barangay' => $request->barangay,
                'summary' => $request->assessment_summary,
            ]) : null;

            $record = AssistanceRequest::create([
                ...$request->safe()->except(['incident_name', 'incident_date', 'items']),
                'reference_number' => 'REQ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'incident_id' => $incident?->id,
                'encoded_by' => $request->user()->id,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            foreach ($request->validated('items') as $item) {
                $record->items()->create($item);
            }

            return $record;
        });

        $audit->log('request.submitted', $assistanceRequest, [], $assistanceRequest->load('items')->toArray());

        return redirect()->route('requests.assessment', $assistanceRequest)->with('success', 'Assessment submitted and document files are ready.');
    }

    public function approve(Request $request, AssistanceRequest $assistanceRequest, InventoryService $inventory, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        abort_unless($request->user()?->can('process requests'), 403);
        abort_unless($assistanceRequest->assessment_status === 'submitted', 422, 'Submit the finalized assessment before recording a decision.');
        abort_if(in_array($assistanceRequest->status, ['approved', 'partially_approved', 'rejected'], true), 422, 'A decision has already been recorded for this assessment.');

        $data = $request->validate([
            'decision' => ['required', 'in:approved,partially_approved,rejected'],
            'remarks' => ['nullable', 'string'],
            'items' => ['array'],
            'items.*.id' => ['required', 'exists:request_items,id'],
            'items.*.approved_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($assistanceRequest, $data, $inventory, $audit): void {
            foreach ($data['items'] ?? [] as $itemData) {
                $assistanceRequest->items()->whereKey($itemData['id'])->update([
                    'approved_quantity' => $itemData['approved_quantity'],
                    'status' => ($itemData['approved_quantity'] ?? 0) > 0 ? 'approved' : 'rejected',
                ]);
            }

            $assistanceRequest->update(['status' => $data['decision']]);
            $assistanceRequest->approvals()->create([
                'approved_by' => auth()->id(),
                'decision' => $data['decision'],
                'remarks' => $data['remarks'] ?? null,
                'decided_at' => now(),
            ]);

            if (in_array($data['decision'], ['approved', 'partially_approved'], true)) {
                $inventory->reserveForRequest($assistanceRequest->fresh('items'));
            }

            $audit->log('request.decision_recorded', $assistanceRequest, [], $assistanceRequest->fresh('items')->toArray());
        });
        $workflowNotifications->notifyRrosDecisionRecorded($assistanceRequest->fresh(['encoder', 'items', 'assessmentType']));

        return back()->with('success', 'Request decision recorded.');
    }

    public function assessmentForm(AssistanceRequest $assistanceRequest): Response
    {
        return Inertia::render('Requests/AssessmentForm', [
            'request' => $assistanceRequest->load(['items', 'assessmentType', 'incident', 'encoder']),
            'drnPrefixes' => OperationalLibraryValue::query()->where('library_type', 'drn_prefix')->where('is_active', true)->orderBy('context')->orderBy('value')->get(['id', 'value', 'context']),
        ]);
    }

    public function sourceDocument(AssistanceRequest $assistanceRequest): BinaryFileResponse
    {
        abort_if(blank($assistanceRequest->source_document_url), 404, 'This transaction has no uploaded source document.');

        $urlPath = (string) parse_url($assistanceRequest->source_document_url, PHP_URL_PATH);
        $storagePath = ltrim((string) str($urlPath)->after('/storage/'), '/');
        abort_unless($storagePath !== '' && Storage::disk('public')->exists($storagePath), 404, 'The uploaded source document could not be found.');

        $absolutePath = Storage::disk('public')->path($storagePath);

        return response()->file($absolutePath, [
            'Content-Disposition' => 'inline; filename="'.basename($absolutePath).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function assessmentPdf(Request $request, AssistanceRequest $assistanceRequest): HttpResponse
    {
        $record = $assistanceRequest->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']);
        $requestedMargin = (int) $request->integer('margin', 18);
        $pageMargin = in_array($requestedMargin, [18, 27, 36, 54, 72], true) ? $requestedMargin : 18;
        $pdf = Pdf::loadView('documents.assessment', ['request' => $record, 'pageMargin' => $pageMargin])->setPaper('a4', 'portrait');
        $filename = "Assessment-{$record->reference_number}.pdf";
        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    public function updateAssessment(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'assessment_type_id' => ['nullable', 'exists:assessment_types,id'], 'purpose' => ['nullable', 'string', 'max:255'],
            'incident_name' => ['nullable', 'required_if:purpose,Relief Augmentation', 'string', 'max:255'], 'incident_date' => ['nullable', 'required_if:purpose,Relief Augmentation', 'date'],
            'incident_details' => ['nullable', 'string', 'max:255'], 'incident_count' => ['nullable', 'integer', 'min:1'],
            'affected_families' => ['nullable', 'integer', 'min:0'], 'assigned_social_worker' => ['nullable', 'string', 'max:255'],
            'assessment_drn' => ['nullable', 'string', 'max:255'], 'assessment_summary' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'], 'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.id' => ['required', 'exists:request_items,id'],
            'items.*.requested_quantity' => ['required', 'numeric', 'min:0.01'], 'items.*.priority' => ['required', 'in:low,normal,high,urgent'],
            'items.*.remarks' => ['nullable', 'string'],
        ]);
        $old = $assistanceRequest->load('items')->toArray();
        $data['recommendations'] = AssessmentNarrative::sanitize($data['recommendations'] ?? null);
        DB::transaction(function () use ($assistanceRequest, $data): void {
            $oldIncident = $assistanceRequest->incident;
            $incident = null;
            if (($data['purpose'] ?? null) === 'Relief Augmentation') {
                $incident = Incident::query()->updateOrCreate(['id' => $assistanceRequest->incident_id], [
                    'name' => $data['incident_name'],
                    'incident_date' => $data['incident_date'],
                ]);
            }
            $assistanceRequest->update([
                ...collect($data)->except(['incident_name', 'incident_date', 'items'])->all(),
                'incident_id' => $incident?->id,
            ]);
            if (! $incident && $oldIncident && $oldIncident->requests()->doesntExist()) $oldIncident->delete();
            foreach ($data['items'] as $item) {
                $assistanceRequest->items()->whereKey($item['id'])->update(collect($item)->except('id')->all());
            }
        });
        $audit->log('request.assessment_updated', $assistanceRequest, $old, $assistanceRequest->fresh(['items', 'incident'])->toArray());
        return back()->with('success', 'Assessment saved and ready for printing.');
    }

    public function completeAssessment(AssistanceRequestRequest $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse
    {
        abort_unless($assistanceRequest->endorsed_to_drrs, 422, 'Only records endorsed to DRRS can be assessed through this workflow.');

        $data = $request->validated();
        $data['recommendations'] = AssessmentNarrative::sanitize($data['recommendations'] ?? null);
        $old = $assistanceRequest->load(['items', 'incident'])->toArray();

        DB::transaction(function () use ($assistanceRequest, $data): void {
            $oldIncident = $assistanceRequest->incident;
            $incident = null;
            if (($data['purpose'] ?? null) === 'Relief Augmentation') {
                $incident = Incident::query()->updateOrCreate(['id' => $assistanceRequest->incident_id], [
                    'name' => $data['incident_name'],
                    'incident_date' => $data['incident_date'],
                    'province' => $data['province'] ?? null,
                    'municipality' => $data['municipality'] ?? null,
                    'barangay' => $data['barangay'] ?? null,
                    'summary' => $data['assessment_summary'] ?? null,
                ]);
            }

            $assistanceRequest->update([
                ...collect($data)->except(['incident_name', 'incident_date', 'items'])->all(),
                'incident_id' => $incident?->id,
                'status' => 'under_review',
                'assessment_status' => 'draft',
            ]);

            $assistanceRequest->items()->delete();
            foreach ($data['items'] as $item) {
                $assistanceRequest->items()->create($item);
            }
            if (! $incident && $oldIncident && $oldIncident->requests()->doesntExist()) $oldIncident->delete();
        });

        $fresh = $assistanceRequest->fresh(['items', 'incident', 'requestParty']);
        $audit->log('request.assessment_completed', $fresh, $old, $fresh->toArray());

        return back()->with('success', "Assessment for {$fresh->reference_number} saved. Assessment and response-letter downloads are ready.");
    }

    public function assessmentStatus(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $data = $request->validate(['assessment_status' => ['required', 'in:draft,final,submitted']]);
        abort_if(blank($assistanceRequest->assessment_status), 422, 'Create the assessment before changing its status.');
        $old = $assistanceRequest->assessment_status;
        $allowed = match ($old) {
            // Drafts become final only after the e-PIRMA callback verifies a
            // successful signature. A direct status request cannot bypass it.
            'draft' => [],
            'final' => ['draft', 'submitted'],
            'submitted' => in_array($assistanceRequest->status, ['submitted', 'rejected'], true) ? ['draft'] : [],
            default => [],
        };
        abort_unless(in_array($data['assessment_status'], $allowed, true), 422, 'This assessment status transition is not allowed. Reopen or revise the assessment through the Created Assessments tab.');
        $assistanceRequest->update([
            'assessment_status' => $data['assessment_status'],
            'status' => match ($data['assessment_status']) {
                'draft' => 'under_review',
                'final' => 'acted',
                'submitted' => 'submitted',
            },
        ]);
        $audit->log('request.assessment_status_changed', $assistanceRequest, ['assessment_status' => $old], ['assessment_status' => $data['assessment_status']]);
        if ($data['assessment_status'] === 'submitted') {
            $workflowNotifications->notifyDrrsAssessmentSubmitted($assistanceRequest->fresh(['encoder', 'items']));
        }

        return back()->with('success', 'Assessment status updated to '.str($data['assessment_status'])->title().'.');
    }

    public function polishAssessment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:generate,polish'],
            'text' => ['nullable', 'string', 'max:10000'], 'requesting_agency' => ['nullable', 'string', 'max:255'],
            'incident_name' => ['nullable', 'string', 'max:255'], 'incident_details' => ['nullable', 'string', 'max:500'],
            'purpose' => ['nullable', 'string', 'max:255'], 'affected_families' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array', 'max:50'], 'items.*.item_name' => ['nullable', 'string', 'max:255'],
            'items.*.requested_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.available_quantity' => ['nullable', 'numeric', 'min:0'],
            'form_context' => ['nullable', 'array', 'max:30'],
        ]);

        if ($data['mode'] === 'polish' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Enter an assessment narrative before polishing it.'], 422);
        }

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') return response()->json(['message' => 'Groq AI is not configured. Add GROQ_API_KEY to the server environment, then clear the configuration cache.'], 503);

        $items = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['item_name'] ?? null))->map(fn ($item) => sprintf('%s: requested %s %s; total current stockpile %s %s; %s', $item['item_name'], $item['requested_quantity'] ?? 0, $item['unit'] ?? '', $item['available_quantity'] ?? 0, $item['unit'] ?? '', (float) ($item['requested_quantity'] ?? 0) > (float) ($item['available_quantity'] ?? 0) ? 'DEFICIT' : 'SUFFICIENT'))->implode('; ');
        $facts = collect([
            'Proposing party: '.($data['requesting_agency'] ?? 'Not specified'),
            'Incident: '.collect([$data['incident_name'] ?? null, $data['incident_details'] ?? null])->filter()->implode(' - '),
            'Purpose: '.($data['purpose'] ?? 'Not specified'),
            'Affected families: '.($data['affected_families'] ?? 'Not specified'),
            'FNI stock validation: '.($items ?: 'No FNI rows supplied'),
            ($data['mode'] === 'polish' ? 'Current draft to polish: ' : 'Existing draft (reference only; independently generate from encoded facts): ').($data['text'] ?? 'No draft supplied.'),
            'Additional encoded assessment fields (JSON; null or blank means not supplied): '.json_encode($data['form_context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])->implode("\n");

        try {
            $modeInstruction = $data['mode'] === 'generate'
                ? 'Create a new narrative from the encoded facts. Follow this three-paragraph structure: (1) incident context, impact, and documented preparedness or response actions; (2) reported affected families, displacement, source of information, and resulting humanitarian needs; (3) the proposing party request, relevant stock sufficiency or deficit, and a clear augmentation recommendation tied to the selected purpose. If facts for a part are absent, omit them rather than inventing them.'
                : 'Polish the current draft only. Preserve its facts, quantities, meaning, and recommendation while improving grammar, organization, transitions, concision, and formal DSWD tone. Do not introduce any fact that is absent from the draft or encoded fields.';

            $response = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(rtrim((string) config('services.groq.base_url'), '/').'/chat/completions', [
                'model' => config('services.groq.model'), 'temperature' => $data['mode'] === 'generate' ? 0.3 : 0.15, 'max_completion_tokens' => 650,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are assisting the social worker who is personally preparing this assessment. In this system, FNI always means Food and Non-Food Items. Never expand FNI as Family Needs Identification or assign it any other meaning. Produce an official FNI Assessment and Delivery Form narrative in plain professional English. Treat populated encoded fields as verified facts, ignore blank fields, and never invent dates, quantities, affected populations, preparedness actions, findings, coordination, signatories, or approvals. Never mention the assigned social worker, current user, case handler, assessor, or who is preparing or handling the case. Use all other materially relevant facts without exposing JSON or field labels. Clearly state stock sufficiency or deficit where stock figures are supplied. Keep the complete output within 270 words so the full narrative, delivery header, and signature spaces fit one A4 assessment page. Return only 2 to 3 cohesive paragraphs, without headings, bullets, markdown, greetings, or commentary. '.$modeInstruction],
                    ['role' => 'user', 'content' => $facts],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Groq AI could not be reached. Your current assessment was not changed.'], 503);
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Groq API error '.$response->status().': '.$response->body()));
            return response()->json(['message' => 'Groq AI could not enhance the assessment. Confirm the API key, model, and free-tier availability.'], 502);
        }
        $polished = AssessmentNarrative::sanitize((string) data_get($response->json(), 'choices.0.message.content'));
        if ($polished === '') return response()->json(['message' => 'Groq AI returned an empty result. Your current assessment was not changed.'], 502);

        return response()->json(['polished' => $polished, 'provider' => 'Groq', 'model' => config('services.groq.model')]);
    }

    public function responseLetter(AssistanceRequest $assistanceRequest, ResponseLetterDocumentService $documents): BinaryFileResponse
    {
        abort_if(blank($assistanceRequest->response_drn), 422, 'Enter the Response Letter DRN before generating the document.');
        $file = $documents->generate($assistanceRequest->load(['items', 'approvals', 'incident']));
        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function responseLetterPdf(Request $request, AssistanceRequest $assistanceRequest, ResponseLetterDocumentService $documents, WordToPdfService $converter): BinaryFileResponse
    {
        abort_if(blank($assistanceRequest->response_drn), 422, 'Enter the Response Letter DRN before generating the document.');
        $record = $assistanceRequest->load(['items', 'approvals', 'incident', 'requestParty.lguDirectoryEntry.officials', 'requestParty.lguDirectoryEntry.contacts']);
        $word = $documents->generate($record);

        try {
            $pdfPath = $converter->convert($word['path']);
        } finally {
            @unlink($word['path']);
        }

        $filename = "Response-Letter-{$record->reference_number}.pdf";
        $headers = [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
        $response = $request->boolean('inline')
            ? response()->file($pdfPath, [...$headers, 'Content-Disposition' => 'inline; filename="'.$filename.'"'])
            : response()->download($pdfPath, $filename, $headers);

        return $response->deleteFileAfterSend(true);
    }

    public function updateResponseDrn(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
            'year' => ['required', 'digits:2'],
            'month' => ['required', 'date_format:m'],
            'specified' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
        ]);
        $drn = DocumentReferenceNumber::compose($data['prefix'], $data['year'], $data['month'], $data['specified']);
        $old = $assistanceRequest->response_drn;
        $assistanceRequest->update(['response_drn' => $drn]);
        $audit->log('request.response_drn_updated', $assistanceRequest, ['response_drn' => $old], ['response_drn' => $drn]);

        return response()->json(['drn' => $drn]);
    }
}
