<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Services\EpirmaDocumentStatusService;
use App\Services\PdfPageExtractService;
use App\Services\RealtimePublisher;
use App\Services\WorkflowNotificationService;
use App\Support\InlinePdfFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LguResponseLetterController extends Controller
{
    public function __construct(
        private EpirmaDocumentStatusService $statusService,
        private PdfPageExtractService $pdfPages,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user?->hasRole('LGU') || $user?->hasRole('Super Admin'), 403);

        $psgc = (string) ($user->lgu_psgc_code ?? '');

        $letters = AssistanceRequest::query()
            ->with([
                'incident:id,name',
                'lguResponseLetterAcker:id,name',
                'lguResponseLetterAdvanceAcker:id,name',
                'sourceLguDromicReport:id,lgu_psgc_code,lgu_submitted_by,requesting_agency',
                'epirmaSignedDocuments' => function ($query): void {
                    $query->where('document_type', EpirmaSignedDocument::TYPE_RESPONSE_LETTER)
                        ->where('action', EpirmaSignedDocument::ACTION_ROUTE)
                        ->where('routing_status', EpirmaSignedDocument::STATUS_SIGNED)
                        ->where(function ($artifact): void {
                            $artifact->whereNotNull('remote_document_url')
                                ->orWhereNotNull('remote_base_path')
                                ->orWhere('document_path', 'like', EpirmaDocumentStatusService::SIGNED_CACHE_DIR.'%');
                        })
                        ->latest('id');
                },
            ])
            ->where(function ($query): void {
                $query->whereNotNull('lgu_response_letter_advance_sent_at')
                    ->orWhere(function ($signed): void {
                        $signed->whereNotNull('epirma_response_letter_signed_at')
                            ->whereNotNull('lgu_response_letter_sent_at');
                    });
            })
            ->where(function ($query) use ($user, $psgc): void {
                $query->where('lgu_psgc_code', $psgc)
                    ->orWhereHas('sourceLguDromicReport', function ($source) use ($user, $psgc): void {
                        $source->where('lgu_submitted_by', $user->id);
                        if ($psgc !== '') {
                            $source->orWhere('lgu_psgc_code', $psgc);
                        }
                    })
                    ->orWhere('lgu_submitted_by', $user->id);
            })
            ->orderByDesc('lgu_response_letter_advance_sent_at')
            ->orderByDesc('lgu_response_letter_sent_at')
            ->get()
            ->map(function (AssistanceRequest $record): array {
                $hasAdvance = filled($record->lgu_response_letter_advance_sent_at)
                    && filled($record->lgu_response_letter_advance_path);
                $signedDocument = $record->epirmaSignedDocuments->first(
                    fn (EpirmaSignedDocument $document): bool => $this->statusService->hasSignedArtifact($document)
                );
                $hasSigned = filled($record->epirma_response_letter_signed_at)
                    && filled($record->lgu_response_letter_sent_at)
                    && $signedDocument !== null;

                return [
                    'id' => $record->id,
                    'reference_number' => $record->reference_number,
                    'requesting_agency' => $record->requesting_agency,
                    'incident' => $record->incident?->only(['id', 'name']),
                    'advance_sent_at' => $record->lgu_response_letter_advance_sent_at?->toIso8601String(),
                    'advance_acked_at' => $record->lgu_response_letter_advance_acked_at?->toIso8601String(),
                    'advance_acked_by' => $record->lguResponseLetterAdvanceAcker?->only(['id', 'name']),
                    'advance_view_url' => $hasAdvance ? route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'advance']) : null,
                    'sent_at' => $record->lgu_response_letter_sent_at?->toIso8601String(),
                    'signed_at' => $record->epirma_response_letter_signed_at?->toIso8601String(),
                    'acked_at' => $record->lgu_response_letter_acked_at?->toIso8601String(),
                    'acked_by' => $record->lguResponseLetterAcker?->only(['id', 'name']),
                    'view_url' => $hasSigned
                        ? route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'signed'])
                        : null,
                    'local_view_url' => $hasSigned
                        ? route('lgu.response-letters.show', ['assistanceRequest' => $record, 'kind' => 'signed'])
                        : null,
                    'has_advance' => $hasAdvance,
                    'has_signed' => $hasSigned,
                ];
            })
            ->values();

        return Inertia::render('Lgu/ResponseLetters/Index', [
            'letters' => $letters,
            'focusId' => $request->integer('focus') ?: null,
        ]);
    }

    public function show(Request $request, AssistanceRequest $assistanceRequest): BinaryFileResponse|Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertCanAccess($user, $assistanceRequest);

        $kind = $request->string('kind')->toString() ?: 'signed';
        abort_unless(in_array($kind, ['advance', 'signed'], true), 404);

        if ($kind === 'advance') {
            abort_unless(
                filled($assistanceRequest->lgu_response_letter_advance_path)
                && Storage::disk('public')->exists((string) $assistanceRequest->lgu_response_letter_advance_path),
                404
            );

            $advanceFilename = InlinePdfFilename::fromCandidates(
                $assistanceRequest->lgu_response_letter_advance_name,
                $assistanceRequest->response_drn ? 'Response-Letter-'.$assistanceRequest->response_drn : null,
                $assistanceRequest->reference_number ? 'Response-Letter-'.$assistanceRequest->reference_number : null,
                'advance-response-letter',
            );

            return response()->file(Storage::disk('public')->path($assistanceRequest->lgu_response_letter_advance_path), [
                'Content-Disposition' => InlinePdfFilename::disposition($advanceFilename),
                'X-Epirma-Preview-Kind' => 'advance',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        abort_unless(
            filled($assistanceRequest->epirma_response_letter_signed_at)
            && filled($assistanceRequest->lgu_response_letter_sent_at),
            404,
            'Signed response letter is not available yet.'
        );

        $document = EpirmaSignedDocument::query()
            ->where('assistance_request_id', $assistanceRequest->id)
            ->where('document_type', EpirmaSignedDocument::TYPE_RESPONSE_LETTER)
            ->where('action', EpirmaSignedDocument::ACTION_ROUTE)
            ->where('routing_status', EpirmaSignedDocument::STATUS_SIGNED)
            ->latest('id')
            ->first();

        abort_unless($document && $this->statusService->hasSignedArtifact($document), 404, 'Signed response letter artifact is not available.');

        $cachedPath = $this->statusService->ensureCachedSignedPdf($document);
        abort_unless($cachedPath && Storage::disk('public')->exists($cachedPath), 404, 'Unable to load the signed response letter.');

        // Never serve the advance / unsigned route payload as the signed letter.
        $advancePath = (string) ($assistanceRequest->lgu_response_letter_advance_path ?? '');
        abort_if($advancePath !== '' && $cachedPath === $advancePath, 404, 'Signed response letter is not available.');

        $filename = InlinePdfFilename::fromCandidates(
            $document->document_name,
            $assistanceRequest->response_drn ? 'Response-Letter-'.$assistanceRequest->response_drn : null,
            $assistanceRequest->reference_number ? 'Response-Letter-'.$assistanceRequest->reference_number : null,
            'signed-response-letter',
        );

        // e-PIRMA signs the full 2-page letter for DRRS/AA; LGU receives page 2 only.
        $sourceAbsolute = Storage::disk('public')->path($cachedPath);
        try {
            $lguPagePath = $this->pdfPages->extractPage($sourceAbsolute, 2);
        } catch (\Throwable) {
            return response()->file($sourceAbsolute, [
                'Content-Disposition' => InlinePdfFilename::disposition($filename),
                'X-Epirma-Preview-Kind' => 'signed',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file($lguPagePath, [
            'Content-Disposition' => InlinePdfFilename::disposition($filename),
            'X-Epirma-Preview-Kind' => 'signed',
            'X-Content-Type-Options' => 'nosniff',
            'X-Lgu-Response-Letter-Page' => '2',
        ])->deleteFileAfterSend(true);
    }

    public function acknowledge(
        Request $request,
        AssistanceRequest $assistanceRequest,
        WorkflowNotificationService $workflowNotifications,
        RealtimePublisher $realtime,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertCanAccess($user, $assistanceRequest);

        $data = $request->validate([
            'kind' => ['nullable', 'in:advance,signed'],
        ]);
        $kind = $data['kind'] ?? 'signed';
        $now = now();

        if ($kind === 'advance') {
            abort_unless(filled($assistanceRequest->lgu_response_letter_advance_sent_at), 422, 'Advance response letter is not available yet.');
            abort_if(filled($assistanceRequest->lgu_response_letter_advance_acked_at), 422, 'This advance response letter was already acknowledged.');

            $assistanceRequest->forceFill([
                'lgu_response_letter_advance_acked_at' => $now,
                'lgu_response_letter_advance_acked_by' => $user->id,
            ])->save();

            $fresh = $assistanceRequest->fresh();
            $workflowNotifications->notifyDrrsAaLguAdvanceAcknowledged($fresh);
            $workflowNotifications->broadcastLguFniProcessingUpdated($fresh, [
                'reason' => 'advance_acknowledged',
                'copy' => 'advance',
            ]);

            $realtime->usersChanged(
                collect([$assistanceRequest->epirma_forwarded_by, $assistanceRequest->assessment_acted_by])->filter()->unique()->values(),
                'workflow.receipt.changed',
                [
                    'request_id' => $assistanceRequest->id,
                    'kind' => 'response_letter_advance_ack',
                    'acked_at' => $now->toIso8601String(),
                    'acked_by' => $user->name,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Advance response letter receipt acknowledged.',
                'kind' => 'advance',
                'acked_at' => $now->toIso8601String(),
                'acked_by' => $user->name,
            ]);
        }

        abort_unless(
            filled($assistanceRequest->epirma_response_letter_signed_at)
            && filled($assistanceRequest->lgu_response_letter_sent_at),
            422,
            'Signed response letter is not available yet.'
        );
        abort_if(filled($assistanceRequest->lgu_response_letter_acked_at), 422, 'This response letter was already acknowledged.');

        $assistanceRequest->forceFill([
            'lgu_response_letter_acked_at' => $now,
            'lgu_response_letter_acked_by' => $user->id,
        ])->save();

        $fresh = $assistanceRequest->fresh();
        $workflowNotifications->notifyDrrsAaLguAcknowledged($fresh);
        $workflowNotifications->broadcastLguFniProcessingUpdated($fresh, [
            'reason' => 'signed_acknowledged',
            'copy' => 'signed',
        ]);

        $realtime->usersChanged(
            collect([$assistanceRequest->epirma_forwarded_by, $assistanceRequest->assessment_acted_by])->filter()->unique()->values(),
            'workflow.receipt.changed',
            [
                'request_id' => $assistanceRequest->id,
                'kind' => 'response_letter_ack',
                'acked_at' => $now->toIso8601String(),
                'acked_by' => $user->name,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Signed response letter receipt acknowledged.',
            'kind' => 'signed',
            'acked_at' => $now->toIso8601String(),
            'acked_by' => $user->name,
        ]);
    }

    private function assertCanAccess($user, AssistanceRequest $assistanceRequest): void
    {
        if ($user->hasRole('Super Admin')) {
            return;
        }

        abort_unless($user->hasRole('LGU'), 403);

        $psgc = (string) ($user->lgu_psgc_code ?? '');
        $source = $assistanceRequest->sourceLguDromicReport;

        $allowed = ($psgc !== '' && $assistanceRequest->lgu_psgc_code === $psgc)
            || (int) $assistanceRequest->lgu_submitted_by === (int) $user->id
            || ($source && (int) $source->lgu_submitted_by === (int) $user->id)
            || ($source && $psgc !== '' && $source->lgu_psgc_code === $psgc);

        abort_unless($allowed, 403, 'This response letter is not assigned to your LGU.');
    }
}
