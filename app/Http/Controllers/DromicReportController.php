<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\PsgcAddress;
use App\Services\AorCoverageService;
use App\Services\AuditLogger;
use App\Services\LguReliefRequestHandoffService;
use App\Services\WorkflowNotificationService;
use App\Support\LinkedLguDromicIncidentReports;
use App\Support\LguDromicReportTitle;
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

    public function lguReports(Request $request, AorCoverageService $aorCoverage): Response
    {
        $user = $request->user();
        abort_unless(
            $user?->hasAnyRole(['DRIMS', 'DRRS', 'QRT', 'Quick Response Team', 'OCD Caraga', 'Super Admin'])
                || $user?->can('monitor requests')
                || $user?->can('manage regional alerts'),
            403,
        );

        $baseQuery = AssistanceRequest::query()
            ->with([
                'incident:id,name,incident_date',
                'lguSubmitter:id,name,lgu_name,lgu_psgc_code,area_of_assignment',
                'lguDromicReviewer:id,name,office',
                'lguReliefReviewer:id,name,office',
                'lguDromicViewer:id,name,office',
                'lguReliefViewer:id,name,office',
                'lguDromicAcker:id,name,office',
                'lguReliefAcker:id,name,office',
                'lguAmendmentRequester:id,name',
                'lguAmendmentReviewer:id,name',
                'reliefAugmentationRequest:id,source_lgu_dromic_request_id,reference_number,status',
                'signedDocumentVersions:id,request_id,kind,path,original_name,uploaded_at,created_at',
            ])
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_submitted_to_dswd_at')
            ->whereIn('lgu_report_status', ['advance_submitted', 'submitted'])
            ->where(function ($query): void {
                $query->whereNull('lgu_dromic_payload->standalone_relief_request')
                    ->orWhere('lgu_dromic_payload->standalone_relief_request', false);
            });

        $incidentRows = AssistanceRequest::query()
            ->with([
                'incident:id,name,incident_date',
                'lguSubmitter:id,name,lgu_name,lgu_psgc_code,area_of_assignment',
            ])
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_submitted_to_dswd_at')
            ->whereIn('lgu_report_status', ['advance_submitted', 'submitted'])
            ->where(function ($query): void {
                $query->whereNull('lgu_dromic_payload->standalone_relief_request')
                    ->orWhere('lgu_dromic_payload->standalone_relief_request', false);
            })
            ->latest('created_at')
            ->get();
        $requestedTab = $request->string('tab')->toString();
        $defaultTab = $request->user()?->hasRole('DRRS') && ! $request->user()?->hasRole('DRIMS')
            ? 'requests'
            : 'incidents';
        $tab = in_array($requestedTab, ['incidents', 'reports', 'requests'], true) ? $requestedTab : $defaultTab;
        $search = trim($request->string('search')->toString());
        $validation = $request->string('validation')->toString();
        $amendmentFilter = $request->string('amendment')->toString();
        $submissionStatus = $request->string('status')->toString();
        $classification = $request->string('classification')->toString();
        $seriesKey = trim($request->string('series_key')->toString());
        $seriesSearch = Str::of($search)->lower()->replace('dromic-inc-', '')->replace('dis-inc-', '')->replace('inc-', '')->replace('dis-', '')->trim()->toString();

        if ($tab === 'reports') {
            $baseQuery->where(fn ($scope) => $scope->whereNull('lgu_correction_target')->orWhere('lgu_correction_target', 'report'));
        } elseif ($tab === 'requests') {
            // Requests tab includes lump/standalone relief letters and per-incident request letters.
            $baseQuery = AssistanceRequest::query()
                ->with([
                    'incident:id,name,incident_date',
                    'lguSubmitter:id,name,lgu_name,lgu_psgc_code,area_of_assignment',
                    'lguDromicReviewer:id,name,office',
                    'lguReliefReviewer:id,name,office',
                    'lguDromicViewer:id,name,office',
                    'lguReliefViewer:id,name,office',
                    'lguDromicAcker:id,name,office',
                    'lguReliefAcker:id,name,office',
                    'lguAmendmentRequester:id,name',
                    'lguAmendmentReviewer:id,name',
                    'reliefAugmentationRequest:id,source_lgu_dromic_request_id,reference_number,status',
                    'signedDocumentVersions:id,request_id,kind,path,original_name,uploaded_at,created_at',
                ])
                ->where('submission_type', 'lgu_dromic_relief_request')
                ->whereNotNull('lgu_relief_request_reference')
                ->where(function ($query): void {
                    $query->where(function ($submitted): void {
                        $submitted->whereNotNull('lgu_submitted_to_dswd_at')
                            ->whereIn('lgu_report_status', ['advance_submitted', 'submitted']);
                    })->orWhere(function ($pendingAmendment): void {
                        $pendingAmendment->where('lgu_amendment_request_status', 'requested')
                            ->where('lgu_amendment_request_target', 'request')
                            ->whereNotNull('lgu_submitted_to_dswd_at');
                    });
                });
        }
        if ($amendmentFilter === 'requested') {
            $baseQuery->where('lgu_amendment_request_status', 'requested');
            if ($tab === 'requests') {
                $baseQuery->where('lgu_amendment_request_target', 'request');
            } elseif ($tab === 'reports') {
                $baseQuery->where(function ($query): void {
                    $query->whereNull('lgu_amendment_request_target')
                        ->orWhere('lgu_amendment_request_target', 'report');
                });
            }
        }
        if (in_array($tab, ['reports', 'requests'], true)) {
            $baseQuery->where(function ($scope) use ($tab): void {
                $scope->whereNull('lgu_dromic_report_number')
                    ->orWhereNotExists(function ($newer) use ($tab): void {
                        $newer->selectRaw('1')
                            ->from('requests as newer_revision')
                            ->whereColumn('newer_revision.lgu_dromic_series_key', 'requests.lgu_dromic_series_key')
                            ->whereColumn('newer_revision.lgu_dromic_report_number', 'requests.lgu_dromic_report_number')
                            ->whereNull('newer_revision.deleted_at')
                            ->whereNotNull('newer_revision.lgu_submitted_to_dswd_at')
                            ->whereIn('newer_revision.lgu_report_status', ['advance_submitted', 'submitted'])
                            ->whereColumn('newer_revision.lgu_dromic_revision_number', '>', 'requests.lgu_dromic_revision_number');
                        if ($tab === 'reports') {
                            $newer->where(fn ($target) => $target->whereNull('newer_revision.lgu_correction_target')->orWhere('newer_revision.lgu_correction_target', 'report'));
                        } else {
                            $newer->whereNotNull('newer_revision.lgu_relief_request_reference');
                        }
                    });
            });
        }

        $aorCodes = $aorCoverage->normalizeUserAorCodes($user);
        $hasAssignedAor = $aorCodes['cities'] !== [] || $aorCodes['districts'] !== [] || $aorCodes['provinces'] !== [];
        if ($hasAssignedAor && ! $user->hasRole('Super Admin') && ! $user->hasAnyRole(['QRT', 'Quick Response Team'])) {
            $coveredIds = (clone $baseQuery)
                ->get(['id', 'lgu_psgc_code', 'province', 'municipality'])
                ->filter(fn (AssistanceRequest $row): bool => $aorCoverage->coversRequest($user, $row, null))
                ->pluck('id')
                ->all();
            $baseQuery->whereIn('id', $coveredIds ?: [0]);
            $incidentRows = $incidentRows->filter(
                fn (AssistanceRequest $row): bool => $aorCoverage->coversRequest($user, $row, null)
            )->values();
        }

        $filteredQuery = $baseQuery
            ->when($seriesKey, fn ($query, $value) => $query->where('lgu_dromic_series_key', $value))
            ->when($search, fn ($query, $value) => $query->where(fn ($nested) => $nested
                ->where('reference_number', 'like', "%{$value}%")
                ->orWhere('lgu_relief_request_reference', 'like', "%{$value}%")
                ->orWhere('requesting_agency', 'like', "%{$value}%")
                ->orWhere('requester', 'like', "%{$value}%")
                ->orWhere('municipality', 'like', "%{$value}%")
                ->orWhere('province', 'like', "%{$value}%")
                ->orWhere('barangay', 'like', "%{$value}%")
                ->orWhere('lgu_dromic_series_key', 'like', "%{$seriesSearch}%")
                ->orWhere('lgu_dromic_payload->incident_name', 'like', "%{$value}%")
                ->orWhere('lgu_dromic_payload->incident_type', 'like', "%{$value}%")))
            ->when($validation, fn ($query, $value) => $query->where(
                $tab === 'requests' ? 'lgu_relief_validation_status' : 'lgu_dromic_validation_status',
                $value,
            ))
            ->when($submissionStatus, fn ($query, $value) => $query->where('lgu_report_status', $value))
            ->when($classification, fn ($query, $value) => $query->where('lgu_dromic_report_classification', $value));
        $metricRows = (clone $filteredQuery)->get();
        $reports = $filteredQuery
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        $barangaysByLguCode = PsgcAddress::query()
            ->where('level', 'barangay')
            ->where('is_active', true)
            ->whereIn('parent_code', $incidentRows->pluck('lguSubmitter.lgu_psgc_code')->filter()->unique())
            ->orderBy('name')
            ->get(['parent_code', 'name'])
            ->groupBy('parent_code')
            ->map(fn ($rows) => $rows->pluck('name')->values()->all());

        $serializeReport = fn (AssistanceRequest $report): array => [
            'id' => $report->id,
            'reference_number' => $report->reference_number,
            'report_title' => LguDromicReportTitle::make($report),
            'request_reference' => $report->lgu_relief_request_reference,
            'requesting_agency' => $report->lguSubmitter?->lgu_name ?: $report->requesting_agency,
            'canonical_lgu_name' => $report->lguSubmitter?->lgu_name ?: $report->requesting_agency ?: $report->municipality,
            'requester' => $report->requester,
            'province' => $report->province,
            'municipality' => $report->municipality,
            'affected_families' => $report->affected_families,
            'affected_persons' => $report->affected_persons,
            'incident' => $report->incident,
            'lgu_dromic_payload' => $this->sanitizedPayload($report->lgu_dromic_payload ?? []),
            'available_barangays' => $barangaysByLguCode->get($report->lguSubmitter?->lgu_psgc_code, []),
            'lgu_dromic_series_key' => $report->lgu_dromic_series_key,
            'incident_code' => 'DIS-INC-'.Str::upper(Str::substr($report->lgu_dromic_series_key ?: 'REQ-'.$report->id, 0, 12)),
            'lgu_dromic_report_number' => $report->lgu_dromic_report_number,
            'lgu_dromic_revision_number' => (int) $report->lgu_dromic_revision_number,
            'lgu_dromic_report_classification' => $report->lgu_dromic_report_classification,
            'lgu_submitted_to_dswd_at' => $report->lgu_submitted_to_dswd_at,
            'updated_at' => $report->updated_at,
            'lgu_report_status' => $report->lgu_report_status,
            'validation_status' => $report->lgu_dromic_validation_status ?: 'pending_review',
            'validated_copy' => $this->resolvedDromicValidatedCopy($report),
            'signed_review_pending' => $this->dromicSignedReviewPending($report),
            'validation_note' => $report->lgu_dromic_review_note,
            'validation_screenshots' => $report->lgu_dromic_review_screenshots ?? [],
            'validation_history' => $report->lgu_dromic_review_history ?? [],
            'correction_scope' => $report->lgu_dromic_correction_scope,
            'correction_of_id' => $report->lgu_correction_of_id,
            'correction_target' => $report->lgu_correction_target,
            'amendment_request_status' => $report->lgu_amendment_request_status,
            'amendment_request_target' => $report->lgu_amendment_request_target ?: (
                $report->lgu_amendment_request_status ? 'report' : null
            ),
            'amendment_request_reason' => $report->lgu_amendment_request_reason,
            'amendment_requested_at' => $report->lgu_amendment_requested_at,
            'amendment_requester' => $report->lguAmendmentRequester?->name,
            'amendment_reviewed_at' => $report->lgu_amendment_reviewed_at,
            'amendment_reviewer' => $report->lguAmendmentReviewer?->name,
            'amendment_review_note' => $report->lgu_amendment_review_note,
            'reviewed_at' => $report->lgu_dromic_reviewed_at,
            'reviewer' => $report->lguDromicReviewer ? [
                'name' => $report->lguDromicReviewer->name,
                'office' => $report->lguDromicReviewer->office,
            ] : null,
            'seen_at' => $report->lgu_dromic_seen_at,
            'seen_by' => $report->lguDromicViewer?->name,
            'acked_at' => $report->lgu_dromic_acked_at,
            'acked_by' => $report->lguDromicAcker?->name,
            'relief_validation_status' => $report->lgu_relief_validation_status ?: ((bool) data_get($report->lgu_dromic_payload, 'has_relief_request') ? 'pending_review' : 'not_applicable'),
            'relief_validation_note' => $report->lgu_relief_review_note,
            'relief_validation_screenshots' => $report->lgu_relief_review_screenshots ?? [],
            'relief_validation_history' => $report->lgu_relief_review_history ?? [],
            'relief_correction_scope' => $report->lgu_relief_correction_scope,
            'relief_reviewed_at' => $report->lgu_relief_reviewed_at,
            'relief_reviewer' => $report->lguReliefReviewer ? [
                'name' => $report->lguReliefReviewer->name,
                'office' => $report->lguReliefReviewer->office,
            ] : null,
            'relief_seen_at' => $report->lgu_relief_seen_at,
            'relief_seen_by' => $report->lguReliefViewer?->name,
            'relief_acked_at' => $report->lgu_relief_acked_at,
            'relief_acked_by' => $report->lguReliefAcker?->name,
            'has_relief_request' => filled($report->lgu_relief_request_reference),
            'standalone_relief_request' => (bool) data_get($report->lgu_dromic_payload, 'standalone_relief_request'),
            'linked_incidents' => array_values((array) data_get($report->lgu_dromic_payload, 'linked_incidents', [])),
            'linked_incident_reports' => LinkedLguDromicIncidentReports::for($report),
            'signed_document_versions' => $report->signedDocumentVersions
                ->map(fn ($version): array => [
                    'id' => $version->id,
                    'kind' => $version->kind,
                    'original_name' => $version->original_name,
                    'uploaded_at' => $version->uploaded_at,
                    'created_at' => $version->created_at,
                ])->values()->all(),
            'augmentation_status' => $report->lgu_routing_status,
            'relief_request' => $report->reliefAugmentationRequest ? [
                'reference_number' => $report->reliefAugmentationRequest->reference_number,
                'status' => $report->reliefAugmentationRequest->status,
            ] : null,
            'lgu_signed_report_path' => $report->lgu_signed_report_path,
            'lgu_signed_request_path' => $report->lgu_signed_request_path,
            'last_reporter' => $report->requester ?: $report->lguSubmitter?->name,
            'can_acknowledge_report' => $user->hasRole('Super Admin')
                || ($user->hasRole('DRIMS') && (! $hasAssignedAor || $aorCoverage->coversRequest($user, $report, 'DRIMS'))),
            'can_acknowledge_request' => $user->hasRole('Super Admin')
                || ($user->hasRole('DRRS') && (! $hasAssignedAor || $aorCoverage->coversRequest($user, $report, 'DRRS'))),
        ];
        $reports->through($serializeReport);

        $seriesClaimedByLumpRequest = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_dromic_payload->standalone_relief_request', true)
            ->whereNotNull('lgu_relief_request_reference')
            ->get(['lgu_dromic_payload'])
            ->flatMap(fn (AssistanceRequest $row): array => array_values((array) data_get($row->lgu_dromic_payload, 'linked_incident_series_keys', [])))
            ->filter()
            ->unique()
            ->values();

        $incidentGroups = $incidentRows
            ->groupBy(fn (AssistanceRequest $row): string => $row->lgu_dromic_series_key ?: 'request-'.$row->id)
            ->map(function ($rows, string $key) use ($barangaysByLguCode, $seriesClaimedByLumpRequest): array {
                $logicalReports = $rows
                    ->reject(fn (AssistanceRequest $row): bool => $row->lgu_correction_target === 'request')
                    ->groupBy(fn (AssistanceRequest $row): string => (string) ($row->lgu_dromic_report_number ?? 'draft-'.$row->id))
                    ->map(fn ($versions) => $versions->sortByDesc(fn (AssistanceRequest $version): array => [(int) $version->lgu_dromic_revision_number, $version->created_at?->timestamp ?? 0])->first())
                    ->values();
                $latest = $logicalReports->sortByDesc('created_at')->first() ?: $rows->sortByDesc('created_at')->first();
                $receivedReports = $logicalReports->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['advance_submitted', 'submitted'], true));
                $reportNeedingAction = $rows->first(fn (AssistanceRequest $row): bool => $row->lgu_dromic_validation_status === 'needs_lgu_action' && blank($row->lgu_dromic_correction_resolved_at));
                $requestNeedingAction = $rows->first(fn (AssistanceRequest $row): bool => $row->lgu_relief_validation_status === 'needs_lgu_action' && blank($row->lgu_relief_correction_resolved_at));
                $code = $latest->lguSubmitter?->lgu_psgc_code;

                return [
                    'series_key' => $key,
                    'incident_code' => 'DIS-INC-'.Str::upper(Str::substr($key, 0, 12)),
                    'incident_name' => data_get($latest->lgu_dromic_payload, 'incident_name') ?: $latest->incident?->name ?: 'Disaster Incident',
                    'incident_type' => data_get($latest->lgu_dromic_payload, 'incident_type') ?: $latest->incident?->name ?: '-',
                    'occurrence_started_at' => data_get($latest->lgu_dromic_payload, 'occurrence_started_at') ?: $latest->incident?->incident_date,
                    'canonical_lgu_name' => $latest->lguSubmitter?->lgu_name ?: $latest->requesting_agency ?: $latest->municipality,
                    'province' => $latest->province,
                    'affected_barangays' => array_values((array) data_get($latest->lgu_dromic_payload, 'affected_barangays', [])),
                    'document_reference_codes' => $rows
                        ->flatMap(fn (AssistanceRequest $row): array => array_filter([
                            $row->reference_number,
                            $row->lgu_relief_request_reference,
                        ]))
                        ->unique()
                        ->values()
                        ->all(),
                    'available_barangays' => $barangaysByLguCode->get($code, []),
                    'report_count' => $receivedReports->count(),
                    'advance_count' => $receivedReports->where('lgu_report_status', 'advance_submitted')->count(),
                    'signed_count' => $receivedReports->filter(fn (AssistanceRequest $row): bool => $row->lgu_report_status === 'submitted' && filled($row->lgu_signed_report_path))->count(),
                    'latest_report_status' => $latest->lgu_report_status ?: 'advance_submitted',
                    'has_relief_request' => $rows->contains(fn (AssistanceRequest $row): bool => filled($row->lgu_relief_request_reference))
                        || $seriesClaimedByLumpRequest->contains($key),
                    'is_closed' => $rows->contains(fn (AssistanceRequest $row): bool => in_array($row->lgu_dromic_report_classification, ['terminal', 'first_and_final'], true)
                        && $row->lgu_report_status === 'submitted'
                        && filled($row->lgu_signed_report_path)
                        && $row->lgu_dromic_validation_status === 'validated_no_findings'),
                    'latest_validation_status' => $latest->lgu_dromic_validation_status,
                    'latest_relief_validation_status' => $latest->lgu_relief_validation_status,
                    'report_needs_lgu_action' => (bool) $reportNeedingAction,
                    'request_needs_lgu_action' => (bool) $requestNeedingAction,
                    'report_action_note' => $reportNeedingAction?->lgu_dromic_review_note,
                    'request_action_note' => $requestNeedingAction?->lgu_relief_review_note,
                    'last_reporter' => $latest->requester ?: $latest->lguSubmitter?->name ?: '-',
                    'created_at' => $latest->created_at,
                    'updated_at' => $latest->updated_at,
                ];
            })
            ->sortByDesc('created_at')
            ->when($search, fn ($groups) => $groups->filter(fn (array $group): bool => Str::contains(Str::lower(implode(' ', [
                $group['incident_name'],
                $group['incident_code'],
                $group['incident_type'],
                $group['canonical_lgu_name'],
                $group['province'],
                implode(' ', $group['affected_barangays']),
                implode(' ', $group['document_reference_codes']),
            ])), Str::lower($search))))
            ->values();

        if ($tab === 'incidents') {
            $visibleSeriesKeys = $incidentGroups->pluck('series_key');
            $metricRows = $incidentRows
                ->filter(fn (AssistanceRequest $row): bool => $visibleSeriesKeys->contains($row->lgu_dromic_series_key ?: 'request-'.$row->id))
                ->values();
        }

        $reportMetricRows = $metricRows
            ->reject(fn (AssistanceRequest $row): bool => $row->lgu_correction_target === 'request')
            ->groupBy(fn (AssistanceRequest $row): string => ($row->lgu_dromic_series_key ?: 'request-'.$row->id).'|'.($row->lgu_dromic_report_number ?? 'draft-'.$row->id))
            ->map(fn ($versions) => $versions->sortByDesc(fn (AssistanceRequest $version): array => [(int) $version->lgu_dromic_revision_number, $version->created_at?->timestamp ?? 0])->first())
            ->values();
        $requestMetricRows = $metricRows
            ->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_relief_request_reference))
            ->groupBy(fn (AssistanceRequest $row): string => ($row->lgu_dromic_series_key ?: 'request-'.$row->id).'|'.($row->lgu_dromic_report_number ?? 'draft-'.$row->id))
            ->map(fn ($versions) => $versions->sortByDesc(fn (AssistanceRequest $version): array => [(int) $version->lgu_dromic_revision_number, $version->created_at?->timestamp ?? 0])->first())
            ->values();
        $pendingLumpRequestCount = 0;
        if ($tab === 'incidents') {
            // Keep incidents metrics aligned with the incident list. Lump/standalone
            // request letters live on the Requests tab — surface only a count for CTA copy.
            $pendingLumpRequestCount = AssistanceRequest::query()
                ->where('submission_type', 'lgu_dromic_relief_request')
                ->where('lgu_dromic_payload->standalone_relief_request', true)
                ->whereNotNull('lgu_relief_request_reference')
                ->whereNotNull('lgu_submitted_to_dswd_at')
                ->whereIn('lgu_report_status', ['advance_submitted', 'submitted'])
                ->count();
        }

        $reportDashboard = [
            'incident_count' => $tab === 'incidents'
                ? $incidentGroups->count()
                : $metricRows->groupBy(fn (AssistanceRequest $row): string => $row->lgu_dromic_series_key ?: 'request-'.$row->id)->count(),
            'submitted' => $reportMetricRows->count(),
            'signed_submitted' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_signed_report_path))->count(),
            'pending_signed_copies' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => blank($row->lgu_signed_report_path))->count(),
            'awaiting_review' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_dromic_validation_status, [null, 'pending_review', 'under_review'], true)
                || $this->dromicSignedReviewPending($row))->count(),
            'validated_no_findings' => $reportMetricRows->where('lgu_dromic_validation_status', 'validated_no_findings')->count(),
            'with_findings' => $reportMetricRows->where('lgu_dromic_validation_status', 'needs_lgu_action')->count(),
            'amendment_requested' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => $row->lgu_amendment_request_status === 'requested'
                && ($row->lgu_amendment_request_target === null || $row->lgu_amendment_request_target === 'report'))->count(),
            'pending_lump_requests' => $pendingLumpRequestCount,
        ];
        $requestDashboard = [
            'total' => $requestMetricRows->count(),
            'submitted_total' => $requestMetricRows->count(),
            'signed' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_signed_request_path))->count(),
            'pending_signed_copies' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => blank($row->lgu_signed_request_path))->count(),
            'awaiting_review' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_signed_request_path) && in_array($row->lgu_relief_validation_status, [null, 'pending_review', 'under_review'], true))->count(),
            'validated_no_findings' => $requestMetricRows->where('lgu_relief_validation_status', 'validated_no_findings')->count(),
            'with_findings' => $requestMetricRows->where('lgu_relief_validation_status', 'needs_lgu_action')->count(),
            'amendment_requested' => $requestMetricRows->where('lgu_amendment_request_status', 'requested')->where('lgu_amendment_request_target', 'request')->count(),
            'routed' => $requestMetricRows->where('lgu_routing_status', 'routed_to_drrs')->count(),
            'pending_lump_requests' => $pendingLumpRequestCount,
        ];

        $reliefGateBase = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_relief_request_reference')
            ->whereNotNull('lgu_submitted_to_dswd_at')
            ->whereNotNull('lgu_signed_request_path')
            ->whereIn('lgu_report_status', ['advance_submitted', 'submitted']);

        return Inertia::render('Dromic/LguReports', [
            'reports' => $reports,
            'incidentGroups' => $incidentGroups,
            'activeTab' => $tab,
            'filters' => ['search' => $search, 'validation' => $validation, 'status' => $submissionStatus, 'classification' => $classification, 'series_key' => $seriesKey, 'amendment' => $amendmentFilter],
            'reportDashboard' => $reportDashboard,
            'requestDashboard' => $requestDashboard,
            'canReviewDromic' => $request->user()->hasAnyRole(['DRIMS', 'DRRS', 'QRT', 'Quick Response Team', 'Super Admin']),
            'canReviewRelief' => $request->user()->hasAnyRole(['DRRS', 'Super Admin']),
            'reliefAssessmentGate' => [
                'awaiting_validation' => (clone $reliefGateBase)
                    ->where(fn ($query) => $query->whereNull('lgu_relief_validation_status')
                        ->orWhereIn('lgu_relief_validation_status', ['pending_review', 'under_review']))
                    ->count(),
                'needs_lgu_action' => (clone $reliefGateBase)
                    ->where('lgu_relief_validation_status', 'needs_lgu_action')
                    ->count(),
            ],
        ]);
    }

    public function updateLguReportValidation(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        WorkflowNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->hasAnyRole(['DRIMS', 'DRRS', 'QRT', 'Quick Response Team', 'Super Admin']),
            403,
        );
        abort_unless(
            $assistanceRequest->submission_type === 'lgu_dromic_relief_request'
                && filled($assistanceRequest->lgu_submitted_to_dswd_at),
            404,
        );

        $data = $request->validate([
            'validation_status' => ['required', 'in:under_review,needs_lgu_action,validated_no_findings'],
            'review_note' => ['nullable', 'required_if:validation_status,needs_lgu_action', 'string', 'min:10', 'max:3000'],
            'correction_scope' => ['nullable', 'in:document,encoding,both'],
            'screenshots' => ['nullable', 'array', 'max:5'],
            'screenshots.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        if ($request->hasFile('screenshots') && blank($data['review_note'] ?? null)) {
            return back()->withErrors(['review_note' => 'Add a validation note explaining the attached screenshot(s).']);
        }

        $validatedCopy = filled($assistanceRequest->lgu_signed_report_path) ? 'signed' : 'advance';
        $old = $assistanceRequest->toArray();
        $screenshotUpdate = $this->storeValidationScreenshots(
            $assistanceRequest,
            'report',
            $request->file('screenshots', []),
            $data['validation_status'],
        );
        $screenshots = (array) ($screenshotUpdate['lgu_dromic_review_screenshots'] ?? []);
        $history = $this->appendValidationHistory(
            $assistanceRequest->lgu_dromic_review_history,
            $request,
            [
                ...$data,
                'validated_copy' => $data['validation_status'] === 'validated_no_findings' ? $validatedCopy : null,
            ],
            $screenshots,
        );
        $assistanceRequest->update([
            'lgu_dromic_validation_status' => $data['validation_status'],
            'lgu_dromic_reviewed_by' => $request->user()->id,
            'lgu_dromic_reviewed_at' => now(),
            'lgu_dromic_review_note' => filled($data['review_note'] ?? null) ? trim($data['review_note']) : null,
            'lgu_dromic_correction_scope' => $data['validation_status'] === 'needs_lgu_action' ? ($data['correction_scope'] ?? 'document') : null,
            'lgu_dromic_correction_resolved_at' => null,
            'lgu_dromic_review_history' => $history,
            ...$screenshotUpdate,
        ]);
        if ($data['validation_status'] === 'validated_no_findings'
            && $assistanceRequest->lgu_correction_of_id
            && $assistanceRequest->lgu_correction_target === 'report') {
            AssistanceRequest::query()->whereKey($assistanceRequest->lgu_correction_of_id)->update([
                'lgu_dromic_correction_resolved_at' => now(),
                'lgu_dromic_validation_status' => 'superseded',
                'lgu_dromic_correction_scope' => null,
            ]);
        }

        $fresh = $assistanceRequest->fresh(['encoder', 'lguSubmitter', 'lguDromicReviewer']);
        $audit->log('lgu_dromic.validation_status_updated', $assistanceRequest, $old, $fresh->toArray());
        $notifications->notifyLguDromicValidationOutcome($fresh);
        if ($data['validation_status'] === 'validated_no_findings' && filled($fresh->lgu_signed_report_path)) {
            $hasRequest = filled($fresh->lgu_relief_request_reference);
            if (! $hasRequest || ($fresh->lgu_relief_validation_status === 'validated_no_findings' && $fresh->reliefAugmentationRequest)) {
                $notifications->notifyValidatedLguDocumentsReceived($fresh, $fresh->reliefAugmentationRequest);
            }
        }

        $label = match ($data['validation_status']) {
            'validated_no_findings' => $validatedCopy === 'signed'
                ? 'Validated — No Findings (signed PDF)'
                : 'Validated — No Findings (advance copy)',
            'needs_lgu_action' => 'Needs LGU Action',
            default => 'Under DSWD Review',
        };

        $success = "{$assistanceRequest->reference_number} marked {$label}.";
        if ($data['validation_status'] === 'needs_lgu_action' && filled($assistanceRequest->lgu_signed_report_path)) {
            $success = "{$assistanceRequest->reference_number} marked Needs LGU Action on the signed PDF. The previous advance-copy clearance no longer stands.";
        }

        return back()->with('success', $success);
    }

    public function decideLguAmendmentRequest(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        WorkflowNotificationService $notifications,
        LguDromicRequestController $lguDromic,
    ): RedirectResponse {
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);
        abort_unless(
            $assistanceRequest->lgu_amendment_request_status === 'requested',
            422,
            'There is no pending amendment request for this document.',
        );

        $target = $assistanceRequest->lgu_amendment_request_target ?: 'report';
        if ($target === 'request') {
            abort_unless(
                $request->user()?->hasAnyRole(['DRRS', 'Super Admin']),
                403,
            );
        } else {
            abort_unless(
                $request->user()?->hasAnyRole(['DRIMS', 'DRRS', 'QRT', 'Quick Response Team', 'Super Admin']),
                403,
            );
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,deny'],
            'review_note' => ['nullable', 'required_if:decision,deny', 'string', 'min:10', 'max:3000'],
        ]);

        $status = $assistanceRequest->lgu_report_status ?: $assistanceRequest->status;
        abort_unless(
            in_array($status, ['advance_submitted', 'submitted'], true),
            422,
            $target === 'request'
                ? 'Only relief requests already submitted to DSWD can receive an amendment decision.'
                : 'Only reports already submitted to DSWD can receive an amendment decision.',
        );

        if ($target === 'request') {
            abort_unless(
                $assistanceRequest->lgu_relief_validation_status !== 'needs_lgu_action',
                422,
                'This relief request was already returned for correction.',
            );
            abort_unless(
                ! in_array($assistanceRequest->lgu_relief_validation_status, ['validated_no_findings', 'superseded'], true),
                422,
                'This relief request can no longer be amended because validation is already closed.',
            );
        } else {
            abort_unless(
                $assistanceRequest->lgu_dromic_validation_status !== 'needs_lgu_action',
                422,
                'This report was already returned for correction.',
            );
            abort_unless(
                ! in_array($assistanceRequest->lgu_dromic_validation_status, ['validated_no_findings', 'superseded'], true),
                422,
                'This report can no longer be amended because validation is already closed.',
            );
        }

        $old = $assistanceRequest->toArray();
        $approved = $data['decision'] === 'approve';
        $correctionDraftId = null;

        if ($approved) {
            $existingDraft = $lguDromic->openCorrectionDraftFor($assistanceRequest, $target);
            abort_unless(
                blank($existingDraft),
                422,
                $target === 'request'
                    ? 'An open correction draft already exists for this relief request.'
                    : 'An open correction draft already exists for this report.',
            );

            $reason = trim((string) $assistanceRequest->lgu_amendment_request_reason);

            if ($target === 'request') {
                $scope = filled($assistanceRequest->lgu_signed_request_path) ? 'both' : 'encoding';
                $reviewNote = 'DRRS approved an LGU amendment request so omitted request/FNI data can be encoded on the same request letter without creating a new relief request.'
                    .($reason !== '' ? " LGU reason: {$reason}" : '');

                $assistanceRequest->update([
                    'lgu_amendment_request_status' => 'approved',
                    'lgu_amendment_reviewed_by' => $request->user()->id,
                    'lgu_amendment_reviewed_at' => now(),
                    'lgu_amendment_review_note' => filled($data['review_note'] ?? null) ? trim($data['review_note']) : null,
                    'lgu_relief_validation_status' => 'needs_lgu_action',
                    'lgu_relief_reviewed_by' => $request->user()->id,
                    'lgu_relief_reviewed_at' => now(),
                    'lgu_relief_review_note' => $reviewNote,
                    'lgu_relief_correction_scope' => $scope,
                    'lgu_relief_correction_resolved_at' => null,
                ]);
            } else {
                $scope = filled($assistanceRequest->lgu_signed_report_path) ? 'both' : 'encoding';
                $reviewNote = 'DRIMS approved an LGU amendment request so omitted data can be encoded on the same report number without creating the next SitRep.'
                    .($reason !== '' ? " LGU reason: {$reason}" : '');

                $assistanceRequest->update([
                    'lgu_amendment_request_status' => 'approved',
                    'lgu_amendment_reviewed_by' => $request->user()->id,
                    'lgu_amendment_reviewed_at' => now(),
                    'lgu_amendment_review_note' => filled($data['review_note'] ?? null) ? trim($data['review_note']) : null,
                    'lgu_dromic_validation_status' => 'needs_lgu_action',
                    'lgu_dromic_reviewed_by' => $request->user()->id,
                    'lgu_dromic_reviewed_at' => now(),
                    'lgu_dromic_review_note' => $reviewNote,
                    'lgu_dromic_correction_scope' => $scope,
                    'lgu_dromic_correction_resolved_at' => null,
                ]);
            }

            $draft = $lguDromic->createCorrectionDraftRecord($assistanceRequest->fresh(), $target);
            $correctionDraftId = $draft->id;
            $audit->log('lgu_dromic.correction_draft_created', $draft, [], [
                ...$draft->toArray(),
                'source_request_id' => $assistanceRequest->id,
                'correction_target' => $target,
                'via' => 'amendment_approval',
            ]);
        } else {
            $assistanceRequest->update([
                'lgu_amendment_request_status' => 'denied',
                'lgu_amendment_reviewed_by' => $request->user()->id,
                'lgu_amendment_reviewed_at' => now(),
                'lgu_amendment_review_note' => trim($data['review_note']),
            ]);
        }

        $fresh = $assistanceRequest->fresh(['encoder', 'lguSubmitter', 'lguAmendmentReviewer']);
        $audit->log(
            $approved
                ? ($target === 'request' ? 'lgu_relief.amendment_approved' : 'lgu_dromic.amendment_approved')
                : ($target === 'request' ? 'lgu_relief.amendment_denied' : 'lgu_dromic.amendment_denied'),
            $assistanceRequest,
            $old,
            $fresh->toArray(),
        );
        $notifications->notifyLguAmendmentDecision($fresh);

        $label = $target === 'request'
            ? ($assistanceRequest->lgu_relief_request_reference ?: $assistanceRequest->reference_number)
            : $assistanceRequest->reference_number;
        $response = back()->with('success', $approved
            ? "Amendment approved for {$label}. A correction draft is ready for the LGU."
            : "Amendment request denied for {$label}.");

        return $correctionDraftId
            ? $response->with('correction_draft_id', $correctionDraftId)
            : $response;
    }

    public function updateLguReliefValidation(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        WorkflowNotificationService $notifications,
        LguReliefRequestHandoffService $handoff,
    ): RedirectResponse {
        abort_unless($request->user()?->hasAnyRole(['DRRS', 'Super Admin']), 403);
        abort_unless(
            $assistanceRequest->submission_type === 'lgu_dromic_relief_request'
                && filled($assistanceRequest->lgu_submitted_to_dswd_at)
                && (
                    (bool) data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request')
                    || filled($assistanceRequest->lgu_relief_request_reference)
                ),
            404,
        );
        abort_unless(filled($assistanceRequest->lgu_signed_request_path), 422, 'The LGU must upload the signed relief augmentation request before DRRS can validate it.');

        $data = $request->validate([
            'validation_status' => ['required', 'in:under_review,needs_lgu_action,validated_no_findings'],
            'review_note' => ['nullable', 'required_if:validation_status,needs_lgu_action', 'string', 'min:10', 'max:3000'],
            'correction_scope' => ['nullable', 'in:document,encoding,both'],
            'screenshots' => ['nullable', 'array', 'max:5'],
            'screenshots.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        if ($request->hasFile('screenshots') && blank($data['review_note'] ?? null)) {
            return back()->withErrors(['review_note' => 'Add a validation note explaining the attached screenshot(s).']);
        }
        $old = $assistanceRequest->toArray();
        $screenshotUpdate = $this->storeValidationScreenshots(
            $assistanceRequest,
            'request',
            $request->file('screenshots', []),
            $data['validation_status'],
        );
        $screenshots = (array) ($screenshotUpdate['lgu_relief_review_screenshots'] ?? []);
        $history = $this->appendValidationHistory(
            $assistanceRequest->lgu_relief_review_history,
            $request,
            $data,
            $screenshots,
        );
        $assistanceRequest->update([
            'lgu_relief_validation_status' => $data['validation_status'],
            'lgu_relief_reviewed_by' => $request->user()->id,
            'lgu_relief_reviewed_at' => now(),
            'lgu_relief_review_note' => filled($data['review_note'] ?? null) ? trim($data['review_note']) : null,
            'lgu_relief_correction_scope' => $data['validation_status'] === 'needs_lgu_action' ? ($data['correction_scope'] ?? 'document') : null,
            'lgu_relief_correction_resolved_at' => null,
            'lgu_relief_review_history' => $history,
            ...$screenshotUpdate,
        ]);
        if ($data['validation_status'] === 'validated_no_findings'
            && $assistanceRequest->lgu_correction_of_id
            && $assistanceRequest->lgu_correction_target === 'request') {
            AssistanceRequest::query()->whereKey($assistanceRequest->lgu_correction_of_id)->update([
                'lgu_relief_correction_resolved_at' => now(),
                'lgu_relief_validation_status' => 'superseded',
                'lgu_relief_correction_scope' => null,
            ]);
        }

        $fresh = $assistanceRequest->fresh(['encoder', 'lguSubmitter', 'lguReliefReviewer']);
        $operationalRequest = null;
        if ($data['validation_status'] === 'validated_no_findings') {
            $operationalRequest = $handoff->handoff($fresh, $request->user()->id);
        }
        $audit->log('lgu_relief_augmentation.validation_status_updated', $assistanceRequest, $old, $fresh->toArray());
        $notifications->notifyLguReliefValidationOutcome($fresh);
        if ($operationalRequest) {
            $notifications->notifyValidatedLguRequestReadyForAssessment($operationalRequest);
        }

        $label = match ($data['validation_status']) {
            'validated_no_findings' => 'Validated — No Findings',
            'needs_lgu_action' => 'Needs LGU Action',
            default => 'Under DRRS Review',
        };

        if ($operationalRequest) {
            return redirect()
                ->route('requests.index', [
                    'tab' => 'tracker',
                    'highlight' => $operationalRequest->id,
                ])
                ->with(
                    'success',
                    "Relief augmentation request for {$assistanceRequest->reference_number} marked {$label}. The FNI request is ready for Create Assessment.",
                );
        }

        return back()->with('success', "Relief augmentation request for {$assistanceRequest->reference_number} marked {$label}.");
    }

    private function resolvedDromicValidatedCopy(AssistanceRequest $report): ?string
    {
        if ($report->lgu_dromic_validation_status !== 'validated_no_findings') {
            return null;
        }

        $historyCopy = collect($report->lgu_dromic_review_history ?? [])
            ->reverse()
            ->first(fn ($entry): bool => data_get($entry, 'validation_status') === 'validated_no_findings'
                && filled(data_get($entry, 'validated_copy')));

        if (filled(data_get($historyCopy, 'validated_copy'))) {
            return data_get($historyCopy, 'validated_copy');
        }

        return filled($report->lgu_signed_report_path) ? 'signed' : 'advance';
    }

    private function dromicSignedReviewPending(AssistanceRequest $report): bool
    {
        return $report->lgu_dromic_validation_status === 'validated_no_findings'
            && filled($report->lgu_signed_report_path)
            && $this->resolvedDromicValidatedCopy($report) === 'advance';
    }

    private function storeValidationScreenshots(
        AssistanceRequest $assistanceRequest,
        string $kind,
        array $files,
        string $validationStatus,
    ): array {
        $column = $kind === 'request'
            ? 'lgu_relief_review_screenshots'
            : 'lgu_dromic_review_screenshots';
        $existing = (array) $assistanceRequest->{$column};

        if ($validationStatus === 'validated_no_findings') {
            return [$column => null];
        }

        if ($files === []) {
            return [$column => null];
        }

        $stored = collect($files)->map(function ($file) use ($assistanceRequest, $kind): array {
            $path = $file->store("lgu-dromic/validation-screenshots/{$assistanceRequest->id}/{$kind}", 'local');

            return [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        })->values()->all();

        return [$column => $stored];
    }

    private function appendValidationHistory(
        ?array $history,
        Request $request,
        array $data,
        array $screenshots,
    ): array {
        $entries = collect($history ?? []);
        $entries->push([
            'validation_status' => $data['validation_status'],
            'validated_copy' => $data['validated_copy'] ?? null,
            'correction_scope' => $data['validation_status'] === 'needs_lgu_action'
                ? ($data['correction_scope'] ?? 'document')
                : null,
            'review_note' => filled($data['review_note'] ?? null) ? trim($data['review_note']) : null,
            'screenshots' => array_values($screenshots),
            'reviewer' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'office' => $request->user()->office,
            ],
            'reviewed_at' => now()->toIso8601String(),
        ]);

        return $entries->take(-50)->values()->all();
    }

    public function store(Request $request, AuditLogger $audit, WorkflowNotificationService $workflowNotifications): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && ($user->hasAnyRole(['Super Admin', 'DRIMS']) || $user->can('manage dromic reports')),
            403,
        );

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

    private function sanitizedPayload(array $payload): array
    {
        foreach (['official_advisory_rows', 'photo_rows', 'photo_documentation_rows', 'photo_collage_rows'] as $field) {
            foreach (($payload[$field] ?? []) as $index => $row) {
                $hasImage = filled($row['screenshot_data_url'] ?? $row['data_url'] ?? $row['image_data_url'] ?? null);
                unset(
                    $payload[$field][$index]['screenshot_data_url'],
                    $payload[$field][$index]['data_url'],
                    $payload[$field][$index]['image_data_url'],
                );
                $payload[$field][$index]['image_attached'] = $hasImage;
            }
        }

        return $payload;
    }
}
