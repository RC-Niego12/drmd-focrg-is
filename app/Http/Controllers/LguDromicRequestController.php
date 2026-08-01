<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\BarangayPopulation;
use App\Models\FniLibraryItem;
use App\Models\Incident;
use App\Models\LguDirectoryEntry;
use App\Models\LguDromicRequestedItem;
use App\Models\LguSignedDocumentVersion;
use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Services\AuditLogger;
use App\Services\OfficialAdvisoryService;
use App\Services\RealtimePublisher;
use App\Services\WorkflowNotificationService;
use App\Support\AssessmentNarrative;
use App\Support\LguDromicReportTitle;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LguDromicRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isProvince = $this->isProvinceLgu($user->lgu_level);
        $baseQuery = AssistanceRequest::query()
            ->with([
                'encoder:id,name',
                'incident:id,name,incident_date,province,municipality,barangay',
                'lguDromicReviewer:id,name,office',
                'lguReliefReviewer:id,name,office',
                'lguDromicViewer:id,name,office',
                'lguReliefViewer:id,name,office',
                'reliefAugmentationRequest:id,source_lgu_dromic_request_id,reference_number,status',
                'signedDocumentVersions:id,request_id,kind,path,original_name,uploaded_at,created_at',
            ])
            ->where('submission_type', 'lgu_dromic_relief_request');

        if ($isProvince) {
            $baseQuery
                ->where('province', $user->lgu_name)
                ->where(function ($query) use ($user): void {
                    $query->whereNull('lgu_level')
                        ->orWhereNotIn('lgu_level', ['province', 'PLGU'])
                        ->orWhere('lgu_submitted_by', '!=', $user->id);
                });
        } else {
            $baseQuery->where(function ($query) use ($user): void {
                $query->where('lgu_submitted_by', $user->id)
                    ->orWhere('encoded_by', $user->id)
                    ->when(filled($user->lgu_psgc_code), function ($query) use ($user): void {
                        $query->orWhere('lgu_psgc_code', $user->lgu_psgc_code);
                    })
                    ->when(blank($user->lgu_psgc_code) && filled($user->lgu_name), function ($query) use ($user): void {
                        $query->orWhere('requesting_agency', $user->lgu_name)
                            ->orWhere('lgu', $user->lgu_name);
                    });
            });
        }

        $allReportRows = (clone $baseQuery)
            ->latest('created_at')
            ->get([
                'id',
                'reference_number',
                'incident_id',
                'requesting_agency',
                'municipality',
                'province',
                'requester',
                'affected_families',
                'lgu_dromic_payload',
                'lgu_routing_status',
                'lgu_report_status',
                'status',
                'lgu_dromic_series_key',
                'lgu_dromic_report_number',
                'lgu_dromic_revision_number',
                'lgu_dromic_report_classification',
                'lgu_relief_request_reference',
                'lgu_dromic_validation_status',
                'lgu_dromic_review_note',
                'lgu_dromic_reviewed_at',
                'lgu_relief_validation_status',
                'lgu_relief_review_note',
                'lgu_relief_reviewed_at',
                'lgu_dromic_correction_scope',
                'lgu_dromic_correction_resolved_at',
                'lgu_relief_correction_scope',
                'lgu_relief_correction_resolved_at',
                'lgu_correction_of_id',
                'lgu_correction_target',
                'lgu_signed_report_path',
                'lgu_signed_report_name',
                'lgu_signed_request_path',
                'lgu_signed_request_name',
                'lgu_dromic_draft_save_count',
                'lgu_submitted_to_dswd_at',
                'created_at',
                'updated_at',
            ]);
        $seriesStats = $allReportRows
            ->groupBy(fn (AssistanceRequest $row): string => $row->lgu_dromic_series_key ?: 'request-'.$row->id)
            ->map(function ($rows): array {
                $latest = $rows->first();

                return [
                    'drafts_saved' => $rows->sum('lgu_dromic_draft_save_count'),
                    'reports_finalized' => $rows->filter(fn (AssistanceRequest $row): bool => ($row->lgu_report_status ?: $row->status) !== 'draft')->count(),
                    'last_reporter' => $latest?->requester ?: '-',
                    'latest_request_id' => $latest?->id,
                    'is_terminal' => $rows->contains(fn (AssistanceRequest $row): bool => in_array($row->lgu_dromic_report_classification, ['terminal', 'first_and_final'], true)
                        && $row->lgu_report_status === 'submitted'
                        && filled($row->lgu_signed_report_path)
                        && $row->lgu_dromic_validation_status === 'validated_no_findings'),
                    'has_pending_version' => $rows->contains(fn (AssistanceRequest $row): bool => in_array(
                        $row->lgu_report_status ?: $row->status,
                        ['draft', 'final'],
                        true,
                    )),
                ];
            });

        $incidentGroups = $allReportRows
            ->groupBy(fn (AssistanceRequest $row): string => $row->lgu_dromic_series_key ?: 'request-'.$row->id)
            ->map(function ($rows, string $seriesKey): array {
                $logicalReports = $rows
                    ->reject(fn (AssistanceRequest $row): bool => $row->lgu_correction_target === 'request')
                    ->groupBy(fn (AssistanceRequest $row): string => (string) ($row->lgu_dromic_report_number ?? 'draft-'.$row->id))
                    ->map(fn ($versions) => $versions->sortByDesc(fn (AssistanceRequest $version): array => [(int) $version->lgu_dromic_revision_number, $version->created_at?->timestamp ?? 0])->first())
                    ->values();
                $latest = $logicalReports->sortByDesc('created_at')->first() ?: $rows->first();
                $reportNeedingAction = $rows->first(fn (AssistanceRequest $row): bool => $row->lgu_dromic_validation_status === 'needs_lgu_action' && blank($row->lgu_dromic_correction_resolved_at));
                $requestNeedingAction = $rows->first(fn (AssistanceRequest $row): bool => $row->lgu_relief_validation_status === 'needs_lgu_action' && blank($row->lgu_relief_correction_resolved_at));

                return [
                    'series_key' => $seriesKey,
                    'incident_code' => 'DIS-INC-'.Str::upper(Str::substr($seriesKey, 0, 12)),
                    'incident_name' => data_get($latest->lgu_dromic_payload, 'incident_name') ?: $latest->incident?->name ?: 'Disaster Incident',
                    'incident_type' => data_get($latest->lgu_dromic_payload, 'incident_type') ?: $latest->incident?->name ?: '-',
                    'occurrence_started_at' => data_get($latest->lgu_dromic_payload, 'occurrence_started_at') ?: $latest->incident?->incident_date,
                    'requesting_agency' => $latest->requesting_agency,
                    'affected_barangays' => array_values((array) data_get($latest->lgu_dromic_payload, 'affected_barangays', [])),
                    'document_reference_codes' => $rows
                        ->flatMap(fn (AssistanceRequest $row): array => array_filter([
                            $row->reference_number,
                            $row->lgu_relief_request_reference,
                        ]))
                        ->unique()
                        ->values()
                        ->all(),
                    'report_count' => $logicalReports->count(),
                    'finalized_count' => $logicalReports->filter(fn (AssistanceRequest $row): bool => ($row->lgu_report_status ?: $row->status) === 'final')->count(),
                    'draft_count' => $logicalReports->filter(fn (AssistanceRequest $row): bool => ($row->lgu_report_status ?: $row->status) === 'draft')->count(),
                    'advance_count' => $logicalReports->where('lgu_report_status', 'advance_submitted')->count(),
                    'signed_count' => $logicalReports->filter(fn (AssistanceRequest $row): bool => $row->lgu_report_status === 'submitted' && filled($row->lgu_signed_report_path))->count(),
                    'latest_report_id' => $latest->id,
                    'latest_reference_number' => $latest->reference_number,
                    'latest_report_status' => $latest->lgu_report_status ?: $latest->status,
                    'latest_validation_status' => $latest->lgu_dromic_validation_status,
                    'latest_has_signed_report' => filled($latest->lgu_signed_report_path),
                    'latest_relief_validation_status' => $latest->lgu_relief_validation_status,
                    'report_needs_lgu_action' => (bool) $reportNeedingAction,
                    'request_needs_lgu_action' => (bool) $requestNeedingAction,
                    'report_action_note' => $reportNeedingAction?->lgu_dromic_review_note,
                    'request_action_note' => $requestNeedingAction?->lgu_relief_review_note,
                    'has_relief_request' => $rows->contains(fn (AssistanceRequest $row): bool => filled($row->lgu_relief_request_reference)),
                    'is_closed' => $rows->contains(fn (AssistanceRequest $row): bool => in_array($row->lgu_dromic_report_classification, ['terminal', 'first_and_final'], true)
                        && $row->lgu_report_status === 'submitted'
                        && filled($row->lgu_signed_report_path)
                        && $row->lgu_dromic_validation_status === 'validated_no_findings'),
                    'last_reporter' => $latest->requester ?: '-',
                    'created_at' => $latest->created_at,
                    'updated_at' => $latest->updated_at,
                ];
            })
            ->when($request->filled('search'), fn ($groups) => $groups->filter(function (array $group) use ($request): bool {
                $needle = Str::lower(trim($request->string('search')->toString()));
                return Str::contains(Str::lower(implode(' ', [
                    $group['incident_name'],
                    $group['incident_code'],
                    $group['incident_type'],
                    $group['requesting_agency'],
                    implode(' ', $group['affected_barangays']),
                    implode(' ', $group['document_reference_codes']),
                ])), $needle);
            }))
            ->sortByDesc('created_at')
            ->values();

        $filters = [
            'tab' => $request->string('tab')->toString() ?: 'incidents',
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'classification' => $request->string('classification')->toString(),
            'validation' => $request->string('validation')->toString(),
            'request_letter' => $request->string('request_letter')->toString(),
            'series_key' => $request->string('series_key')->toString(),
        ];
        if ($filters['tab'] === 'requests') {
            $filters['request_letter'] = 'requested';
        }
        $currentRevisionConstraint = function ($query, string $document): void {
            if ($document === 'report') {
                $query->where(fn ($scope) => $scope->whereNull('lgu_correction_target')->orWhere('lgu_correction_target', 'report'));
            } else {
                $query->whereNotNull('lgu_relief_request_reference');
            }
            $query->where(function ($scope) use ($document): void {
                $scope->whereNull('lgu_dromic_report_number')
                    ->orWhereNotExists(function ($newer) use ($document): void {
                        $newer->selectRaw('1')
                            ->from('requests as newer_revision')
                            ->whereColumn('newer_revision.lgu_dromic_series_key', 'requests.lgu_dromic_series_key')
                            ->whereColumn('newer_revision.lgu_dromic_report_number', 'requests.lgu_dromic_report_number')
                            ->whereNull('newer_revision.deleted_at')
                            ->whereColumn('newer_revision.lgu_dromic_revision_number', '>', 'requests.lgu_dromic_revision_number');
                        if ($document === 'report') {
                            $newer->where(fn ($target) => $target->whereNull('newer_revision.lgu_correction_target')->orWhere('newer_revision.lgu_correction_target', 'report'));
                        } else {
                            $newer->whereNotNull('newer_revision.lgu_relief_request_reference');
                        }
                    });
            });
        };
        if (in_array($filters['tab'], ['reports', 'requests'], true)) {
            $currentRevisionConstraint($baseQuery, $filters['tab'] === 'requests' ? 'request' : 'report');
        }
        $baseQuery
            ->when($filters['series_key'], fn ($query, $value) => $query->where('lgu_dromic_series_key', $value))
            ->when($filters['search'], function ($query, $value): void {
                $seriesSearch = Str::of($value)->lower()->replace('dromic-inc-', '')->replace('dis-inc-', '')->replace('inc-', '')->replace('dis-', '')->trim()->toString();
                $query->where(function ($search) use ($value, $seriesSearch): void {
                    $search->where('reference_number', 'like', "%{$value}%")
                        ->orWhere('requesting_agency', 'like', "%{$value}%")
                        ->orWhere('requester', 'like', "%{$value}%")
                        ->orWhere('lgu_relief_request_reference', 'like', "%{$value}%")
                        ->orWhere('municipality', 'like', "%{$value}%")
                        ->orWhere('province', 'like', "%{$value}%")
                        ->orWhere('barangay', 'like', "%{$value}%")
                        ->orWhere('lgu_dromic_series_key', 'like', "%{$seriesSearch}%")
                        ->orWhere('lgu_dromic_payload->incident_name', 'like', "%{$value}%")
                        ->orWhere('lgu_dromic_payload->incident_type', 'like', "%{$value}%");
                });
            })
            ->when($filters['status'], fn ($query, $value) => $query->where('lgu_report_status', $value))
            ->when($filters['classification'], fn ($query, $value) => $query->where('lgu_dromic_report_classification', $value))
            ->when($filters['validation'], fn ($query, $value) => $query->where(
                $filters['tab'] === 'requests' ? 'lgu_relief_validation_status' : 'lgu_dromic_validation_status',
                $value,
            ))
            ->when($filters['request_letter'] === 'requested', fn ($query) => $query->whereNotNull('lgu_relief_request_reference'))
            ->when($filters['request_letter'] === 'not_requested', fn ($query) => $query->whereNull('lgu_relief_request_reference'));

        $metricRows = $filters['tab'] === 'incidents'
            ? $allReportRows->filter(fn (AssistanceRequest $row): bool => $incidentGroups->contains(
                'series_key',
                $row->lgu_dromic_series_key ?: 'request-'.$row->id,
            ))->values()
            : (clone $baseQuery)->get();

        $paginatedRequests = $baseQuery
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString()
            ->through(function (AssistanceRequest $row) use ($seriesStats, $allReportRows, $filters): AssistanceRequest {
                $seriesKey = $row->lgu_dromic_series_key ?: 'request-'.$row->id;
                $row->setAttribute('series_summary', $seriesStats->get($seriesKey, [
                    'drafts_saved' => 0,
                    'reports_finalized' => 0,
                    'last_reporter' => $row->requester ?: '-',
                    'latest_request_id' => $row->id,
                    'is_terminal' => false,
                    'has_pending_version' => false,
                ]));
                $row->setAttribute('report_title', LguDromicReportTitle::make($row));
                $row->setAttribute('incident_code', 'DIS-INC-'.Str::upper(Str::substr($seriesKey, 0, 12)));
                $document = $filters['tab'] === 'requests' ? 'request' : 'report';
                $history = $allReportRows
                    ->filter(fn (AssistanceRequest $version): bool => $version->lgu_dromic_series_key === $row->lgu_dromic_series_key
                        && (int) $version->lgu_dromic_report_number === (int) $row->lgu_dromic_report_number
                        && ($document === 'request'
                            ? filled($version->lgu_relief_request_reference)
                            : $version->lgu_correction_target !== 'request'))
                    ->sortByDesc(fn (AssistanceRequest $version): array => [(int) $version->lgu_dromic_revision_number, $version->created_at?->timestamp ?? 0])
                    ->values()
                    ->map(fn (AssistanceRequest $version): array => [
                        'id' => $version->id,
                        'revision_number' => (int) $version->lgu_dromic_revision_number,
                        'reference_number' => $version->reference_number,
                        'request_reference' => $version->lgu_relief_request_reference,
                        'status' => $version->lgu_report_status ?: $version->status,
                        'validation_status' => $document === 'request' ? $version->lgu_relief_validation_status : $version->lgu_dromic_validation_status,
                        'validation_note' => $document === 'request' ? $version->lgu_relief_review_note : $version->lgu_dromic_review_note,
                        'reviewed_at' => $document === 'request' ? $version->lgu_relief_reviewed_at : $version->lgu_dromic_reviewed_at,
                        'has_signed_document' => $document === 'request' ? filled($version->lgu_signed_request_path) : filled($version->lgu_signed_report_path),
                        'created_at' => $version->created_at,
                        'updated_at' => $version->updated_at,
                    ])->all();
                $row->setAttribute('revision_history', $history);

                return $row;
            });

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
        $correctionDraft = $allReportRows->firstWhere('id', (int) $request->session()->get('correction_draft_id'));
        if ($correctionDraft) {
            $seriesKey = $correctionDraft->lgu_dromic_series_key ?: 'request-'.$correctionDraft->id;
            $correctionDraft->setAttribute('series_summary', $seriesStats->get($seriesKey, [
                'drafts_saved' => 0,
                'reports_finalized' => 0,
                'last_reporter' => $correctionDraft->requester ?: '-',
                'latest_request_id' => $correctionDraft->id,
                'is_terminal' => false,
                'has_pending_version' => true,
            ]));
            $correctionDraft->setAttribute('report_title', LguDromicReportTitle::make($correctionDraft));
        }

        return Inertia::render('Lgu/DromicRequests/Index', [
            'lguProfile' => [
                'name' => $user->lgu_name ?: $user->area_of_assignment ?: $user->name,
                'level' => $user->lgu_level ?: 'LGU',
                'psgc_code' => $user->lgu_psgc_code,
                'province' => $this->resolveLguProvince($user),
                'is_province' => $isProvince,
            ],
            'incidentTypes' => OperationalLibraryValue::query()
                ->where('library_type', 'incident_type')
                ->where('is_active', true)
                ->orderBy('value')
                ->pluck('value')
                ->unique()
                ->values(),
            'barangayOptions' => $this->barangayOptionsWithPopulation($user->lgu_psgc_code),
            'psgcOptions' => $this->caragaAddressOptions(),
            'fniLibraryItems' => FniLibraryItem::query()
                ->orderBy('item_category')
                ->orderBy('item_name')
                ->orderBy('brand_description')
                ->get(['id', 'item_category', 'item_name', 'brand_description', 'unit_of_measure']),
            'defaultIncidentDate' => now()->toDateString(),
            'monitoringSummary' => [
                'reports' => $metricRows->count(),
                'with_requests' => $metricRows->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_relief_request_reference))->count(),
                'affected_families' => $metricRows->sum(fn (AssistanceRequest $row): int => (int) ($row->affected_families ?? data_get($row->lgu_dromic_payload, 'affected_families', 0))),
                'cities_municipalities' => $metricRows->pluck('municipality')->filter()->unique()->count(),
            ],
            'reportDashboard' => [
                'incident_count' => $metricRows->groupBy(fn (AssistanceRequest $row): string => $row->lgu_dromic_series_key ?: 'request-'.$row->id)->count(),
                'submitted' => $reportMetricRows->whereIn('lgu_report_status', ['advance_submitted', 'submitted'])->count(),
                'signed_submitted' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['advance_submitted', 'submitted'], true) && filled($row->lgu_signed_report_path))->count(),
                'drafts' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => ($row->lgu_report_status ?: $row->status) === 'draft')->count(),
                'finalized' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => ($row->lgu_report_status ?: $row->status) === 'final')->count(),
                'validated_no_findings' => $reportMetricRows->where('lgu_dromic_validation_status', 'validated_no_findings')->count(),
                'with_findings' => $reportMetricRows->where('lgu_dromic_validation_status', 'needs_lgu_action')->count(),
                'pending_signed_copies' => $reportMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['final', 'advance_submitted'], true) && blank($row->lgu_signed_report_path))->count(),
            ],
            'requestDashboard' => [
                'total' => $requestMetricRows->count(),
                'submitted_total' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['advance_submitted', 'submitted'], true))->count(),
                'signed' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => filled($row->lgu_signed_request_path) && in_array($row->lgu_report_status, ['advance_submitted', 'submitted'], true))->count(),
                'pending_signed_copies' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['final', 'advance_submitted'], true)
                    && blank($row->lgu_signed_request_path))->count(),
                'awaiting_review' => $requestMetricRows->filter(fn (AssistanceRequest $row): bool => in_array($row->lgu_report_status, ['advance_submitted', 'submitted'], true)
                    && filled($row->lgu_signed_request_path)
                    && in_array($row->lgu_relief_validation_status, [null, 'pending_review', 'under_review'], true))->count(),
                'validated_no_findings' => $requestMetricRows->where('lgu_relief_validation_status', 'validated_no_findings')->count(),
                'with_findings' => $requestMetricRows->where('lgu_relief_validation_status', 'needs_lgu_action')->count(),
                'routed' => $requestMetricRows->where('lgu_routing_status', 'routed_to_drrs')->count(),
            ],
            'requests' => $paginatedRequests,
            'correctionDraft' => $correctionDraft,
            'incidentGroups' => $incidentGroups,
            'reportFilters' => $filters,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->withRequestedFniItemDetails($this->validatedPayload($request));
        $user = $request->user();
        abort_if($this->isProvinceLgu($user->lgu_level), 403, 'PLGU accounts are for monitoring city/municipal LGU reports and cannot create separate reports.');
        $submissionStatus = ($data['submission_status'] ?? 'final') === 'draft' ? 'draft' : 'final';
        $isDraft = $submissionStatus === 'draft';
        $hasReliefRequest = (bool) ($data['has_relief_request'] ?? false);
        $seriesKey = $this->resolveDromicSeriesKey($data, $user);
        $data = $this->retainSeriesIncidentIdentity($data, $seriesKey, $user->lgu_psgc_code);
        $lifecycle = $this->resolveDromicLifecycle($data, $seriesKey, $isDraft, null, $user->lgu_psgc_code);
        $data['report_series_key'] = $seriesKey;
        $data['report_classification'] = $lifecycle['classification'];
        $data['report_number'] = $lifecycle['report_number'];

        $record = DB::transaction(function () use ($data, $user, $hasReliefRequest, $isDraft, $seriesKey, $lifecycle): AssistanceRequest {
            $incidentName = filled($data['incident_name'] ?? null) ? $data['incident_name'] : 'Draft DROMIC / Situational Report';
            $incidentDate = $data['incident_date'] ?? now()->toDateString();
            $requestingLgu = $user->lgu_name ?: ($data['requesting_lgu'] ?? 'LGU');
            $requesterName = filled($data['requester_name'] ?? null) ? $data['requester_name'] : ($user->name ?? 'LGU user');
            $narrative = filled($data['narrative'] ?? null) ? $data['narrative'] : 'Draft report. Situation overview is pending completion.';

            $incident = Incident::create([
                'name' => $incidentName,
                'incident_date' => $incidentDate,
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'] ?? null,
                'barangay' => $data['barangay'] ?? null,
                'summary' => $data['incident_summary'] ?? null,
            ]);

            $record = AssistanceRequest::create([
                'reference_number' => 'LGU-DROMIC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'submission_type' => 'lgu_dromic_relief_request',
                'incident_id' => $incident->id,
                'encoded_by' => $user->id,
                'lgu_submitted_by' => $user->id,
                'requesting_agency' => $requestingLgu,
                'lgu' => $requestingLgu,
                'lgu_level' => $user->lgu_level ?: 'LGU',
                'lgu_psgc_code' => $user->lgu_psgc_code,
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'] ?? null,
                'barangay' => $data['barangay'] ?? null,
                'requester' => $requesterName,
                'requester_position' => $data['requester_position'] ?? null,
                'requester_address' => $data['requester_address'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'date_requested' => now()->toDateString(),
                'purpose' => $isDraft ? 'Draft DROMIC / Situational Report' : ($hasReliefRequest ? 'DROMIC Report and Request for Relief Augmentation' : 'DROMIC Report'),
                'assessment_summary' => $narrative,
                'recommendations' => $data['recommendations'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'affected_families' => $data['affected_families'] ?? null,
                'assessment_form_data' => $data,
                'lgu_dromic_payload' => $data,
                'lgu_dromic_narrative' => $narrative,
                'status' => $isDraft ? 'draft' : 'final',
                'lgu_routing_status' => $isDraft ? 'draft' : 'final_for_submission',
                'lgu_report_status' => $isDraft ? 'draft' : 'final',
                'lgu_dromic_series_key' => $seriesKey,
                'lgu_dromic_report_number' => $lifecycle['report_number'],
                'lgu_dromic_report_classification' => $lifecycle['classification'],
                'lgu_relief_request_reference' => $hasReliefRequest ? $this->newLguReliefRequestReference() : null,
                'lgu_dromic_draft_save_count' => $isDraft ? 1 : 0,
                'lgu_dromic_terminal_at' => $lifecycle['terminates_series'] ? now() : null,
                'lgu_finalized_at' => $isDraft ? null : now(),
                'submitted_at' => null,
            ]);
            $this->syncRequestedFniItems($record, $data);

            return $record;
        });

        $audit->log($isDraft ? 'lgu_dromic.draft_saved' : 'lgu_dromic.final_saved', $record, [], $record->toArray());

        if ($isDraft) {
            return back()->with('success', "{$record->reference_number} saved as draft. You may continue editing it.");
        }

        return back()
            ->with('success', "{$record->reference_number} saved as final and locked. Review the generated report, attach signed copies, then submit it to DSWD.")
            ->with('preview_report_id', $record->id);
    }

    public function update(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeLguOwner($request, $assistanceRequest);
        abort_unless(($assistanceRequest->lgu_report_status ?: $assistanceRequest->status) === 'draft', 422, 'Only draft reports can be edited.');

        $data = $this->withRequestedFniItemDetails($this->validatedPayload($request));
        $data = $this->restrictCorrectionPayload($data, $assistanceRequest);
        $user = $request->user();
        $isDraft = ($data['submission_status'] ?? 'draft') === 'draft';
        $hasReliefRequest = (bool) ($data['has_relief_request'] ?? false);
        $old = $assistanceRequest->toArray();
        $seriesKey = $assistanceRequest->lgu_dromic_series_key ?: $this->resolveDromicSeriesKey($data, $user);
        $data = $this->retainSeriesIncidentIdentity($data, $seriesKey, $user->lgu_psgc_code, $assistanceRequest);
        $lifecycle = $this->resolveDromicLifecycle($data, $seriesKey, $isDraft, $assistanceRequest, $user->lgu_psgc_code);
        $data['report_series_key'] = $seriesKey;
        $data['report_classification'] = $lifecycle['classification'];
        $data['report_number'] = $lifecycle['report_number'];

        DB::transaction(function () use ($assistanceRequest, $data, $isDraft, $hasReliefRequest, $user, $seriesKey, $lifecycle): void {
            $incidentName = filled($data['incident_name'] ?? null) ? $data['incident_name'] : 'Draft DROMIC / Situational Report';
            $narrative = filled($data['narrative'] ?? null) ? $data['narrative'] : 'Draft report. Situation overview is pending completion.';

            $assistanceRequest->incident?->update([
                'name' => $incidentName,
                'incident_date' => $data['incident_date'] ?? now()->toDateString(),
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'] ?? null,
                'barangay' => $data['barangay'] ?? null,
                'summary' => $data['incident_summary'] ?? null,
            ]);

            $assistanceRequest->update([
                'requesting_agency' => $data['requesting_lgu'] ?? $assistanceRequest->requesting_agency,
                'lgu' => $data['requesting_lgu'] ?? $assistanceRequest->lgu,
                'lgu_psgc_code' => $assistanceRequest->lgu_psgc_code ?: $user->lgu_psgc_code,
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'] ?? null,
                'barangay' => $data['barangay'] ?? null,
                'requester' => $data['requester_name'] ?? $assistanceRequest->requester,
                'requester_position' => $data['requester_position'] ?? null,
                'requester_address' => $data['requester_address'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'purpose' => $isDraft ? 'Draft DROMIC / Situational Report' : ($hasReliefRequest ? 'DROMIC Report and Request for Relief Augmentation' : 'DROMIC Report'),
                'assessment_summary' => $narrative,
                'recommendations' => $data['recommendations'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'affected_families' => $data['affected_families'] ?? null,
                'assessment_form_data' => $data,
                'lgu_dromic_payload' => $data,
                'lgu_dromic_narrative' => $narrative,
                'status' => $isDraft ? 'draft' : 'final',
                'lgu_routing_status' => $isDraft ? 'draft' : 'final_for_submission',
                'lgu_report_status' => $isDraft ? 'draft' : 'final',
                'lgu_dromic_series_key' => $seriesKey,
                'lgu_dromic_report_number' => $lifecycle['report_number'],
                'lgu_dromic_report_classification' => $lifecycle['classification'],
                'lgu_relief_request_reference' => $hasReliefRequest
                    ? ($assistanceRequest->lgu_relief_request_reference ?: $this->newLguReliefRequestReference())
                    : null,
                'lgu_dromic_draft_save_count' => $isDraft
                    ? ((int) $assistanceRequest->lgu_dromic_draft_save_count) + 1
                    : (int) $assistanceRequest->lgu_dromic_draft_save_count,
                'lgu_dromic_terminal_at' => $lifecycle['terminates_series'] ? now() : null,
                'lgu_finalized_at' => $isDraft ? null : now(),
            ]);
            $this->syncRequestedFniItems($assistanceRequest, $data);
        });

        $audit->log($isDraft ? 'lgu_dromic.draft_updated' : 'lgu_dromic.final_saved', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());

        $response = back()->with('success', $isDraft
            ? "{$assistanceRequest->reference_number} draft updated."
            : "{$assistanceRequest->reference_number} saved as final and locked. Review, attach signed copies, then submit it to DSWD.");

        return $isDraft ? $response : $response->with('preview_report_id', $assistanceRequest->id);
    }

    public function startCorrectionDraft(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeLguOwner($request, $assistanceRequest);
        $data = $request->validate([
            'target' => ['required', 'in:report,request'],
        ]);
        $target = $data['target'];
        $scopeColumn = $target === 'report' ? 'lgu_dromic_correction_scope' : 'lgu_relief_correction_scope';
        $statusColumn = $target === 'report' ? 'lgu_dromic_validation_status' : 'lgu_relief_validation_status';
        $resolvedColumn = $target === 'report' ? 'lgu_dromic_correction_resolved_at' : 'lgu_relief_correction_resolved_at';

        abort_unless(
            $assistanceRequest->{$statusColumn} === 'needs_lgu_action'
                && in_array($assistanceRequest->{$scopeColumn}, ['encoding', 'both'], true)
                && blank($assistanceRequest->{$resolvedColumn}),
            422,
            'DSWD did not return this document for encoded-data correction.',
        );

        $existing = AssistanceRequest::query()
            ->where('lgu_correction_of_id', $assistanceRequest->id)
            ->where('lgu_correction_target', $target)
            ->whereIn('lgu_report_status', ['draft', 'final'])
            ->latest('created_at')
            ->first();
        if ($existing) {
            return back()
                ->with('success', "Continue the existing correction draft {$existing->reference_number}.")
                ->with('correction_draft_id', $existing->id);
        }

        $draft = DB::transaction(function () use ($assistanceRequest, $target): AssistanceRequest {
            $revisionNumber = ((int) AssistanceRequest::query()
                ->where('submission_type', 'lgu_dromic_relief_request')
                ->where('lgu_dromic_series_key', $assistanceRequest->lgu_dromic_series_key)
                ->where('lgu_dromic_report_number', $assistanceRequest->lgu_dromic_report_number)
                ->max('lgu_dromic_revision_number')) + 1;
            $copy = $assistanceRequest->replicate([
                'reference_number',
                'status',
                'lgu_routing_status',
                'lgu_report_status',
                'lgu_dromic_validation_status',
                'lgu_dromic_reviewed_by',
                'lgu_dromic_reviewed_at',
                'lgu_dromic_review_note',
                'lgu_dromic_seen_at',
                'lgu_dromic_seen_by',
                'lgu_relief_validation_status',
                'lgu_relief_reviewed_by',
                'lgu_relief_reviewed_at',
                'lgu_relief_review_note',
                'lgu_relief_seen_at',
                'lgu_relief_seen_by',
                'lgu_finalized_at',
                'lgu_submitted_to_dswd_at',
                'lgu_signed_report_path',
                'lgu_signed_report_name',
                'lgu_signed_report_uploaded_at',
                'lgu_signed_request_path',
                'lgu_signed_request_name',
                'lgu_signed_request_uploaded_at',
                'submitted_at',
                'completed_at',
            ]);
            $copy->fill([
                'reference_number' => 'LGU-DROMIC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'status' => 'draft',
                'lgu_routing_status' => 'correction_draft',
                'lgu_report_status' => 'draft',
                'lgu_correction_of_id' => $assistanceRequest->id,
                'lgu_correction_target' => $target,
                'lgu_dromic_report_number' => $assistanceRequest->lgu_dromic_report_number,
                'lgu_dromic_revision_number' => $revisionNumber,
                'lgu_dromic_report_classification' => $assistanceRequest->lgu_dromic_report_classification,
                'lgu_relief_request_reference' => $target === 'request'
                    ? Str::limit($assistanceRequest->lgu_relief_request_reference, 35, '').'-C'.$revisionNumber
                    : null,
                'lgu_dromic_draft_save_count' => 1,
            ]);
            if ($target === 'request') {
                $copy->lgu_signed_report_path = $assistanceRequest->lgu_signed_report_path;
                $copy->lgu_signed_report_name = $assistanceRequest->lgu_signed_report_name;
                $copy->lgu_signed_report_uploaded_at = $assistanceRequest->lgu_signed_report_uploaded_at;
                $copy->lgu_dromic_validation_status = $assistanceRequest->lgu_dromic_validation_status;
            }
            $copy->save();
            $this->syncRequestedFniItems($copy, $copy->lgu_dromic_payload ?? []);

            return $copy;
        });

        $audit->log('lgu_dromic.correction_draft_created', $draft, [], [
            ...$draft->toArray(),
            'source_request_id' => $assistanceRequest->id,
            'correction_target' => $target,
        ]);

        return back()
            ->with('success', "Correction draft {$draft->reference_number} created. The submitted version remains preserved.")
            ->with('correction_draft_id', $draft->id);
    }

    public function uploadSignedCopies(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $notifications): RedirectResponse
    {
        $this->authorizeLguOwner($request, $assistanceRequest);
        abort_if(($assistanceRequest->lgu_report_status ?: $assistanceRequest->status) === 'draft', 422, 'Save the report as final before uploading signed copies.');

        $hasReliefRequest = (bool) data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request');
        $data = $request->validate([
            'signed_report' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240', 'required_without:signed_request'],
            'signed_request' => [$hasReliefRequest ? 'nullable' : 'prohibited', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ]);
        abort_if(
            $request->hasFile('signed_report')
                && $assistanceRequest->lgu_dromic_validation_status === 'validated_no_findings'
                && filled($assistanceRequest->lgu_signed_report_path),
            422,
            'The signed DROMIC report is locked because the signed PDF was validated with no findings.',
        );
        abort_if(
            $request->hasFile('signed_request') && $assistanceRequest->lgu_relief_validation_status === 'validated_no_findings',
            422,
            'The signed relief augmentation request is locked because DRRS validated it with no findings.',
        );
        abort_if(
            $request->hasFile('signed_report')
                && filled($assistanceRequest->lgu_signed_report_path)
                && in_array($assistanceRequest->lgu_report_status, ['advance_submitted', 'submitted'], true)
                && (
                    $assistanceRequest->lgu_dromic_validation_status !== 'needs_lgu_action'
                    || ! in_array($assistanceRequest->lgu_dromic_correction_scope ?: 'document', ['document'], true)
                ),
            422,
            'The signed DROMIC report can be replaced only when DSWD marks that document as Needs LGU Action.',
        );
        abort_if(
            $request->hasFile('signed_request')
                && filled($assistanceRequest->lgu_signed_request_path)
                && in_array($assistanceRequest->lgu_report_status, ['advance_submitted', 'submitted'], true)
                && (
                    $assistanceRequest->lgu_relief_validation_status !== 'needs_lgu_action'
                    || ! in_array($assistanceRequest->lgu_relief_correction_scope ?: 'document', ['document'], true)
                ),
            422,
            'The signed request letter can be replaced only when DRRS marks that document as Needs LGU Action.',
        );
        $old = $assistanceRequest->toArray();
        $updates = [];

        foreach (['signed_report' => 'report', 'signed_request' => 'request'] as $field => $kind) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $pathColumn = "lgu_signed_{$kind}_path";
            if ($assistanceRequest->{$pathColumn}) {
                LguSignedDocumentVersion::query()->create([
                    'request_id' => $assistanceRequest->id,
                    'kind' => $kind,
                    'path' => $assistanceRequest->{$pathColumn},
                    'original_name' => $assistanceRequest->{"lgu_signed_{$kind}_name"},
                    'uploaded_at' => $assistanceRequest->{"lgu_signed_{$kind}_uploaded_at"},
                    'archived_by' => $request->user()->id,
                ]);
            }
            $file = $request->file($field);
            $updates[$pathColumn] = $file->store("lgu-dromic/{$assistanceRequest->id}/signed", 'public');
            $updates["lgu_signed_{$kind}_name"] = $file->getClientOriginalName();
            $updates["lgu_signed_{$kind}_uploaded_at"] = now();
        }
        if ($request->hasFile('signed_report') && in_array($assistanceRequest->lgu_report_status, ['advance_submitted', 'submitted'], true)) {
            // Keep LGU-facing advance "no findings" check until DSWD marks findings
            // against the signed PDF (needs_lgu_action) or clears the signed copy.
            $latestNoFindings = collect($assistanceRequest->lgu_dromic_review_history ?? [])
                ->reverse()
                ->first(fn ($entry): bool => data_get($entry, 'validation_status') === 'validated_no_findings');
            $keepAdvanceClearance = $assistanceRequest->lgu_dromic_validation_status === 'validated_no_findings'
                && data_get($latestNoFindings, 'validated_copy', 'advance') === 'advance';

            if (! $keepAdvanceClearance) {
                $updates['lgu_dromic_validation_status'] = 'pending_review';
                $updates['lgu_dromic_reviewed_by'] = null;
                $updates['lgu_dromic_reviewed_at'] = null;
                $updates['lgu_dromic_review_note'] = null;
            }
        }
        if ($request->hasFile('signed_request') && in_array($assistanceRequest->lgu_report_status, ['advance_submitted', 'submitted'], true)) {
            $updates['lgu_relief_validation_status'] = 'pending_review';
            $updates['lgu_relief_reviewed_by'] = null;
            $updates['lgu_relief_reviewed_at'] = null;
            $updates['lgu_relief_review_note'] = null;
        }

        $assistanceRequest->update($updates);
        $this->completeAdvanceCopyWhenReady($assistanceRequest->fresh(), $notifications);
        $audit->log('lgu_dromic.signed_copies_uploaded', $assistanceRequest, $old, $assistanceRequest->fresh()->toArray());

        return back()->with('success', 'Signed copy attachment(s) uploaded successfully.');
    }

    public function submitToDswd(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, WorkflowNotificationService $notifications): RedirectResponse
    {
        $this->authorizeLguOwner($request, $assistanceRequest);
        abort_unless($assistanceRequest->lgu_report_status === 'final', 422, 'This report was already submitted. Upload any remaining signed copies to complete the submission.');

        $old = $assistanceRequest->toArray();
        $hasReliefRequest = (bool) data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request');
        $signedComplete = filled($assistanceRequest->lgu_signed_report_path)
            && (! $hasReliefRequest || filled($assistanceRequest->lgu_signed_request_path));
        $routingStatus = ! $hasReliefRequest
            ? 'report_submitted'
            : ($signedComplete ? 'for_drmd_aa_review' : 'awaiting_signed_copies');

        $assistanceRequest->update([
            'status' => $signedComplete ? 'submitted_with_signed_copies' : 'advance_copy_submitted',
            'lgu_report_status' => $signedComplete ? 'submitted' : 'advance_submitted',
            'lgu_routing_status' => $routingStatus,
            'lgu_dromic_validation_status' => $assistanceRequest->lgu_correction_target === 'request'
                ? $assistanceRequest->lgu_dromic_validation_status
                : 'pending_review',
            'lgu_relief_validation_status' => $hasReliefRequest ? 'pending_review' : null,
            'submitted_at' => $assistanceRequest->submitted_at ?: now(),
            'lgu_submitted_to_dswd_at' => $assistanceRequest->lgu_submitted_to_dswd_at ?: now(),
            'completed_at' => $signedComplete ? now() : null,
            'lgu_signed_copy_reminder_sent_at' => $signedComplete ? null : now(),
        ]);

        $fresh = $assistanceRequest->fresh(['encoder', 'lguSubmitter']);
        $notifications->notifyLguReportSubmitted($fresh, $signedComplete);
        if (! $signedComplete) {
            $notifications->notifyLguSignedCopiesRequired($fresh);
        }
        $audit->log('lgu_dromic.submitted_to_dswd', $assistanceRequest, $old, $fresh->toArray());

        return back()->with('success', $signedComplete
            ? "{$assistanceRequest->reference_number} submitted to DSWD with the required signed copy/copies."
            : "{$assistanceRequest->reference_number} submitted as an advance copy. Signed copy requirements remain pending and reminders are active.");
    }

    public function polish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:generate,polish,justification,caption'],
            'text' => ['nullable', 'string', 'max:12000'],
            'facts' => ['nullable', 'array'],
        ]);

        if ($data['mode'] === 'polish' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Write a draft Situation Overview first, or use Auto-generate.'], 422);
        }

        if ($data['mode'] === 'caption' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Enter a caption first. AI Roger can polish it, but it should start from your actual photo context.'], 422);
        }

        if ($data['mode'] === 'justification' && blank($data['text'] ?? null)) {
            return response()->json(['message' => 'Encode the real reason first. AI can polish and check the justification, but it cannot invent why the count exceeded the PSA 2024 population.'], 422);
        }

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') {
            return response()->json(['message' => 'Groq AI is not configured. Add GROQ_API_KEY, then clear config cache.'], 503);
        }

        $factPayload = $data['facts'] ?? [];
        $blockedScreenshot = collect(data_get($factPayload, 'official_agency_advisories', []))
            ->contains(fn ($advisory): bool => data_get($advisory, 'source_kind') === 'screenshot'
                && (
                    data_get($advisory, 'content_status') !== 'extracted_for_review'
                    || blank(data_get($advisory, 'summary'))
                ));
        if (in_array($data['mode'], ['generate', 'polish'], true) && $blockedScreenshot) {
            return response()->json([
                'message' => 'Remove the screenshot that still requires attention or paste a clearer copy before using the Situation Overview AI.',
            ], 422);
        }

        $facts = collect($factPayload)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $key) => Str::headline((string) $key).': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value))
            ->implode("\n");

        try {
            $payload = [
                'model' => config('services.groq.model'),
                'temperature' => $data['mode'] === 'generate' ? 0.3 : 0.18,
                'max_completion_tokens' => 1000,
                'messages' => [
                    ['role' => 'system', 'content' => $data['mode'] === 'justification'
                        ? 'You help LGU focal persons prepare a concise official justification when reported affected persons exceed PSA 2024 population. First judge if the draft contains a real, relevant reason for the excess. Relevant reasons may include actual validation results, transient/visiting population, displaced persons from nearby areas, locally stranded individuals, renters/boarders, census undercount/outdated data, duplicate household sharing, or another concrete LGU-verified explanation. Do not invent facts, numbers, causes, validations, agencies, or approvals. If the draft is empty, vague, circular, or unrelated, return exactly: NEEDS_USER_INPUT: Please encode the actual reason why the affected persons exceeded the PSA 2024 population. If relevant, polish it into 1 concise official paragraph without headings or markdown.'
                        : ($data['mode'] === 'caption'
                            ? 'You help LGU focal persons polish photo documentation captions for a DROMIC/Situational Report. Preserve the original meaning, keep it official and concise, and do not invent activities, dates, locations, agencies, or beneficiaries. If the draft is random letters, vague, or has no usable idea, return exactly: NEEDS_USER_INPUT: Please encode a clear caption that describes the documented LGU response action or activity. Return one complete sentence only, using no more than 35 words and 220 characters so it fits within three displayed lines.'
                            : 'Write as a real LGU employee preparing the Situation Overview for the LGU\'s current DROMIC/Situational Report. Sound human, observant, practical, and professional rather than mechanical or AI-generated. Describe what is happening in the locality, how residents and communities are being affected, what conditions are currently evident, and what the LGU has done or is continuing to do. Write from the reporting LGU perspective, but do not begin every paragraph with "The LGU reports" and do not repeatedly mention that the information came from the LGU. Never write "as reported by the local government unit," "as reported by the LGU," or an equivalent attribution to the reporting LGU. Replace that empty attribution with a useful elaboration of the actual local hazard, observable condition, effect, or response supported by the encoded facts. On the first use of an acronym in the narrative, write the complete official term followed by the acronym in parentheses, for example "Local Government Unit (LGU)." On every later use, write only the acronym. Do not spell out the same acronym more than once. Treat the supplied encoded facts as the sole source of truth. Any Official Agency Advisories in the facts were selected and reviewed by the LGU and may be used only as supporting hazard context. An advisory row that has only a source URL or has content_status "reference_only" is a citation record only: never infer, quote, summarize, or attribute any claim from its URL, title, or agency name. Use an advisory only when it includes a verified relevant excerpt or extracted summary. Attribute an agency statement naturally and cite the agency no more often than needed. Never present an agency forecast or warning as an observed local impact. Never mix regional or agency figures with LGU-validated affected-population figures. For an earthquake, use supplied technical details only when relevant, then describe local effects solely from LGU data. For weather hazards, use the verified advisory to explain the weather system or warning that influenced the locality, while flooding, displacement, damage, and response must come from LGU data. For fire, health, maritime, geohazard, environmental, or other incidents, use the verified responsible-agency excerpt only for technical context and keep the LGU narrative focused on locally validated conditions and actions. Never invent or infer an unencoded date, count, barangay, evacuation center, damaged house, casualty, assistance item, action, weather condition, agency response, validation, signatory, or approval. Missing information is unknown and must simply be omitted. Do not include reporting cut-off times, the time information was received, the reporter\'s name, form-completion details, database language, field labels, source URLs, match notes, or other administrative metadata. Mention an incident date only when it helps the reader understand how the event developed; omit exact times unless the timing is essential to the incident itself. Use readable whole numbers with thousands separators and proper units. Produce at least 2 cohesive paragraphs and use a third paragraph only when the encoded facts support a genuinely separate topic. Give every paragraph a distinct purpose: normally the first describes the incident and present conditions, the second explains verified effects on people or places together with the most relevant figures, and an optional third covers LGU actions, assistance, remaining needs, or continuing concerns. When the data is limited, combine impacts and actions into two natural paragraphs rather than padding the report. Never repeat a fact, count, conclusion, or idea in another paragraph merely by changing the wording. Avoid generic filler, ceremonial language, overlong introductions, item-by-item form recitation, and repetitive closing statements. Preserve useful facts from an existing draft when polishing, but remove repetition and irrelevant administrative details. Return only the narrative paragraphs separated by blank lines, without a heading, bullets, numbering, sources, notes, or markdown.')],
                    ['role' => 'user', 'content' => match ($data['mode']) {
                        'generate' => "Write a natural, human Situation Overview from the encoded facts below as the LGU employee responsible for this incident report. Produce at least two paragraphs, keep each paragraph focused on a different aspect of the situation, and include only facts that help describe current conditions, verified effects, and the LGU response. If a reviewed official agency advisory is supplied, use it briefly and with attribution to explain the hazard, without treating a warning as an observed LGU impact. Do not repeat the same thought or figure in different words:\n\nEncoded data:\n".$facts,
                        'caption' => 'Polish this photo documentation caption into one concise official sentence. Do not add unsupported details. Draft caption: '.($data['text'] ?? '')."\n\nEncoded facts for context only:\n".$facts,
                        'justification' => 'Review whether this justification has a real idea related to why affected persons exceeded PSA 2024 population. If valid, polish it. Draft justification: '.($data['text'] ?? '')."\n\nEncoded facts:\n".$facts,
                        default => 'Rewrite this draft as a natural Situation Overview written by an LGU employee who understands the incident. Produce at least two distinct paragraphs based on the available facts. Preserve supported facts, improve the description of current conditions and LGU actions, remove repeated thoughts and administrative details, and do not add unsupported information. Draft: '.($data['text'] ?? '')."\n\nEncoded data:\n".$facts,
                    }],
                ],
            ];
            if (in_array($data['mode'], ['generate', 'polish'], true)) {
                $payload['messages'][0]['content'] = $this->situationOverviewSystemPrompt();
                $payload['messages'][1]['content'] = ($data['mode'] === 'generate'
                    ? 'Write the Situation Overview in exactly four distinct paragraphs using the required paragraph purpose and order.'
                    : 'Rewrite the draft into exactly four distinct paragraphs using the required paragraph purpose and order. Preserve supported facts and remove repetition.')
                    ."\n\n".($data['mode'] === 'polish' ? 'Existing draft: '.($data['text'] ?? '')."\n\n" : '')
                    ."Encoded data:\n".$facts;
            }

            $response = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(
                rtrim((string) config('services.groq.base_url'), '/').'/chat/completions',
                $payload,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Groq AI could not be reached.'], 503);
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Groq API error '.$response->status().': '.$response->body()));

            return response()->json(['message' => 'Groq AI could not process the narrative.'], 502);
        }

        $polished = AssessmentNarrative::sanitize((string) data_get($response->json(), 'choices.0.message.content'));

        if (Str::startsWith($polished, 'NEEDS_USER_INPUT:')) {
            return response()->json([
                'message' => trim(Str::after($polished, 'NEEDS_USER_INPUT:'))
                    ?: 'Please correct the Situation Overview so it agrees with the encoded report data.',
            ], 422);
        }

        if (in_array($data['mode'], ['generate', 'polish'], true) && $polished !== '' && $this->situationOverviewNeedsCorrection($polished, $facts)) {
            $correctionPayload = $payload;
            $correctionPayload['temperature'] = 0.12;
            $correctionPayload['messages'][1]['content'] = "Correct the draft below so it follows the required structure and contains exactly four paragraphs separated by blank lines. Never name or enumerate the affected barangays; state only the number of affected barangays when useful. Remove weather information about Luzon, Visayas, Metro Manila, or another place outside Caraga unless the encoded facts explicitly show that it directly explains conditions in the reporting LGU. Remove every statement about missing, unreadable, unavailable, unspecified, or unencoded information, including statements that no challenges or gaps were encoded. Remove phrases such as \"as reported by the local government unit\" or \"as reported by the LGU\" and replace them with supported elaboration about the local condition, effect, or response. Expand each acronym only on its first mention by writing the complete official term followed by the acronym in parentheses; use only the acronym afterward and never expand it twice. If Report Classification is terminal or first_and_final, rewrite every response action as completed in past tense and remove language saying that an action is ongoing, underway, continuing, planned, or still to be done. For those closed reports, make the fourth paragraph a concise completion statement rather than a future commitment. Simply omit unavailable content. Do not add unsupported facts.\n\nDraft to correct:\n".$polished."\n\nEncoded data:\n".$facts;

            try {
                $correctionResponse = Http::timeout(45)->retry(1, 500)->withToken($apiKey)->acceptJson()->post(
                    rtrim((string) config('services.groq.base_url'), '/').'/chat/completions',
                    $correctionPayload,
                );
                if ($correctionResponse->successful()) {
                    $corrected = AssessmentNarrative::sanitize((string) data_get($correctionResponse->json(), 'choices.0.message.content'));
                    if (! $this->situationOverviewNeedsCorrection($corrected, $facts)) {
                        $polished = $corrected;
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (in_array($data['mode'], ['justification', 'caption'], true) && Str::startsWith($polished, 'NEEDS_USER_INPUT:')) {
            return response()->json([
                'message' => trim(Str::after($polished, 'NEEDS_USER_INPUT:')) ?: 'Please encode the actual reason before polishing the justification.',
            ], 422);
        }

        return $polished !== ''
            ? response()->json(['polished' => $polished, 'provider' => 'Groq', 'model' => config('services.groq.model')])
            : response()->json(['message' => 'Groq AI returned an empty result.'], 502);
    }

    public function officialAdvisories(Request $request, OfficialAdvisoryService $officialAdvisories): JsonResponse
    {
        $data = $request->validate([
            'incident_type' => ['required', 'string', 'max:255'],
            'incident_name' => ['nullable', 'string', 'max:255'],
            'incident_details' => ['nullable', 'string', 'max:1000'],
            'incident_date' => ['required', 'date'],
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($officialAdvisories->lookup($data));
    }

    public function importOfficialAdvisory(Request $request, OfficialAdvisoryService $officialAdvisories): JsonResponse
    {
        $data = $request->validate([
            'source_url' => ['required', 'string', 'max:2048'],
            'incident_type' => ['nullable', 'string', 'max:255'],
            'incident_date' => ['nullable', 'date'],
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return response()->json($officialAdvisories->importFromUrl($data['source_url'], $data));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function extractOfficialAdvisoryScreenshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'screenshot_data_url' => ['required', 'string', 'max:5000000', 'regex:/^data:image\/(?:jpeg|png|webp);base64,/i'],
            'screenshot_name' => ['nullable', 'string', 'max:255'],
            'incident_type' => ['nullable', 'string', 'max:255'],
            'incident_date' => ['nullable', 'date'],
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
        ]);

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') {
            return response()->json(['message' => 'Advisory screenshot reading is not configured. Remove this screenshot or try again after Groq Vision is configured.'], 503);
        }

        $location = collect([$data['municipality'] ?? null, $data['province'] ?? null])->filter()->implode(', ');
        $cacheKey = 'lgu-dromic:advisory-ocr:'.hash('sha256', (string) config('services.groq.vision_model').'|'.$data['screenshot_data_url']);
        if (is_array($cached = Cache::get($cacheKey))) {
            return response()->json([...$cached, 'cached' => true]);
        }

        $visionSlot = null;
        foreach (range(1, 4) as $slot) {
            $candidate = Cache::lock("lgu-dromic:groq-vision-slot:{$slot}", 90);
            if ($candidate->get()) {
                $visionSlot = $candidate;
                break;
            }
        }
        if (! $visionSlot) {
            return response()
                ->json(['message' => 'The advisory reader is handling other screenshots. Keep this screenshot in place and retry shortly.'], 429)
                ->header('Retry-After', '10');
        }

        $prompt = <<<'PROMPT'
Read this screenshot as an OCR and fact-extraction assistant for an LGU disaster report. It should be a PAGASA or DOST-PHIVOLCS advisory/post. Extract only text and facts that are visibly supported by the screenshot. Never complete cropped text, guess hidden information, use outside knowledge, or convert a forecast/warning into an observed LGU impact.

Return one JSON object with exactly these string fields:
- agency: "PAGASA", "DOST-PHIVOLCS", or "Unverified warning-agency screenshot"
- advisory_title: the visible bulletin/post title, or a short neutral title if no title is visible
- issued_at: only a visible issuance/post date and time
- covered_location: only the location or forecast area visibly stated
- summary: a concise faithful transcription/summary retaining visible hazard names, magnitudes, depths, intensities, forecast conditions, warning levels, dates, and units; omit social-media controls, comments, reactions, navigation, and unrelated text

If important content is unreadable or cropped, omit it. Do not add markdown or commentary outside the JSON object.
PROMPT;

        $visionPayload = [
            'model' => config('services.groq.vision_model'),
            'temperature' => 0.1,
            'max_completion_tokens' => 1000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt."\n\nEncoded incident type: ".($data['incident_type'] ?? 'Not yet encoded')."\nEncoded LGU location: ".($location ?: 'Not yet encoded')."\nEncoded incident date: ".($data['incident_date'] ?? 'Not yet encoded')],
                    ['type' => 'image_url', 'image_url' => ['url' => $data['screenshot_data_url']]],
                ],
            ]],
        ];
        $visionEndpoint = rtrim((string) config('services.groq.base_url'), '/').'/chat/completions';

        try {
            $response = Http::connectTimeout(8)->timeout(35)->retry(1, 750)->withToken($apiKey)->acceptJson()->post($visionEndpoint, $visionPayload);

            // Some Groq vision models can produce valid JSON from the prompt but
            // reject provider-enforced JSON mode before generation begins.
            if ($response->status() === 400 && str_contains($response->body(), 'json_validate_failed')) {
                unset($visionPayload['response_format']);
                $response = Http::connectTimeout(8)->timeout(35)->withToken($apiKey)->acceptJson()->post($visionEndpoint, $visionPayload);
            }
        } catch (\Throwable $exception) {
            $visionSlot->release();
            report($exception);

            return response()->json(['message' => 'The advisory reader did not finish this screenshot. Remove it or retry with the screenshot after a moment.'], 503);
        }

        if (! $response->successful()) {
            $visionSlot->release();
            report(new \RuntimeException('Groq Vision API error '.$response->status().': '.$response->body()));

            return response()->json(['message' => 'The advisory reader could not process this screenshot. Remove it and paste a clearer copy, or retry later.'], 502);
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content'));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $extracted = json_decode($content, true);
        if (! is_array($extracted)) {
            $visionSlot->release();

            return response()->json(['message' => 'The advisory reader could not safely extract this screenshot. Remove it and paste a clearer copy.'], 422);
        }

        $agency = Str::limit(trim(strip_tags((string) ($extracted['agency'] ?? ''))), 120, '');
        $title = Str::limit(trim(strip_tags((string) ($extracted['advisory_title'] ?? ''))), 255, '');
        $summary = Str::limit(trim(strip_tags((string) ($extracted['summary'] ?? ''))), 3000, '');
        $issuedAt = Str::limit(trim(strip_tags((string) ($extracted['issued_at'] ?? ''))), 120, '');
        $coveredLocation = Str::limit(trim(strip_tags((string) ($extracted['covered_location'] ?? ''))), 500, '');
        if ($summary === '') {
            $visionSlot->release();

            return response()->json(['message' => 'No usable advisory details were extracted. Remove this screenshot and paste a clearer copy before generating the Situation Overview.'], 422);
        }

        $result = [
            'source' => [
                'agency' => $agency ?: 'Unverified warning-agency screenshot',
                'advisory_title' => $title ?: 'Pasted warning-agency screenshot',
                'issued_at' => $issuedAt,
                'covered_location' => $coveredLocation ?: $location,
                'summary' => $summary,
                'source_url' => '',
                'match_basis' => 'Extracted by Groq Vision from a screenshot pasted by the LGU. The encoder must compare the text with the screenshot and confirm the official page, date, and locality before generating the Situation Overview.',
                'content_status' => 'extracted_for_review',
                'source_kind' => 'screenshot',
            ],
            'provider' => 'Groq',
            'model' => config('services.groq.vision_model'),
            'notice' => 'Screenshot text was extracted. Review it against the pasted image before using it in the Situation Overview.',
        ];
        Cache::put($cacheKey, $result, now()->addDay());
        $visionSlot->release();

        return response()->json($result);
    }

    public function pdf(Request $request, AssistanceRequest $assistanceRequest): HttpResponse
    {
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $record = $assistanceRequest->load(['incident', 'lguSubmitter', 'drmdAssignedUser']);
        $reportProfile = $this->dromicReportProfile($record);
        $orientation = $this->dromicPdfOrientation((array) ($record->lgu_dromic_payload ?? []));
        $filename = "LGU-DROMIC-{$record->reference_number}.pdf";
        $pdf = Pdf::loadView('documents.lgu-dromic', [
            'request' => $record,
            'reportProfile' => $reportProfile,
            'orientation' => $orientation,
        ])->setPaper('a4', $orientation);
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 92,
            $canvas->get_height() - 20,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            8,
            [0.25, 0.31, 0.39],
        );

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function dromicPdfOrientation(array $payload): string
    {
        $notApplicable = collect(data_get($payload, 'not_applicable_sections', []));
        $displayedColumnCounts = collect([5, 2]);

        foreach ([
            'inside_ec' => 10,
            'outside_ec' => 6,
            'damaged_houses' => 7,
            'assistance' => 11,
            'cluster_gaps' => 4,
        ] as $section => $columns) {
            if (! $notApplicable->contains($section)) {
                $displayedColumnCounts->push($columns);
            }
        }

        foreach ([
            'related_incidents' => 'related_incident_rows',
            'casualties' => 'casualty_rows',
            'infrastructure_damage' => 'infrastructure_damage_rows',
            'agriculture_damage' => 'agriculture_damage_rows',
            'class_suspension' => 'class_suspension_rows',
            'work_suspension' => 'work_suspension_rows',
            'roads_bridges' => 'road_bridge_rows',
            'power_lifelines' => 'power_lifeline_rows',
            'water_lifelines' => 'water_lifeline_rows',
            'communication_lifelines' => 'communication_lifeline_rows',
            'seaports' => 'seaport_rows',
            'airports' => 'airport_rows',
            'land_transport_terminals' => 'land_transport_terminal_rows',
            'stranded_transport' => 'stranded_transport_rows',
            'calamity_declaration' => 'calamity_declaration_rows',
            'preemptive_evacuation' => 'preemptive_evacuation_rows',
        ] as $section => $field) {
            $firstRow = collect(data_get($payload, $field, []))->first();

            if (! $notApplicable->contains($section) && is_array($firstRow)) {
                $displayedColumnCounts->push(count($firstRow));
            }
        }

        if (data_get($payload, 'has_relief_request')
            && collect(data_get($payload, 'requested_fni_items', []))->isNotEmpty()) {
            $displayedColumnCounts->push(4);
        }

        return $displayedColumnCounts->max() >= 8 ? 'landscape' : 'portrait';
    }

    private function newLguReliefRequestReference(): string
    {
        do {
            $reference = 'LGU-REQ-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (AssistanceRequest::withTrashed()->where('lgu_relief_request_reference', $reference)->exists());

        return $reference;
    }

    public function encodedData(Request $request, AssistanceRequest $assistanceRequest): StreamedResponse
    {
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($request->user()->hasAnyRole(['DRIMS', 'Super Admin']), 403);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $record = $assistanceRequest->load(['incident', 'encoder:id,name,email', 'lguSubmitter:id,name,email']);
        $payload = $this->sanitizedEncodedPayload($record->lgu_dromic_payload ?? []);

        $export = [
            'exported_at' => now()->toIso8601String(),
            'system_tracking_reference' => $record->reference_number,
            'report' => [
                'id' => $record->id,
                'classification' => $record->lgu_dromic_report_classification,
                'report_number' => $record->lgu_dromic_report_number,
                'report_status' => $record->lgu_report_status,
                'routing_status' => $record->lgu_routing_status,
                'reporting_lgu' => $record->requesting_agency,
                'province' => $record->province,
                'municipality' => $record->municipality,
                'incident' => $record->incident?->name,
                'incident_date' => $record->incident?->incident_date,
                'last_reporter' => $record->lguSubmitter?->name ?? $record->encoder?->name,
                'finalized_at' => optional($record->lgu_finalized_at)->toIso8601String(),
                'submitted_to_dswd_at' => optional($record->lgu_submitted_to_dswd_at)->toIso8601String(),
            ],
            'encoded_report' => $payload,
        ];
        $filename = 'LGU-DROMIC-ENCODED-'.$record->reference_number.'.json';

        return response()->streamDownload(
            static function () use ($export): void {
                echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            $filename,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function signedCopy(Request $request, AssistanceRequest $assistanceRequest, string $kind): HttpResponse
    {
        abort_unless(in_array($kind, ['report', 'request'], true), 404);
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $path = $assistanceRequest->{"lgu_signed_{$kind}_path"};
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        $filename = $this->safeInlinePdfFilename(
            $request->string('filename')->toString()
                ?: ($assistanceRequest->{"lgu_signed_{$kind}_name"} ?: ($kind === 'request'
                    ? ($assistanceRequest->lgu_relief_request_reference ?: $assistanceRequest->reference_number).'-request.pdf'
                    : ($assistanceRequest->reference_number ?: 'signed-dromic-report').'.pdf')),
        );

        return Storage::disk('public')->response($path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->inlineContentDisposition($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function validationScreenshot(
        Request $request,
        AssistanceRequest $assistanceRequest,
        string $kind,
        int $index,
    ): HttpResponse {
        abort_unless(in_array($kind, ['report', 'request'], true), 404);
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $column = $kind === 'request'
            ? 'lgu_relief_review_screenshots'
            : 'lgu_dromic_review_screenshots';
        $screenshot = data_get($assistanceRequest->{$column}, $index);
        $path = data_get($screenshot, 'path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            data_get($screenshot, 'name') ?: basename($path),
            [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function validationHistoryScreenshot(
        Request $request,
        AssistanceRequest $assistanceRequest,
        string $kind,
        int $reviewIndex,
        int $screenshotIndex,
    ): HttpResponse {
        abort_unless(in_array($kind, ['report', 'request'], true), 404);
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);

        $column = $kind === 'request'
            ? 'lgu_relief_review_history'
            : 'lgu_dromic_review_history';
        $screenshot = data_get($assistanceRequest->{$column}, "{$reviewIndex}.screenshots.{$screenshotIndex}");
        $path = data_get($screenshot, 'path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            data_get($screenshot, 'name') ?: basename($path),
            [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function signedHistory(Request $request, LguSignedDocumentVersion $version): HttpResponse
    {
        $assistanceRequest = $version->assistanceRequest;
        abort_unless($assistanceRequest, 404);
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless(Storage::disk('public')->exists($version->path), 404);

        $filename = $this->safeInlinePdfFilename(
            $request->string('filename')->toString() ?: ($version->original_name ?: basename($version->path)),
        );

        return Storage::disk('public')->response($version->path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->inlineContentDisposition($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function documentViewed(
        Request $request,
        AssistanceRequest $assistanceRequest,
        RealtimePublisher $realtime,
    ): JsonResponse {
        $this->authorizeDromicViewer($request, $assistanceRequest);
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);
        abort_unless($request->user()?->hasAnyRole(['DRIMS', 'DRRS', 'QRT', 'Quick Response Team', 'DRMD AA', 'DRMD Chief', 'Super Admin']), 403);

        $data = $request->validate(['kind' => ['required', 'in:report,request']]);
        $kind = $data['kind'];
        abort_if($kind === 'request' && ! data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request'), 422, 'This report has no request letter.');

        $seenAtColumn = $kind === 'report' ? 'lgu_dromic_seen_at' : 'lgu_relief_seen_at';
        $seenByColumn = $kind === 'report' ? 'lgu_dromic_seen_by' : 'lgu_relief_seen_by';
        $now = now();
        $assistanceRequest->forceFill([
            $seenAtColumn => $now,
            $seenByColumn => $request->user()->id,
        ])->save();

        $recipientIds = collect([$assistanceRequest->lgu_submitted_by, $assistanceRequest->encoded_by])
            ->filter()
            ->unique()
            ->values();
        $realtime->usersChanged($recipientIds, 'workflow.receipt.changed', [
            'request_id' => $assistanceRequest->id,
            'kind' => $kind,
            'seen_at' => $now->toIso8601String(),
            'seen_by' => $request->user()->name,
        ]);

        return response()->json([
            'message' => ucfirst($kind).' view receipt recorded.',
            'seen_at' => $now->toIso8601String(),
            'seen_by' => $request->user()->name,
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        if (blank($request->input('province'))) {
            $request->merge(['province' => $this->resolveLguProvince($request->user())]);
        }
        if (blank($request->input('municipality'))) {
            $request->merge(['municipality' => $request->user()?->lgu_name]);
        }

        $isDraft = $request->input('submission_status') === 'draft';
        $required = $isDraft ? 'nullable' : 'required';
        $areaRowsRule = $isDraft ? ['nullable', 'array'] : ['required', 'array', 'min:1'];
        $areaValueRule = $isDraft ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'];

        $rules = [
            'submission_status' => ['nullable', 'in:draft,final'],
            'report_series_key' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'report_classification' => ['nullable', 'in:regular,first_and_final,terminal'],
            'requesting_lgu' => [$required, 'string', 'max:255'],
            'requester_name' => [$required, 'string', 'max:255'],
            'requester_position' => ['nullable', 'string', 'max:255'],
            'requester_address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:80'],
            'has_relief_request' => ['nullable', 'boolean'],
            'incident_name' => [$required, 'string', 'max:255'],
            'incident_date' => [$required, 'date'],
            'incident_summary' => ['nullable', 'string', 'max:3000'],
            'province' => [$required, 'string', 'max:255'],
            'municipality' => [$required, 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:2000'],
            'affected_families' => ['nullable', 'integer', 'min:0'],
            'affected_persons' => ['nullable', 'integer', 'min:0'],
            'displaced_families' => ['nullable', 'integer', 'min:0'],
            'damaged_houses' => ['nullable', 'integer', 'min:0'],
            'casualties' => ['nullable', 'string', 'max:1000'],
            'needs' => ['nullable', 'string', 'max:3000'],
            'requested_fni_items' => $isDraft
                ? ['exclude_unless:has_relief_request,true', 'nullable', 'array', 'max:100']
                : ['exclude_unless:has_relief_request,true', 'required_if:has_relief_request,true', 'array', 'min:1', 'max:100'],
            'requested_fni_items.*.fni_library_item_id' => ['required', 'integer', 'distinct', 'exists:fni_library_items,id'],
            'requested_fni_items.*.requested_quantity' => $isDraft
                ? ['nullable', 'numeric', 'min:1', 'max:999999999999.99']
                : ['required', 'numeric', 'min:1', 'max:999999999999.99'],
            'not_applicable_sections' => ['nullable', 'array'],
            'not_applicable_sections.*' => ['nullable', 'string', 'max:100'],
            'narrative' => [$required, 'string', 'max:12000'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'population_justification' => ['nullable', 'string', 'max:2000'],
            'incident_type_other' => ['nullable', 'string', 'max:255'],
            'evacuation_center_rows' => ['nullable', 'array'],
            'assistance_rows' => ['nullable', 'array'],
            'related_incident_rows' => ['nullable', 'array'],
            'related_incident_rows.*.city_municipality' => ['nullable', 'string', 'max:255'],
            'related_incident_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'related_incident_rows.*.incident_type' => ['nullable', 'string', 'max:255'],
            'related_incident_rows.*.incident_type_other' => ['nullable', 'string', 'max:255'],
            'related_incident_rows.*.occurrence_at' => ['nullable', 'date'],
            'related_incident_rows.*.occurrence_date' => ['nullable', 'date'],
            'related_incident_rows.*.occurrence_time' => ['nullable', 'date_format:H:i'],
            'related_incident_rows.*.description' => ['nullable', 'string', 'max:3000'],
            'related_incident_rows.*.actions_taken' => ['nullable', 'string', 'max:3000'],
            'related_incident_rows.*.status' => ['nullable', 'string', 'max:2000'],
            'casualty_rows' => ['nullable', 'array'],
            'casualty_rows.*.casualty_status' => ['nullable', 'in:Injured,Missing,Dead'],
            'casualty_rows.*.city_municipality' => ['nullable', 'string', 'max:255'],
            'casualty_rows.*.last_name' => ['nullable', 'string', 'max:255'],
            'casualty_rows.*.first_name' => ['nullable', 'string', 'max:255'],
            'casualty_rows.*.middle_name' => ['nullable', 'string', 'max:255'],
            'casualty_rows.*.age' => ['nullable', 'integer', 'min:0'],
            'casualty_rows.*.sex' => ['nullable', 'in:M,F,Male,Female'],
            'casualty_rows.*.address' => ['nullable', 'string', 'max:1000'],
            'casualty_rows.*.cause' => ['nullable', 'string', 'max:1000'],
            'casualty_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'casualty_rows.*.source_of_data' => ['nullable', 'string', 'max:255'],
            'infrastructure_damage_rows' => ['nullable', 'array'],
            'infrastructure_damage_rows.*.city_municipality' => ['nullable', 'string', 'max:255'],
            'infrastructure_damage_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'infrastructure_damage_rows.*.structure_type' => ['nullable', 'string', 'max:255'],
            'infrastructure_damage_rows.*.structure_type_other' => ['nullable', 'string', 'max:255'],
            'infrastructure_damage_rows.*.damage_description' => ['nullable', 'string', 'max:3000'],
            'infrastructure_damage_rows.*.length_meters' => ['nullable', 'numeric', 'min:0'],
            'infrastructure_damage_rows.*.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'infrastructure_damage_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'agriculture_damage_rows' => ['nullable', 'array'],
            'agriculture_damage_rows.*.city_municipality' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.classification' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.classification_other' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.type' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'agriculture_damage_rows.*.affected_farmers_fisherfolks' => ['nullable', 'integer', 'min:0'],
            'agriculture_damage_rows.*.area_no_chance_recovery' => ['nullable', 'numeric', 'min:0'],
            'agriculture_damage_rows.*.area_with_chance_recovery' => ['nullable', 'numeric', 'min:0'],
            'agriculture_damage_rows.*.infrastructure_totally_damaged' => ['nullable', 'integer', 'min:0'],
            'agriculture_damage_rows.*.infrastructure_partially_damaged' => ['nullable', 'integer', 'min:0'],
            'agriculture_damage_rows.*.production_loss_heads' => ['nullable', 'numeric', 'min:0'],
            'agriculture_damage_rows.*.production_loss_cost_per_head' => ['nullable', 'numeric', 'min:0'],
            'agriculture_damage_rows.*.production_loss_volume_mt' => ['nullable', 'numeric', 'min:0'],
            'agriculture_damage_rows.*.production_loss_value' => ['nullable', 'numeric', 'min:0'],
            'class_suspension_rows' => ['nullable', 'array'],
            'class_suspension_rows.*.province_city_municipality' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.coverage' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.level_from' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.level_from_other' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.level_to' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.level_to_other' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.type' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'class_suspension_rows.*.suspension_at' => ['nullable', 'date'],
            'class_suspension_rows.*.suspension_date' => ['nullable', 'date'],
            'class_suspension_rows.*.suspension_time' => ['nullable', 'date_format:H:i'],
            'class_suspension_rows.*.resumed_at' => ['nullable', 'date'],
            'class_suspension_rows.*.resumed_date' => ['nullable', 'date'],
            'class_suspension_rows.*.resumed_time' => ['nullable', 'date_format:H:i'],
            'class_suspension_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'work_suspension_rows' => ['nullable', 'array'],
            'work_suspension_rows.*.province_city_municipality' => ['nullable', 'string', 'max:255'],
            'work_suspension_rows.*.coverage' => ['nullable', 'string', 'max:255'],
            'work_suspension_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'work_suspension_rows.*.type' => ['nullable', 'string', 'max:255'],
            'work_suspension_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'work_suspension_rows.*.suspension_at' => ['nullable', 'date'],
            'work_suspension_rows.*.suspension_date' => ['nullable', 'date'],
            'work_suspension_rows.*.suspension_time' => ['nullable', 'date_format:H:i'],
            'work_suspension_rows.*.resumed_at' => ['nullable', 'date'],
            'work_suspension_rows.*.resumed_date' => ['nullable', 'date'],
            'work_suspension_rows.*.resumed_time' => ['nullable', 'date_format:H:i'],
            'work_suspension_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'road_bridge_rows' => ['nullable', 'array'],
            'road_bridge_rows.*.province_city_municipality' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.type' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.classification' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.classification_other' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.road_section' => ['nullable', 'string', 'max:2000'],
            'road_bridge_rows.*.status' => ['nullable', 'string', 'max:255'],
            'road_bridge_rows.*.reported_not_passable_at' => ['nullable', 'date'],
            'road_bridge_rows.*.date_reported_not_passable' => ['nullable', 'date'],
            'road_bridge_rows.*.time_reported_not_passable' => ['nullable', 'date_format:H:i'],
            'road_bridge_rows.*.reported_passable_at' => ['nullable', 'date'],
            'road_bridge_rows.*.date_reported_passable' => ['nullable', 'date'],
            'road_bridge_rows.*.time_reported_passable' => ['nullable', 'date_format:H:i'],
            'road_bridge_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'power_lifeline_rows' => ['nullable', 'array'],
            'power_lifeline_rows.*.coverage' => ['nullable', 'string', 'max:255'],
            'power_lifeline_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'power_lifeline_rows.*.type' => ['nullable', 'string', 'max:255'],
            'power_lifeline_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'power_lifeline_rows.*.service_provider' => ['nullable', 'string', 'max:255'],
            'power_lifeline_rows.*.interrupted_at' => ['nullable', 'date'],
            'power_lifeline_rows.*.restored_at' => ['nullable', 'date'],
            'power_lifeline_rows.*.remarks_status' => ['nullable', 'string', 'max:2000'],
            'water_lifeline_rows' => ['nullable', 'array'],
            'water_lifeline_rows.*.coverage' => ['nullable', 'string', 'max:255'],
            'water_lifeline_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'water_lifeline_rows.*.type' => ['nullable', 'string', 'max:255'],
            'water_lifeline_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'water_lifeline_rows.*.service_provider' => ['nullable', 'string', 'max:255'],
            'water_lifeline_rows.*.interrupted_at' => ['nullable', 'date'],
            'water_lifeline_rows.*.restored_at' => ['nullable', 'date'],
            'water_lifeline_rows.*.remarks_status' => ['nullable', 'string', 'max:2000'],
            'communication_lifeline_rows' => ['nullable', 'array'],
            'communication_lifeline_rows.*.coverage' => ['nullable', 'string', 'max:255'],
            'communication_lifeline_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'communication_lifeline_rows.*.communication_status' => ['nullable', 'string', 'max:255'],
            'communication_lifeline_rows.*.communication_status_other' => ['nullable', 'string', 'max:255'],
            'communication_lifeline_rows.*.service_provider' => ['nullable', 'string', 'max:255'],
            'communication_lifeline_rows.*.interrupted_at' => ['nullable', 'date'],
            'communication_lifeline_rows.*.restored_at' => ['nullable', 'date'],
            'communication_lifeline_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'seaport_rows' => ['nullable', 'array'],
            'seaport_rows.*.name' => ['nullable', 'string', 'max:255'],
            'seaport_rows.*.status' => ['nullable', 'string', 'max:255'],
            'seaport_rows.*.status_other' => ['nullable', 'string', 'max:255'],
            'seaport_rows.*.stranded_passengers' => ['nullable', 'integer', 'min:0'],
            'seaport_rows.*.reported_non_operational_at' => ['nullable', 'date'],
            'seaport_rows.*.reported_operational_at' => ['nullable', 'date'],
            'seaport_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'airport_rows' => ['nullable', 'array'],
            'airport_rows.*.name' => ['nullable', 'string', 'max:255'],
            'airport_rows.*.status' => ['nullable', 'string', 'max:255'],
            'airport_rows.*.status_other' => ['nullable', 'string', 'max:255'],
            'airport_rows.*.stranded_passengers' => ['nullable', 'integer', 'min:0'],
            'airport_rows.*.reported_non_operational_at' => ['nullable', 'date'],
            'airport_rows.*.reported_operational_at' => ['nullable', 'date'],
            'airport_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'land_transport_terminal_rows' => ['nullable', 'array'],
            'land_transport_terminal_rows.*.name' => ['nullable', 'string', 'max:255'],
            'land_transport_terminal_rows.*.status' => ['nullable', 'string', 'max:255'],
            'land_transport_terminal_rows.*.status_other' => ['nullable', 'string', 'max:255'],
            'land_transport_terminal_rows.*.stranded_passengers' => ['nullable', 'integer', 'min:0'],
            'land_transport_terminal_rows.*.reported_non_operational_at' => ['nullable', 'date'],
            'land_transport_terminal_rows.*.reported_operational_at' => ['nullable', 'date'],
            'land_transport_terminal_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'stranded_transport_rows' => ['nullable', 'array'],
            'stranded_transport_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'stranded_transport_rows.*.district' => ['nullable', 'string', 'max:255'],
            'stranded_transport_rows.*.station' => ['nullable', 'string', 'max:255'],
            'stranded_transport_rows.*.port_terminal' => ['nullable', 'string', 'max:255'],
            'stranded_transport_rows.*.passengers' => ['nullable', 'integer', 'min:0'],
            'stranded_transport_rows.*.rolling_cargoes' => ['nullable', 'integer', 'min:0'],
            'stranded_transport_rows.*.vessel_bus_liner' => ['nullable', 'string', 'max:255'],
            'stranded_transport_rows.*.mbca' => ['nullable', 'integer', 'min:0'],
            'stranded_transport_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'calamity_declaration_rows' => ['nullable', 'array'],
            'calamity_declaration_rows.*.location' => ['nullable', 'string', 'max:255'],
            'calamity_declaration_rows.*.type' => ['nullable', 'string', 'max:255'],
            'calamity_declaration_rows.*.type_other' => ['nullable', 'string', 'max:255'],
            'calamity_declaration_rows.*.resolution_number' => ['nullable', 'string', 'max:255'],
            'calamity_declaration_rows.*.resolution_date' => ['nullable', 'date'],
            'calamity_declaration_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'preemptive_evacuation_rows' => ['nullable', 'array'],
            'preemptive_evacuation_rows.*.barangay' => ['nullable', 'string', 'max:255'],
            'preemptive_evacuation_rows.*.families' => ['nullable', 'integer', 'min:0'],
            'preemptive_evacuation_rows.*.male' => ['nullable', 'integer', 'min:0'],
            'preemptive_evacuation_rows.*.female' => ['nullable', 'integer', 'min:0'],
            'preemptive_evacuation_rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'cluster_gap_rows' => ['nullable', 'array'],
            'cluster_gap_rows.*.cluster' => ['nullable', 'string', 'max:255'],
            'cluster_gap_rows.*.cluster_other' => ['nullable', 'string', 'max:255'],
            'cluster_gap_rows.*.areas_of_concern' => ['nullable', 'string', 'max:3000'],
            'cluster_gap_rows.*.actions_undertaken' => ['nullable', 'string', 'max:3000'],
            'cluster_gap_rows.*.status_remarks' => ['nullable', 'string', 'max:3000'],
            'response_action_rows' => $isDraft ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'response_action_rows.*.acted_by_office' => $isDraft ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'response_action_rows.*.acted_by_office_other' => ['nullable', 'string', 'max:255'],
            'response_action_rows.*.action_intervention' => $isDraft ? ['nullable', 'string', 'max:3000'] : ['required', 'string', 'max:3000'],
            'official_advisory_rows' => ['nullable', 'array', 'max:5'],
            'official_advisory_rows.*.agency' => ['required_with:official_advisory_rows', 'string', 'max:120'],
            'official_advisory_rows.*.advisory_title' => ['required_with:official_advisory_rows', 'string', 'max:255'],
            'official_advisory_rows.*.issued_at' => ['nullable', 'string', 'max:120'],
            'official_advisory_rows.*.covered_location' => ['nullable', 'string', 'max:500'],
            'official_advisory_rows.*.summary' => ['nullable', 'string', 'max:3000'],
            'official_advisory_rows.*.source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'official_advisory_rows.*.match_basis' => ['nullable', 'string', 'max:1000'],
            'official_advisory_rows.*.content_status' => ['nullable', 'in:extracted_for_review,reference_only,processing,extraction_failed'],
            'official_advisory_rows.*.source_kind' => ['nullable', 'in:link,screenshot,clipboard_text'],
            'official_advisory_rows.*.client_id' => ['nullable', 'string', 'max:100'],
            'official_advisory_rows.*.screenshot_name' => ['nullable', 'string', 'max:255'],
            'official_advisory_rows.*.screenshot_data_url' => ['nullable', 'string', 'max:5000000', 'regex:/^data:image\/(?:jpeg|png|webp);base64,/i'],
            'official_advisory_rows.*.pasted_text' => ['nullable', 'string', 'max:5000'],
            'photo_documentation_rows' => ['nullable', 'array', 'max:10'],
            'photo_documentation_rows.*.id' => ['nullable', 'string', 'max:80'],
            'photo_documentation_rows.*.name' => ['nullable', 'string', 'max:255'],
            'photo_documentation_rows.*.type' => ['nullable', 'string', 'max:80'],
            'photo_documentation_rows.*.size' => ['nullable', 'integer', 'min:0'],
            'photo_documentation_rows.*.data_url' => ['nullable', 'string'],
            'photo_collage_rows' => ['nullable', 'array', 'max:2'],
            'photo_collage_rows.*.id' => ['nullable', 'string', 'max:80'],
            'photo_collage_rows.*.title' => ['nullable', 'string', 'max:255'],
            'photo_collage_rows.*.heading' => ['required_with:photo_collage_rows', 'string', 'max:500'],
            'photo_collage_rows.*.date_label' => ['nullable', 'string', 'max:120'],
            'photo_collage_rows.*.layout' => ['nullable', 'string', 'max:50'],
            'photo_collage_rows.*.lgu_logo_url' => ['nullable', 'string', 'max:2048'],
            'photo_collage_rows.*.photo_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'photo_collage_rows.*.generated_at' => ['nullable', 'date'],
            'photo_collage_rows.*.data_url' => ['nullable', 'string'],
            'assistance_rows.*.barangay' => ['required_with:assistance_rows', 'string', 'max:255'],
            'assistance_rows.*.barangay_code' => ['nullable', 'string', 'max:20'],
            'assistance_rows.*.source' => ['required_with:assistance_rows', 'string', 'max:255'],
            'assistance_rows.*.source_details' => ['nullable', 'string', 'max:255'],
            'assistance_rows.*.quantity' => ['required_with:assistance_rows', 'numeric', 'min:0'],
            'assistance_rows.*.unit' => ['required_with:assistance_rows', 'string', 'max:100'],
            'assistance_rows.*.item_type' => ['required_with:assistance_rows', 'string', 'max:255'],
            'assistance_rows.*.particular' => ['required_with:assistance_rows', 'string', 'max:255'],
            'assistance_rows.*.cost_per_unit' => ['required_with:assistance_rows', 'numeric', 'min:0'],
            'assistance_rows.*.families_served' => ['required_with:assistance_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.barangay_address' => ['required_with:evacuation_center_rows', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_address_scope' => ['nullable', 'in:current,outside'],
            'evacuation_center_rows.*.barangay_address_province' => ['nullable', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_address_province_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.barangay_address_city' => ['nullable', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_address_city_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.barangay_address_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.evacuation_center' => ['required_with:evacuation_center_rows', 'string', 'max:255'],
            'evacuation_center_rows.*.families_cum' => ['required_with:evacuation_center_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.families_now' => ['required_with:evacuation_center_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.persons_cum' => ['required_with:evacuation_center_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.persons_now' => ['required_with:evacuation_center_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.barangay_origin' => ['required_with:evacuation_center_rows', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_origin_scope' => ['nullable', 'in:current,outside'],
            'evacuation_center_rows.*.barangay_origin_province' => ['nullable', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_origin_province_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.barangay_origin_city' => ['nullable', 'string', 'max:255'],
            'evacuation_center_rows.*.barangay_origin_city_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.barangay_origin_code' => ['nullable', 'string', 'max:20'],
            'evacuation_center_rows.*.classrooms_used' => ['required_with:evacuation_center_rows', 'integer', 'min:0'],
            'evacuation_center_rows.*.disaggregation_completed' => ['required_with:evacuation_center_rows', 'boolean'],
            'evacuation_center_rows.*.disaggregation' => ['required_with:evacuation_center_rows', 'array'],
            'incident_type' => [$required, 'string', 'max:255'],
            'incident_types' => ['nullable', 'array', 'max:1'],
            'incident_types.*' => ['nullable', 'string', 'max:255'],
            'incident_specific_details' => ['nullable', 'string', 'max:500'],
            'affected_areas' => ['nullable', 'string', 'max:2000'],
            'affected_barangays' => $isDraft ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'affected_barangays.*' => [$required, 'string', 'max:255'],
            'occurrence_started_at' => [$required, 'date', 'before_or_equal:now'],
            'incident_status' => [$required, 'in:Ongoing,Ended'],
            'incident_ended_at' => ['nullable', 'required_if:incident_status,Ended', 'date', 'after_or_equal:occurrence_started_at', 'before_or_equal:now'],
            'information_received_at' => [$required, 'date', 'after_or_equal:occurrence_started_at', 'before_or_equal:now'],
            'dromic_reporter' => ['nullable', 'string', 'max:255'],
            'area_rows' => $areaRowsRule,
            'area_rows.*.area' => [$required, 'string', 'max:255'],
            'area_rows.*.psgc_code' => ['nullable', 'string', 'max:20'],
            'area_rows.*.psa_2024' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.affected_families' => $areaValueRule,
            'area_rows.*.affected_persons' => $areaValueRule,
            'area_rows.*.evacuation_centers' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.inside_ec_families' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.inside_ec_persons' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.inside_ec_disaggregation' => ['nullable', 'string', 'max:3000'],
            'area_rows.*.outside_ec_families' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.outside_ec_persons' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.outside_ec_included' => ['nullable', 'boolean'],
            'area_rows.*.outside_ec_families_cum' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.outside_ec_families_now' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.outside_ec_persons_cum' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.outside_ec_persons_now' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.damaged_houses_included' => ['nullable', 'boolean'],
            'area_rows.*.damaged_houses_totally' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.damaged_houses_partially' => ['nullable', 'integer', 'min:0'],
            'area_rows.*.damaged_houses_estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($isDraft) {
            $rules = collect($rules)->map(fn (array $fieldRules): array => collect($fieldRules)
                ->reject(fn ($rule): bool => is_string($rule) && (
                    $rule === 'required'
                    || str_starts_with($rule, 'required_if:')
                    || str_starts_with($rule, 'required_with:')
                    || str_starts_with($rule, 'required_without:')
                ))
                ->prepend('nullable')
                ->unique()
                ->values()
                ->all())
                ->all();
        }

        $data = $request->validate($rules, [], [
            'response_action_rows' => 'response actions and interventions',
            'response_action_rows.*.acted_by_office' => 'acted by',
            'response_action_rows.*.acted_by_office_other' => 'other acted by',
            'response_action_rows.*.action_intervention' => 'response action / intervention',
        ]);

        $data['not_applicable_sections'] = array_values(array_filter(
            $data['not_applicable_sections'] ?? [],
            fn ($section): bool => $section !== 'response_actions',
        ));

        foreach ($data['official_advisory_rows'] ?? [] as $index => $advisory) {
            if (blank($advisory['source_url'] ?? null) && blank($advisory['screenshot_data_url'] ?? null) && blank($advisory['pasted_text'] ?? null)) {
                throw ValidationException::withMessages([
                    "official_advisory_rows.{$index}.source_url" => 'Paste a PAGASA/PHIVOLCS screenshot or copied advisory text.',
                ]);
            }
            if (! $isDraft && filled($advisory['screenshot_data_url'] ?? null) && (
                ($advisory['content_status'] ?? null) !== 'extracted_for_review'
                || blank($advisory['summary'] ?? null)
            )) {
                throw ValidationException::withMessages([
                    "official_advisory_rows.{$index}.screenshot_data_url" => 'Remove this screenshot or paste a clearer copy before saving the final report.',
                ]);
            }
        }

        if ($isDraft) {
            return $data;
        }

        $notApplicableSections = collect($data['not_applicable_sections'] ?? []);
        $areaRows = collect($data['area_rows'] ?? []);
        $sectionContent = [
            'inside_ec' => ! empty($data['evacuation_center_rows']),
            'outside_ec' => $areaRows->contains(fn (array $row): bool => (bool) ($row['outside_ec_included'] ?? false)),
            'damaged_houses' => $areaRows->contains(fn (array $row): bool => (bool) ($row['damaged_houses_included'] ?? false)),
            'assistance' => ! empty($data['assistance_rows']),
            'related_incidents' => ! empty($data['related_incident_rows']),
            'casualties' => ! empty($data['casualty_rows']),
            'infrastructure_damage' => ! empty($data['infrastructure_damage_rows']),
            'agriculture_damage' => ! empty($data['agriculture_damage_rows']),
            'class_suspension' => ! empty($data['class_suspension_rows']),
            'work_suspension' => ! empty($data['work_suspension_rows']),
            'roads_bridges' => ! empty($data['road_bridge_rows']),
            'power_lifelines' => ! empty($data['power_lifeline_rows']),
            'water_lifelines' => ! empty($data['water_lifeline_rows']),
            'communication_lifelines' => ! empty($data['communication_lifeline_rows']),
            'seaports' => ! empty($data['seaport_rows']),
            'airports' => ! empty($data['airport_rows']),
            'land_transport_terminals' => ! empty($data['land_transport_terminal_rows']),
            'stranded_transport' => ! empty($data['stranded_transport_rows']),
            'calamity_declaration' => ! empty($data['calamity_declaration_rows']),
            'preemptive_evacuation' => ! empty($data['preemptive_evacuation_rows']),
            'cluster_gaps' => ! empty($data['cluster_gap_rows']),
            'photo_documentation' => ! empty($data['photo_documentation_rows']) || ! empty($data['photo_collage_rows']),
        ];
        $sectionLabels = [
            'inside_ec' => 'Inside Evacuation Centers',
            'outside_ec' => 'Outside Evacuation Centers',
            'damaged_houses' => 'Damaged Houses',
            'assistance' => 'Status of Assistance Provided',
            'related_incidents' => 'Related Incidents',
            'casualties' => 'Casualties',
            'infrastructure_damage' => 'Damage to Infrastructure',
            'agriculture_damage' => 'Damage and Losses to Agriculture',
            'class_suspension' => 'Class Suspension',
            'work_suspension' => 'Work Suspension',
            'roads_bridges' => 'Status of Roads and Bridges',
            'power_lifelines' => 'Status of Power Supply',
            'water_lifelines' => 'Status of Water Supply',
            'communication_lifelines' => 'Status of Communication Lines',
            'seaports' => 'Status of Seaports',
            'airports' => 'Status of Airports',
            'land_transport_terminals' => 'Status of Land Transportation Terminals',
            'stranded_transport' => 'Stranded Passengers and Transport',
            'calamity_declaration' => 'Declaration of State of Calamity',
            'preemptive_evacuation' => 'Pre-emptive Evacuation',
            'cluster_gaps' => 'Gaps / Challenges',
            'photo_documentation' => 'Photo Documentation',
        ];

        foreach ($sectionContent as $section => $hasContent) {
            if (! $hasContent && ! $notApplicableSections->contains($section)) {
                throw ValidationException::withMessages([
                    'not_applicable_sections' => "{$sectionLabels[$section]}: Add at least one entry or mark this section N/A before saving as final.",
                ]);
            }
        }

        if ($notApplicableSections->contains('inside_ec')) {
            $data['evacuation_center_rows'] = [];
        }
        if ($notApplicableSections->contains('outside_ec')) {
            $data['area_rows'] = collect($data['area_rows'] ?? [])
                ->map(fn (array $row): array => [
                    ...$row,
                    'outside_ec_included' => false,
                    'outside_ec_families_cum' => null,
                    'outside_ec_families_now' => null,
                    'outside_ec_persons_cum' => null,
                    'outside_ec_persons_now' => null,
                ])
                ->all();
        }

        $this->validateSituationOverview((string) ($data['narrative'] ?? ''));
        $this->ensureMeaningfulTextInputs($data);

        foreach ($data['area_rows'] ?? [] as $index => $row) {
            $hasFamilies = array_key_exists('affected_families', $row) && filled($row['affected_families']);
            $hasPersons = array_key_exists('affected_persons', $row) && filled($row['affected_persons']);

            if ($hasFamilies !== $hasPersons) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.affected_families" => 'Affected families and affected persons must be encoded together.',
                ]);
            }

            $families = (int) ($row['affected_families'] ?? 0);
            $persons = (int) ($row['affected_persons'] ?? 0);

            $pairIssue = $this->familyPersonPairIssue($families, $persons, 'Affected population');
            if ($pairIssue !== '') {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.affected_families" => $pairIssue,
                ]);
            }

            if ($families === 0 && $persons === 0) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.affected_families" => 'Affected population must be greater than zero before encoding displaced population or evacuation center data.',
                ]);
            }

            if ((bool) ($row['damaged_houses_included'] ?? false)) {
                $totallyBlank = ! array_key_exists('damaged_houses_totally', $row) || $row['damaged_houses_totally'] === '' || $row['damaged_houses_totally'] === null;
                $partiallyBlank = ! array_key_exists('damaged_houses_partially', $row) || $row['damaged_houses_partially'] === '' || $row['damaged_houses_partially'] === null;

                if ($totallyBlank || $partiallyBlank) {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.damaged_houses_totally" => 'Totally and partially damaged houses must be encoded together. Enter 0 if none.',
                    ]);
                }

                $totalDamagedHouses = (int) ($row['damaged_houses_totally'] ?? 0) + (int) ($row['damaged_houses_partially'] ?? 0);
                if ($totalDamagedHouses > $families) {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.damaged_houses_totally" => 'Total damaged houses cannot be greater than affected families.',
                    ]);
                }
            }

            $psa2024 = (int) ($row['psa_2024'] ?? 0);
            if ($psa2024 > 0 && $persons > $psa2024 && blank($data['population_justification'] ?? null)) {
                throw ValidationException::withMessages([
                    'population_justification' => 'Justification is required when affected persons exceed the PSA 2024 barangay population.',
                ]);
            }
        }

        $affectedPopulationTotals = collect($data['area_rows'] ?? [])->reduce(fn (array $totals, array $row): array => [
            'families' => $totals['families'] + (int) ($row['affected_families'] ?? 0),
            'persons' => $totals['persons'] + (int) ($row['affected_persons'] ?? 0),
        ], ['families' => 0, 'persons' => 0]);
        $affectedAreaByKey = collect($data['area_rows'] ?? [])->reduce(function (array $lookup, array $row): array {
            foreach (array_filter(array_unique([
                trim((string) ($row['psgc_code'] ?? '')),
                trim((string) ($row['area'] ?? '')),
            ])) as $key) {
                $lookup[$key] = $row;
            }

            return $lookup;
        }, []);

        foreach ($data['assistance_rows'] ?? [] as $index => $row) {
            $barangayKey = trim((string) ($row['barangay_code'] ?? $row['barangay'] ?? ''));
            $affectedArea = $affectedAreaByKey[$barangayKey] ?? null;

            if (! $affectedArea) {
                throw ValidationException::withMessages([
                    "assistance_rows.{$index}.barangay" => 'Assistance must be assigned to an affected barangay.',
                ]);
            }

            if ((float) ($row['quantity'] ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    "assistance_rows.{$index}.quantity" => 'Quantity must be greater than 0.',
                ]);
            }

            if ((int) ($row['families_served'] ?? 0) > (int) ($affectedArea['affected_families'] ?? 0)) {
                throw ValidationException::withMessages([
                    "assistance_rows.{$index}.families_served" => 'No. of families served cannot be greater than the affected families of the selected barangay.',
                ]);
            }
        }

        $areaLookupKeys = static fn (array $row): array => array_values(array_filter(array_unique([
            trim((string) ($row['psgc_code'] ?? $row['barangay_origin_code'] ?? '')),
            trim((string) ($row['area'] ?? $row['barangay_origin'] ?? '')),
        ])));

        $insideEcByOrigin = collect($data['evacuation_center_rows'] ?? [])->reduce(function (array $totals, array $row): array {
            $keys = array_values(array_filter(array_unique([
                trim((string) ($row['barangay_origin_code'] ?? '')),
                trim((string) ($row['barangay_origin'] ?? '')),
            ])));

            if ($keys === []) {
                return $totals;
            }

            $current = ['families_cum' => 0, 'families_now' => 0, 'persons_cum' => 0, 'persons_now' => 0];
            foreach ($keys as $key) {
                if (isset($totals[$key])) {
                    $current = $totals[$key];
                    break;
                }
            }

            $summary = [
                'families_cum' => $current['families_cum'] + (int) ($row['families_cum'] ?? 0),
                'families_now' => $current['families_now'] + (int) ($row['families_now'] ?? 0),
                'persons_cum' => $current['persons_cum'] + (int) ($row['persons_cum'] ?? 0),
                'persons_now' => $current['persons_now'] + (int) ($row['persons_now'] ?? 0),
            ];

            foreach ($keys as $key) {
                $totals[$key] = $summary;
            }

            return $totals;
        }, []);
        $areaRowsByKey = collect($data['area_rows'] ?? [])->reduce(function (array $totals, array $row) use ($areaLookupKeys): array {
            foreach ($areaLookupKeys($row) as $key) {
                $totals[$key] = $row;
            }

            return $totals;
        }, []);
        $outsideEcByOrigin = collect($data['area_rows'] ?? [])->reduce(function (array $totals, array $row) use ($areaLookupKeys): array {
            if (! (bool) ($row['outside_ec_included'] ?? false)) {
                return $totals;
            }

            $keys = $areaLookupKeys($row);
            if ($keys === []) {
                return $totals;
            }

            $summary = [
                'families_cum' => (int) ($row['outside_ec_families_cum'] ?? 0),
                'families_now' => (int) ($row['outside_ec_families_now'] ?? 0),
                'persons_cum' => (int) ($row['outside_ec_persons_cum'] ?? 0),
                'persons_now' => (int) ($row['outside_ec_persons_now'] ?? 0),
            ];

            foreach ($keys as $key) {
                $totals[$key] = $summary;
            }

            return $totals;
        }, []);

        foreach ($data['area_rows'] ?? [] as $index => $row) {
            if (! (bool) ($row['outside_ec_included'] ?? false)) {
                continue;
            }

            foreach (['outside_ec_families_cum', 'outside_ec_families_now', 'outside_ec_persons_cum', 'outside_ec_persons_now'] as $field) {
                if (! array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.{$field}" => 'Outside EC CUM and NOW fields are required after adding a barangay row. Enter 0 if none.',
                    ]);
                }
            }

            $outsideFamiliesCum = (int) ($row['outside_ec_families_cum'] ?? $row['outside_ec_families'] ?? 0);
            $outsideFamiliesNow = (int) ($row['outside_ec_families_now'] ?? 0);
            $outsidePersonsCum = (int) ($row['outside_ec_persons_cum'] ?? $row['outside_ec_persons'] ?? 0);
            $outsidePersonsNow = (int) ($row['outside_ec_persons_now'] ?? 0);

            $outsideCumIssue = $this->familyPersonPairIssue($outsideFamiliesCum, $outsidePersonsCum, 'Outside EC CUM');
            if ($outsideCumIssue !== '') {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_cum" => $outsideCumIssue,
                ]);
            }

            $outsideNowIssue = $this->familyPersonPairIssue($outsideFamiliesNow, $outsidePersonsNow, 'Outside EC NOW');
            if ($outsideNowIssue !== '') {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_now" => $outsideNowIssue,
                ]);
            }

            if ($outsideFamiliesNow > $outsideFamiliesCum || $outsidePersonsNow > $outsidePersonsCum) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_now" => 'Outside EC NOW counts cannot be greater than Outside EC CUM counts.',
                ]);
            }

            $key = (string) ($row['psgc_code'] ?? $row['area'] ?? '');
            $inside = $insideEcByOrigin[$key] ?? ['families_cum' => 0, 'families_now' => 0, 'persons_cum' => 0, 'persons_now' => 0];
            $affectedFamilies = (int) ($row['affected_families'] ?? 0);
            $affectedPersons = (int) ($row['affected_persons'] ?? 0);
            $totalDisplacedFamiliesCum = $inside['families_cum'] + $outsideFamiliesCum;
            $totalDisplacedFamiliesNow = $inside['families_now'] + $outsideFamiliesNow;
            $totalDisplacedPersonsCum = $inside['persons_cum'] + $outsidePersonsCum;
            $totalDisplacedPersonsNow = $inside['persons_now'] + $outsidePersonsNow;

            if ($affectedFamilies > 0 && $affectedPersons > 0
                && $inside['families_cum'] === $affectedFamilies
                && $inside['persons_cum'] === $affectedPersons
                && ($outsideFamiliesCum > 0 || $outsidePersonsCum > 0)) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_cum" => 'This barangay is already fully encoded in Inside EC CUM. Remove the Outside EC row or set Outside EC CUM values to 0 to avoid double-counting.',
                ]);
            }

            if ($affectedFamilies > 0 && $affectedPersons > 0
                && $inside['families_now'] === $affectedFamilies
                && $inside['persons_now'] === $affectedPersons
                && ($outsideFamiliesNow > 0 || $outsidePersonsNow > 0)) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_now" => 'This barangay is already fully encoded in Inside EC NOW. Remove the Outside EC row or set Outside EC NOW values to 0 to avoid double-counting.',
                ]);
            }

            if (($totalDisplacedFamiliesCum > $affectedFamilies)
                || ($totalDisplacedFamiliesNow > $affectedFamilies)) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_families_cum" => 'Inside EC + Outside EC displaced families cannot be greater than affected families.',
                ]);
            }

            if (($totalDisplacedPersonsCum > $affectedPersons)
                || ($totalDisplacedPersonsNow > $affectedPersons)) {
                throw ValidationException::withMessages([
                    "area_rows.{$index}.outside_ec_persons_cum" => 'Inside EC + Outside EC displaced persons cannot be greater than affected persons.',
                ]);
            }

        }

        foreach ($data['area_rows'] ?? [] as $index => $row) {
            $inside = ['families_cum' => 0, 'families_now' => 0, 'persons_cum' => 0, 'persons_now' => 0];
            foreach ($areaLookupKeys($row) as $key) {
                if (isset($insideEcByOrigin[$key])) {
                    $inside = $insideEcByOrigin[$key];
                    break;
                }
            }
            $outside = (bool) ($row['outside_ec_included'] ?? false)
                ? [
                    'families_cum' => (int) ($row['outside_ec_families_cum'] ?? 0),
                    'families_now' => (int) ($row['outside_ec_families_now'] ?? 0),
                    'persons_cum' => (int) ($row['outside_ec_persons_cum'] ?? 0),
                    'persons_now' => (int) ($row['outside_ec_persons_now'] ?? 0),
                ]
                : ['families_cum' => 0, 'families_now' => 0, 'persons_cum' => 0, 'persons_now' => 0];
            $affectedFamilies = (int) ($row['affected_families'] ?? 0);
            $affectedPersons = (int) ($row['affected_persons'] ?? 0);

            foreach (['cum' => 'CUM', 'now' => 'NOW'] as $period => $periodLabel) {
                $displacedFamilies = $inside["families_{$period}"] + $outside["families_{$period}"];
                $displacedPersons = $inside["persons_{$period}"] + $outside["persons_{$period}"];

                if ($displacedFamilies > $affectedFamilies || $displacedPersons > $affectedPersons) {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.affected_families" => "Total displaced {$periodLabel} cannot exceed affected population. Displaced: {$displacedFamilies} families / {$displacedPersons} persons; affected: {$affectedFamilies} families / {$affectedPersons} persons.",
                    ]);
                }

                $allFamiliesDisplaced = $affectedFamilies > 0 && $displacedFamilies === $affectedFamilies;
                $allPersonsDisplaced = $affectedPersons > 0 && $displacedPersons === $affectedPersons;
                if ($allFamiliesDisplaced !== $allPersonsDisplaced) {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.affected_families" => $allFamiliesDisplaced
                            ? "Total displaced {$periodLabel} families equal all affected families, but displaced persons do not equal affected persons. Correct the Inside/Outside EC counts so families and persons are fully accounted together."
                            : "Total displaced {$periodLabel} persons equal all affected persons, but displaced families do not equal affected families. Correct the Inside/Outside EC counts so families and persons are fully accounted together.",
                    ]);
                }

                $nonIdpFamilies = $affectedFamilies - $displacedFamilies;
                $nonIdpPersons = $affectedPersons - $displacedPersons;
                $nonIdpIssue = $this->familyPersonPairIssue($nonIdpFamilies, $nonIdpPersons, "Non-IDP {$periodLabel}");
                if ($nonIdpIssue !== '') {
                    throw ValidationException::withMessages([
                        "area_rows.{$index}.affected_families" => "{$nonIdpIssue} Computed Non-IDPs are {$nonIdpFamilies} families / {$nonIdpPersons} persons. Correct the affected or Inside/Outside EC counts.",
                    ]);
                }
            }
        }

        $evacuationCenterTotals = [
            'families_cum' => 0,
            'families_now' => 0,
            'persons_cum' => 0,
            'persons_now' => 0,
        ];

        foreach ($data['evacuation_center_rows'] ?? [] as $index => $row) {
            $evacuationCenterTotals['families_cum'] += (int) ($row['families_cum'] ?? 0);
            $evacuationCenterTotals['families_now'] += (int) ($row['families_now'] ?? 0);
            $evacuationCenterTotals['persons_cum'] += (int) ($row['persons_cum'] ?? 0);
            $evacuationCenterTotals['persons_now'] += (int) ($row['persons_now'] ?? 0);

            $cumPairIssue = $this->familyPersonPairIssue((int) ($row['families_cum'] ?? 0), (int) ($row['persons_cum'] ?? 0), 'Evacuation center CUM');
            if ($cumPairIssue !== '') {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.families_cum" => $cumPairIssue,
                ]);
            }

            $nowPairIssue = $this->familyPersonPairIssue((int) ($row['families_now'] ?? 0), (int) ($row['persons_now'] ?? 0), 'Evacuation center NOW');
            if ($nowPairIssue !== '') {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.families_now" => $nowPairIssue,
                ]);
            }

            if ((int) ($row['families_now'] ?? 0) > (int) ($row['families_cum'] ?? 0)
                || (int) ($row['persons_now'] ?? 0) > (int) ($row['persons_cum'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.families_now" => 'NOW counts cannot be greater than CUM counts.',
                ]);
            }

            if ((int) ($row['families_cum'] ?? 0) > 0
                && (int) ($row['families_now'] ?? 0) === (int) ($row['families_cum'] ?? 0)
                && (int) ($row['persons_now'] ?? 0) < (int) ($row['persons_cum'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.persons_now" => sprintf(
                        'Persons NOW cannot be incomplete while Families NOW is complete. Since Families NOW equals Families CUM (%d), Persons NOW must also equal Persons CUM (%d), or correct Families NOW.',
                        (int) ($row['families_cum'] ?? 0),
                        (int) ($row['persons_cum'] ?? 0),
                    ),
                ]);
            }

            $originKey = (string) ($row['barangay_origin_code'] ?? $row['barangay_origin'] ?? '');
            $areaRow = $areaRowsByKey[$originKey] ?? null;
            $outside = $outsideEcByOrigin[$originKey] ?? ['families_cum' => 0, 'families_now' => 0, 'persons_cum' => 0, 'persons_now' => 0];

            if ($areaRow !== null) {
                $affectedFamilies = (int) ($areaRow['affected_families'] ?? 0);
                $affectedPersons = (int) ($areaRow['affected_persons'] ?? 0);

                if ($affectedFamilies > 0 && $affectedPersons > 0
                    && $outside['families_cum'] === $affectedFamilies
                    && $outside['persons_cum'] === $affectedPersons
                    && ((int) ($row['families_cum'] ?? 0) > 0 || (int) ($row['persons_cum'] ?? 0) > 0)) {
                    throw ValidationException::withMessages([
                        "evacuation_center_rows.{$index}.families_cum" => 'This barangay is already fully encoded in Outside EC CUM. Remove the Inside EC row or set one section to 0 to avoid double-counting.',
                    ]);
                }

                if ($affectedFamilies > 0 && $affectedPersons > 0
                    && $outside['families_now'] === $affectedFamilies
                    && $outside['persons_now'] === $affectedPersons
                    && ((int) ($row['families_now'] ?? 0) > 0 || (int) ($row['persons_now'] ?? 0) > 0)) {
                    throw ValidationException::withMessages([
                        "evacuation_center_rows.{$index}.families_now" => 'This barangay is already fully encoded in Outside EC NOW. Remove the Inside EC row or set one section to 0 to avoid double-counting.',
                    ]);
                }
            }

            if (! (bool) ($row['disaggregation_completed'] ?? false)) {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.disaggregation" => 'Age/sex and sectoral disaggregated data is required for each evacuation center. Enter 0 if none.',
                ]);
            }

            $disaggregationPairIssue = $this->disaggregationPairIssue($row);
            if ($disaggregationPairIssue !== '') {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.disaggregation" => $disaggregationPairIssue,
                ]);
            }

            $ageSexTotals = collect(data_get($row, 'disaggregation.age_sex', []))
                ->reduce(fn (array $totals, array $values): array => [
                    'cum' => $totals['cum'] + (int) ($values['male_cum'] ?? 0) + (int) ($values['female_cum'] ?? 0),
                    'now' => $totals['now'] + (int) ($values['male_now'] ?? 0) + (int) ($values['female_now'] ?? 0),
                ], ['cum' => 0, 'now' => 0]);

            if ($ageSexTotals['cum'] !== (int) ($row['persons_cum'] ?? 0) || $ageSexTotals['now'] !== (int) ($row['persons_now'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.disaggregation" => 'Age/sex disaggregation totals must equal the evacuation center Persons CUM and NOW counts.',
                ]);
            }
        }

        if (($evacuationCenterTotals['families_cum'] > $affectedPopulationTotals['families'])
            || ($evacuationCenterTotals['families_now'] > $affectedPopulationTotals['families'])) {
            throw ValidationException::withMessages([
                'evacuation_center_rows' => 'Inside evacuation center grand total families cannot be greater than the total affected families.',
            ]);
        }

        if (($evacuationCenterTotals['persons_cum'] > $affectedPopulationTotals['persons'])
            || ($evacuationCenterTotals['persons_now'] > $affectedPopulationTotals['persons'])) {
            throw ValidationException::withMessages([
                'evacuation_center_rows' => 'Inside evacuation center grand total persons cannot be greater than the total affected persons.',
            ]);
        }

        return $data;
    }

    private function familyPersonPairIssue(int $families, int $persons, string $label): string
    {
        if ($families === 0 && $persons > 0) {
            return "{$label}: families cannot be 0 when persons are greater than 0.";
        }

        if ($families > 0 && $persons === 0) {
            return "{$label}: persons cannot be 0 when families are greater than 0.";
        }

        if ($families > $persons) {
            return "{$label}: families cannot be greater than persons.";
        }

        return '';
    }

    private function disaggregationPairIssue(array $row): string
    {
        $ageSexRows = [
            'infant' => 'Infant',
            'toddler' => 'Toddler',
            'pre_school' => 'Pre-School',
            'school_age' => 'School Age',
            'teenage' => 'Teenage',
            'adult' => 'Adult',
            'elderly' => 'Elderly',
        ];

        foreach ($ageSexRows as $key => $label) {
            $values = (array) data_get($row, "disaggregation.age_sex.{$key}", []);

            foreach (['male' => 'male', 'female' => 'female'] as $prefix => $sexLabel) {
                $issue = $this->cumNowPairIssue($values["{$prefix}_cum"] ?? null, $values["{$prefix}_now"] ?? null, "{$label} {$sexLabel}");
                if ($issue !== '') {
                    return $issue;
                }
            }
        }

        $sectoralRows = [
            'pwds' => ['label' => 'Persons with Disabilities (PWDs)', 'has_male' => true],
            'child_headed_family' => ['label' => 'Child-Headed Family', 'has_male' => true],
            'single_headed_family' => ['label' => 'Single-Headed Family', 'has_male' => true],
            'solo_parent' => ['label' => 'Solo Parent', 'has_male' => true],
            'pregnant_women' => ['label' => 'Pregnant Women', 'has_male' => false],
            'lactating_mothers' => ['label' => 'Lactating Mothers', 'has_male' => false],
            'four_ps' => ['label' => '4Ps Beneficiaries (4Ps)', 'has_male' => true],
            'indigenous_people' => ['label' => 'Indigenous People (IP)', 'has_male' => true],
        ];

        foreach ($sectoralRows as $key => $meta) {
            $values = (array) data_get($row, "disaggregation.sectoral.{$key}", []);

            if ($meta['has_male']) {
                $issue = $this->cumNowPairIssue($values['male_cum'] ?? null, $values['male_now'] ?? null, "{$meta['label']} male");
                if ($issue !== '') {
                    return $issue;
                }
            }

            $issue = $this->cumNowPairIssue($values['female_cum'] ?? null, $values['female_now'] ?? null, "{$meta['label']} female");
            if ($issue !== '') {
                return $issue;
            }
        }

        $ageSexValues = (array) data_get($row, 'disaggregation.age_sex', []);
        $childAgeSexTotals = collect(['infant', 'toddler', 'pre_school', 'school_age', 'teenage'])
            ->reduce(function (array $totals, string $key) use ($ageSexValues): array {
                $values = (array) ($ageSexValues[$key] ?? []);

                return [
                    'cum' => $totals['cum'] + (int) ($values['male_cum'] ?? 0) + (int) ($values['female_cum'] ?? 0),
                    'now' => $totals['now'] + (int) ($values['male_now'] ?? 0) + (int) ($values['female_now'] ?? 0),
                ];
            }, ['cum' => 0, 'now' => 0]);
        foreach (['pregnant_women' => 'Pregnant Women', 'lactating_mothers' => 'Lactating Mothers'] as $key => $label) {
            $values = (array) data_get($row, "disaggregation.sectoral.{$key}", []);
            $cum = (int) ($values['female_cum'] ?? 0);
            $now = (int) ($values['female_now'] ?? 0);
            $eligibleFemaleTotals = collect(['teenage', 'adult'])
                ->reduce(function (array $totals, string $ageKey) use ($ageSexValues): array {
                    $values = (array) ($ageSexValues[$ageKey] ?? []);

                    return [
                        'cum' => $totals['cum'] + (int) ($values['female_cum'] ?? 0),
                        'now' => $totals['now'] + (int) ($values['female_now'] ?? 0),
                    ];
                }, ['cum' => 0, 'now' => 0]);

            if ($cum > 0 && $eligibleFemaleTotals['cum'] === 0) {
                return "{$label}: CUM cannot be encoded without female CUM data under Teenage and/or Adult Age/Sex groups.";
            }

            if ($now > 0 && $eligibleFemaleTotals['now'] === 0) {
                return "{$label}: NOW cannot be encoded without female NOW data under Teenage and/or Adult Age/Sex groups.";
            }

            if ($cum > $eligibleFemaleTotals['cum']) {
                return "{$label}: CUM cannot exceed the combined Teenage/Adult female CUM ({$eligibleFemaleTotals['cum']}).";
            }

            if ($now > $eligibleFemaleTotals['now']) {
                return "{$label}: NOW cannot exceed the combined Teenage/Adult female NOW ({$eligibleFemaleTotals['now']}).";
            }

            if ($cum > 0 && $now === 0 && $eligibleFemaleTotals['now'] > 0) {
                return "{$label}: NOW cannot stay 0 while Teenage/Adult female NOW still has a count.";
            }

        }

        $childHeadedValues = (array) data_get($row, 'disaggregation.sectoral.child_headed_family', []);
        $childHeadedCum = (int) ($childHeadedValues['male_cum'] ?? 0) + (int) ($childHeadedValues['female_cum'] ?? 0);
        $childHeadedNow = (int) ($childHeadedValues['male_now'] ?? 0) + (int) ($childHeadedValues['female_now'] ?? 0);

        if ($childHeadedCum > 0 && $childAgeSexTotals['cum'] === 0) {
            return 'Child-Headed Family: CUM cannot be encoded without CUM data under Infant, Toddler, Pre-School, School Age, or Teenage groups.';
        }

        if ($childHeadedNow > 0 && $childAgeSexTotals['now'] === 0) {
            return 'Child-Headed Family: NOW cannot be encoded without NOW data under Infant, Toddler, Pre-School, School Age, or Teenage groups.';
        }

        if ($childHeadedCum > 0 && $childHeadedNow === 0 && $childAgeSexTotals['now'] > 0) {
            return 'Child-Headed Family: NOW cannot stay 0 while Infant/Toddler/Pre-School/School Age/Teenage Age/Sex NOW still has a count.';
        }

        $soloParentValues = (array) data_get($row, 'disaggregation.sectoral.solo_parent', []);
        $soloParentCum = (int) ($soloParentValues['male_cum'] ?? 0) + (int) ($soloParentValues['female_cum'] ?? 0);
        $soloParentNow = (int) ($soloParentValues['male_now'] ?? 0) + (int) ($soloParentValues['female_now'] ?? 0);
        $soloParentEligibleTotals = collect(['teenage', 'adult', 'elderly'])
            ->reduce(function (array $totals, string $ageKey) use ($ageSexValues): array {
                $values = (array) ($ageSexValues[$ageKey] ?? []);

                return [
                    'cum' => $totals['cum'] + (int) ($values['male_cum'] ?? 0) + (int) ($values['female_cum'] ?? 0),
                    'now' => $totals['now'] + (int) ($values['male_now'] ?? 0) + (int) ($values['female_now'] ?? 0),
                ];
            }, ['cum' => 0, 'now' => 0]);

        if ($soloParentCum > 0 && $soloParentEligibleTotals['cum'] === 0) {
            return 'Solo Parent: CUM cannot be encoded without CUM data under Teenage, Adult, and/or Elderly Age/Sex groups.';
        }

        if ($soloParentNow > 0 && $soloParentEligibleTotals['now'] === 0) {
            return 'Solo Parent: NOW cannot be encoded without NOW data under Teenage, Adult, and/or Elderly Age/Sex groups.';
        }

        if ($soloParentCum > 0 && $soloParentNow === 0 && $soloParentEligibleTotals['now'] > 0) {
            return 'Solo Parent: NOW cannot stay 0 while Teenage/Adult/Elderly Age/Sex NOW still has a count.';
        }

        $maleAgeSexTotals = collect($ageSexValues)
            ->reduce(fn (array $totals, array $values): array => [
                'cum' => $totals['cum'] + (int) ($values['male_cum'] ?? 0),
                'now' => $totals['now'] + (int) ($values['male_now'] ?? 0),
            ], ['cum' => 0, 'now' => 0]);
        $femaleAgeSexTotals = collect($ageSexValues)
            ->reduce(fn (array $totals, array $values): array => [
                'cum' => $totals['cum'] + (int) ($values['female_cum'] ?? 0),
                'now' => $totals['now'] + (int) ($values['female_now'] ?? 0),
            ], ['cum' => 0, 'now' => 0]);
        $stableSectoralGroups = ['pwds', 'child_headed_family', 'single_headed_family', 'solo_parent', 'four_ps', 'indigenous_people'];

        foreach ($sectoralRows as $key => $meta) {
            $values = (array) data_get($row, "disaggregation.sectoral.{$key}", []);
            $maleCum = (int) ($values['male_cum'] ?? 0);
            $maleNow = (int) ($values['male_now'] ?? 0);
            $femaleCum = (int) ($values['female_cum'] ?? 0);
            $femaleNow = (int) ($values['female_now'] ?? 0);
            $sectorCum = $maleCum + $femaleCum;
            $sectorNow = $maleNow + $femaleNow;

            if ($sectorCum > (int) ($row['persons_cum'] ?? 0)) {
                return "{$meta['label']}: total CUM cannot exceed this EC Persons CUM (".(int) ($row['persons_cum'] ?? 0).').';
            }
            if ($sectorNow > (int) ($row['persons_now'] ?? 0)) {
                return "{$meta['label']}: total NOW cannot exceed this EC Persons NOW (".(int) ($row['persons_now'] ?? 0).').';
            }
            if ($meta['has_male'] && $maleCum > $maleAgeSexTotals['cum']) {
                return "{$meta['label']}: male CUM cannot exceed the male Age/Sex CUM total ({$maleAgeSexTotals['cum']}).";
            }
            if ($meta['has_male'] && $maleNow > $maleAgeSexTotals['now']) {
                return "{$meta['label']}: male NOW cannot exceed the male Age/Sex NOW total ({$maleAgeSexTotals['now']}).";
            }
            if ($femaleCum > $femaleAgeSexTotals['cum']) {
                return "{$meta['label']}: female CUM cannot exceed the female Age/Sex CUM total ({$femaleAgeSexTotals['cum']}).";
            }
            if ($femaleNow > $femaleAgeSexTotals['now']) {
                return "{$meta['label']}: female NOW cannot exceed the female Age/Sex NOW total ({$femaleAgeSexTotals['now']}).";
            }

            if (in_array($key, $stableSectoralGroups, true)
                && $meta['has_male']
                && $maleAgeSexTotals['cum'] === $maleAgeSexTotals['now']
                && $maleCum > 0
                && $maleNow !== $maleCum) {
                return "{$meta['label']}: male NOW must equal male CUM because the EC male Age/Sex population is unchanged at {$maleAgeSexTotals['cum']}.";
            }

            if (in_array($key, $stableSectoralGroups, true)
                && $femaleAgeSexTotals['cum'] === $femaleAgeSexTotals['now']
                && $femaleCum > 0
                && $femaleNow !== $femaleCum) {
                return "{$meta['label']}: female NOW must equal female CUM because the EC female Age/Sex population is unchanged at {$femaleAgeSexTotals['cum']}.";
            }
        }

        return '';
    }

    private function cumNowPairIssue(mixed $cum, mixed $now, string $label): string
    {
        $hasCum = $cum !== null && $cum !== '';
        $hasNow = $now !== null && $now !== '';

        if ($hasCum !== $hasNow) {
            return "{$label}: CUM and NOW must both be encoded. Enter 0 when there is no count.";
        }

        if (! $hasCum && ! $hasNow) {
            return '';
        }

        if (filter_var($cum, FILTER_VALIDATE_INT) === false
            || filter_var($now, FILTER_VALIDATE_INT) === false
            || (int) $cum < 0
            || (int) $now < 0) {
            return "{$label}: CUM and NOW must be whole numbers from 0 and above.";
        }

        $cum = (int) $cum;
        $now = (int) $now;

        if ($cum === 0 && $now > 0) {
            return "{$label}: CUM cannot be blank or 0 when NOW has a count. Encode the matching CUM value in the same row.";
        }

        if ($now > $cum) {
            return "{$label}: NOW cannot be greater than CUM.";
        }

        return '';
    }

    private function ensureMeaningfulTextInputs(array $data): void
    {
        $messages = [];

        foreach ([
            'narrative' => 'Situation Overview',
            'population_justification' => 'Population justification',
            'incident_type_other' => 'Other Type of Disaster / Incident',
        ] as $field => $label) {
            $this->collectUnclearText($messages, data_get($data, $field), $label);
        }

        $rowFields = [
            'related_incident_rows' => [
                'incident_type_other' => 'Other Type of Incident',
                'description' => 'Description',
                'actions_taken' => 'Actions Taken',
                'status' => 'Status',
            ],
            'casualty_rows' => [
                'last_name' => 'Last Name',
                'first_name' => 'First Name',
                'middle_name' => 'Middle Name',
                'cause' => 'Cause',
                'remarks' => 'Remarks',
                'source_of_data' => 'Source of Data',
            ],
            'infrastructure_damage_rows' => [
                'structure_type_other' => 'Other Type of Structure',
                'damage_description' => 'Damage Description',
                'remarks' => 'Remarks',
            ],
            'agriculture_damage_rows' => [
                'classification_other' => 'Other Classification',
                'type_other' => 'Other Type',
            ],
            'class_suspension_rows' => [
                'level_from_other' => 'Other Level From',
                'level_to_other' => 'Other Level To',
                'type_other' => 'Other Type',
                'remarks' => 'Remarks',
            ],
            'work_suspension_rows' => [
                'type_other' => 'Other Type',
                'remarks' => 'Remarks',
            ],
            'road_bridge_rows' => [
                'type_other' => 'Other Type',
                'classification_other' => 'Other Classification',
                'road_section' => 'Road Section',
                'remarks' => 'Remarks',
            ],
            'power_lifeline_rows' => [
                'type_other' => 'Other Type',
                'service_provider' => 'Service Provider',
                'remarks_status' => 'Remarks / Status',
            ],
            'water_lifeline_rows' => [
                'type_other' => 'Other Type',
                'service_provider' => 'Service Provider',
                'remarks_status' => 'Remarks / Status',
            ],
            'communication_lifeline_rows' => [
                'communication_status_other' => 'Other Communication Status',
                'service_provider' => 'Service Provider',
                'remarks' => 'Remarks',
            ],
            'seaport_rows' => [
                'name' => 'Name of Port',
                'status_other' => 'Other Port Status',
                'remarks' => 'Remarks',
            ],
            'airport_rows' => [
                'name' => 'Name of Airport',
                'status_other' => 'Other Airport Status',
                'remarks' => 'Remarks',
            ],
            'land_transport_terminal_rows' => [
                'name' => 'Name of Terminal',
                'status_other' => 'Other Terminal Status',
                'remarks' => 'Remarks',
            ],
            'stranded_transport_rows' => [
                'district' => 'District',
                'station' => 'Station',
                'port_terminal' => 'Port / Terminal',
                'vessel_bus_liner' => 'Vessel / Bus Liner',
                'remarks' => 'Remarks',
            ],
            'calamity_declaration_rows' => [
                'type_other' => 'Other Calamity Type',
                'resolution_number' => 'Resolution Number',
                'remarks' => 'Remarks',
            ],
            'preemptive_evacuation_rows' => [
                'remarks' => 'Remarks',
            ],
            'cluster_gap_rows' => [
                'cluster_other' => 'Other Cluster',
                'areas_of_concern' => 'Areas of Concern',
                'actions_undertaken' => 'Actions Undertaken',
                'status_remarks' => 'Status / Remarks',
            ],
            'response_action_rows' => [
                'acted_by_office_other' => 'Other Acted by',
                'action_intervention' => 'Response Action / Intervention',
            ],
            'photo_collage_rows' => [
                'heading' => 'Photo documentation caption',
            ],
        ];

        foreach ($rowFields as $rowsField => $fields) {
            foreach (($data[$rowsField] ?? []) as $rowIndex => $row) {
                foreach ($fields as $field => $label) {
                    $this->collectUnclearText($messages, data_get($row, $field), "{$label} on {$this->humanRowLabel($rowsField, (int) $rowIndex)}");
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['data_quality' => $messages]);
        }
    }

    private function validateSituationOverview(string $narrative): void
    {
        $narrative = trim(strip_tags($narrative));
        $placeholderPattern = '/\b(?:n\/?a|not\s+applicable|to\s+follow|to\s+be\s+(?:followed|advised|determined|updated)|tba|tbd|pending|none|no\s+data)\b/i';

        if ($narrative === '' || preg_match($placeholderPattern, $narrative)) {
            throw ValidationException::withMessages([
                'narrative' => 'Situation Overview cannot be blank or contain placeholders such as N/A, Not Applicable, To Follow, TBD, pending, or no data. Encode the actual validated situation.',
            ]);
        }

        if (mb_strlen($narrative) < 20 || ! preg_match('/[a-z]{3,}/i', $narrative) || ! $this->looksMeaningfulText($narrative)) {
            throw ValidationException::withMessages([
                'narrative' => 'Situation Overview needs a clear and meaningful description of the validated incident situation.',
            ]);
        }
    }

    private function collectUnclearText(array &$messages, mixed $value, string $label): void
    {
        if (blank($value)) {
            return;
        }

        $text = trim(strip_tags((string) $value));
        if ($text === '' || in_array(Str::lower($text), ['n/a', 'na', 'not applicable', 'none', '0'], true)) {
            return;
        }

        if (! $this->looksMeaningfulText($text)) {
            $messages[] = "{$label} needs a clearer entry. Avoid random letters; encode an understandable detail, estimate, or N/A if not applicable.";
        }
    }

    private function looksMeaningfulText(string $text): bool
    {
        $letters = preg_replace('/[^a-z]+/i', '', $text) ?? '';

        if (mb_strlen($text) < 3) {
            return false;
        }

        // Office/agency codes from controlled lists (LDRRMC, LSWDO, MDRRMO, etc.)
        // are valid even when they contain few or no vowels.
        if (preg_match('/^[A-Z][A-Z0-9.&\/-]{1,19}$/', $text)) {
            return true;
        }

        if ($letters !== '' && preg_match('/^(.)\1{2,}$/i', $letters)) {
            return false;
        }

        if (preg_match('/(?:asdf|qwer|zxcv|sdfsdf|dfsf|aaaa|bbbb|xxxxx|lorem ipsum)/i', $text)) {
            return false;
        }

        if (mb_strlen($letters) >= 5 && ! preg_match('/[aeiou]/i', $letters)) {
            return false;
        }

        if (str_word_count($text) === 1 && mb_strlen($letters) >= 8 && ! preg_match('/[aeiou]{1,}/i', $letters)) {
            return false;
        }

        return true;
    }

    private function humanRowLabel(string $rowsField, int $rowIndex): string
    {
        return Str::of($rowsField)
            ->replace('_rows', '')
            ->replace('_', ' ')
            ->headline()
            ->append(' row '.($rowIndex + 1))
            ->toString();
    }

    private function caragaAddressOptions(): array
    {
        $provinceCodes = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('parent_code', '1600000000')
                    ->orWhere('code', 'like', '16%');
            })
            ->orderBy('name')
            ->pluck('code');

        $cityCodes = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->whereIn('parent_code', $provinceCodes)
            ->orderBy('name')
            ->pluck('code');

        return [
            'provinces' => PsgcAddress::query()
                ->whereIn('code', $provinceCodes)
                ->orderBy('name')
                ->get(['code', 'name'])
                ->values(),
            'cities' => PsgcAddress::query()
                ->whereIn('code', $cityCodes)
                ->orderBy('name')
                ->get(['code', 'parent_code', 'name'])
                ->values(),
            'barangays' => PsgcAddress::query()
                ->where('level', 'barangay')
                ->where('is_active', true)
                ->whereIn('parent_code', $cityCodes)
                ->orderBy('name')
                ->get(['code', 'parent_code', 'name'])
                ->values(),
        ];
    }

    private function situationOverviewSystemPrompt(): string
    {
        return <<<'PROMPT'
Write as the LGU employee responsible for the current DROMIC/Situational Report. Sound human, direct, observant, and professional. Treat the encoded form data and readable text extracted from pasted warning-agency screenshots as the only sources of truth. Never invent, infer, or complete missing facts. Never treat a forecast, warning, or hazard statement as an observed local impact. Never mix PAGASA/PHIVOLCS figures with LGU-validated affected-population figures.

Before writing, test whether the existing Situation Overview and the encoded facts are coherent. Reject any draft that is merely N/A, NA, Not Applicable, To Follow, TBA, TBD, pending, none, no data, random text, generic filler, or another placeholder. Also reject a draft that materially contradicts the encoded incident type, incident status, dates, affected-population totals, displacement totals, damage, casualties, assistance, gaps, or response actions. Do not silently turn contradictory or meaningless input into plausible official prose. In either case, return exactly: NEEDS_USER_INPUT: Please replace the placeholder or correct the Situation Overview so it agrees with the encoded report data. When generating without an existing draft, use only coherent supported facts and omit unavailable facts.

On the first mention of any acronym, write its complete official term followed by the acronym in parentheses, for example "Local Government Unit (LGU)." Use only the acronym on every succeeding mention, and never spell out the same acronym more than once. Never write "as reported by the local government unit," "as reported by the LGU," or an equivalent attribution to the reporting LGU. Instead, use that sentence to elaborate on the actual local hazard, observed condition, effect on the community, or ongoing response supported by the encoded facts.

Return exactly four narrative paragraphs, separated by one blank line, with no heading, numbering, bullets, source list, URLs, field labels, or markdown:

Paragraph 1 — Current incident situation. For a weather disturbance, describe only the weather conditions, forecast area, warning, or weather system that applies to the reporting LGU, its province, Caraga Region, or Mindanao when the excerpt clearly includes the LGU's area. Omit conditions, forecasts, cyclone positions, wind speeds, movement, and other details concerning Luzon, Visayas, Metro Manila, or another area outside Caraga unless the encoded advisory explicitly connects that information to a direct effect or forecast for the reporting LGU. A tropical cyclone outside the Philippine Area of Responsibility is not automatically relevant; include it only when the supplied excerpt states that its trough, circulation, or another identified influence is affecting Caraga or the LGU. For an earthquake, describe the earthquake information using the supplied DOST-PHIVOLCS screenshot excerpt, retaining only visible/supplied magnitude, depth, epicentral location, date, time, and intensity applicable to or felt in the LGU; an epicenter outside Caraga may be named when it identifies the same earthquake experienced by the LGU. For another disaster, describe the incident information or present situation supported by its screenshot excerpt or encoded incident facts. Attribute the agency naturally once when its usable excerpt exists.

Paragraph 2 — Summarized LGU-validated effects. State only the available municipality/city-wide totals for affected families and persons, displaced families and persons inside or outside evacuation centers, damaged houses, casualties, and other material effects. Present these totals directly and naturally in one or two concise sentences. Never name, list, or enumerate the affected barangays. If useful, state only the total number of affected barangays, such as "across nine barangays." Do not say that people are spread across named barangays, provide barangay-level breakdowns, recite table rows, compare data types, calculate percentages, interpret trends, or analyze why one count differs from another. Never write phrases such as "the exact breakdown is not specified," "with the breakdown not included in this summary," "the LGU has reported these numbers," "as part of its response efforts," "based on the encoded data," or any similar commentary about missing detail, data provenance, or report preparation. Omit any total that was not encoded.

Paragraph 3 — Challenges, gaps, and response. Briefly summarize only the challenges, unmet needs, service or cluster gaps, access/lifeline concerns, and other issues actually encoded in the appropriate form sections. When none are supplied, begin directly with the recorded response actions and never mention the absence of challenge or gap data. It is strictly forbidden to write "no specific challenges or gaps were encoded," "no challenges were reported," "no gaps were identified," "no information was provided," or any similar missing-data statement. In the same paragraph, summarize the response actions, interventions, and assistance actually provided or underway by the LGU and any partner agencies explicitly named in the encoded data. For a Terminal Report or First and Final Report, treat every encoded response action as completed: use past-tense verbs such as conducted, coordinated, distributed, assessed, assisted, or completed, and never say that an action is ongoing, underway, continuing, planned, or still to be done. For a Regular Report, distinguish completed actions from actions explicitly encoded as ongoing. Do not invent a challenge, partner, coordination activity, assistance item, or completed response.

Paragraph 4 — Report conclusion. For a Regular Report, conclude in the LGU's voice with its continuing commitment to act promptly, sustain response operations, address immediate needs, monitor and validate conditions, and coordinate with appropriate partner agencies, without claiming unsupported assistance or approval. For a Terminal Report or First and Final Report, do not promise future or continuing response work. Instead, close in past tense by concisely confirming that the encoded actions and interventions were completed and that the report presents the final validated situation. Do not claim that every need was resolved, that recovery was complete, or that unencoded assistance or agency support occurred.

Use readable whole numbers with thousands separators and proper units. Never discuss whether a screenshot, source, section, or value was readable, unreadable, timed out, missing, unavailable, unspecified, unencoded, or not provided; omit it silently. Do not include reporting cut-off times, information-received times, reporter names, form-completion details, database language, or repetitive conclusions. Each fact or idea may appear in only one paragraph. When data is limited, keep the applicable paragraph concise rather than padding it or borrowing content from another paragraph.
PROMPT;
    }

    private function situationParagraphCount(string $narrative): int
    {
        return count(array_values(array_filter(
            preg_split('/\R\s*\R/u', trim($narrative)) ?: [],
            fn (string $paragraph): bool => filled(trim($paragraph)),
        )));
    }

    private function situationOverviewNeedsCorrection(string $narrative, string $facts): bool
    {
        if ($this->situationParagraphCount($narrative) !== 4) {
            return true;
        }

        $lowerNarrative = Str::lower($narrative);
        $metaCommentary = [
            'exact breakdown',
            'breakdown is not specified',
            'breakdown not specified',
            'not included in this summary',
            'not specified in this summary',
            'reported these numbers',
            'as reported by the local government unit',
            'as reported by the lgu',
            'based on the encoded data',
            'part of its response efforts',
            'spread across several barangays',
            'spread across the barangays',
            'no specific challenges',
            'no challenges were',
            'no gaps were',
            'not been encoded',
            'were encoded',
            'was encoded',
            'not readable',
            'unreadable',
            'could not be read',
            'timed out',
            'not provided',
            'no information was',
        ];
        if (Str::contains($lowerNarrative, $metaCommentary)) {
            return true;
        }

        $closedReport = Str::contains(Str::lower($facts), [
            'report classification: terminal',
            'report classification: first_and_final',
        ]);
        if ($closedReport && preg_match('/\b(?:continues?|continuing|ongoing|underway|will\s+(?:continue|conduct|coordinate|provide|monitor)|is\s+(?:conducting|coordinating|providing|monitoring)|are\s+(?:conducting|coordinating|providing|monitoring)|plans?\s+to|still\s+to\s+be)\b/i', $narrative)) {
            return true;
        }

        if (preg_match('/\bbarangays?\s+(?:of|namely|including|such as)\b/i', $narrative)) {
            return true;
        }

        $weatherIncident = Str::contains(Str::lower($facts), [
            'weather', 'typhoon', 'tropical cyclone', 'tropical depression', 'tropical storm',
            'rain', 'thunderstorm', 'monsoon', 'habagat', 'amihan', 'flood',
        ]);

        return $weatherIncident && Str::contains($lowerNarrative, ['luzon', 'visayas', 'metro manila']);
    }

    private function barangayOptionsWithPopulation(?string $cityMunicipalityCode)
    {
        if (! $cityMunicipalityCode) {
            return [];
        }

        $barangays = PsgcAddress::query()
            ->where('level', 'barangay')
            ->where('parent_code', $cityMunicipalityCode)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name']);

        $populations = BarangayPopulation::query()
            ->where('census_year', 2024)
            ->whereIn('barangay_psgc_code', $barangays->pluck('code'))
            ->pluck('population', 'barangay_psgc_code');

        return $barangays
            ->map(fn (PsgcAddress $barangay): array => [
                'value' => $barangay->name,
                'label' => $barangay->name,
                'code' => $barangay->code,
                'psgc_code' => $barangay->code,
                'psa_2024' => (int) ($populations[$barangay->code] ?? 0),
            ])
            ->values();
    }

    private function isProvinceLgu(?string $level): bool
    {
        return in_array(strtoupper((string) $level), ['PROVINCE', 'PLGU'], true);
    }

    private function resolveLguProvince($user): ?string
    {
        if (! $user) {
            return null;
        }
        if ($this->isProvinceLgu($user->lgu_level)) {
            return $user->lgu_name ?: $user->area_of_assignment;
        }

        $assignment = trim((string) $user->area_of_assignment);
        if (str_contains($assignment, ',')) {
            return trim(Str::afterLast($assignment, ','));
        }

        return null;
    }

    private function dromicReportProfile(AssistanceRequest $report): array
    {
        $directory = LguDirectoryEntry::query()
            ->with(['officials', 'ldrrmoOfficers'])
            ->when(
                filled($report->lgu_psgc_code),
                fn ($query) => $query->where('psgc_code', $report->lgu_psgc_code),
                fn ($query) => $query->where(fn ($match) => $match
                    ->where('lgu_name', $report->lgu)
                    ->orWhere('override_lgu_name', $report->lgu)),
            )
            ->first();
        $officials = $directory?->officials?->keyBy('role') ?? collect();
        $official = static function (string $role) use ($officials): array {
            $row = $officials->get($role);

            return [
                'name' => $row?->override_name ?: $row?->name,
                'position' => $row?->override_position_designation ?: $row?->position_designation,
            ];
        };
        $primaryLdrrmo = $directory?->ldrrmoOfficers?->firstWhere('is_primary', true)
            ?: $directory?->ldrrmoOfficers?->first();

        $withDesignationFallback = static function (array $signatory, string $fallback): array {
            $position = trim((string) ($signatory['position'] ?? ''));

            if ($position === '' || in_array(Str::lower($position), ['-', '–', '—', 'n/a', 'na', 'none', 'not applicable'], true)) {
                $signatory['position'] = $fallback;
            }

            return $signatory;
        };

        return [
            'logos' => [
                'lgu' => $this->storedImageDataUri($directory?->lgu_logo_path),
                'dromic' => $this->localImageDataUri(public_path('images/dromic-logo.png')),
                'ldrrmc' => $this->storedImageDataUri($directory?->ldrrmc_logo_path),
            ],
            'signatories' => [
                'lswdo' => $withDesignationFallback($official('lswd_officer'), 'LSWDO'),
                'ldrrmo' => $withDesignationFallback([
                    'name' => $primaryLdrrmo?->name ?: $directory?->ldrrmo_name,
                    'position' => $primaryLdrrmo?->designation ?: $directory?->ldrrmo_position,
                ], 'LDRRMO'),
                'lce' => $withDesignationFallback($official('lce'), 'LCE'),
            ],
        ];
    }

    private function storedImageDataUri(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return 'data:'.(Storage::disk('public')->mimeType($path) ?: 'image/png').';base64,'
            .base64_encode(Storage::disk('public')->get($path));
    }

    private function localImageDataUri(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
    }

    private function resolveDromicSeriesKey(array $data, $user): string
    {
        if (filled($data['report_series_key'] ?? null)) {
            return (string) $data['report_series_key'];
        }

        $identity = collect([
            $user->lgu_psgc_code ?: $user->lgu_name ?: $user->id,
            $data['incident_type'] ?? $data['incident_name'] ?? 'incident',
            $data['incident_specific_details'] ?? '',
            Str::before((string) ($data['occurrence_started_at'] ?? $data['incident_date'] ?? now()->toDateString()), 'T'),
        ])
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->implode('|');

        return hash('sha256', $identity);
    }

    private function retainSeriesIncidentIdentity(
        array $data,
        string $seriesKey,
        ?string $lguPsgcCode = null,
        ?AssistanceRequest $current = null,
    ): array {
        $reports = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_dromic_series_key', $seriesKey)
            ->when(filled($lguPsgcCode), fn ($query) => $query->where('lgu_psgc_code', $lguPsgcCode))
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->oldest('created_at')
            ->oldest('id')
            ->get(['lgu_dromic_payload'])
            ->map(fn (AssistanceRequest $report) => $report->lgu_dromic_payload ?? []);

        if ($reports->isEmpty()) {
            return $data;
        }

        $firstReport = $reports->first();
        foreach (['incident_type', 'incident_specific_details', 'occurrence_started_at', 'information_received_at'] as $field) {
            $data[$field] = data_get($firstReport, $field);
        }

        $firstEndedAt = $reports
            ->pluck('incident_ended_at')
            ->first(fn ($timestamp) => filled($timestamp));
        if (filled($firstEndedAt)) {
            $data['incident_ended_at'] = $firstEndedAt;
        }

        return $data;
    }

    private function validateUnchangedEcSectoralData(
        array $data,
        string $seriesKey,
        ?string $lguPsgcCode = null,
        ?AssistanceRequest $current = null,
    ): void {
        $previous = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_dromic_series_key', $seriesKey)
            ->when(filled($lguPsgcCode), fn ($query) => $query->where('lgu_psgc_code', $lguPsgcCode))
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->latest('created_at')
            ->latest('id')
            ->first(['lgu_dromic_payload']);

        if (! $previous) {
            return;
        }

        $previousRows = collect(data_get($previous->lgu_dromic_payload, 'evacuation_center_rows', []))
            ->groupBy(fn (array $row): string => $this->evacuationCenterComparisonKey($row));

        foreach (data_get($data, 'evacuation_center_rows', []) as $index => $row) {
            $comparisonKey = $this->evacuationCenterComparisonKey($row);
            $previousRow = $previousRows->get($comparisonKey)?->shift();

            if (! $previousRow) {
                continue;
            }

            $totalsUnchanged = collect(['families_cum', 'families_now', 'persons_cum', 'persons_now'])
                ->every(fn (string $field): bool => (int) data_get($row, $field, 0) === (int) data_get($previousRow, $field, 0));

            if ($totalsUnchanged
                && $this->normalizedSectoralData($row) !== $this->normalizedSectoralData($previousRow)) {
                throw ValidationException::withMessages([
                    "evacuation_center_rows.{$index}.disaggregation" => 'Sectoral Group data cannot be changed while this evacuation center’s Families and Persons CUM/NOW totals are unchanged. Restore the previous sectoral values, or update the EC totals first when validated population figures have changed.',
                ]);
            }
        }
    }

    private function evacuationCenterComparisonKey(array $row): string
    {
        return collect([
            data_get($row, 'barangay_address_code') ?: data_get($row, 'barangay_address'),
            data_get($row, 'evacuation_center'),
            data_get($row, 'barangay_origin_code') ?: data_get($row, 'barangay_origin'),
        ])->map(fn ($value): string => Str::lower(trim((string) $value)))->implode('|');
    }

    private function normalizedSectoralData(array $row): array
    {
        $groups = [
            'pwds' => true,
            'child_headed_family' => true,
            'single_headed_family' => true,
            'solo_parent' => true,
            'pregnant_women' => false,
            'lactating_mothers' => false,
            'four_ps' => true,
            'indigenous_people' => true,
        ];

        return collect($groups)->mapWithKeys(function (bool $hasMale, string $key) use ($row): array {
            $values = (array) data_get($row, "disaggregation.sectoral.{$key}", []);

            return [$key => [
                'male_cum' => $hasMale ? (int) ($values['male_cum'] ?? 0) : null,
                'male_now' => $hasMale ? (int) ($values['male_now'] ?? 0) : null,
                'female_cum' => (int) ($values['female_cum'] ?? 0),
                'female_now' => (int) ($values['female_now'] ?? 0),
            ]];
        })->all();
    }

    private function resolveDromicLifecycle(
        array $data,
        string $seriesKey,
        bool $isDraft,
        ?AssistanceRequest $current = null,
        ?string $lguPsgcCode = null,
    ): array {
        if ($current?->lgu_correction_of_id) {
            $source = AssistanceRequest::query()->find($current->lgu_correction_of_id);
            return [
                'classification' => $source?->lgu_dromic_report_classification ?: $current->lgu_dromic_report_classification ?: 'regular',
                'report_number' => $source?->lgu_dromic_report_number,
                'terminates_series' => ! $isDraft && in_array($source?->lgu_dromic_report_classification ?: $current->lgu_dromic_report_classification, ['terminal', 'first_and_final'], true),
            ];
        }
        $classification = (string) ($data['report_classification'] ?? 'regular');
        $series = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_dromic_series_key', $seriesKey)
            ->when(filled($lguPsgcCode), fn ($query) => $query->where('lgu_psgc_code', $lguPsgcCode))
            ->when($current, fn ($query) => $query->whereKeyNot($current->id));

        $finalized = (clone $series)
            ->where(fn ($query) => $query->whereNull('lgu_report_status')->orWhere('lgu_report_status', '!=', 'draft'));
        $finalizedCount = $finalized->count();
        $alreadyTerminal = (clone $finalized)
            ->whereIn('lgu_dromic_report_classification', ['terminal', 'first_and_final'])
            ->exists();
        $pendingVersion = (clone $series)
            ->whereIn('lgu_report_status', ['draft', 'final'])
            ->exists();

        if ($alreadyTerminal) {
            throw ValidationException::withMessages([
                'report_classification' => 'Reporting for this incident has already been terminated. No additional draft or final report can be created.',
            ]);
        }

        if (! $current && $pendingVersion) {
            throw ValidationException::withMessages([
                'incident_name' => 'This incident already has a draft or a final report awaiting submission. Edit or submit that version before creating another report for the same incident.',
            ]);
        }

        if (! $isDraft && in_array($classification, ['first_and_final', 'terminal'], true) && ($data['incident_status'] ?? null) !== 'Ended') {
            throw ValidationException::withMessages([
                'report_classification' => 'First and Final or Terminal reporting is available only when Status of Incident is Ended.',
            ]);
        }

        if (! $isDraft && $classification === 'first_and_final' && $finalizedCount !== 0) {
            throw ValidationException::withMessages([
                'report_classification' => 'First and Final Report is available only when no earlier report has been finalized for this incident.',
            ]);
        }

        if (! $isDraft && $classification === 'terminal' && $finalizedCount < 1) {
            throw ValidationException::withMessages([
                'report_classification' => 'A Terminal Report must follow at least one earlier finalized report for the same incident.',
            ]);
        }

        return [
            'classification' => $classification,
            'report_number' => $isDraft ? null : ((int) $finalized->max('lgu_dromic_report_number')) + 1,
            'terminates_series' => ! $isDraft && in_array($classification, ['terminal', 'first_and_final'], true),
        ];
    }

    private function restrictCorrectionPayload(array $data, AssistanceRequest $current): array
    {
        if (! $current->lgu_correction_of_id) {
            return $data;
        }

        $source = AssistanceRequest::query()->findOrFail($current->lgu_correction_of_id);
        $original = (array) $source->lgu_dromic_payload;
        if ($current->lgu_correction_target === 'request') {
            foreach (['has_relief_request', 'relief_requested', 'requested_fni_items'] as $key) {
                $original[$key] = $data[$key] ?? $original[$key] ?? null;
            }
            $original['submission_status'] = $data['submission_status'] ?? 'draft';

            return $original;
        }

        $data['has_relief_request'] = false;
        $data['relief_requested'] = '';
        $data['requested_fni_items'] = [];

        return $data;
    }

    private function authorizeLguOwner(Request $request, AssistanceRequest $assistanceRequest): void
    {
        abort_unless($assistanceRequest->submission_type === 'lgu_dromic_relief_request', 404);
        $user = $request->user();
        $samePsgc = filled($user->lgu_psgc_code)
            && filled($assistanceRequest->lgu_psgc_code)
            && $user->lgu_psgc_code === $assistanceRequest->lgu_psgc_code;
        $sameLegacyLgu = blank($assistanceRequest->lgu_psgc_code)
            && filled($user->lgu_name)
            && in_array($user->lgu_name, [$assistanceRequest->requesting_agency, $assistanceRequest->lgu], true);
        abort_unless(
            $assistanceRequest->lgu_submitted_by === $user->id
            || $assistanceRequest->encoded_by === $user->id
            || $samePsgc
            || $sameLegacyLgu,
            403,
        );
    }

    private function safeInlinePdfFilename(string $filename): string
    {
        $cleaned = basename(trim(str_replace(["\r", "\n", '"', '/', '\\'], '', $filename)));
        if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
            return 'document.pdf';
        }

        return str_ends_with(Str::lower($cleaned), '.pdf') ? $cleaned : "{$cleaned}.pdf";
    }

    private function inlineContentDisposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'document.pdf';

        return 'inline; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }

    private function authorizeDromicViewer(Request $request, AssistanceRequest $assistanceRequest): void
    {
        $user = $request->user();
        $canViewAsPlgu = $this->isProvinceLgu($user->lgu_level)
            && $assistanceRequest->province === $user->lgu_name;
        $isOriginatingLgu = $assistanceRequest->lgu_submitted_by === $user->id
            || $assistanceRequest->encoded_by === $user->id
            || $canViewAsPlgu;
        $canViewAsDswd = filled($assistanceRequest->lgu_submitted_to_dswd_at)
            && (
                $user->can('route lgu dromic requests')
                || $user->can('monitor requests')
                || $user->can('process requests')
                || $user->can('manage regional alerts')
            );

        abort_unless($isOriginatingLgu || $canViewAsDswd, 403);
    }

    private function syncRequestedFniItems(AssistanceRequest $request, array $data): void
    {
        $rows = (bool) ($data['has_relief_request'] ?? false)
            ? collect($data['requested_fni_items'] ?? [])
            : collect();
        $fniItemIds = [];

        foreach ($rows as $row) {
            $fniItemId = (int) ($row['fni_library_item_id'] ?? 0);
            if ($fniItemId < 1) {
                continue;
            }
            if (blank($row['requested_quantity'] ?? null)) {
                continue;
            }

            $fniItemIds[] = $fniItemId;
            LguDromicRequestedItem::query()->updateOrCreate(
                [
                    'request_id' => $request->id,
                    'fni_library_item_id' => $fniItemId,
                ],
                [
                    'requested_quantity' => $row['requested_quantity'],
                ],
            );
        }

        LguDromicRequestedItem::query()
            ->where('request_id', $request->id)
            ->when(
                $fniItemIds !== [],
                fn ($query) => $query->whereNotIn('fni_library_item_id', $fniItemIds),
            )
            ->delete();
    }

    private function withRequestedFniItemDetails(array $data): array
    {
        if (! (bool) ($data['has_relief_request'] ?? false)) {
            $data['requested_fni_items'] = [];

            return $data;
        }

        $rows = collect($data['requested_fni_items'] ?? []);
        if ($rows->isEmpty()) {
            $data['requested_fni_items'] = [];

            return $data;
        }

        $library = FniLibraryItem::query()
            ->whereIn('id', $rows->pluck('fni_library_item_id')->filter())
            ->get()
            ->keyBy('id');
        $data['requested_fni_items'] = $rows
            ->map(function (array $row) use ($library): array {
                $item = $library->get((int) $row['fni_library_item_id']);

                return [
                    'fni_library_item_id' => (int) $row['fni_library_item_id'],
                    'item_category' => $item?->item_category,
                    'item_name' => $item?->item_name,
                    'brand_description' => $item?->brand_description,
                    'unit_of_measure' => $item?->unit_of_measure,
                    'requested_quantity' => $row['requested_quantity'],
                ];
            })
            ->values()
            ->all();

        return $data;
    }

    private function completeAdvanceCopyWhenReady(AssistanceRequest $assistanceRequest, WorkflowNotificationService $notifications): void
    {
        if ($assistanceRequest->lgu_report_status !== 'advance_submitted') {
            return;
        }

        $hasReliefRequest = (bool) data_get($assistanceRequest->lgu_dromic_payload, 'has_relief_request');
        $signedComplete = filled($assistanceRequest->lgu_signed_report_path)
            && (! $hasReliefRequest || filled($assistanceRequest->lgu_signed_request_path));

        if (! $signedComplete) {
            return;
        }

        $assistanceRequest->update([
            'status' => 'submitted_with_signed_copies',
            'lgu_report_status' => 'submitted',
            'lgu_routing_status' => $hasReliefRequest ? 'for_drmd_aa_review' : $assistanceRequest->lgu_routing_status,
            'completed_at' => now(),
            'lgu_signed_copy_reminder_sent_at' => null,
        ]);
        $notifications->notifyLguSignedCopiesCompleted($assistanceRequest->fresh(['encoder', 'lguSubmitter']));
    }

    private function sanitizedEncodedPayload(array $payload): array
    {
        foreach (($payload['official_advisory_rows'] ?? []) as $index => $advisory) {
            unset($payload['official_advisory_rows'][$index]['screenshot_data_url']);
            $payload['official_advisory_rows'][$index]['screenshot_attached'] = filled($advisory['screenshot_data_url'] ?? null);
        }
        foreach (($payload['photo_rows'] ?? []) as $index => $photo) {
            unset($payload['photo_rows'][$index]['data_url'], $payload['photo_rows'][$index]['image_data_url']);
            $payload['photo_rows'][$index]['image_attached'] = filled($photo['data_url'] ?? $photo['image_data_url'] ?? null);
        }
        foreach (($payload['photo_collage_rows'] ?? []) as $index => $collage) {
            unset($payload['photo_collage_rows'][$index]['data_url'], $payload['photo_collage_rows'][$index]['image_data_url']);
            $payload['photo_collage_rows'][$index]['image_attached'] = filled($collage['data_url'] ?? $collage['image_data_url'] ?? null);
        }

        return $payload;
    }
}
