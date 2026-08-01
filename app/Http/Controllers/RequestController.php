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
use App\Services\AorCoverageService;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\InventoryBalanceService;
use App\Services\PreviousAugmentationResolver;
use App\Services\RequestPartySheetService;
use App\Services\ResponseLetterDocumentService;
use App\Services\WorkflowNotificationService;
use App\Services\WordToPdfService;
use App\Support\DocumentReferenceNumber;
use App\Support\AssessmentNarrative;
use Illuminate\Http\JsonResponse;
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

class RequestController extends Controller
{
    public function drrsRequests(Request $request): Response
    {
        $request->merge(['status' => 'actionable', 'default_tab' => 'tracker']);

        return $this->index($request);
    }

    public function index(Request $request, InventoryBalanceService $inventoryBalanceService, AorCoverageService $aorCoverage): Response|RedirectResponse
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

        $user = $request->user();
        $withAssessmentAccess = function ($paginator) use ($user, $aorCoverage) {
            $paginator->getCollection()->transform(function (AssistanceRequest $record) use ($user, $aorCoverage) {
                $record->setAttribute('assessment_access', $user
                    ? $aorCoverage->assessmentAccessFor($user, $record)
                    : [
                        'can_create' => false,
                        'can_act_on_behalf' => false,
                        'can_access_documents' => false,
                        'is_owner' => false,
                        'acted_by' => null,
                        'primary_owner' => null,
                        'reason' => 'unauthenticated',
                    ]);

                return $record;
            });

            return $paginator;
        };

        $workspaceBase = AssistanceRequest::query()
            ->where('submission_type', '!=', 'lgu_dromic_relief_request')
            ->where('endorsed_to_drrs', true);

        $workspaceSummary = [
            'requests' => (clone $workspaceBase)->count(),
            'acted' => (clone $workspaceBase)->where(function ($query): void {
                $query->whereIn('assessment_status', ['final', 'submitted'])
                    ->orWhereIn('status', ['acted', 'approved', 'partially_approved', 'rejected']);
            })->count(),
            'still_for_action' => (clone $workspaceBase)->where(function ($query): void {
                $query->whereNull('assessment_status')
                    ->orWhere('assessment_status', 'draft');
            })->whereNotIn('status', ['approved', 'partially_approved', 'rejected'])->count(),
            'lgu_linked' => (clone $workspaceBase)->whereNotNull('source_lgu_dromic_request_id')->count(),
            'breakdown' => [
                'awaiting_assessment' => (clone $workspaceBase)->whereNull('assessment_status')->whereNotIn('status', ['approved', 'partially_approved', 'rejected'])->count(),
                'draft_under_review' => (clone $workspaceBase)->where('assessment_status', 'draft')->count(),
                'final' => (clone $workspaceBase)->where('assessment_status', 'final')->count(),
                'submitted' => (clone $workspaceBase)->where('assessment_status', 'submitted')->count(),
                'approved' => (clone $workspaceBase)->whereIn('status', ['approved', 'partially_approved'])->count(),
            ],
        ];

        $reliefRequestBase = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_submitted_to_dswd_at')
            ->whereNotNull('lgu_signed_request_path');

        $reliefAssessmentGate = [
            'awaiting_validation' => (clone $reliefRequestBase)
                ->where(function ($query): void {
                    $query->whereNull('lgu_relief_validation_status')
                        ->orWhereIn('lgu_relief_validation_status', ['pending_review', 'under_review']);
                })
                ->count(),
            'needs_lgu_action' => (clone $reliefRequestBase)
                ->where('lgu_relief_validation_status', 'needs_lgu_action')
                ->count(),
        ];

        $highlightRequestId = $request->integer('highlight') ?: null;

