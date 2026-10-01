<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistanceRequestRequest;
use App\Models\AssessmentType;
use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Models\RequestItem;
use App\Models\RequestParty;
use App\Models\RequisitionIssuanceSlip;
use App\Models\RisSyncRun;
use App\Models\StfSyncRun;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AorCoverageService;
use App\Services\AuditLogger;
use App\Services\EpirmaWorkflowService;
use App\Services\InventoryBalanceService;
use App\Services\InventoryService;
use App\Services\GroqChatService;
use App\Services\PreviousAugmentationResolver;
use App\Services\RequestPartySheetService;
use App\Services\ResponseLetterDocumentService;
use App\Services\RisReservationService;
use App\Services\StfSheetSyncService;
use App\Services\WordToPdfService;
use App\Services\WorkflowNotificationService;
use App\Support\AssessmentNarrative;
use App\Support\InlinePdfFilename;
use App\Support\LinkedLguDromicIncidentReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RequestController extends Controller
{
    public function drrsRequests(
        Request $request,
        InventoryBalanceService $inventoryBalanceService,
        AorCoverageService $aorCoverage,
    ): Response {
        $request->merge(['status' => 'actionable', 'default_tab' => 'tracker']);

        return $this->index($request, $inventoryBalanceService, $aorCoverage);
    }

    public function rrosRequests(
        Request $request,
        InventoryBalanceService $inventoryBalanceService,
        AorCoverageService $aorCoverage,
    ): Response {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        $request->merge(['default_tab' => 'still_for_action']);
        $request->attributes->set('request_workspace_mode', 'rros');

        return $this->index($request, $inventoryBalanceService, $aorCoverage);
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
        $warehouseLocations = Warehouse::query()->get(['id', 'province', 'municipality', 'district', 'latitude', 'longitude'])->keyBy('id');
        $warehouseStock = $inventoryBalanceService->balanceRows()
            ->map(function (array $row) use ($warehouseLocations): array {
                $location = $warehouseLocations->get($row['warehouse_id'] ?? null);

                return [
                    'warehouse_id' => $row['warehouse_id'] ?? null,
                    'warehouse' => $row['warehouse'] ?? 'Unnamed Warehouse',
                    'warehouse_type' => $row['warehouse_type'] ?? null,
                    'warehouse_ownership' => $row['partnership'] ?? null,
                    'province' => $location?->province ?? $row['warehouse_province'] ?? null,
                    'municipality' => $location?->municipality ?? $row['warehouse_municipality'] ?? null,
                    'district' => $location?->district ?? null,
                    'latitude' => filled($location?->latitude) ? (float) $location->latitude : null,
                    'longitude' => filled($location?->longitude) ? (float) $location->longitude : null,
                    'item' => $row['item'] ?? '',
                    'category' => $row['category'] ?? null,
                    'uom' => $row['uom'] ?? '',
                    'brand_description' => $row['brand_description'] ?? null,
                    // Current stockpile (WIT): same basis as Inventory → Warehouse Stockpile cards.
                    'current' => (float) ($row['current_balance'] ?? 0),
                    'available' => (float) ($row['current_balance'] ?? 0),
                    'expiry' => $row['expiry'] ?? null,
                    'unit_price' => (float) ($row['current_balance'] ?? 0) > 0
                        ? max(0, (float) ($row['cost'] ?? 0)) / (float) $row['current_balance']
                        : null,
                ];
            })->filter(fn (array $row): bool => filled($row['warehouse_id']) && filled($row['item']))->values();

        $user = $request->user();
        $districtsByPsgc = $municipalities->mapWithKeys(fn ($row) => [$row->code => $row->district]);
        $sdn1Municipalities = $municipalities->filter(fn ($row) => str_replace(' ', '', strtolower((string) $row->district)) === 'sdn1')->pluck('name')->map(fn ($name) => strtolower(trim($name)))->all();
        $withAssessmentAccess = function ($paginator) use ($user, $aorCoverage, $districtsByPsgc, $sdn1Municipalities) {
            $paginator->getCollection()->transform(function (AssistanceRequest $record) use ($user, $aorCoverage, $districtsByPsgc, $sdn1Municipalities) {
                $province = strtolower((string) $record->province);
                $district = strtolower((string) $districtsByPsgc->get($record->lgu_psgc_code, ''));
                $isSdn1 = str_replace(' ', '', $district) === 'sdn1'
                    || (str_contains($province, 'surigao') && str_contains($province, 'norte') && in_array(strtolower(trim((string) $record->municipality)), $sdn1Municipalities, true));
                $record->setAttribute('location_zone', str_contains($province, 'dinagat')
                    ? 'pdi'
                    : ($isSdn1 ? 'sdn1' : 'mainland'));
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

        $applyStillForAction = function ($query): void {
            // Needs PDRC assessment — omit any draft/final (or later) assessed rows.
            $query->whereNull('assessment_status');
        };
        $applyCreatedAssessments = function ($query): void {
            $query->whereNotNull('assessment_status')
                ->where(function ($inner): void {
                    $inner->whereNull('epirma_assessment_signed_at')
                        ->orWhereNull('epirma_response_letter_signed_at');
                });
        };
        $applyApproved = function ($query): void {
            $query->whereNotNull('epirma_assessment_signed_at')
                ->whereNotNull('epirma_response_letter_signed_at');
        };
        // RROS: Still for Action = RIS/DR has not been started or remains a draft.
        $applyRisStillForAction = function ($query) use ($applyApproved): void {
            $applyApproved($query);
            $query->where(function ($inner): void {
                $inner->whereDoesntHave('requisitionIssuanceSlip')
                    ->orWhereHas(
                        'requisitionIssuanceSlip',
                        fn ($slip) => $slip->where('status', 'draft')
                    );
            });
        };
        // RROS: In Progress = prepared/generated and awaiting approval or post-RIS work.
        $applyRisInProgress = function ($query) use ($applyApproved): void {
            $applyApproved($query);
            $query->whereHas(
                'requisitionIssuanceSlip',
                fn ($slip) => $slip->where('status', 'prepared')
            );
        };
        // RROS: Approved = post RIS/DR form completed (delivery / accounting recorded).
        $applyRisApproved = function ($query) use ($applyApproved): void {
            $applyApproved($query);
            $query->whereHas(
                'requisitionIssuanceSlip',
                fn ($slip) => $slip->where('status', 'approved')
            );
        };
        // RROS: Completed = fully closed with post RIS/DR requirements satisfied.
        $applyRisCompleted = function ($query) use ($applyApproved): void {
            $applyApproved($query);
            $query->whereHas(
                'requisitionIssuanceSlip',
                fn ($slip) => $slip->where('status', 'completed')
            );
        };
        $applySearch = function ($query, ?string $search): void {
            if (blank($search)) {
                return;
            }

            $query->where(function ($inner) use ($search): void {
                $inner->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('requesting_agency', 'like', "%{$search}%")
                    ->orWhere('requester', 'like', "%{$search}%")
                    ->orWhere('province', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('request_drn', 'like', "%{$search}%")
                    ->orWhereHas('sourceLguDromicReport', function ($source) use ($search): void {
                        $source->where('reference_number', 'like', "%{$search}%")
                            ->orWhere('lgu_relief_request_reference', 'like', "%{$search}%");
                    });
            });
        };

        $isRrosWorkspace = $request->attributes->get('request_workspace_mode') === 'rros';

        $workspaceSummary = [
            'requests' => (clone $workspaceBase)->count(),
            'still_for_action' => (clone $workspaceBase)->tap($applyStillForAction)->count(),
            'created_assessments' => (clone $workspaceBase)->tap($applyCreatedAssessments)->count(),
            'approved' => (clone $workspaceBase)->tap($applyApproved)->count(),
            'ris_still_for_action' => (clone $workspaceBase)->tap($applyRisStillForAction)->count(),
            'ris_in_progress' => (clone $workspaceBase)->tap($applyRisInProgress)->count(),
            // Legacy alias kept for any stale clients during deploy.
            'ris_for_signing' => (clone $workspaceBase)->tap($applyRisInProgress)->count(),
            'ris_approved' => (clone $workspaceBase)->tap($applyRisApproved)->count(),
            'ris_completed' => (clone $workspaceBase)->tap($applyRisCompleted)->count(),
            'lgu_linked' => (clone $workspaceBase)->whereNotNull('source_lgu_dromic_request_id')->count(),
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
        $search = $request->search ? (string) $request->search : null;

        $listRelations = [
            'items',
            'assessmentType',
            'incident',
            'encoder:id,name,office',
            'assessmentActor:id,name,office',
            'assessmentOnBehalfOwner:id,name,office',
            'sourceLguDromicReport:id,incident_id,reference_number,lgu_relief_request_reference,lgu_signed_request_path,lgu_signed_report_path,lgu_signed_report_name,lgu_relief_validation_status,lgu_dromic_payload,lgu_dromic_narrative,lgu_dromic_series_key,lgu_dromic_report_number,lgu_report_status,affected_families,province,municipality,barangay,requesting_agency,requester,requester_position,requester_address,contact_number',
            'sourceLguDromicReport.incident',
            'requestParty.lguDirectoryEntry.officials',
            'requestParty.lguDirectoryEntry.contacts',
        ];

        $withLinkedIncidentReports = function ($paginator) {
            $paginator->getCollection()->transform(function (AssistanceRequest $record) {
                if ($record->sourceLguDromicReport) {
                    $linked = LinkedLguDromicIncidentReports::for($record->sourceLguDromicReport);
                    $record->sourceLguDromicReport->setAttribute('linked_incident_reports', $linked);
                    // Also expose on the operational request so Inertia never drops nested dynamic attrs.
                    $record->setAttribute('linked_incident_reports', $linked);
                }

                return $record;
            });

            return $paginator;
        };

        $withSignedPreview = function ($paginator) use ($withLinkedIncidentReports) {
            $paginator->getCollection()->transform(function (AssistanceRequest $record) {
                $signedDocs = $record->relationLoaded('epirmaSignedDocuments')
                    ? $record->epirmaSignedDocuments
                    : $record->epirmaSignedDocuments()
                        ->where('routing_status', 'signed')
                        ->orderByDesc('id')
                        ->get();

                $assessmentDoc = $signedDocs->firstWhere('document_type', 'assessment')
                    ?: $signedDocs->first(fn ($doc) => ($doc->document_type ?? 'assessment') === 'assessment');
                $responseDoc = $signedDocs->firstWhere('document_type', 'response_letter');

                // Same path as response letter: always route signed assessments through
                // /epirma/documents/{id}/view so viewDocument can download+cache.
                // Never point Signed Assessment at DomPDF /assessment-pdf.
                $assessmentViewUrl = null;
                $assessmentPreviewKind = null;
                if ($assessmentDoc?->id) {
                    $assessmentViewUrl = url("/requests/{$record->id}/epirma/documents/{$assessmentDoc->id}/view");
                    $assessmentPreviewKind = 'signed';
                }

                $record->setAttribute('signed_assessment_view_url', $assessmentViewUrl);
                $record->setAttribute('signed_assessment_preview_kind', $assessmentPreviewKind);
                $record->setAttribute(
                    'signed_response_letter_view_url',
                    $responseDoc?->id
                        ? url("/requests/{$record->id}/epirma/documents/{$responseDoc->id}/view")
                        : null
                );

                $directory = $record->requestParty?->lguDirectoryEntry;
                if (! $directory && filled($record->lgu_psgc_code)) {
                    $directory = LguDirectoryEntry::with(['officials', 'contacts'])->where('psgc_code', $record->lgu_psgc_code)->first();
                }
                if (! $directory) {
                    $place = $record->municipality ?: $record->lgu ?: $record->requesting_agency;
                    if (filled($place)) {
                        $directory = LguDirectoryEntry::with(['officials', 'contacts'])
                            ->where(fn ($query) => $query->where('lgu_name', 'like', "%{$place}%")->orWhere('override_lgu_name', 'like', "%{$place}%"))
                            ->first();
                    }
                }
                $lswdo = $directory?->officials?->firstWhere('role', 'lswd_officer');
                $record->setAttribute('ris_receiving_representative', $lswdo?->override_name ?: $lswdo?->name ?: $directory?->lswd_alternate_name);
                $record->setAttribute('ris_receiving_contact_number', $directory?->lswd_contact_number ?: $directory?->lswd_alternate_contact_number);

                if ($record->sourceLguDromicReport) {
                    $linked = LinkedLguDromicIncidentReports::for($record->sourceLguDromicReport);
                    $record->sourceLguDromicReport->setAttribute('linked_incident_reports', $linked);
                    $record->setAttribute('linked_incident_reports', $linked);
                }

                $slip = $record->requisitionIssuanceSlip;
                if ($slip) {
                    $record->setAttribute(
                        'signed_ris_view_url',
                        $slip->approval_routing_mode === 'epirma' && $slip->ris_epirma_status === 'signed'
                            ? url("/rros/ris/{$slip->id}/epirma/signed-preview")
                            : null
                    );
                    $record->setAttribute('ris_view_url', filled($slip->ris_drn) && filled($slip->ris_link) ? url("/rros/ris/{$slip->id}/documents/ris") : null);
                    $record->setAttribute('ris_drn_pending', blank($slip->ris_drn));
                    $record->setAttribute('rds_view_url', filled($slip->rds_path) || filled($slip->rds_link) ? url("/rros/ris/{$slip->id}/documents/rds") : null);
                    $record->setAttribute('csmr_view_url', filled($slip->csmr_path) || filled($slip->csmr_link) ? url("/rros/ris/{$slip->id}/documents/csmr") : null);
                    $record->setAttribute('ris_preview', $this->serializeRisPreviewPayload($slip));
                    // Same-origin DomPDF advance previews (iframe parity with Assessment draft).
                    $record->setAttribute('ris_advance_pdf_view_url', "/rros/ris/{$slip->id}/preview-pdf/ris?inline=1");
                    $hasDr = filled($slip->dr_number)
                        || filled(data_get($slip->tracking_data, 'dr_number'));
                    $record->setAttribute(
                        'dr_advance_pdf_view_url',
                        $hasDr ? "/rros/ris/{$slip->id}/preview-pdf/dr?inline=1" : null
                    );
                    $record->setAttribute('ris_slip_id', $slip->id);
                    $record->setAttribute('assessment_pdf_view_url', "/requests/{$record->id}/assessment-pdf?margin=18&inline=1");
                } else {
                    $record->setAttribute('assessment_pdf_view_url', "/requests/{$record->id}/assessment-pdf?margin=18&inline=1");
                }

                return $record;
            });

            return $paginator;
        };

        $stillForAction = $withLinkedIncidentReports($withAssessmentAccess(
            AssistanceRequest::query()
                ->with($listRelations)
                ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                ->where('endorsed_to_drrs', true)
                ->tap($applyStillForAction)
                ->when(! $highlightRequestId && $request->status === 'actionable', fn ($q) => $q->whereIn('status', ['endorsed', 'submitted', 'under_review', 'acted']))
                ->when(! $highlightRequestId && $request->status && $request->status !== 'actionable', fn ($q, $status) => $q->where('status', $status))
                ->tap(fn ($q) => $applySearch($q, $search))
                ->when(
                    $highlightRequestId,
                    fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$highlightRequestId])->latest(),
                    fn ($q) => $q->latest(),
                )
                ->paginate(15)
                ->withQueryString()
        ));

        $createdAssessments = $withSignedPreview($withAssessmentAccess(
            AssistanceRequest::query()
                ->with([
                    ...$listRelations,
                    'items.sourceWarehouse:id,name,province',
                    'epirmaSignedDocuments' => fn ($q) => $q->where('routing_status', 'signed')->orderByDesc('id'),
                ])
                ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                ->where('endorsed_to_drrs', true)
                ->tap($applyCreatedAssessments)
                ->tap(fn ($q) => $applySearch($q, $search))
                ->latest('updated_at')
                ->paginate(15, ['*'], 'assessments_page')
                ->withQueryString()
        ));

        $approvedRelations = [
            ...$listRelations,
            'items.sourceWarehouse:id,name,province,municipality',
            'requisitionIssuanceSlip.preparer:id,name,office',
            'requisitionIssuanceSlip.allocationItems',
            'requisitionIssuanceSlip.dispatchPlan',
            'epirmaSignedDocuments' => fn ($q) => $q->where('routing_status', 'signed')->orderByDesc('id'),
        ];

        $approved = $withSignedPreview($withAssessmentAccess(
            AssistanceRequest::query()
                ->with($approvedRelations)
                ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                ->where('endorsed_to_drrs', true)
                ->tap($isRrosWorkspace ? $applyRisStillForAction : $applyApproved)
                ->tap(fn ($q) => $applySearch($q, $search))
                ->latest('epirma_response_letter_signed_at')
                ->paginate(15, ['*'], 'approved_page')
                ->withQueryString()
        ));

        $emptyPaginator = ['data' => [], 'links' => [], 'meta' => null];

        $inProgress = $isRrosWorkspace
            ? $withSignedPreview($withAssessmentAccess(
                AssistanceRequest::query()
                    ->with($approvedRelations)
                    ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                    ->where('endorsed_to_drrs', true)
                    ->tap($applyRisInProgress)
                    ->tap(fn ($q) => $applySearch($q, $search))
                    ->latest('epirma_response_letter_signed_at')
                    ->paginate(15, ['*'], 'in_progress_page')
                    ->withQueryString()
            ))
            : $emptyPaginator;

        $risApproved = $isRrosWorkspace
            ? $withSignedPreview($withAssessmentAccess(
                AssistanceRequest::query()
                    ->with($approvedRelations)
                    ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                    ->where('endorsed_to_drrs', true)
                    ->tap($applyRisApproved)
                    ->tap(fn ($q) => $applySearch($q, $search))
                    ->latest('epirma_response_letter_signed_at')
                    ->paginate(15, ['*'], 'ris_approved_page')
                    ->withQueryString()
            ))
            : $emptyPaginator;

        $risCompleted = $isRrosWorkspace
            ? $withSignedPreview($withAssessmentAccess(
                AssistanceRequest::query()
                    ->with($approvedRelations)
                    ->where('submission_type', '!=', 'lgu_dromic_relief_request')
                    ->where('endorsed_to_drrs', true)
                    ->tap($applyRisCompleted)
                    ->tap(fn ($q) => $applySearch($q, $search))
                    ->latest('epirma_response_letter_signed_at')
                    ->paginate(15, ['*'], 'ris_completed_page')
                    ->withQueryString()
            ))
            : $emptyPaginator;

        $defaultTab = $request->get('default_tab', $request->get('tab', 'tracker'));
        // Map legacy RROS tab ids from older links/notifications.
        if ($isRrosWorkspace) {
            $defaultTab = match ($defaultTab) {
                'approved' => 'still_for_action',
                'for_signing' => 'in_progress',
                default => $defaultTab,
            };
        }
        $allowedTabs = $isRrosWorkspace
            ? ['still_for_action', 'in_progress', 'ris_approved', 'ris_completed', 'ris_transactions']
            : ['tracker', 'assessments', 'approved'];
        if (! in_array($defaultTab, $allowedTabs, true)) {
            $defaultTab = $isRrosWorkspace ? 'still_for_action' : 'tracker';
        }

        $risTransactions = $isRrosWorkspace
            ? RequisitionIssuanceSlip::query()
                ->withCount('allocationItems')
                ->latest('ris_date')
                ->latest('id')
                ->get()
                ->map(fn (RequisitionIssuanceSlip $slip): array => [
                    'id' => $slip->id,
                    'ris_number' => $slip->ris_number,
                    'dr_number' => $slip->dr_number ?: data_get($slip->tracking_data, 'dr_number'),
                    'ris_date' => optional($slip->ris_date)?->toDateString(),
                    'recipient' => $slip->recipient,
                    'delivery_site' => $slip->delivery_site,
                    'status' => match (true) {
                        (bool) $slip->fully_delivered && (bool) $slip->forwarded_to_accounting => 'Fully Delivered / Picked-up and Forwarded to Accounting',
                        (bool) $slip->fully_delivered => 'Fully Delivered / Picked-up',
                        (bool) $slip->forwarded_to_accounting => 'Forwarded to Accounting',
                        default => 'Recorded',
                    },
                    'source' => $slip->sync_source,
                    'item_count' => $slip->allocation_items_count,
                    'sheet_synced_at' => optional($slip->sheet_synced_at)?->toIso8601String(),
                    'ris_preview_url' => $slip->approval_routing_mode === 'epirma' && $slip->ris_epirma_status === 'signed'
                        ? "/rros/ris/{$slip->id}/epirma/signed-preview"
                        : "/rros/ris/{$slip->id}/preview-pdf/ris?inline=1",
                    'ris_preview_kind' => $slip->approval_routing_mode === 'epirma' && $slip->ris_epirma_status === 'signed'
                        ? 'signed'
                        : 'draft',
                    'dr_preview_url' => filled($slip->dr_number ?: data_get($slip->tracking_data, 'dr_number'))
                        ? "/rros/ris/{$slip->id}/preview-pdf/dr?inline=1"
                        : null,
                ])
            : collect();

        return Inertia::render('Requests/Index', [
            'workspaceSummary' => $workspaceSummary,
            'reliefAssessmentGate' => $reliefAssessmentGate,
            'highlightRequestId' => $highlightRequestId,
            'requests' => $stillForAction,
            'assessments' => $createdAssessments,
            'approved' => $approved,
            'inProgress' => $inProgress,
            'risApproved' => $risApproved,
            'risCompleted' => $risCompleted,
            'risTransactions' => $risTransactions,
            // Legacy alias — same as inProgress (prepared RIS bucket).
            'forSigning' => $inProgress,
            'filters' => $request->only(['search', 'status']),
            'assessmentTypes' => AssessmentType::where('is_active', true)->orderBy('name')->get(),
            'inventoryItems' => InventoryItem::where('status', 'active')->orderBy('name')->get(),
            'fniLibraryItems' => FniLibraryItem::query()->orderBy('item_category')->orderBy('item_name')->orderBy('brand_description')->get(),
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
            'drrsSignatories' => OperationalLibraryValue::query()->where('library_type', 'drrs_signatory')->where('is_active', true)
                ->where(fn ($query) => $query->where('metadata->document_type', 'assessment')->orWhereNull('metadata->document_type'))
                ->orderBy('context')->get(['id', 'value', 'context', 'metadata']),
            'rrosSignatories' => OperationalLibraryValue::query()->whereIn('library_type', ['rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory'])->where('is_active', true)->orderBy('library_type')->orderBy('context')->get(['id', 'library_type', 'value', 'context', 'metadata']),
            'drnPrefixes' => OperationalLibraryValue::query()->where('library_type', 'drn_prefix')->where('is_active', true)->orderBy('context')->orderBy('value')->get(['id', 'value', 'context']),
            'requestParties' => RequestParty::query()->with('lguDirectoryEntry:id,psgc_code,lgu_name,override_lgu_name')->where('is_active', true)->orderBy('requesting_party')->orderBy('office_agency_details')->get(),
            'psgc' => ['provinces' => $provinces, 'municipalities' => $municipalities, 'barangays' => $barangays],
            'socialWorkers' => User::query()->role('DRRS')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouseStock' => $warehouseStock,
            'warehouseReservations' => app(RisReservationService::class)->payload(),
            'defaultTab' => $defaultTab,
            'workspaceMode' => $request->attributes->get('request_workspace_mode', 'drrs'),
            'risSync' => $request->attributes->get('request_workspace_mode') === 'rros'
                ? RisSyncRun::latest('started_at')->first()
                : null,
            'stfSync' => $request->attributes->get('request_workspace_mode') === 'rros'
                ? StfSyncRun::latest('started_at')->first()
                : null,
            'stfRecords' => $request->attributes->get('request_workspace_mode') === 'rros'
                ? app(StfSheetSyncService::class)->transactionRows()
                : [],
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
        $user = request()->user();
        abort_unless($user, 403, 'Authentication is required.');
        // Prefer role checks so stale Spatie permission cache cannot block assessment prep.
        abort_unless(
            $user->hasAnyRole(['Super Admin', 'DRRS'])
                || $user->can('encode requests')
                || $user->can('monitor requests')
                || $user->can('process requests'),
            403,
            'You are not allowed to load previous augmentations for this request.',
        );

        $resolved = $resolver->resolve($assistanceRequest);

        return response()->json($resolved);
    }

    public function assessmentForm(Request $request, AssistanceRequest $assistanceRequest, AorCoverageService $aorCoverage, ResponseLetterDocumentService $documents): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(filled($assistanceRequest->assessment_status), 404, 'No assessment documents are available for this request yet.');

        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        abort_unless($access['can_access_documents'], 403, 'Only the DRRS PDRC who created this assessment can open its documents.');

        $forwarded = filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at);

        return Inertia::render('Requests/AssessmentForm', [
            'request' => $assistanceRequest->load(['items', 'assessmentType', 'incident', 'encoder', 'assessmentActor', 'assessmentOnBehalfOwner', 'sourceLguDromicReport']),
            'epirma' => app(EpirmaWorkflowService::class)->capabilitiesFor($assistanceRequest),
            'drnPrefixes' => OperationalLibraryValue::query()->where('library_type', 'drn_prefix')->where('is_active', true)->orderBy('context')->orderBy('value')->get(['id', 'value', 'context']),
            'assessmentAccess' => $access,
            'responseLetterBody' => $documents->effectiveBodyParagraphs($assistanceRequest),
            'canEditResponseLetterBody' => $access['can_access_documents'] && ! $forwarded,
        ]);
    }

    public function updateResponseLetterBody(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AorCoverageService $aorCoverage,
        ResponseLetterDocumentService $documents,
        AuditLogger $audit
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user, 403);

        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        abort_unless($access['can_access_documents'], 403, 'Only the DRRS PDRC who created this assessment can edit the response letter body.');
        abort_if(
            filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at),
            422,
            'This assessment was forwarded to DRRS AA and the response letter body can no longer be edited.'
        );

        $data = $request->validate([
            'opening' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'closing' => ['nullable', 'string', 'max:5000'],
            'reset_fields' => ['nullable', 'array'],
            'reset_fields.*' => ['string', 'in:opening,assessment,closing'],
            'reset' => ['nullable', 'boolean'],
        ]);

        $meta = (array) ($assistanceRequest->assessment_form_data ?? []);
        $body = (array) ($meta['response_letter_body'] ?? []);
        $oldBody = $body;

        if (($data['reset'] ?? false) === true) {
            $body = [];
        } else {
            foreach ((array) ($data['reset_fields'] ?? []) as $field) {
                unset($body[$field]);
            }
            foreach (['opening', 'assessment', 'closing'] as $field) {
                if (! array_key_exists($field, $data) || $data[$field] === null) {
                    continue;
                }
                $value = trim((string) $data[$field]);
                if ($value === '') {
                    unset($body[$field]);
                } else {
                    $body[$field] = $value;
                }
            }
        }

        if ($body === []) {
            unset($meta['response_letter_body']);
        } else {
            $meta['response_letter_body'] = $body;
        }

        $assistanceRequest->update(['assessment_form_data' => $meta]);
        $audit->log('request.response_letter_body_updated', $assistanceRequest, [
            'response_letter_body' => $oldBody,
        ], [
            'response_letter_body' => $body,
        ]);

        return back()->with([
            'success' => 'Response letter body saved.',
            'responseLetterBody' => $documents->effectiveBodyParagraphs($assistanceRequest->fresh()),
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

    public function assessmentPdf(Request $request, AssistanceRequest $assistanceRequest, InventoryBalanceService $inventoryBalanceService): HttpResponse
    {
        $record = $assistanceRequest->load([
            'items.sourceWarehouse',
            'assessmentType',
            'incident',
            'encoder',
            'sourceLguDromicReport:id,lgu_dromic_payload,affected_families',
        ]);
        $this->applyLiveAvailableQuantities($record, $inventoryBalanceService);
        $record->assessment_form_data = $this->uppercaseAssessmentSignatories(
            (array) ($record->assessment_form_data ?? [])
        );
        $requestedMargin = (int) $request->integer('margin', 18);
        $pageMargin = in_array($requestedMargin, [18, 27, 36, 54, 72], true) ? $requestedMargin : 18;
        $pdf = Pdf::loadView('documents.assessment', ['request' => $record, 'pageMargin' => $pageMargin])->setPaper('a4', 'portrait');
        $filename = InlinePdfFilename::fromCandidates(
            $record->assessment_drn ? 'Assessment-'.$record->assessment_drn : null,
            $record->reference_number ? 'Assessment-'.$record->reference_number : null,
            'Assessment-'.$record->id,
        );

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    public function assessmentDraftPdf(Request $request, InventoryBalanceService $inventoryBalanceService): HttpResponse
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasAnyRole(['Super Admin', 'DRRS']) || $user->can('encode requests')),
            403
        );

        $requestedMargin = (int) $request->integer('margin', 18);
        $pageMargin = in_array($requestedMargin, [18, 27, 36, 54, 72], true) ? $requestedMargin : 18;
        $baseId = (int) ($request->input('preview_request_id') ?: $request->input('request_id') ?: 0);
        $base = $baseId > 0
            ? AssistanceRequest::query()
                ->with(['items', 'incident', 'encoder', 'sourceLguDromicReport:id,lgu_dromic_payload,affected_families'])
                ->find($baseId)
            : null;

        $record = $this->makeLiveAssessmentPreview($request->all(), $base);
        $this->applyLiveAvailableQuantities($record, $inventoryBalanceService);
        $pdf = Pdf::loadView('documents.assessment', [
            'request' => $record,
            'pageMargin' => $pageMargin,
        ])->setPaper('a4', 'portrait');
        $filename = InlinePdfFilename::fromCandidates(
            $record->assessment_drn ? 'Assessment-'.$record->assessment_drn : null,
            $record->reference_number ? 'Assessment-'.$record->reference_number : null,
            'Assessment-Draft-Preview',
        );

        return $pdf->stream($filename);
    }

    private function makeLiveAssessmentPreview(array $data, ?AssistanceRequest $base = null): AssistanceRequest
    {
        $meta = $this->uppercaseAssessmentSignatories(
            (array) ($data['assessment_form_data'] ?? $base?->assessment_form_data ?? [])
        );
        $dateRequested = $data['date_requested'] ?? $base?->date_requested;
        if (is_string($dateRequested) && $dateRequested !== '') {
            try {
                $dateRequested = \Illuminate\Support\Carbon::parse($dateRequested);
            } catch (\Throwable) {
                $dateRequested = $base?->date_requested;
            }
        }

        $record = new AssistanceRequest([
            'reference_number' => $base?->reference_number
                ?: (string) ($data['reference_number'] ?? 'Draft Assessment'),
            'assessment_drn' => $data['assessment_drn']
                ?? data_get($meta, 'assessment_drn')
                ?? $base?->assessment_drn,
            'requesting_agency' => $data['requesting_agency'] ?? $base?->requesting_agency,
            'purpose' => $data['purpose']
                ?? data_get($meta, 'response_purpose')
                ?? $base?->purpose,
            'date_requested' => $dateRequested,
            'affected_families' => $data['affected_families'] ?? $base?->affected_families,
            'incident_details' => $data['incident_details']
                ?? data_get($meta, 'incident_specific_details')
                ?? $base?->incident_details,
            'assessment_form_data' => $meta,
            'recommendations' => AssessmentNarrative::sanitize(
                $data['recommendations'] ?? $base?->recommendations
            ),
            'remarks' => $data['remarks'] ?? $base?->remarks,
            'assessment_summary' => $data['assessment_summary'] ?? $base?->assessment_summary,
            'lgu_dromic_payload' => $base?->lgu_dromic_payload,
            'source_lgu_dromic_request_id' => $base?->source_lgu_dromic_request_id,
        ]);

        if ($base?->relationLoaded('sourceLguDromicReport') && $base->sourceLguDromicReport) {
            $record->setRelation('sourceLguDromicReport', $base->sourceLguDromicReport);
        }

        $itemRows = collect($data['items'] ?? []);
        if ($itemRows->isEmpty() && $base) {
            $itemRows = $base->items->map(fn ($item) => $item->toArray());
        }

        $items = $itemRows
            ->filter(fn ($item) => is_array($item) && filled($item['item_name'] ?? null))
            ->values()
            ->map(fn (array $item) => new RequestItem([
                'item_name' => $item['item_name'] ?? '',
                'requested_quantity' => $item['requested_quantity'] ?? 0,
                'available_quantity' => array_key_exists('available_quantity', $item)
                    ? $item['available_quantity']
                    : null,
                'unit' => $item['unit'] ?? null,
                'priority' => $item['priority'] ?? 'normal',
                'remarks' => $item['remarks'] ?? null,
            ]));

        if ($items->isEmpty()) {
            $items = collect([new RequestItem([
                'item_name' => '',
                'requested_quantity' => 0,
                'available_quantity' => null,
            ])]);
        }

        $record->setRelation('items', $items);

        $incidentName = $data['incident_name']
            ?? data_get($meta, 'incident_type')
            ?? $base?->incident?->name;
        $incidentDate = $data['incident_date']
            ?? data_get($meta, 'occurrence_started_at')
            ?? $base?->incident?->incident_date;
        $record->setRelation('incident', new Incident([
            'name' => $incidentName,
            'incident_date' => $incidentDate,
            'province' => $data['province'] ?? $base?->incident?->province ?? $base?->province,
            'municipality' => $data['municipality'] ?? $base?->incident?->municipality ?? $base?->municipality,
            'barangay' => $data['barangay'] ?? $base?->incident?->barangay ?? $base?->barangay,
        ]));

        return $record;
    }

    private function uppercaseAssessmentSignatories(array $meta): array
    {
        if (filled($meta['prepared_by'] ?? null)) {
            $meta['prepared_by'] = Str::upper(trim((string) $meta['prepared_by']));
        }
        foreach (['reviewed_by', 'approved_by'] as $field) {
            if (! array_key_exists($field, $meta)) {
                continue;
            }
            $raw = trim((string) $meta[$field]);
            if ($raw === '') {
                $meta[$field] = '';
                continue;
            }
            $parts = explode('|', $raw, 2);
            $parts[0] = Str::upper(trim($parts[0]));
            $meta[$field] = implode('|', $parts);
        }

        return $meta;
    }

    /**
     * Resolve inventory/RIS item keys the same way RROS does, stripping a trailing
     * " - brand" suffix so LGU-prefilled lines still match stockpile item names.
     */
    private function inventoryItemKey(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $base = trim((string) preg_replace('/\s+-\s+.+$/u', '', $name));

        return preg_replace('/[^a-z0-9]+/', '', strtolower($base !== '' ? $base : $name)) ?? '';
    }

    private function quantityForInventoryKey(Collection $totals, string $key): float
    {
        if ($key === '') {
            return 0.0;
        }
        if ($totals->has($key)) {
            return (float) $totals->get($key);
        }

        // Prefer the longest inventory key that is a prefix of the request key
        // (handles residual brand text that was not separated by " - ").
        $matchKey = $totals->keys()
            ->filter(fn ($candidate) => is_string($candidate) && $candidate !== '' && str_starts_with($key, $candidate))
            ->sortByDesc(fn (string $candidate): int => strlen($candidate))
            ->first();

        return $matchKey ? (float) $totals->get($matchKey) : 0.0;
    }

    private function applyLiveAvailableQuantities(
        AssistanceRequest $record,
        InventoryBalanceService $inventoryBalanceService,
        ?RisReservationService $reservationService = null,
    ): void {
        $availableTotals = $inventoryBalanceService->availableTotalsByItem();
        $reservedByItem = ($reservationService ?? app(RisReservationService::class))->totalsByItem();
        $items = $record->items->map(function ($item) use ($availableTotals, $reservedByItem) {
            $key = $this->inventoryItemKey($item->item_name ?? '');
            $physical = $this->quantityForInventoryKey($availableTotals, $key);
            $reserved = $this->quantityForInventoryKey($reservedByItem, $key);
            $item->available_quantity = (int) max(0, $physical - $reserved);

            return $item;
        });
        $record->setRelation('items', $items);
    }

    public function updateAssessment(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse
    {
        abort_if(
            filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at),
            422,
            'This assessment was forwarded to DRRS AA and can no longer be edited.'
        );

        $data = $request->validate([
            'assessment_type_id' => ['nullable', 'exists:assessment_types,id'], 'purpose' => ['nullable', 'string', 'max:255'],
            'incident_name' => ['nullable', 'required_if:purpose,Relief Augmentation', 'string', 'max:255'], 'incident_date' => ['nullable', 'required_if:purpose,Relief Augmentation', 'date'],
            'incident_details' => ['nullable', 'string', 'max:500'], 'incident_count' => ['nullable', 'integer', 'min:1'],
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
            if (! $incident && $oldIncident && $oldIncident->requests()->doesntExist()) {
                $oldIncident->delete();
            }
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
    ): RedirectResponse {
        abort_unless($assistanceRequest->endorsed_to_drrs, 422, 'Only records endorsed to DRRS can be assessed through this workflow.');
        abort_if(
            filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at),
            422,
            'This assessment was forwarded to DRRS AA and can no longer be edited.'
        );

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
                $user->hasAnyRole(['Super Admin', 'DRRS']) || $user->can('encode requests'),
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
        $data['assessment_form_data'] = $this->uppercaseAssessmentSignatories(
            (array) ($data['assessment_form_data'] ?? [])
        );
        $availableTotals = $inventoryBalanceService->availableTotalsByItem();
        $reservedByItem = app(RisReservationService::class)->totalsByItem();
        $data['items'] = collect($data['items'])->map(function (array $item) use ($availableTotals, $reservedByItem): array {
            $key = $this->inventoryItemKey($item['item_name'] ?? '');
            $physical = $this->quantityForInventoryKey($availableTotals, $key);
            $reserved = $this->quantityForInventoryKey($reservedByItem, $key);
            // Same available-to-plan rule as RROS RIS planning: physical available_balance minus active RIS reservations.
            $item['available_quantity'] = (int) max(0, $physical - $reserved);

            return $item;
        })->all();
        $unavailableItems = collect($data['items'])->filter(
            fn (array $item): bool => (float) ($item['requested_quantity'] ?? 0) > (float) ($item['available_quantity'] ?? 0)
        );
        if ($unavailableItems->isNotEmpty()) {
            $names = $unavailableItems->pluck('item_name')->filter()->unique()->implode(', ');

            return back()->withErrors([
                'items' => 'Assessment cannot be submitted because the requested quantity is not available to plan (stockpile minus quantities already reserved by active RIS): '.$names.'.',
            ])->withInput();
        }
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
            if (! $incident && $oldIncident && $oldIncident->requests()->doesntExist()) {
                $oldIncident->delete();
            }
        });

        $fresh = $assistanceRequest->fresh(['items', 'incident', 'requestParty']);
        $audit->log('request.assessment_completed', $fresh, $old, $fresh->toArray());

        return redirect()
            ->route('requests.assessment', $fresh)
            ->with('success', "Assessment for {$fresh->reference_number} saved. Assessment and response-letter previews, printing, and downloads are ready.");
    }

    public function assessmentStatus(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $workflowNotifications, AorCoverageService $aorCoverage, InventoryBalanceService $inventoryBalanceService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $access = $aorCoverage->assessmentAccessFor($user, $assistanceRequest);
        abort_unless($access['can_access_documents'], 403, 'Only the DRRS PDRC who created this assessment can change its status.');

        $data = $request->validate(['assessment_status' => ['required', 'in:draft,final,submitted']]);
        abort_if(blank($assistanceRequest->assessment_status), 422, 'Create the assessment before changing its status.');

        // After forward/signed, status is owned by e-PIRMA routing — no reopen/submit/recall from UI.
        abort_if(
            filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at),
            422,
            'This assessment was forwarded to DRRS AA. Status changes (including reopen) are no longer allowed.'
        );

        $old = $assistanceRequest->assessment_status;
        $allowed = match ($old) {
            // Drafts become final only after the e-PIRMA callback verifies a
            // successful signature. A direct status request cannot bypass it.
            'draft' => [],
            // Legacy non-e-PIRMA path: final→submitted still exists for rare cases
            // where an assessment reached final without AA forward. UI no longer
            // exposes Submit; keep the transition for API/admin recovery only.
            'final' => ['draft', 'submitted'],
            'submitted' => in_array($assistanceRequest->status, ['submitted', 'rejected'], true) ? ['draft'] : [],
            default => [],
        };
        abort_unless(in_array($data['assessment_status'], $allowed, true), 422, 'This assessment status transition is not allowed. Reopen or revise the assessment through the In Progress tab.');
        if ($data['assessment_status'] === 'submitted') {
            $availableTotals = $inventoryBalanceService->availableTotalsByItem();
            $reservedByItem = app(RisReservationService::class)->totalsByItem();
            $unavailable = $assistanceRequest->items()->get()->filter(function ($item) use ($availableTotals, $reservedByItem): bool {
                $key = $this->inventoryItemKey($item->item_name);
                $availableToPlan = max(0, $this->quantityForInventoryKey($availableTotals, $key) - $this->quantityForInventoryKey($reservedByItem, $key));

                return (float) $item->requested_quantity > $availableToPlan;
            });
            abort_if(
                $unavailable->isNotEmpty(),
                422,
                'Assessment cannot be submitted because stock available to plan is insufficient for: '.$unavailable->pluck('item_name')->unique()->implode(', ').'.'
            );
        }
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

    public function polishAssessment(Request $request, GroqChatService $groq): JsonResponse
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
        if ($apiKey === '') {
            return response()->json(['message' => 'Groq AI is not configured. Add GROQ_API_KEY to the server environment, then clear the configuration cache.'], 503);
        }

        $context = (array) ($data['form_context'] ?? []);
        validator(['incidents' => $context['incidents'] ?? []], [
            'incidents' => ['array', 'max:25'],
            'incidents.*.incident_type' => ['nullable', 'string', 'max:255'],
            'incidents.*.incident_details' => ['nullable', 'string', 'max:1000'],
            'incidents.*.occurrence_at' => ['nullable', 'date'],
            'incidents.*.city_municipality' => ['nullable', 'string', 'max:255'],
            'incidents.*.barangay' => ['nullable', 'string', 'max:255'],
            'incidents.*.affected_families' => ['nullable', 'integer', 'min:0'],
            'incidents.*.affected_persons' => ['nullable', 'integer', 'min:0'],
            'incidents.*.description' => ['nullable', 'string', 'max:3000'],
            'incidents.*.source_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $incidentRows = collect($context['incidents'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['incident_type'] ?? null))
            ->values();
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
        $advisories = collect($context['source_official_advisories'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && collect($row)->filter(fn ($value) => filled($value))->isNotEmpty())
            ->values();
        $incidentTypeLabel = strtolower(trim((string) ($data['incident_name'] ?? $context['incident_type'] ?? '')));
        $isWeatherOrSeismicIncident = $incidentTypeLabel !== '' && preg_match(
            '/typhoon|tropical\s*cyclone|storm|flood|flash\s*flood|rainfall|monsoon|habagat|weather|wind|surge|landslide|earthquake|seismic|volcan(?:o|ic)|ashfall|tsunami|phivolcs|pagasa/i',
            $incidentTypeLabel
        ) === 1;
        $advisoryInstruction = $advisories->isNotEmpty()
            ? 'Official advisory rows were supplied. Attribute PAGASA, PHIVOLCS, or another agency only when that agency appears in those rows, and paraphrase only the relevant verified hazard information; never invent an agency finding.'
            : ($isWeatherOrSeismicIncident
                ? 'No official PAGASA/PHIVOLCS advisory rows were supplied. Do not invent advisory content. You may omit advisory discussion entirely.'
                : 'No official PAGASA/PHIVOLCS advisory rows were supplied, and this is not a weather or earthquake/volcanic incident. Do not mention PAGASA, PHIVOLCS, weather advisories, seismic bulletins, or the absence of such advisories.');
        $facts = collect([
            'Canonical requesting-party identity: '.$formalLguName,
            'Affected geographic area: '.$affectedLocality,
            'Stored requesting-party label (abbreviated form of the governing body; do not state it separately from the canonical identity): '.($requestingAgency !== '' ? $requestingAgency : 'Not specified'),
            'Incident: '.collect([$data['incident_name'] ?? null, $data['incident_details'] ?? null])->filter()->implode(' - '),
            'Structured incident occurrences (each row is a separate incident; preserve its own date, place, population, details, and source): '.($incidentRows->isNotEmpty() ? $incidentRows->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'Not supplied'),
            'Number of separate incidents: '.($incidentRows->isNotEmpty() ? $incidentRows->count() : 1),
            'Purpose: '.($data['purpose'] ?? 'Not specified'),
            'Affected families (preserve this exact number): '.(array_key_exists('affected_families', $data) ? number_format((int) $data['affected_families']) : 'Not supplied'),
            'Affected persons (preserve this exact number): '.(array_key_exists('affected_persons', $context) && filled($context['affected_persons']) ? number_format((int) $context['affected_persons']) : 'Not supplied'),
            'Affected-area presentation: '.$affectedAreaSummary,
            'Mandatory temporal framing: '.$temporalFraming,
            'Chronology controls (use to select tense; do not automatically narrate every date): incident occurrence '.($context['incident_occurrence_display'] ?? $context['incident_date'] ?? 'not supplied').'; request date '.($context['date_requested'] ?? 'not supplied').'; assessment date '.($context['assessment_date'] ?? now()->toDateString()).'; DROMIC incident status '.($incidentStatus ?: 'not supplied').'; incident ended '.($incidentEndedAt ?: 'not supplied').'; report classification '.($reportClassification ?: 'not supplied').'.',
            'Official advisory rows: '.($advisories->isNotEmpty() ? $advisories->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'None supplied'),
            'Advisory handling rule: '.$advisoryInstruction,
            'FNI stock validation: '.($items ?: 'No FNI rows supplied'),
            ($data['mode'] === 'polish' ? 'Current draft to polish: ' : 'Existing draft (reference only; independently generate from encoded facts): ').($data['text'] ?? 'No draft supplied.'),
            'Other encoded assessment facts (JSON; null or blank means not supplied): '.json_encode($curatedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])->implode("\n");

        try {
            $modeInstruction = $data['mode'] === 'generate'
                ? 'Create a new narrative from the encoded facts in exactly this operational sequence. Paragraph 1: describe what happened, when and where it happened, and how the hazard developed or could affect the area. '.$advisoryInstruction.' Paragraph 2: describe documented effects, current incident or affected-area status, exact affected families and persons when supplied, displacement inside or outside evacuation centers, other material impacts, identified needs, and the LGU response actions. Omit any category without supplied data. Paragraph 3: state what the requesting party requested, each item and exact quantity, and why augmentation is warranted based only on the documented effects, population, displacement, needs, and stock assessment. State exact available stock and whether it is sufficient or deficient, then give a clear recommendation. Paragraph 4: state that the DRMD continues monitoring the situation and identifying the needs of the affected area and population, and assure continued active coordination with the LGU to validate and help provide immediate needs and determine whether additional support may be required. This final paragraph is an institutional ongoing-action statement, not a claim of an approval or completed delivery.'
                : 'Perform a conservative polish of the user-written draft. Preserve the user’s paragraph order, substantive wording, facts, quantities, emphasis, qualifications, recommendation, and manually added details. Do not force it into the auto-generation four-paragraph structure. Do not add a DRMD assurance, incident detail, justification, finding, or conclusion unless it already appears in the draft. Make only the minimum edits needed to correct grammar, spelling, punctuation, awkward phrasing, terminology, clarity, formal tone, and tense conflicts with the authoritative DROMIC status. Prefer rephrasing a flawed sentence over replacing the user’s idea. Never remove a meaningful manually written statement merely to make the draft shorter. '.$advisoryInstruction.' If official advisory rows were not supplied, delete any draft sentence that invents PAGASA/PHIVOLCS content or that mentions the absence, lack, or non-citation of such advisories.';
            $structureInstruction = $data['mode'] === 'generate'
                ? 'Use four concise cohesive paragraphs in the required sequence.'
                : 'Retain the draft’s existing paragraph structure and sequence; do not expand it into a new assessment.';
            $lguIdentityInstruction = 'MLGU means Municipal Local Government Unit, CLGU means City Local Government Unit, and PLGU means Provincial Local Government Unit. The LGU is the governing body; the municipality, city, or province is the geographic area under its jurisdiction. Keep their grammatical roles distinct. Only the geographic area may experience, be affected by, or recover from a disaster. Only the LGU may report, request assistance, monitor, coordinate, validate, or undertake response actions. Never say that an MLGU, CLGU, or PLGU is experiencing or is affected by the hazard. Introduce the canonical requesting-party identity only when describing an LGU action; use the affected geographic area when describing the hazard and its effects. The stored requesting-party label is merely an abbreviated form of the canonical governing-body identity and must not be stated separately.';
            $multiIncidentInstruction = $incidentRows->count() > 1
                ? 'This consolidated request covers multiple separate incidents. Identify every encoded occurrence by its own date and location, preserve its population breakdown, then state only the reconciled combined totals. Never collapse the rows into one event, duplicate a population, or imply that separate incidents occurred on the same date or at the same location. Explain that one consolidated assessment and augmentation recommendation covers them.'
                : '';
            $modeInstruction = collect([$lguIdentityInstruction, $multiIncidentInstruction, $modeInstruction])->filter()->implode(' ');

            $payload = [
                'model' => config('services.groq.model'),
                'temperature' => $data['mode'] === 'generate' ? 0.3 : 0.15,
                'max_completion_tokens' => 650,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are assisting the social worker who is personally preparing this assessment. In this system, FNI always means Food and Non-Food Items. Never expand FNI as Family Needs Identification or assign it any other meaning. Produce an official FNI Assessment and Delivery Form narrative in plain professional English. Treat populated encoded fields as verified facts and ignore blank fields. Structured encoded totals, incident status, ended date, report classification, and chronology controls are authoritative and override any older or inconsistent wording, number, or status quoted inside the source DROMIC narrative or advisory text. Apply the mandatory temporal framing exactly: an ENDED incident must use past tense for what happened, its effects, and LGU actions; an ONGOING incident must use present-perfect or continuing tense for experiences and effects that continue. Never confuse the incident tense with ongoing DRMD monitoring and coordination, which may correctly remain in present tense after the hazard has ended. Never invent or change dates, quantities, affected populations, displacement, damage, preparedness or response actions, findings, needs, coordination, signatories, approvals, deliveries, or LGU resource shortages. A positive affected count must never become zero. Always call the organization making the request the "requesting party"; never call it the proposing party. Do not mention when the request was made or restate the Date of Request. Mention occurrence and information dates only when they materially clarify the assessment. When more than five affected areas are supplied, state only their count and never enumerate their names. Attribute PAGASA, PHIVOLCS, or another agency only when an official-advisory fact from that agency is supplied, and paraphrase only what is relevant to what happened and how the hazard affected or threatened the reported location. Never invent advisory content, and never mention that PAGASA or PHIVOLCS advisories were absent, missing, or not cited. Avoid repetitive statements, vague recovery claims, and generic humanitarian language unsupported by encoded facts. Never include "Approved by", "Prepared by", "Reviewed by", signature lines, date lines, names of signatories, or any sign-off placeholder; those belong in separate form fields. Never mention the assigned social worker, current user, case handler, assessor, or who is preparing or handling the case. Use materially relevant facts without exposing JSON or field labels. Clearly state exact requested stock, exact available stock, and whether it is sufficient or deficient. '.$structureInstruction.' Keep the complete output within 260 words so the full narrative and signature spaces fit one A4 assessment page. Return paragraphs only, without headings, bullets, markdown, greetings, or commentary. '.$modeInstruction],
                    ['role' => 'user', 'content' => $facts],
                ],
            ];
            ['response' => $response, 'model' => $model] = $groq->complete($payload);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => $groq->unreachableMessage($exception).' Your current assessment was not changed.'], 503);
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Groq API error '.$response->status().': '.$response->body()));

            return response()->json(['message' => $groq->errorMessage($response, 'Groq AI could not enhance the assessment. Confirm the API key, model, and free-tier availability.')], 502);
        }
        $polished = $groq->messageText($response);
        if ($polished === '') {
            return response()->json(['message' => 'Groq AI returned an empty result. Your current assessment was not changed.'], 502);
        }
        $endedTenseViolation = $endedIncident && preg_match(
            '/\b(is|are)\s+(?:currently\s+)?experiencing\b|\b(has|have)\s+been\s+experiencing\b|\b(is|are)\s+(?:currently\s+)?affecting\b|\bcontinues?\s+to\s+affect\b|\b(is|are)\s+expected\s+to\s+affect\b/i',
            (string) (preg_split('/\R\s*\R/u', $polished, 2)[0] ?? $polished)
        ) === 1;

        if ($endedTenseViolation) {
            try {
                ['response' => $correction] = $groq->complete([
                    'model' => config('services.groq.model'),
                    'temperature' => 0.05,
                    'max_completion_tokens' => 650,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a strict temporal-grammar editor. The DROMIC incident is ENDED. Correct only the temporal grammar of the supplied assessment without changing its paragraph structure, facts, date, count, requested item, stock figure, recommendation, manually written details, or ongoing DRMD assurance. Use past tense for the incident, hazard experience, effects, displacement, and LGU actions. Do not use "is/are experiencing", "has/have been experiencing", "is/are affecting", "continues to affect", or "is/are expected to affect" for the ended hazard. Ongoing DRMD monitoring and coordination may remain in present tense. Return only the corrected assessment with no heading, sign-off, or commentary.'],
                        ['role' => 'user', 'content' => $polished],
                    ],
                ]);

                if ($correction->successful()) {
                    $polished = $groq->messageText($correction);
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

        return response()->json(['polished' => $polished, 'provider' => 'Groq', 'model' => $model]);
    }

    public function responseLetter(AssistanceRequest $assistanceRequest, ResponseLetterDocumentService $documents): BinaryFileResponse
    {
        abort_if(blank($assistanceRequest->response_drn), 422, 'DRRS AA must assign the Response Letter DRN before downloading the document.');
        $file = $documents->generate($assistanceRequest->load(['items', 'approvals', 'incident']));

        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function responseLetterPdf(Request $request, AssistanceRequest $assistanceRequest, ResponseLetterDocumentService $documents, WordToPdfService $converter): BinaryFileResponse
    {
        abort_if(! $request->boolean('inline') && blank($assistanceRequest->response_drn), 422, 'DRRS AA must assign the Response Letter DRN before downloading the document.');
        $record = $assistanceRequest->load(['items', 'approvals', 'incident', 'requestParty.lguDirectoryEntry.officials', 'requestParty.lguDirectoryEntry.contacts']);
        $word = $documents->generate($record);

        try {
            $pdfPath = $converter->convert($word['path']);
        } finally {
            @unlink($word['path']);
        }

        $filename = InlinePdfFilename::fromCandidates(
            $record->response_drn ? 'Response-Letter-'.$record->response_drn : null,
            $record->reference_number ? 'Response-Letter-'.$record->reference_number : null,
            'Response-Letter-'.$record->id,
        );
        $headers = [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
        $response = $request->boolean('inline')
            ? response()->file($pdfPath, [...$headers, 'Content-Disposition' => InlinePdfFilename::disposition($filename)])
            : response()->download($pdfPath, $filename, $headers);

        return $response->deleteFileAfterSend(true);
    }

    public function updateResponseDrn(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        abort(410, 'Response Letter DRNs are assigned together with the Assessment DRN by DRRS AA after forwarding.');
    }

    /**
     * Advance RIS / DR printable payload for Document Preview (same shape as Dispatch Plan).
     */
    private function serializeRisPreviewPayload(RequisitionIssuanceSlip $slip): array
    {
        $tracking = is_array($slip->tracking_data) ? $slip->tracking_data : [];
        $previewItems = $this->risPreviewItemsFromSlip($slip)->map(fn (array $item) => [
            'item_name' => $item['item_name'] ?? 'Item',
            'unit' => $item['unit'] ?? null,
            'quantity' => (int) ($item['allocated_quantity'] ?? 0),
            'remarks' => $item['remarks'] ?? null,
            'warehouse_name' => $item['warehouse_name'] ?? null,
            'warehouse_id' => $item['warehouse_id'] ?? null,
        ])->values();
        $remarks = trim((string) ($slip->remarks ?? ''));

        return [
            'form' => [
                'ris_number' => $slip->ris_number,
                'ris_date' => optional($slip->ris_date)?->format('Y-m-d'),
                'purpose_of_release' => $slip->purpose_of_release,
                'recipient' => $slip->recipient,
                'delivery_site' => $slip->delivery_site,
                'receiving_representative' => $slip->receiving_representative,
                'contact_number' => $slip->contact_number,
                'remarks' => $remarks !== '' ? $remarks : $slip->remarks,
                'items' => $previewItems,
            ],
            'tracking' => [
                ...$tracking,
                'ris_drn' => $slip->ris_drn ?: ($tracking['ris_drn'] ?? null),
                'dr_number' => $slip->dr_number ?: ($tracking['dr_number'] ?? null),
                'prepared_by_name' => $slip->prepared_by_name ?: ($tracking['prepared_by_name'] ?? null),
                'release_witnessed_by' => $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null),
                'delivered_at' => optional($slip->delivered_at)?->format('Y-m-d') ?: ($tracking['delivered_at'] ?? null),
                'returned_particulars' => $slip->returned_particulars ?: ($tracking['returned_particulars'] ?? null),
                'returned_quantity' => $slip->returned_quantity ?? ($tracking['returned_quantity'] ?? null),
                'returned_reason' => $slip->returned_reason ?: ($tracking['returned_reason'] ?? null),
                'driver_name' => $tracking['driver_name'] ?? null,
                'driver_contact_number' => $tracking['driver_contact_number'] ?? null,
                'vehicle_plate_number' => $tracking['vehicle_plate_number'] ?? null,
                'mode_of_transportation' => $tracking['mode_of_transportation'] ?? [],
                'fulfillment_note' => $tracking['fulfillment_note'] ?? null,
            ],
            'has_dr' => filled($slip->dr_number ?: ($tracking['dr_number'] ?? null)),
        ];
    }

    private function risPreviewItemsFromSlip(RequisitionIssuanceSlip $slip): Collection
    {
        $allocation = $slip->relationLoaded('allocationItems')
            ? $slip->allocationItems
            : $slip->allocationItems()->get();

        if ($allocation->isNotEmpty()) {
            return $allocation->map(fn ($item) => [
                'warehouse_id' => $item->warehouse_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'allocated_quantity' => (int) $item->quantity,
                'remarks' => $item->remarks,
            ]);
        }

        return collect(is_array($slip->items) ? $slip->items : [])->map(fn (array $item) => [
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'item_name' => $item['item_name'] ?? 'Item',
            'unit' => $item['unit'] ?? null,
            'warehouse_name' => $item['warehouse_name'] ?? null,
            'allocated_quantity' => (int) ($item['quantity'] ?? 0),
            'remarks' => $item['remarks'] ?? null,
        ]);
    }

    private function asStringList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => trim((string) $item))->filter()->values()->all();
        }
        if ($value === null || $value === '') {
            return [];
        }
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }
        if (str_contains($text, ',')) {
            return collect(explode(',', $text))->map(fn ($item) => trim($item))->filter()->values()->all();
        }

        return [$text === 'Partner' ? 'Partner LGU' : $text];
    }
}
