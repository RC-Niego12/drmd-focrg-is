<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\OperationalLibraryValue;
use App\Services\EpirmaDocumentStatusService;
use App\Services\EpirmaWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DrrsAaEpirmaController extends Controller
{
    public function __construct(
        private EpirmaWorkflowService $workflowService,
        private EpirmaDocumentStatusService $statusService,
    ) {}

    public function index(Request $request): Response
    {
        // Local Herd often has no schedule:work daemon. Opportunistically poll open
        // e-PIRMA docs at most once per minute so cancel/404 detection still lands.
        $this->maybeSyncOpenDocuments();

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'active'));
        // Exactly two tabs: active (pending + in_progress) and completed.
        if (! in_array($status, ['active', 'completed'], true)) {
            $status = 'active';
        }

        $query = AssistanceRequest::query()
            ->with([
                'incident:id,name',
                'encoder:id,name',
                'epirmaForwarder:id,name',
                'epirmaSignedDocuments',
                'assessmentActor:id,name',
            ])
            ->whereNotNull('epirma_forwarded_to_drrs_aa_at');

        if ($status === 'completed') {
            $query->where('epirma_aa_status', 'completed');
        } else {
            $query->whereIn('epirma_aa_status', ['pending', 'in_progress']);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('requesting_agency', 'like', "%{$search}%");
            });
        }

        $items = $query
            ->orderByDesc('epirma_forwarded_to_drrs_aa_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (AssistanceRequest $record) use ($request): array {
                $caps = $this->workflowService->capabilitiesFor($record, $request->user());
                $assessmentDoc = $caps['assessment']['route_document'] ?? null;
                $responseDoc = $caps['response_letter']['route_document'] ?? null;
                $docs = collect($caps['documents'] ?? []);

                $assessmentPdfUrl = ($assessmentDoc['app_view_url'] ?? null)
                    ?: url("/requests/{$record->id}/assessment-pdf?inline=1");
                $responsePdfUrl = ($responseDoc['app_view_url'] ?? null)
                    ?: url("/requests/{$record->id}/response-letter-pdf?inline=1");

                return [
                    'id' => $record->id,
                    'reference_number' => $record->reference_number,
                    'submission_type' => $record->submission_type,
                    'proposal_type' => $record->proposal_type,
                    'date_received_by_drmd' => $record->date_received_by_drmd?->toDateString(),
                    'date_requested' => $record->date_requested?->toDateString(),
                    'request_drn' => $record->request_drn,
                    'requesting_agency' => $record->requesting_agency,
                    'office_agency_details' => $record->office_agency_details,
                    'purpose' => $record->purpose,
                    'incident_details' => $record->incident_details,
                    'assessment_form_data' => $record->assessment_form_data,
                    'incident' => $record->incident?->only(['id', 'name']),
                    'assessment_status' => $record->assessment_status,
                    'assessment_drn' => $record->assessment_drn,
                    'response_drn' => $record->response_drn,
                    'epirma_aa_status' => $record->epirma_aa_status,
                    'is_completed' => (string) $record->epirma_aa_status === 'completed',
                    'forwarded_at' => $record->epirma_forwarded_to_drrs_aa_at?->toIso8601String(),
                    'forwarded_by' => $record->epirmaForwarder?->only(['id', 'name']),
                    'acted_by' => $record->assessmentActor?->only(['id', 'name']),
                    'assessment_signed_at' => $record->epirma_assessment_signed_at?->toIso8601String(),
                    'response_letter_signed_at' => $record->epirma_response_letter_signed_at?->toIso8601String(),
                    'lgu_acked_at' => $record->lgu_response_letter_acked_at?->toIso8601String(),
                    'capabilities' => [
                        'assessment' => $caps['assessment'],
                        'response_letter' => $caps['response_letter'],
                    ],
                    'documents' => $docs->values()->all(),
                    'assessment_preview_url' => route('requests.assessment', $record),
                    'assessment_pdf_url' => $assessmentPdfUrl,
                    'response_pdf_url' => $responsePdfUrl,
                    'assessment_preview_kind' => ($assessmentDoc['preview_kind'] ?? null) ?: 'draft',
                    'response_preview_kind' => ($responseDoc['preview_kind'] ?? null) ?: 'draft',
                ];
            });

        $forwardedBase = AssistanceRequest::query()->whereNotNull('epirma_forwarded_to_drrs_aa_at');

        return Inertia::render('DrrsAa/Epirma/Index', [
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'queue' => $items,
            'drnPrefixes' => OperationalLibraryValue::query()
                ->where('library_type', 'drn_prefix')
                ->where('is_active', true)
                ->orderBy('context')
                ->orderBy('value')
                ->get(['id', 'value', 'context']),
            'summary' => [
                'pending' => (clone $forwardedBase)->where('epirma_aa_status', 'pending')->count(),
                'in_progress' => (clone $forwardedBase)->where('epirma_aa_status', 'in_progress')->count(),
                'completed' => (clone $forwardedBase)->where('epirma_aa_status', 'completed')->count(),
            ],
        ]);
    }

    private function maybeSyncOpenDocuments(): void
    {
        $lock = Cache::lock('epirma.sync_open.drrs_aa_index', 50);
        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::has('epirma.sync_open.drrs_aa_index.ran')) {
                return;
            }

            $this->statusService->syncOpenDocuments(40, [
                EpirmaSignedDocument::TYPE_ASSESSMENT,
                EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
            ]);
            Cache::put('epirma.sync_open.drrs_aa_index.ran', 1, now()->addMinute());
        } catch (Throwable $e) {
            report($e);
        } finally {
            optional($lock)->release();
        }
    }

    public function sync(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        abort_unless(filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at), 404);

        // Sync from DB (not Track list): cancelled rows are omitted from Track history
        // but recent cancels still need polling for false-cancel recovery.
        EpirmaSignedDocument::query()
            ->where('assistance_request_id', $assistanceRequest->id)
            ->whereIn('document_type', [
                EpirmaSignedDocument::TYPE_ASSESSMENT,
                EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
            ])
            ->whereNotNull('document_uuid')
            ->orderByDesc('id')
            ->get()
            ->each(function (EpirmaSignedDocument $document): void {
                $isRecentCancel = (string) $document->routing_status === EpirmaSignedDocument::STATUS_CANCELLED
                    && $document->updated_at
                    && $document->updated_at->greaterThanOrEqualTo(now()->subDays(2));

                if ($document->isOpen() || $isRecentCancel) {
                    $this->statusService->syncDocument($document, true);
                }
            });

        return response()->json([
            'success' => true,
            'data' => $this->workflowService->capabilitiesFor($assistanceRequest->fresh(), $request->user()),
        ]);
    }

    /**
     * Soft-sync open e-PIRMA documents for one or more queue rows (used by client auto-sync).
     */
    public function syncOpen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:30'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $synced = 0;
        $changedRequestIds = [];

        AssistanceRequest::query()
            ->whereIn('id', $ids)
            ->whereNotNull('epirma_forwarded_to_drrs_aa_at')
            ->with('epirmaSignedDocuments')
            ->get()
            ->each(function (AssistanceRequest $record) use (&$synced, &$changedRequestIds): void {
                $record->epirmaSignedDocuments
                    ->whereIn('document_type', [
                        EpirmaSignedDocument::TYPE_ASSESSMENT,
                        EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
                    ])
                    ->filter(function (EpirmaSignedDocument $document): bool {
                        if (! filled($document->document_uuid)) {
                            return false;
                        }
                        if ($document->isOpen()) {
                            return true;
                        }

                        // Recover false-positive cancels while the remote route is still live.
                        return (string) $document->routing_status === EpirmaSignedDocument::STATUS_CANCELLED
                            && $document->updated_at
                            && $document->updated_at->greaterThanOrEqualTo(now()->subDays(2));
                    })
                    ->each(function (EpirmaSignedDocument $document) use (&$synced, &$changedRequestIds, $record): void {
                        $before = (string) ($document->routing_status ?? '');
                        $result = $this->statusService->syncDocument($document, true);
                        $synced++;
                        $after = (string) (($result['data']['routing_status'] ?? null) ?: $document->fresh()?->routing_status ?: $before);
                        if ($before !== $after) {
                            $changedRequestIds[$record->id] = true;
                        }
                    });
            });

        return response()->json([
            'success' => true,
            'synced' => $synced,
            'changed' => count($changedRequestIds) > 0,
            'changed_request_ids' => array_map('intval', array_keys($changedRequestIds)),
        ]);
    }
}