        return Inertia::render('Requests/Index', [
            'workspaceSummary' => $workspaceSummary,
            'reliefAssessmentGate' => $reliefAssessmentGate,
            'highlightRequestId' => $highlightRequestId,
            'requests' => $withAssessmentAccess(
                AssistanceRequest::query()
                    ->with([
                        'items',
                        'assessmentType',
                        'incident',
                        'encoder:id,name,office',
                        'assessmentActor:id,name,office',
                        'assessmentOnBehalfOwner:id,name,office',
                        'sourceLguDromicReport:id,incident_id,reference_number,lgu_relief_request_reference,lgu_signed_request_path,lgu_signed_report_path,lgu_relief_validation_status,lgu_dromic_payload,lgu_dromic_narrative,affected_families,province,municipality,barangay,requesting_agency,requester,requester_position,requester_address,contact_number',
                        'sourceLguDromicReport.incident',
                    ])
                    ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                    ->when(! $highlightRequestId && $request->status === 'actionable', fn ($q) => $q->whereIn('status', ['endorsed', 'submitted', 'under_review', 'acted']))
                    ->when(! $highlightRequestId && $request->status && $request->status !== 'actionable', fn ($q, $status) => $q->where('status', $status))
                    ->when(! $highlightRequestId && $request->search, function ($q, $search): void {
                        $q->where(function ($query) use ($search): void {
                            $query->where('reference_number', 'like', "%{$search}%")
                                ->orWhere('requesting_agency', 'like', "%{$search}%")
                                ->orWhere('requester', 'like', "%{$search}%")
                                ->orWhere('province', 'like', "%{$search}%")
                                ->orWhere('municipality', 'like', "%{$search}%")
                                ->orWhereHas('sourceLguDromicReport', function ($source) use ($search): void {
                                    $source->where('reference_number', 'like', "%{$search}%")
                                        ->orWhere('lgu_relief_request_reference', 'like', "%{$search}%");
                                });
                        });
                    })
                    ->when(
                        $highlightRequestId,
                        fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$highlightRequestId])->latest(),
                        fn ($q) => $q->latest(),
                    )
                    ->paginate(15)
                    ->withQueryString()
            ),
            'assessments' => $withAssessmentAccess(
                AssistanceRequest::query()
                    ->with([
                        'items.sourceWarehouse:id,name,province',
                        'incident',
                        'encoder:id,name,office',
                        'assessmentActor:id,name,office',
                        'assessmentOnBehalfOwner:id,name,office',
                        'sourceLguDromicReport:id,reference_number,lgu_relief_request_reference',
                    ])
                    ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                    ->whereNotNull('assessment_status')
                    ->latest('updated_at')
                    ->paginate(15, ['*'], 'assessments_page')
                    ->withQueryString()
            ),
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
        if (filled($assistanceRequest->drmd_assigned_to) && (int) $assistanceRequest->drmd_assigned_to !== (int) $request->user()->id) {
            abort(403, 'Only the assigned DRRS PDRC user can action this LGU request.');
        }
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

    public function previousAugmentations(
        AssistanceRequest $assistanceRequest,
        PreviousAugmentationResolver $resolver,
    ): JsonResponse {
        abort_unless(
            request()->user()?->can('encode requests')
                || request()->user()?->can('monitor requests')
                || request()->user()?->can('process requests'),
            403,
        );

        $resolved = $resolver->resolve($assistanceRequest);

        return response()->json($resolved);
    }

    public function assessmentForm(Request $request, AssistanceRequest $assistanceRequest, AorCoverageService $aorCoverage): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(filled($assistanceRequest->assessment_status), 404, 'No assessment documents are available for this request yet.');

        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        abort_unless($access['can_access_documents'], 403, 'Only the DRRS PDRC who created this assessment can open its documents.');

