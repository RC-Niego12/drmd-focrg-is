<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EpirmaDocumentStatusService;
use App\Services\EpirmaService;
use App\Services\EpirmaWorkflowService;
use App\Services\ResponseLetterDocumentService;
use App\Services\WordToPdfService;
use App\Services\WorkflowNotificationService;
use App\Support\InlinePdfFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EpirmaSigningController extends Controller
{
    public function __construct(
        private EpirmaService $epirmaService,
        private EpirmaDocumentStatusService $statusService,
        private EpirmaWorkflowService $workflowService,
        private ResponseLetterDocumentService $responseLetters,
        private WordToPdfService $wordToPdf,
    ) {}

    public function updateDocumentDrns(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->hasRole('DRRS AA') || $user->hasRole('Super Admin') || $user->can('route epirma documents')), 403);
        abort_if(blank($assistanceRequest->epirma_forwarded_to_drrs_aa_at), 422, 'The documents must first be forwarded by DRRS PDRC.');
        abort_if($assistanceRequest->epirmaSignedDocuments()->whereIn('routing_status', EpirmaSignedDocument::BLOCKING_STATUSES)->exists(), 422, 'Document DRNs can no longer be changed after e-PIRMA routing begins.');

        $data = $request->validate([
            'assessment_drn' => ['required', 'string', 'max:255', 'regex:/^.+-\d{2}-\d{2}-.+$/'],
            'response_drn' => ['required', 'string', 'max:255', 'regex:/^.+-\d{2}-\d{2}-.+$/'],
        ], [
            '*.regex' => 'Enter a complete DRN containing the two-digit year, month, and final reference segment.',
        ]);
        $before = $assistanceRequest->only(['assessment_drn', 'response_drn']);
        $assistanceRequest->forceFill($data)->save();
        $audit->log('request.document_drns_assigned_by_drrs_aa', $assistanceRequest, $before, $data, $user->id);

        return response()->json(['success' => true, ...$data]);
    }

    /**
     * MyPortal-style self-sign kept as stub for DRRS AA (pending developer integration).
     * PDRC no longer signs — they forward to DRRS AA for routing.
     */
    public function startSign(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('DRRS AA') || $user->hasRole('Super Admin') || $user->can('route epirma documents')),
            403,
            'Only DRRS AA can initiate e-PIRMA signing.'
        );

        return response()->json([
            'success' => false,
            'message' => 'Sign with e-PIRMA (MyPortal-style) is not available yet. Use Route with e-PIRMA for document routing.',
            'pending_integration' => true,
            'open_in_new_tab' => true,
        ], 422);
    }

    /**
     * DRRS PDRC forwards assessment + response letter to DRRS AA for e-PIRMA routing.
     * Also releases an advance response-letter copy to the concerned LGU.
     */
    public function forward(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->hasRole('DRRS') || $user->hasRole('Super Admin')), 403);

        $caps = $this->workflowService->capabilitiesFor($assistanceRequest, $user)['forward'] ?? [];
        if (! ($caps['can_forward'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $caps['blocked_reason'] ?? 'Unable to forward this assessment to DRRS AA.',
            ], 422);
        }

        $assistanceRequest->forceFill([
            'epirma_forwarded_to_drrs_aa_at' => now(),
            'epirma_forwarded_by' => $user->id,
            'epirma_aa_status' => 'pending',
            'epirma_status' => 'pending',
        ])->save();

        $audit->log('request.epirma_forwarded_to_drrs_aa', $assistanceRequest, [], [
            'forwarded_by' => $user->id,
            'document_drns_pending' => true,
        ]);

        $notifications = app(WorkflowNotificationService::class);
        $fresh = $assistanceRequest->fresh();
        $notifications->notifyDrrsAaEpirmaForwarded($fresh);
        $notifications->broadcastEpirmaStatusChanged($fresh, [
            'source' => 'forward',
            'epirma_aa_status' => 'pending',
        ]);

        $message = 'Assessment and response letter forwarded to DRRS AA. DRRS AA must assign both document DRNs before e-PIRMA routing can begin.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'advance_copy_released' => false,
                'epirma' => $this->workflowService->capabilitiesFor($fresh, $user),
            ]);
        }

        return redirect()
            ->route('requests.assessment', $assistanceRequest)
            ->with('success', $message);
    }

    /**
     * Route a document through e-PIRMA document-routing (DRRS AA only, after PDRC forward).
     */
    public function startRoute(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('DRRS AA') || $user->hasRole('Super Admin') || $user->can('route epirma documents')),
            403,
            'Only DRRS AA can route documents through e-PIRMA.'
        );

        $data = $request->validate([
            'document_type' => ['required', 'in:assessment,response_letter'],
        ]);
        $documentType = $data['document_type'];
        $capabilities = $this->workflowService->capabilitiesFor($assistanceRequest, $user);
        $bucket = $documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER
            ? ($capabilities['response_letter'] ?? [])
            : ($capabilities['assessment'] ?? []);

        if (! ($bucket['can_route'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $bucket['route_blocked_reason'] ?? 'Routing with e-PIRMA is not available for this document.',
            ], 422);
        }

        if ($this->workflowService->findBlockingDocument($assistanceRequest, $documentType, EpirmaSignedDocument::ACTION_ROUTE)) {
            return response()->json([
                'success' => false,
                'message' => 'This document already has an e-PIRMA route transaction. Multiple routing is not allowed.',
            ], 422);
        }

        if (! $this->epirmaService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'e-PIRMA is not configured. Set EPIRMA_BASE_URL and EPIRMA_CLIENT_SECRET per https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php',
            ], 422);
        }

        $idCheck = $this->epirmaIdNumberOrError($user);
        if ($idCheck instanceof JsonResponse) {
            return $idCheck;
        }

        // Do not bump epirma_aa_status here — only after a successful handoff.
        // A failed authorize must leave cancelled/pending queues as pending.
        return $this->startMicroserviceRouteHandoff($request, $assistanceRequest, $audit, $documentType);
    }

    /**
     * Resume a pending (pre-handoff) route, or open the existing remote e-PIRMA document
     * when handoff already completed. Never re-opens document-routing after routed.
     */
    public function continueRoute(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('DRRS AA') || $user->hasRole('Super Admin') || $user->can('route epirma documents')),
            403,
            'Only DRRS AA can continue e-PIRMA routing.'
        );

        $data = $request->validate([
            'document_type' => ['nullable', 'in:assessment,response_letter'],
        ]);
        $documentType = $data['document_type'] ?? EpirmaSignedDocument::TYPE_ASSESSMENT;

        $document = $this->workflowService->findOpenDocument(
            $assistanceRequest,
            $documentType,
            EpirmaSignedDocument::ACTION_ROUTE
        ) ?? $this->workflowService->findBlockingDocument(
            $assistanceRequest,
            $documentType,
            EpirmaSignedDocument::ACTION_ROUTE
        );

        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'No open e-PIRMA route was found for this document. Start a new route instead.',
            ], 409);
        }

        $status = (string) ($document->routing_status ?? '');
        $handoffComplete = in_array($status, [
            EpirmaSignedDocument::STATUS_ROUTED,
            EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
            EpirmaSignedDocument::STATUS_SIGNED,
        ], true) || filled($document->routed_at);

        if ($handoffComplete) {
            return $this->openAlreadyRoutedDocument($document);
        }

        $capabilities = $this->workflowService->capabilitiesFor($assistanceRequest, $user);
        $bucket = $documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER
            ? ($capabilities['response_letter'] ?? [])
            : ($capabilities['assessment'] ?? []);

        if (! ($bucket['can_continue'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $bucket['continue_blocked_reason']
                    ?? $bucket['route_blocked_reason']
                    ?? 'Unable to continue this e-PIRMA route.',
            ], 422);
        }

        // Pending-only resume: document-routing handoff never completed.
        if ($status !== EpirmaSignedDocument::STATUS_PENDING) {
            return $this->openAlreadyRoutedDocument($document);
        }

        $uuid = trim((string) $document->document_uuid);
        if ($uuid === '') {
            return response()->json([
                'success' => false,
                'message' => 'The open e-PIRMA document is missing its transaction UUID. Start a new route instead.',
            ], 422);
        }

        if (! $this->epirmaService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'e-PIRMA is not configured. Set EPIRMA_BASE_URL and EPIRMA_CLIENT_SECRET per https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php',
            ], 422);
        }

        try {
            $idCheck = $this->epirmaIdNumberOrError($user);
            if ($idCheck instanceof JsonResponse) {
                return $idCheck;
            }
            $idUsed = $idCheck;
            $responseData = $this->epirmaService->buildAuthorize($idUsed);

            if (! isset($responseData['success']) || ! $responseData['success'] || ! isset($responseData['token'])) {
                return response()->json([
                    'success' => false,
                    'message' => $this->authorizeFailureMessage($responseData, $idUsed),
                    'details' => $responseData,
                    'id_number' => $idUsed !== '' ? $idUsed : null,
                ], 422);
            }

            // Reuse the existing callback token so in-flight e-PIRMA tabs that still
            // redirect with the original startRoute token keep working after Continue.
            [$callbackToken, $callbackTokenRotated] = $this->resolveCallbackToken($assistanceRequest, true);

            $updated = DB::transaction(function () use (
                $assistanceRequest,
                $document,
                $uuid,
                $callbackToken,
                $callbackTokenRotated,
                $audit,
                $documentType,
                $user
            ) {
                $updates = [
                    'routing_status' => EpirmaSignedDocument::STATUS_ROUTED,
                    'routed_at' => $document->routed_at ?: now(),
                ];
                $document->forceFill($updates)->save();

                $requestUpdates = [
                    'epirma_status' => 'pending',
                    'epirma_transaction_id' => $uuid,
                ];
                if ($callbackTokenRotated) {
                    $requestUpdates['epirma_callback_token'] = hash('sha256', $callbackToken);
                }

                $assistanceRequest->forceFill($requestUpdates)->save();
                $this->statusService->refreshAaStatus($assistanceRequest);

                $audit->log('request.epirma_routing_continued', $assistanceRequest, [], [
                    'transaction_id' => $uuid,
                    'document_id' => $document->id,
                    'document_type' => $documentType,
                    'action' => EpirmaSignedDocument::ACTION_ROUTE,
                    'handoff' => 'document_routing',
                    'continued_by' => $user?->id,
                    'callback_token_reused' => ! $callbackTokenRotated,
                ]);

                return $document->fresh();
            });

            $documentUrl = null;
            if ($updated->document_path && Storage::disk('public')->exists($updated->document_path)) {
                $documentUrl = URL::temporarySignedRoute(
                    'epirma.signed-document',
                    now()->addHours(6),
                    ['document' => $updated->id],
                    absolute: false
                );
                $documentUrl = url($documentUrl);
                $separator = str_contains($documentUrl, '?') ? '&' : '?';
                $documentUrl .= $separator.'filename='.rawurlencode((string) $updated->document_name);
            }

            $callbackUrl = URL::route('epirma.callback', [
                'assistanceRequest' => $assistanceRequest->id,
                'token' => $callbackToken,
            ]);

            $appName = trim((string) config('services.epirma.app_name', ''));
            if ($appName === '') {
                $appName = 'DROMIS';
            }

            $redirectUrl = $this->epirmaService->generateDocumentRoutingUrl(
                $appName,
                $uuid,
                $responseData['token'],
                $callbackUrl,
                $documentUrl
            );

            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'open_in_new_tab' => true,
                'document' => $updated->toTrackingArray(),
                'continued' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('E-Pirma document routing continue failed', [
                'error' => $e->getMessage(),
                'request_id' => $assistanceRequest->id,
                'document_type' => $documentType,
                'document_id' => $document->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->transactionFailureMessage($e),
            ], 500);
        }
    }

    /**
     * Prefer opening an existing remote e-PIRMA document; never create a second handoff.
     */
    private function openAlreadyRoutedDocument(EpirmaSignedDocument $document): JsonResponse
    {
        $alreadyRoutedMessage = 'Already routed in e-PIRMA. Use Sync/Track, or open e-PIRMA Out for Signature. Re-routing is blocked to avoid duplicate documents.';

        $remoteUrl = $this->resolveRemoteViewUrl($document);
        if ($remoteUrl) {
            return response()->json([
                'success' => true,
                'redirect_url' => $remoteUrl,
                'open_in_new_tab' => true,
                'document' => $document->fresh()?->toTrackingArray() ?? $document->toTrackingArray(),
                'continued' => false,
                'already_routed' => true,
            ]);
        }

        $sync = $this->statusService->syncDocument($document, true);
        $fresh = $document->fresh() ?? $document;
        $remoteUrl = $sync['data']['view_url']
            ?? $sync['data']['remote_document_url']
            ?? $this->resolveRemoteViewUrl($fresh);

        if ($remoteUrl) {
            return response()->json([
                'success' => true,
                'redirect_url' => $remoteUrl,
                'open_in_new_tab' => true,
                'document' => $fresh->toTrackingArray(),
                'continued' => false,
                'already_routed' => true,
                'synced' => (bool) ($sync['success'] ?? false),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $alreadyRoutedMessage,
            'already_routed' => true,
            'continued' => false,
            'document' => $fresh->toTrackingArray(),
        ], 409);
    }

    private function resolveRemoteViewUrl(EpirmaSignedDocument $document): ?string
    {
        $raw = trim((string) ($document->remote_document_url ?: $document->remote_base_path ?: ''));
        if ($raw === '') {
            return null;
        }

        // Uses usableDocumentToken() — accepts base64:… public-secure-file tokens;
        // ignores only when EPIRMA_DOCUMENT_TOKEN equals this app's APP_KEY.
        return $this->statusService->buildViewUrl($raw);
    }

    /**
     * @deprecated Legacy combined flow removed. Prefer startSign / startRoute.
     */
    public function start(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The previous e-PIRMA flow was removed. Use Sign (assessment) or Route (assessment/response letter) from the document tabs.',
        ], 422);
    }

    /**
     * Microservice document-routing handoff for assessment or response-letter PDFs.
     */
    private function startMicroserviceRouteHandoff(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        string $documentType
    ): JsonResponse {
        $path = null;

        $idCheck = $this->epirmaIdNumberOrError($request->user());
        if ($idCheck instanceof JsonResponse) {
            return $idCheck;
        }
        $idUsed = $idCheck;

        try {
            [$pdfBinary, $originalName, $description] = $this->buildPdfPayload($assistanceRequest, $documentType);

            $uuid = (string) Str::uuid();
            $storedFileName = $originalName.'-'.Str::lower(Str::random(12)).'.pdf';
            $path = 'epirma_signed_documents/'.$storedFileName;
            Storage::disk('public')->put($path, $pdfBinary);

            $responseData = $this->epirmaService->buildAuthorize($idUsed);

            if (! isset($responseData['success']) || ! $responseData['success'] || ! isset($responseData['token'])) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }

                return response()->json([
                    'success' => false,
                    'message' => $this->authorizeFailureMessage($responseData, $idUsed),
                    'details' => $responseData,
                    'id_number' => $idUsed !== '' ? $idUsed : null,
                ], 422);
            }

            [$callbackToken] = $this->resolveCallbackToken($assistanceRequest, false);

            $document = DB::transaction(function () use (
                $assistanceRequest,
                $request,
                $path,
                $originalName,
                $description,
                $uuid,
                $callbackToken,
                $audit,
                $documentType
            ) {
                $document = EpirmaSignedDocument::create([
                    'assistance_request_id' => $assistanceRequest->id,
                    'document_type' => $documentType,
                    'action' => EpirmaSignedDocument::ACTION_ROUTE,
                    'document_name' => $originalName.'.pdf',
                    'document_path' => $path,
                    'description_subject' => $description,
                    'encoded_by' => (string) ($request->user()->name ?? $request->user()->id),
                    'timestamp' => now(),
                    'document_uuid' => $uuid,
                    'routing_status' => EpirmaSignedDocument::STATUS_ROUTED,
                    'initiated_by' => $request->user()?->id,
                    'routed_at' => now(),
                    'handoff' => 'document_routing',
                ]);

                $assistanceRequest->update([
                    'epirma_status' => 'pending',
                    'epirma_transaction_id' => $uuid,
                    'epirma_callback_token' => hash('sha256', $callbackToken),
                ]);
                $this->statusService->refreshAaStatus($assistanceRequest);

                $audit->log('request.epirma_routing_started', $assistanceRequest, [], [
                    'transaction_id' => $uuid,
                    'document_id' => $document->id,
                    'document_type' => $documentType,
                    'action' => EpirmaSignedDocument::ACTION_ROUTE,
                    'handoff' => 'document_routing',
                ]);

                return $document;
            });

            $documentUrl = URL::temporarySignedRoute(
                'epirma.signed-document',
                now()->addHours(6),
                ['document' => $document->id],
                absolute: false
            );
            $documentUrl = url($documentUrl);
            $separator = str_contains($documentUrl, '?') ? '&' : '?';
            $documentUrl .= $separator.'filename='.rawurlencode((string) $document->document_name);

            $callbackUrl = URL::route('epirma.callback', [
                'assistanceRequest' => $assistanceRequest->id,
                'token' => $callbackToken,
            ]);

            $appName = trim((string) config('services.epirma.app_name', ''));
            if ($appName === '') {
                $appName = 'DROMIS';
            }

            $redirectUrl = $this->epirmaService->generateDocumentRoutingUrl(
                $appName,
                $uuid,
                $responseData['token'],
                $callbackUrl,
                $documentUrl
            );

            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'open_in_new_tab' => true,
                'document' => $document->toTrackingArray(),
            ]);
        } catch (Throwable $e) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            Log::error('E-Pirma document routing failed', [
                'error' => $e->getMessage(),
                'request_id' => $assistanceRequest->id,
                'document_type' => $documentType,
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->transactionFailureMessage($e),
            ], 500);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildPdfPayload(AssistanceRequest $assistanceRequest, string $documentType): array
    {
        if ($documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER) {
            if (blank($assistanceRequest->response_drn)) {
                throw ValidationException::withMessages([
                    'response_drn' => 'Enter the Response Letter DRN before routing with e-PIRMA.',
                ]);
            }
            $record = $assistanceRequest->load([
                'items',
                'approvals',
                'incident',
                'requestParty.lguDirectoryEntry.officials',
                'requestParty.lguDirectoryEntry.contacts',
            ]);
            $word = $this->responseLetters->generate($record);
            try {
                $pdfPath = $this->wordToPdf->convert($word['path']);
                $pdfBinary = (string) file_get_contents($pdfPath);
            } finally {
                @unlink($word['path']);
                if (isset($pdfPath)) {
                    @unlink($pdfPath);
                }
            }

            return [
                $pdfBinary,
                'Response-Letter-'.$record->reference_number,
                'Response letter for '.$assistanceRequest->reference_number,
            ];
        }

        $record = $assistanceRequest->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']);
        $pdfBinary = Pdf::loadView('documents.assessment', [
            'request' => $record,
            'pageMargin' => 18,
        ])->setPaper('a4', 'portrait')->output();

        return [
            $pdfBinary,
            'Assessment-'.$record->reference_number,
            'Assessment form for '.$assistanceRequest->reference_number,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildAdvanceResponseLetterFallback(AssistanceRequest $assistanceRequest): array
    {
        if (blank($assistanceRequest->response_drn)) {
            throw ValidationException::withMessages([
                'response_drn' => 'Enter the Response Letter DRN before forwarding to DRRS AA.',
            ]);
        }

        $record = $assistanceRequest->load([
            'items',
            'approvals',
            'incident',
            'requestParty.lguDirectoryEntry.officials',
            'requestParty.lguDirectoryEntry.contacts',
        ]);
        $pdfBinary = Pdf::loadView('documents.response-letter', [
            'request' => $record,
            'advance_copy' => true,
        ])->setPaper('a4', 'portrait')->output();

        return [
            $pdfBinary,
            'Advance-Response-Letter-'.$record->reference_number,
        ];
    }

    public function latestSignedStatus(AssistanceRequest $assistanceRequest): JsonResponse
    {
        $result = $this->statusService->syncLatestForRequest($assistanceRequest, true);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to fetch latest status.',
                'data' => $result['data'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    public function listDocuments(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        if ($request->boolean('sync')) {
            $this->statusService->listForRequest($assistanceRequest)->each(function ($row) use ($assistanceRequest): void {
                $document = EpirmaSignedDocument::query()
                    ->where('assistance_request_id', $assistanceRequest->id)
                    ->whereKey($row['id'] ?? null)
                    ->first();
                if ($document && $document->isOpen()) {
                    $this->statusService->syncDocument($document, true);
                }
            });
        }

        return response()->json([
            'success' => true,
            'data' => $this->statusService->listForRequest($assistanceRequest)->values()->all(),
        ]);
    }

    public function syncDocument(AssistanceRequest $assistanceRequest, EpirmaSignedDocument $document): JsonResponse
    {
        abort_unless((int) $document->assistance_request_id === (int) $assistanceRequest->id, 404);

        $result = $this->statusService->syncDocument($document, true);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to fetch latest status.',
                'data' => $result['data'] ?? $document->fresh()?->toTrackingArray(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    /**
     * Same-origin PDF view for PdfPreviewModal. Prefers signed remote/authorized
     * content (downloaded/cached). For signed assessments, never redirects to DomPDF.
     * Unsigned/open docs may use local handoff or live DomPDF draft.
     */
    public function viewDocument(Request $request, AssistanceRequest $assistanceRequest, EpirmaSignedDocument $document): Response|BinaryFileResponse|StreamedResponse|RedirectResponse|JsonResponse
    {
        abort_unless((int) $document->assistance_request_id === (int) $assistanceRequest->id, 404);

        $fresh = $document;
        if ($document->isOpen() || ($document->isSigned() && blank($document->remote_document_url) && blank($document->remote_base_path))) {
            $this->statusService->syncDocument($document, true);
            $fresh = $document->fresh() ?? $document;
        }

        $typePrefix = match ($fresh->document_type) {
            EpirmaSignedDocument::TYPE_RESPONSE_LETTER => 'Response-Letter',
            EpirmaSignedDocument::TYPE_RIS => 'RIS',
            default => 'Assessment',
        };
        $officialRef = match ($fresh->document_type) {
            EpirmaSignedDocument::TYPE_RESPONSE_LETTER => $assistanceRequest->response_drn ?: $assistanceRequest->reference_number,
            EpirmaSignedDocument::TYPE_RIS => $fresh->document_name ?: $assistanceRequest->reference_number,
            default => $assistanceRequest->assessment_drn ?: $assistanceRequest->reference_number,
        };
        $filename = InlinePdfFilename::fromCandidates(
            $fresh->document_name,
            $officialRef ? $typePrefix.'-'.$officialRef : null,
            $typePrefix.'-'.$assistanceRequest->id,
        );
        $isSigned = $fresh->isSigned();

        if ($isSigned) {
            $path = trim((string) ($fresh->document_path ?? ''));
            $hasSignedCache = $this->statusService->isCachedSignedPath($path)
                && Storage::disk('public')->exists($path);

            // Always try Connect forwarded-documents before failing when the signed cache is missing.
            if (! $hasSignedCache) {
                $this->statusService->enrichFromForwardedDocuments($fresh, false);
                $fresh = $fresh->fresh() ?? $fresh;
            }

            $cachedPath = $this->statusService->ensureCachedSignedPdf($fresh);
            if ($cachedPath && Storage::disk('public')->exists($cachedPath)) {
                return response()->file(Storage::disk('public')->path($cachedPath), [
                    'Content-Disposition' => InlinePdfFilename::disposition($filename),
                    'X-Epirma-Preview-Kind' => 'signed',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            $binary = $this->statusService->downloadSignedPdfBinary($fresh);
            if ($binary !== null) {
                return response($binary, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => InlinePdfFilename::disposition($filename),
                    'X-Epirma-Preview-Kind' => 'signed',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            // Signed remote/cache miss: never fall back to DomPDF / unsigned handoff
            // for assessment OR response letter (SIGNED badge must not show unsigned PDF).
            $downloadHint = $this->statusService->lastSignedDownloadError();
            $connectHint = $this->statusService->lastForwardedDocumentsError();
            $message = 'Signed file unavailable from e-PIRMA. '
                .($downloadHint ?: 'The remote host may be unreachable, the file may require a valid document token, or the signed PDF is not cached yet.')
                .($connectHint ? ' Connect enrichment: '.$connectHint : '')
                .' Set EPIRMA_DOCUMENT_TOKEN (RICTMS public-secure-file token) and Connect staff auth '
                .'(EPIRMA_CONNECT_BEARER or MYPORTAL_USERNAME+PASSWORD against the same Connect host as EPIRMA_CONNECT_BASE_URL), '
                .'then reopen this preview or run: php artisan epirma:sync-document-status --cache-signed';

            if ($request->expectsJson() || str_contains(strtolower((string) $request->header('Accept')), 'application/json')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'document_id' => $fresh->id,
                    'document_type' => $fresh->document_type,
                    'has_remote_url' => filled($fresh->remote_document_url ?: $fresh->remote_base_path),
                    'remote_host' => parse_url((string) ($fresh->remote_document_url ?: $fresh->remote_base_path), PHP_URL_HOST),
                    'cached_signed' => false,
                    'download_error' => $downloadHint,
                    'connect_error' => $connectHint,
                ], 502);
            }

            $label = match ($fresh->document_type) {
                EpirmaSignedDocument::TYPE_RESPONSE_LETTER => 'Signed Response Letter',
                EpirmaSignedDocument::TYPE_RIS => 'Signed RIS',
                default => 'Signed Assessment',
            };

            return response(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Signed file unavailable</title></head>'
                .'<body style="font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;'
                .'min-height:100vh;margin:0;background:#f8fafc;color:#0f172a">'
                .'<div style="max-width:28rem;padding:1.5rem;text-align:center">'
                .'<p style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#b45309;margin:0">'.e($label).'</p>'
                .'<h1 style="font-size:1.25rem;margin:.5rem 0 0">Signed file unavailable from e-PIRMA</h1>'
                .'<p style="font-size:.875rem;line-height:1.5;color:#475569;margin:1rem 0 0">'.e($message).'</p>'
                .'</div></body></html>',
                502,
                [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'X-Epirma-Preview-Kind' => 'signed-unavailable',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        }

        if (filled($fresh->document_path) && Storage::disk('public')->exists($fresh->document_path)) {
            $absolute = Storage::disk('public')->path($fresh->document_path);

            return response()->file($absolute, [
                'Content-Disposition' => InlinePdfFilename::disposition($filename),
                'X-Epirma-Preview-Kind' => 'draft',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $draftRoute = ($fresh->document_type === EpirmaSignedDocument::TYPE_RESPONSE_LETTER)
            ? route('requests.response-letter-pdf', $assistanceRequest, false)
            : route('requests.assessment-pdf', $assistanceRequest, false);

        return redirect()->to($draftRoute.(str_contains($draftRoute, '?') ? '&' : '?').'inline=1');
    }

    public function destroyDocument(AssistanceRequest $assistanceRequest, EpirmaSignedDocument $document): JsonResponse
    {
        abort_unless((int) $document->assistance_request_id === (int) $assistanceRequest->id, 404);

        if ($document->document_path && Storage::disk('public')->exists($document->document_path)) {
            Storage::disk('public')->delete($document->document_path);
        }

        $wasCurrent = filled($assistanceRequest->epirma_transaction_id)
            && hash_equals((string) $assistanceRequest->epirma_transaction_id, (string) $document->document_uuid);

        $document->delete();

        if ($wasCurrent) {
            $next = EpirmaSignedDocument::query()
                ->where('assistance_request_id', $assistanceRequest->id)
                ->latest('id')
                ->first();

            $assistanceRequest->forceFill([
                'epirma_transaction_id' => $next?->document_uuid,
                'epirma_status' => $next
                    ? ($next->isSigned() ? 'signed' : 'pending')
                    : null,
                'epirma_signature_reference' => $next?->signature_reference,
                'epirma_signed_at' => $next?->completed_at,
                'epirma_callback_token' => $next ? $assistanceRequest->epirma_callback_token : null,
            ])->save();
        }

        $assistanceRequest = $assistanceRequest->fresh() ?? $assistanceRequest;
        if (! $this->statusService->revertForwardIfRoutingInactive($assistanceRequest, allowEmptyRoutes: true)) {
            $this->statusService->refreshAaStatus($assistanceRequest);
        }

        return response()->json([
            'success' => true,
            'message' => 'e-PIRMA document tracking entry deleted.',
            'data' => $this->statusService->listForRequest($assistanceRequest->fresh() ?? $assistanceRequest)->values()->all(),
            'epirma' => app(EpirmaWorkflowService::class)->capabilitiesFor($assistanceRequest->fresh() ?? $assistanceRequest),
        ]);
    }

    public function retrySigning(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['nullable', 'in:assessment,response_letter'],
        ]);

        $documentType = $data['document_type'] ?? EpirmaSignedDocument::TYPE_ASSESSMENT;
        $request->merge(['document_type' => $documentType]);

        return $this->startRoute($request, $assistanceRequest, app(AuditLogger::class));
    }

    public function serveSignedDocument(Request $request, EpirmaSignedDocument $document)
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $corsHeaders);
        }

        if (! $request->hasValidSignatureWhileIgnoring(['filename'], false)) {
            return response('Invalid or expired document link.', 403, $corsHeaders);
        }

        if (! $document->document_path || ! Storage::disk('public')->exists($document->document_path)) {
            return response('Document file not found.', 404, $corsHeaders);
        }

        $filePath = Storage::disk('public')->path($document->document_path);
        $safeFileName = InlinePdfFilename::fromCandidates(
            $document->document_name,
            basename((string) $document->document_path),
            'document',
        );

        return response()->file($filePath, [
            ...$corsHeaders,
            'Content-Disposition' => InlinePdfFilename::disposition($safeFileName),
        ]);
    }

    public function callback(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        try {
            $data = $request->validate([
                'token' => ['required', 'string'],
                'status' => ['nullable', 'string', 'max:50'],
                'signature_reference' => ['nullable', 'string', 'max:255'],
                'return_url' => ['nullable', 'string', 'max:2048'],
            ]);
        } catch (ValidationException $exception) {
            return $this->rejectInvalidCallback(
                $request,
                $assistanceRequest,
                'Invalid or expired e-PIRMA callback.',
                $exception
            );
        }

        $rawStatus = strtolower(trim((string) ($data['status'] ?? 'routed')));
        if ($rawStatus === '') {
            $rawStatus = 'routed';
        }

        $statusMap = [
            'signed' => EpirmaSignedDocument::STATUS_SIGNED,
            'completed' => EpirmaSignedDocument::STATUS_SIGNED,
            'success' => EpirmaSignedDocument::STATUS_SIGNED,
            'successful' => EpirmaSignedDocument::STATUS_SIGNED,
            'cancelled' => EpirmaSignedDocument::STATUS_CANCELLED,
            'canceled' => EpirmaSignedDocument::STATUS_CANCELLED,
            'failed' => EpirmaSignedDocument::STATUS_FAILED,
            'error' => EpirmaSignedDocument::STATUS_FAILED,
            'routed' => EpirmaSignedDocument::STATUS_ROUTED,
            'returned' => EpirmaSignedDocument::STATUS_ROUTED,
            'pending' => EpirmaSignedDocument::STATUS_ROUTED,
            'partial' => EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
            'partially_signed' => EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
        ];
        if (! isset($statusMap[$rawStatus])) {
            return $this->rejectInvalidCallback(
                $request,
                $assistanceRequest,
                'Unsupported e-PIRMA callback status.',
                null,
                422
            );
        }
        $normalizedStatus = $statusMap[$rawStatus];

        $expected = (string) $assistanceRequest->epirma_callback_token;
        if ($expected === '' || ! hash_equals($expected, hash('sha256', $data['token']))) {
            return $this->rejectInvalidCallback(
                $request,
                $assistanceRequest,
                'Invalid or expired e-PIRMA callback.'
            );
        }

        $document = $this->statusService->resolveCurrentDocument($assistanceRequest);
        if ($document) {
            $this->statusService->applyCallbackStatus(
                $document,
                $normalizedStatus,
                $data['signature_reference'] ?? null
            );
            // UUID + poll is the source of truth after handoff (MyPortal/docs pattern).
            $this->statusService->syncDocument($document, false);
            $document = $document->fresh();
            if ($document?->isSigned()) {
                $normalizedStatus = EpirmaSignedDocument::STATUS_SIGNED;
            } elseif ($document && in_array((string) $document->routing_status, [
                EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
                EpirmaSignedDocument::STATUS_ROUTED,
            ], true) && $normalizedStatus === EpirmaSignedDocument::STATUS_SIGNED) {
                $normalizedStatus = (string) $document->routing_status;
            }
        }

        $signed = $normalizedStatus === EpirmaSignedDocument::STATUS_SIGNED;
        $action = (string) ($document?->action ?: EpirmaSignedDocument::ACTION_ROUTE);
        $documentType = (string) ($document?->document_type ?: EpirmaSignedDocument::TYPE_ASSESSMENT);
        $finalizeAssessment = $signed
            && $action === EpirmaSignedDocument::ACTION_ROUTE
            && $documentType === EpirmaSignedDocument::TYPE_ASSESSMENT;

        if ($finalizeAssessment) {
            if ($assistanceRequest->assessment_status !== 'draft') {
                return $this->rejectInvalidCallback(
                    $request,
                    $assistanceRequest,
                    'This assessment is no longer awaiting signature.',
                    null,
                    409
                );
            }

            $assistanceRequest->update([
                'epirma_status' => 'signed',
                'epirma_callback_token' => null,
                'epirma_signature_reference' => $data['signature_reference']
                    ?? $document?->signature_reference
                    ?? $assistanceRequest->epirma_signature_reference,
                'epirma_signed_at' => now(),
                'epirma_assessment_signed_at' => now(),
                'assessment_status' => 'final',
                'status' => 'acted',
                'epirma_aa_status' => filled($assistanceRequest->epirma_response_letter_signed_at) ? 'completed' : 'in_progress',
            ]);
            $this->forgetCallbackTokenCache($assistanceRequest);

            $audit->log('request.epirma_signing_completed', $assistanceRequest, [], [
                'epirma_status' => 'signed',
                'signature_reference' => $assistanceRequest->epirma_signature_reference,
                'document_id' => $document?->id,
                'document_type' => $documentType,
                'action' => $action,
            ]);

            $notifications = app(WorkflowNotificationService::class);
            $signedFresh = $assistanceRequest->fresh();
            $notifications->notifyRrosEpirmaDocumentSigned($signedFresh, EpirmaSignedDocument::TYPE_ASSESSMENT);
            $notifications->notifyPdrcEpirmaDocumentSigned($signedFresh, EpirmaSignedDocument::TYPE_ASSESSMENT);

            $message = 'The e-PIRMA route signatures were verified. The assessment is now Final and RROS / DRRS PDRC have been notified.';
        } elseif ($signed && $action === EpirmaSignedDocument::ACTION_ROUTE && $documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER) {
            $assistanceRequest->update([
                'epirma_status' => 'pending',
                'epirma_response_letter_signed_at' => now(),
                'lgu_response_letter_sent_at' => $assistanceRequest->lgu_response_letter_sent_at ?: now(),
                'epirma_aa_status' => filled($assistanceRequest->epirma_assessment_signed_at) ? 'completed' : 'in_progress',
                'epirma_signature_reference' => $data['signature_reference']
                    ?? $document?->signature_reference
                    ?? $assistanceRequest->epirma_signature_reference,
            ]);

            $fresh = $assistanceRequest->fresh();
            $notifications = app(WorkflowNotificationService::class);
            $notifications->notifyRrosEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_RESPONSE_LETTER);
            $notifications->notifyPdrcEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_RESPONSE_LETTER);
            if (! $fresh->lgu_response_letter_acked_at) {
                $notifications->notifyLguSignedResponseLetter($fresh);
            }

            $audit->log('request.epirma_response_letter_signed', $assistanceRequest, [], [
                'document_id' => $document?->id,
            ]);

            $message = 'Response letter signing completed in e-PIRMA. RROS, DRRS PDRC, and the concerned LGU have been notified.';
        } else {
            // Routing-only return: keep draft and track on the document row.
            // Cancelled/failed may return the handoff to DRRS PDRC when nothing else is open.
            $fresh = $assistanceRequest->fresh() ?? $assistanceRequest;
            if (in_array($normalizedStatus, [
                EpirmaSignedDocument::STATUS_CANCELLED,
                EpirmaSignedDocument::STATUS_FAILED,
            ], true)) {
                $this->statusService->refreshAaStatus($fresh);
                $fresh = $fresh->fresh() ?? $fresh;
                $assistanceRequest->update([
                    'epirma_status' => 'pending',
                    'epirma_signature_reference' => $data['signature_reference']
                        ?? $document?->signature_reference
                        ?? $fresh->epirma_signature_reference,
                ]);
            } else {
                $aaStatus = $fresh->epirma_aa_status === 'pending'
                    ? 'in_progress'
                    : (string) $fresh->epirma_aa_status;
                $assistanceRequest->update([
                    'epirma_status' => 'pending',
                    'epirma_aa_status' => $aaStatus,
                    'epirma_signature_reference' => $data['signature_reference']
                        ?? $document?->signature_reference
                        ?? $fresh->epirma_signature_reference,
                ]);
            }

            $assistanceRequest = $assistanceRequest->fresh() ?? $assistanceRequest;
            $returnedToPdrc = blank($assistanceRequest->epirma_forwarded_to_drrs_aa_at);

            $audit->log('request.epirma_routing_returned', $assistanceRequest, [], [
                'epirma_status' => 'pending',
                'routing_status' => $normalizedStatus,
                'signature_reference' => $data['signature_reference'] ?? null,
                'document_id' => $document?->id,
                'forward_reverted' => $returnedToPdrc,
            ]);

            if ($normalizedStatus === EpirmaSignedDocument::STATUS_CANCELLED) {
                Log::info('e-PIRMA cancel callback received.', [
                    'assistance_request_id' => $assistanceRequest->id,
                    'document_id' => $document?->id,
                    'document_uuid' => $document?->document_uuid,
                    'raw_status' => $rawStatus,
                    'forward_reverted' => $returnedToPdrc,
                ]);
            }

            $message = match ($normalizedStatus) {
                EpirmaSignedDocument::STATUS_CANCELLED => $returnedToPdrc
                    ? 'e-PIRMA routing was cancelled. The assessment was returned to DRRS PDRC for revisions before re-forwarding.'
                    : 'e-PIRMA routing was cancelled for this document. You can route it again from DROMIS.',
                EpirmaSignedDocument::STATUS_FAILED => $returnedToPdrc
                    ? 'e-PIRMA reported a failure. The assessment was returned to DRRS PDRC for revisions before re-forwarding.'
                    : 'e-PIRMA reported a failure. You can route this document again from DROMIS.',
                EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED => 'Document is partially signed in e-PIRMA. DROMIS will keep tracking signer progress.',
                default => 'Returned from e-PIRMA routing. DROMIS continues tracking signing progress.',
            };
        }

        app(WorkflowNotificationService::class)
            ->broadcastEpirmaStatusChanged($assistanceRequest->fresh(), [
                'routing_status' => $normalizedStatus,
                'document_id' => $document?->id,
                'document_type' => $documentType,
                'source' => 'callback',
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'status' => $rawStatus,
                'routing_status' => $normalizedStatus,
                'document' => $document?->toTrackingArray(),
            ]);
        }

        $returnUrl = trim((string) ($data['return_url'] ?? ''));
        if ($returnUrl === '' || ! $this->isSafeAppReturnUrl($returnUrl)) {
            $returnUrl = $this->postSigningReturnUrl($assistanceRequest);
        }

        return redirect()
            ->to($returnUrl)
            ->with('success', $message);
    }

    /**
     * @return array{0: string, 1: bool} [plaintext token, whether the DB hash should be updated]
     */
    private function resolveCallbackToken(AssistanceRequest $assistanceRequest, bool $reuseExisting): array
    {
        $cacheKey = $this->callbackTokenCacheKey($assistanceRequest);
        $existingHash = (string) ($assistanceRequest->epirma_callback_token ?? '');

        if ($reuseExisting && $existingHash !== '') {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '' && hash_equals($existingHash, hash('sha256', $cached))) {
                return [$cached, false];
            }
        }

        $token = Str::random(64);
        Cache::put($cacheKey, $token, now()->addDays(14));

        return [$token, true];
    }

    private function forgetCallbackTokenCache(AssistanceRequest $assistanceRequest): void
    {
        Cache::forget($this->callbackTokenCacheKey($assistanceRequest));
    }

    private function callbackTokenCacheKey(AssistanceRequest $assistanceRequest): string
    {
        return 'epirma.callback_token.'.$assistanceRequest->id;
    }

    private function rejectInvalidCallback(
        Request $request,
        AssistanceRequest $assistanceRequest,
        string $message,
        ?Throwable $previous = null,
        int $status = 403
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson() || $request->wantsJson()) {
            if ($previous instanceof ValidationException) {
                throw $previous;
            }

            abort($status, $message);
        }

        $target = filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at)
            ? route('drrs-aa.epirma.index', ['search' => $assistanceRequest->reference_number])
            : route('requests.assessment', $assistanceRequest);

        return redirect()
            ->to($target)
            ->with('error', $message);
    }

    /**
     * e-PIRMA build-authorize must receive the employee's registered ID number.
     * Never fall back to the local users.id — that yields cryptic "User not found" 422s.
     */
    private function epirmaIdNumberOrError(?User $user): JsonResponse|string
    {
        $idUsed = trim((string) ($user?->id_number ?? ''));
        if ($idUsed === '') {
            return response()->json([
                'success' => false,
                'message' => 'Your employee ID number is not set or not registered in e-PIRMA.',
                'id_number' => null,
            ], 422);
        }

        return $idUsed;
    }

    private function authorizeFailureMessage(array $responseData, string $idUsed): string
    {
        $apiMessage = trim((string) ($responseData['message'] ?? $responseData['error'] ?? ''));
        $baseUrl = rtrim((string) config('services.epirma.base_url', ''), '/');

        if ($apiMessage !== '') {
            if (stripos($apiMessage, 'invalid secret') !== false) {
                return 'e-PIRMA rejected EPIRMA_CLIENT_SECRET for '.$baseUrl.' (Invalid secret). '
                    .'Register a client with POST /epirma/register-client, save the returned secret as EPIRMA_CLIENT_SECRET, '
                    .'set EPIRMA_APP_NAME to that client name, then run php artisan config:clear. '
                    .'Docs: https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php';
            }

            if (stripos($apiMessage, 'user not found') !== false) {
                return 'Your employee ID number is not set or not registered in e-PIRMA'
                    .($idUsed !== '' ? ' (ID '.$idUsed.').' : '.')
                    .' Update your DROMIS profile id_number to match your e-PIRMA account, then try again.';
            }

            return $apiMessage;
        }

        if ($idUsed === '') {
            return 'Your employee ID number is not set or not registered in e-PIRMA.';
        }

        return 'Failed to initialize E-Pirma document creation. You must have an ePIRMA Account to use this feature.';
    }

    private function transactionFailureMessage(Throwable $e): string
    {
        $raw = $e->getMessage();

        if (str_contains($raw, 'database is locked')) {
            return 'Could not save the e-PIRMA document because the database is busy. Wait a moment and click Sign with e-PIRMA again.';
        }

        if (str_contains($raw, 'Failed to connect') || str_contains($raw, 'Could not reach e-PIRMA')) {
            return 'Could not reach e-PIRMA at '.config('services.epirma.base_url').'. Confirm EPIRMA_BASE_URL from the Caraga Connect guide.';
        }

        // Docs default exception copy.
        return 'Failed to process document transaction.';
    }

    private function postSigningReturnUrl(AssistanceRequest $assistanceRequest): string
    {
        if (filled($assistanceRequest->epirma_forwarded_to_drrs_aa_at)) {
            return URL::route('drrs-aa.epirma.index', ['search' => $assistanceRequest->reference_number]);
        }

        return URL::route('requests.assessment', $assistanceRequest);
    }

    private function isSafeAppReturnUrl(string $url): bool
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (! is_string($appHost) || $appHost === '' || ! is_string($urlHost) || $urlHost === '') {
            return false;
        }

        if (strcasecmp($appHost, $urlHost) !== 0) {
            return false;
        }

        return str_starts_with($path, '/requests')
            || str_starts_with($path, '/drrs-aa/epirma');
    }
}