        return Inertia::render('Requests/AssessmentForm', [
            'request' => $assistanceRequest->load(['items', 'assessmentType', 'incident', 'encoder', 'assessmentActor', 'assessmentOnBehalfOwner', 'sourceLguDromicReport']),
            'drnPrefixes' => OperationalLibraryValue::query()->where('library_type', 'drn_prefix')->where('is_active', true)->orderBy('context')->orderBy('value')->get(['id', 'value', 'context']),
            'assessmentAccess' => $access,
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
            'items.*.requested_quantity' => ['required', 'integer', 'min:1'], 'items.*.priority' => ['required', 'in:low,normal,high,urgent'],
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

    public function completeAssessment(
        AssistanceRequestRequest $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        InventoryBalanceService $inventoryBalanceService,
        AorCoverageService $aorCoverage
    ): RedirectResponse
    {
        abort_unless($assistanceRequest->endorsed_to_drrs, 422, 'Only records endorsed to DRRS can be assessed through this workflow.');

        $user = $request->user();
        abort_unless($user, 403);

        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        if (filled($assistanceRequest->assessment_status)) {
            abort_unless(
                $access['can_access_documents'],
                403,
                'Only the DRRS PDRC who created this assessment can update its draft.'
            );
        } else {
            abort_unless(
                $user->hasRole('Super Admin') || ($user->hasRole('DRRS') && $user->can('encode requests')),
                403,
                'Only DRRS encoders can create assessments.'
            );

            $assistanceRequest->loadMissing('sourceLguDromicReport:id,lgu_signed_request_path,lgu_relief_validation_status');
            if ($assistanceRequest->blocksNewAssessmentForUnsignedReliefValidation()) {
                return back()->withErrors([
                    'assessment' => 'Validate the signed LGU request letter first (Validated — No Findings) before creating an assessment for this request.',
                ]);
            }
        }

        $data = $request->validated();
        $data['recommendations'] = AssessmentNarrative::sanitize($data['recommendations'] ?? null);
        $availableTotals = $inventoryBalanceService->availableTotalsByItem();
        $data['items'] = collect($data['items'])->map(function (array $item) use ($availableTotals): array {
            $key = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($item['item_name'] ?? ''))) ?? '';

            if ($key !== '' && $availableTotals->has($key)) {
                $item['available_quantity'] = max(0, (float) $availableTotals->get($key));
            }

            return $item;
        })->all();
        $old = $assistanceRequest->load(['items', 'incident'])->toArray();

        DB::transaction(function () use ($assistanceRequest, $data, $user): void {
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

            $actorFields = [];
            if (blank($assistanceRequest->assessment_status) || blank($assistanceRequest->assessment_acted_by)) {
                $actorFields = [
                    'assessment_acted_by' => $user->id,
                    'assessment_acted_at' => now(),
                    'assigned_social_worker' => $user->name,
                ];
            }

            $assistanceRequest->update([
                ...collect($data)->except(['incident_name', 'incident_date', 'items', 'act_on_behalf', 'on_behalf_reason'])->all(),
                'incident_id' => $incident?->id,
                'status' => 'under_review',
                'assessment_status' => 'draft',
                ...$actorFields,
            ]);

            $assistanceRequest->items()->delete();
            foreach ($data['items'] as $item) {
                $assistanceRequest->items()->create($item);
            }
            if (! $incident && $oldIncident && $oldIncident->requests()->doesntExist()) $oldIncident->delete();
        });

        $fresh = $assistanceRequest->fresh(['items', 'incident', 'requestParty']);
        $audit->log('request.assessment_completed', $fresh, $old, $fresh->toArray());

        return redirect()
            ->route('requests.assessment', $fresh)
            ->with('success', "Assessment for {$fresh->reference_number} saved. Assessment and response-letter previews, printing, and downloads are ready.");
    }

    public function assessmentStatus(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications, AorCoverageService $aorCoverage): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        abort_unless($access['can_access_documents'], 403, 'Only the DRRS PDRC who created this assessment can change its status.');

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
            'form_context' => ['nullable', 'array', 'max:50'],
        ]);

        if ($data['mode'] === 'polish' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Enter an assessment narrative before polishing it.'], 422);
        }

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') return response()->json(['message' => 'Groq AI is not configured. Add GROQ_API_KEY to the server environment, then clear the configuration cache.'], 503);

        $context = (array) ($data['form_context'] ?? []);
        $incidentStatus = trim((string) ($context['incident_status'] ?? ''));
        $reportClassification = trim((string) ($context['source_report_classification'] ?? ''));
        $incidentEndedAt = trim((string) ($context['incident_ended_at'] ?? ''));
        $endedIncident = $incidentEndedAt !== ''
            || preg_match('/\b(ended|closed|resolved|terminated|concluded)\b/i', $incidentStatus) === 1
            || preg_match('/\b(terminal|first\s*(?:and|&)\s*final)\b/i', $reportClassification) === 1;
        $temporalFraming = $endedIncident
            ? 'ENDED. Describe the incident, hazard experience, effects, displacement, and LGU response in past tense. Use forms such as "experienced", "affected", "was/were", and "undertook". Never say "is experiencing", "has/have been experiencing", or imply that the hazard itself is still occurring. Ongoing DRMD monitoring, coordination, assistance, and unmet needs may remain in present tense.'
            : 'ONGOING OR NOT YET REPORTED AS ENDED. Describe the continuing experience/effects with present-perfect or continuing forms such as "has/have been experiencing", "has/have affected", "continues", or "remains". Do not falsely state that the incident has ended.';
        $affectedAreas = collect($context['affected_areas'] ?? [])
            ->map(fn ($area) => trim((string) $area))
            ->filter()
            ->unique()
            ->values();
        $affectedAreaSummary = match (true) {
            $affectedAreas->isEmpty() => 'Not supplied',
            $affectedAreas->count() <= 5 => $affectedAreas->implode(', '),
            default => $affectedAreas->count().' affected areas; summarize by count and do not enumerate their names',
        };
        $lguLevel = strtoupper(trim((string) ($context['lgu_level'] ?? '')));
        $requestingAgency = trim((string) ($data['requesting_agency'] ?? ''));
        if ($lguLevel === '') {
            $lguLevel = match (true) {
                preg_match('/\b(?:PLGU|PGLU)\b/i', $requestingAgency) === 1 => 'PLGU',
                preg_match('/\bCLGU\b/i', $requestingAgency) === 1 => 'CLGU',
                preg_match('/\bMLGU\b/i', $requestingAgency) === 1 => 'MLGU',
                default => 'LGU',
            };
        }
        $lguLevel = $lguLevel === 'PGLU' ? 'PLGU' : $lguLevel;
        $municipality = trim((string) ($context['municipality'] ?? ''));
        $province = trim((string) ($context['province'] ?? ''));
        $displayMunicipality = Str::title(Str::lower($municipality));
        $displayProvince = (string) Str::of($province)->lower()->title()
            ->replace([' Del ', ' De ', ' La '], [' del ', ' de ', ' la ']);
        $affectedLocality = match ($lguLevel) {
            'MLGU' => 'municipality'.($displayMunicipality !== '' ? ' of '.$displayMunicipality : '').($displayProvince !== '' ? ', '.$displayProvince : ''),
            'CLGU' => 'city'.($displayMunicipality !== '' ? ' of '.$displayMunicipality : '').($displayProvince !== '' ? ', '.$displayProvince : ''),
            'PLGU' => 'province'.($displayProvince !== '' ? ' of '.$displayProvince : ''),
            default => collect([$displayMunicipality, $displayProvince])->filter()->implode(', ') ?: 'reported area',
        };
        $formalLguName = match ($lguLevel) {
            'MLGU' => 'Municipal Local Government Unit (MLGU)'.($displayMunicipality !== '' ? ' of '.$displayMunicipality : '').($displayProvince !== '' ? ', '.$displayProvince : ''),
            'CLGU' => 'City Local Government Unit (CLGU)'.($displayMunicipality !== '' ? ' of '.$displayMunicipality : '').($displayProvince !== '' ? ', '.$displayProvince : ''),
            'PLGU' => 'Provincial Local Government Unit (PLGU)'.($displayProvince !== '' ? ' of '.$displayProvince : ''),
            default => $requestingAgency !== '' ? $requestingAgency : 'Local Government Unit (LGU)',
        };
        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => filled($item['item_name'] ?? null))
            ->map(fn ($item) => sprintf(
                '%s: requested %s %s; current available stock %s %s; assessment: %s',
                $item['item_name'],
                number_format((float) ($item['requested_quantity'] ?? 0), 0),
                $item['unit'] ?? '',
                number_format((float) ($item['available_quantity'] ?? 0), 0),
                $item['unit'] ?? '',
                (float) ($item['requested_quantity'] ?? 0) > (float) ($item['available_quantity'] ?? 0) ? 'DEFICIT' : 'SUFFICIENT'
            ))
            ->implode('; ');
        $curatedContext = collect($context)->except(['date_requested', 'affected_areas'])->all();
        $facts = collect([
            'Canonical requesting-party identity: '.$formalLguName,
            'Affected geographic area: '.$affectedLocality,
            'Stored requesting-party label (abbreviated form of the governing body; do not state it separately from the canonical identity): '.($requestingAgency !== '' ? $requestingAgency : 'Not specified'),
            'Incident: '.collect([$data['incident_name'] ?? null, $data['incident_details'] ?? null])->filter()->implode(' - '),
            'Purpose: '.($data['purpose'] ?? 'Not specified'),
            'Affected families (preserve this exact number): '.(array_key_exists('affected_families', $data) ? number_format((int) $data['affected_families']) : 'Not supplied'),
            'Affected persons (preserve this exact number): '.(array_key_exists('affected_persons', $context) ? number_format((int) $context['affected_persons']) : 'Not supplied'),
            'Affected-area presentation: '.$affectedAreaSummary,
            'Mandatory temporal framing: '.$temporalFraming,
            'Chronology controls (use to select tense; do not automatically narrate every date): incident occurrence '.($context['incident_date'] ?? 'not supplied').'; request date '.($context['date_requested'] ?? 'not supplied').'; assessment date '.($context['assessment_date'] ?? now()->toDateString()).'; DROMIC incident status '.($incidentStatus ?: 'not supplied').'; incident ended '.($incidentEndedAt ?: 'not supplied').'; report classification '.($reportClassification ?: 'not supplied').'.',
            'FNI stock validation: '.($items ?: 'No FNI rows supplied'),
            ($data['mode'] === 'polish' ? 'Current draft to polish: ' : 'Existing draft (reference only; independently generate from encoded facts): ').($data['text'] ?? 'No draft supplied.'),
            'Other encoded assessment facts (JSON; null or blank means not supplied): '.json_encode($curatedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])->implode("\n");

        try {
            $modeInstruction = $data['mode'] === 'generate'
                ? 'Create a new narrative from the encoded facts in exactly this operational sequence. Paragraph 1: describe what happened, when and where it happened, and how the hazard developed or could affect the area. When official PAGASA, PHIVOLCS, or another authoritative advisory is supplied, attribute and concisely paraphrase only its relevant verified hazard information; never reproduce the advisory or invent an agency finding. Paragraph 2: describe documented effects, current incident or affected-area status, exact affected families and persons, displacement inside or outside evacuation centers, other material impacts, identified needs, and the LGU response actions. Omit any category without supplied data. Paragraph 3: state what the requesting party requested, each item and exact quantity, and why augmentation is warranted based only on the documented effects, population, displacement, needs, and stock assessment. State exact available stock and whether it is sufficient or deficient, then give a clear recommendation. Paragraph 4: state that the DRMD continues monitoring the situation and identifying the needs of the affected area and population, and assure continued active coordination with the LGU to validate and help provide immediate needs and determine whether additional support may be required. This final paragraph is an institutional ongoing-action statement, not a claim of an approval or completed delivery.'
                : 'Perform a conservative polish of the user-written draft. Preserve the user’s paragraph order, substantive wording, facts, quantities, emphasis, qualifications, recommendation, and manually added details. Do not force it into the auto-generation four-paragraph structure. Do not add a DRMD assurance, incident detail, justification, finding, or conclusion unless it already appears in the draft. Make only the minimum edits needed to correct grammar, spelling, punctuation, awkward phrasing, terminology, clarity, formal tone, and tense conflicts with the authoritative DROMIC status. Prefer rephrasing a flawed sentence over replacing the user’s idea. Never remove a meaningful manually written statement merely to make the draft shorter.';
            $structureInstruction = $data['mode'] === 'generate'
                ? 'Use four concise cohesive paragraphs in the required sequence.'
                : 'Retain the draft’s existing paragraph structure and sequence; do not expand it into a new assessment.';
            $lguIdentityInstruction = 'MLGU means Municipal Local Government Unit, CLGU means City Local Government Unit, and PLGU means Provincial Local Government Unit. The LGU is the governing body; the municipality, city, or province is the geographic area under its jurisdiction. Keep their grammatical roles distinct. Only the geographic area may experience, be affected by, or recover from a disaster. Only the LGU may report, request assistance, monitor, coordinate, validate, or undertake response actions. Never say that an MLGU, CLGU, or PLGU is experiencing or is affected by the hazard. Introduce the canonical requesting-party identity only when describing an LGU action; use the affected geographic area when describing the hazard and its effects. The stored requesting-party label is merely an abbreviated form of the canonical governing-body identity and must not be stated separately.';
            $modeInstruction = $lguIdentityInstruction.' '.$modeInstruction;

            $response = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(rtrim((string) config('services.groq.base_url'), '/').'/chat/completions', [
                'model' => config('services.groq.model'), 'temperature' => $data['mode'] === 'generate' ? 0.3 : 0.15, 'max_completion_tokens' => 650,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are assisting the social worker who is personally preparing this assessment. In this system, FNI always means Food and Non-Food Items. Never expand FNI as Family Needs Identification or assign it any other meaning. Produce an official FNI Assessment and Delivery Form narrative in plain professional English. Treat populated encoded fields as verified facts and ignore blank fields. Structured encoded totals, incident status, ended date, report classification, and chronology controls are authoritative and override any older or inconsistent wording, number, or status quoted inside the source DROMIC narrative or advisory text. Apply the mandatory temporal framing exactly: an ENDED incident must use past tense for what happened, its effects, and LGU actions; an ONGOING incident must use present-perfect or continuing tense for experiences and effects that continue. Never confuse the incident tense with ongoing DRMD monitoring and coordination, which may correctly remain in present tense after the hazard has ended. Never invent or change dates, quantities, affected populations, displacement, damage, preparedness or response actions, findings, needs, coordination, signatories, approvals, deliveries, or LGU resource shortages. A positive affected count must never become zero. Always call the organization making the request the "requesting party"; never call it the proposing party. Do not mention when the request was made or restate the Date of Request. Mention occurrence and information dates only when they materially clarify the assessment. When more than five affected areas are supplied, state only their count and never enumerate their names. Attribute PAGASA, PHIVOLCS, or another agency only when an official-advisory fact from that agency is supplied, and paraphrase only what is relevant to what happened and how the hazard affected or threatened the reported location. Avoid repetitive statements, vague recovery claims, and generic humanitarian language unsupported by encoded facts. Never include "Approved by", "Prepared by", "Reviewed by", signature lines, date lines, names of signatories, or any sign-off placeholder; those belong in separate form fields. Never mention the assigned social worker, current user, case handler, assessor, or who is preparing or handling the case. Use materially relevant facts without exposing JSON or field labels. Clearly state exact requested stock, exact available stock, and whether it is sufficient or deficient. '.$structureInstruction.' Keep the complete output within 260 words so the full narrative and signature spaces fit one A4 assessment page. Return paragraphs only, without headings, bullets, markdown, greetings, or commentary. '.$modeInstruction],
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
        $endedTenseViolation = $endedIncident && preg_match(
            '/\b(is|are)\s+(?:currently\s+)?experiencing\b|\b(has|have)\s+been\s+experiencing\b|\b(is|are)\s+(?:currently\s+)?affecting\b|\bcontinues?\s+to\s+affect\b|\b(is|are)\s+expected\s+to\s+affect\b/i',
            (string) (preg_split('/\R\s*\R/u', $polished, 2)[0] ?? $polished)
        ) === 1;

        if ($endedTenseViolation) {
            try {
                $correction = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(rtrim((string) config('services.groq.base_url'), '/').'/chat/completions', [
                    'model' => config('services.groq.model'),
                    'temperature' => 0.05,
                    'max_completion_tokens' => 650,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a strict temporal-grammar editor. The DROMIC incident is ENDED. Correct only the temporal grammar of the supplied assessment without changing its paragraph structure, facts, date, count, requested item, stock figure, recommendation, manually written details, or ongoing DRMD assurance. Use past tense for the incident, hazard experience, effects, displacement, and LGU actions. Do not use "is/are experiencing", "has/have been experiencing", "is/are affecting", "continues to affect", or "is/are expected to affect" for the ended hazard. Ongoing DRMD monitoring and coordination may remain in present tense. Return only the corrected assessment with no heading, sign-off, or commentary.'],
                        ['role' => 'user', 'content' => $polished],
                    ],
                ]);

                if ($correction->successful()) {
                    $polished = AssessmentNarrative::sanitize((string) data_get($correction->json(), 'choices.0.message.content'));
                }
            } catch (\Throwable $exception) {
                report($exception);
            }

            $stillInvalid = preg_match(
                '/\b(is|are)\s+(?:currently\s+)?experiencing\b|\b(has|have)\s+been\s+experiencing\b|\b(is|are)\s+(?:currently\s+)?affecting\b|\bcontinues?\s+to\s+affect\b|\b(is|are)\s+expected\s+to\s+affect\b/i',
                (string) (preg_split('/\R\s*\R/u', $polished, 2)[0] ?? $polished)
            ) === 1;

            if ($polished === '' || $stillInvalid) {
                return response()->json(['message' => 'Groq could not produce a temporally consistent assessment for an incident marked as ended. Your current narrative was not changed.'], 502);
            }
        }

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
