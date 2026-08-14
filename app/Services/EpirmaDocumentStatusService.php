<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EpirmaDocumentStatusService
{
    /** Consecutive latest-document-base-path "not found" polls required before marking cancelled. */
    public const REMOTE_NOT_FOUND_CANCEL_STREAK = 3;

    /** Fresh handoffs 404 until e-PIRMA indexes the UUID — never cancel during this window. */
    public const REMOTE_NOT_FOUND_GRACE_MINUTES = 5;

    /** Distinct storage prefix for e-PIRMA signed bytes (not the unsigned route handoff PDF). */
    public const SIGNED_CACHE_DIR = 'epirma_signed_documents/signed/';

    private bool $enrichingFromForwardedDocuments = false;

    /** @var string|null Last download failure reason (for viewDocument error copy). */
    private ?string $lastSignedDownloadError = null;

    /** @var string|null Last Connect forwarded-documents failure reason. */
    private ?string $lastForwardedDocumentsError = null;

    public function __construct(private EpirmaService $epirmaService) {}

    public function lastSignedDownloadError(): ?string
    {
        return $this->lastSignedDownloadError;
    }

    public function lastForwardedDocumentsError(): ?string
    {
        return $this->lastForwardedDocumentsError;
    }

    /**
     * Sync one document from e-PIRMA latest-document-base-path (docs) / signed-document fallback.
     *
     * Cancel detection (in priority order):
     * 1) Explicit remote status cancelled/canceled (payload or all-signers cancelled)
     * 2) Callback status=cancelled (handled separately via applyCallbackStatus)
     * 3) Repeated HTTP 404/410 "Document not found" on latest-document-base-path for a
     *    previously handed-off open route (see EpirmaService). Soft-delete cancel in the
     *    e-PIRMA UI often keeps returning pending signers with no status change — that
     *    case cannot be detected from the public integrator APIs and must rely on (1)/(2)
     *    or an eventual hard not-found from e-PIRMA.
     *
     * @return array{success: bool, message?: string, data?: array<string, mixed>, cancelled_via?: string}
     */
    public function syncDocument(EpirmaSignedDocument $document, bool $finalizeRequestWhenSigned = true): array
    {
        $uuid = trim((string) $document->document_uuid);
        if ($uuid === '') {
            return ['success' => false, 'message' => 'Document UUID is missing.'];
        }

        $result = $this->epirmaService->fetchLatestDocumentBasePath($uuid);

        if ($result['success'] ?? false) {
            $this->clearRemoteNotFoundStreak($document);

            $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
            $normalized = $this->normalizePayload($document, $payload);
            $this->persistDocument($document, $normalized);

            $fresh = $document->fresh();
            if ($fresh && $this->needsForwardedDocumentsEnrichment($fresh)) {
                $this->enrichFromForwardedDocuments($fresh, $finalizeRequestWhenSigned);
                $fresh = $document->fresh() ?? $fresh;
            }

            if ($fresh && $fresh->isSigned()) {
                // Always cache signed bytes (assessment + response letter). Callbacks use
                // finalizeRequestWhenSigned=false and previously skipped promote/cache.
                if ($finalizeRequestWhenSigned) {
                    $this->promoteRequestWhenSigned($fresh);
                } else {
                    $this->ensureCachedSignedPdf($fresh);
                }
            }

            return [
                'success' => true,
                'data' => $this->toApiPayload($fresh ?? $document, $normalized),
            ];
        }

        // A newly routed document may be indexed in Connect before the UUID status
        // endpoint recognizes it. Prefer the matching current forwarded transaction
        // before accumulating a remote-not-found cancellation streak.
        if (($result['not_found'] ?? false)
            && $this->needsForwardedDocumentsEnrichment($document)
            && $this->enrichFromForwardedDocuments($document, $finalizeRequestWhenSigned)
        ) {
            $fresh = $document->fresh() ?? $document;

            return [
                'success' => true,
                'data' => $this->toApiPayload($fresh),
            ];
        }

        // latest-document-base-path is the only reliable "gone" signal. Do not treat
        // /api/signed-document 404 as cancel — unsigned routes always 404 there.
        if (($result['not_found'] ?? false) && $this->eligibleForRemoteGoneCancel($document)) {
            if ($this->registerRemoteNotFoundAndMaybeCancel($document)) {
                return [
                    'success' => true,
                    'cancelled_via' => 'remote_not_found',
                    'data' => $this->toApiPayload($document->fresh() ?? $document),
                ];
            }

            $document->forceFill(['last_synced_at' => now()])->save();

            return [
                'success' => false,
                'message' => 'Remote document not found; awaiting confirmation before marking cancelled.',
                'data' => $this->toApiPayload($document->fresh()),
            ];
        }

        // Non-not-found latest failure: signed-document may still report a completed sign.
        $signed = $this->epirmaService->fetchSignedDocumentStatus($uuid);
        if ($signed['success'] ?? false) {
            $this->clearRemoteNotFoundStreak($document);

            $payload = is_array($signed['data'] ?? null) ? $signed['data'] : [];
            $normalized = $this->normalizePayload($document, $payload);
            $this->persistDocument($document, $normalized);

            $fresh = $document->fresh();
            if ($fresh && $this->needsForwardedDocumentsEnrichment($fresh)) {
                $this->enrichFromForwardedDocuments($fresh, $finalizeRequestWhenSigned);
                $fresh = $document->fresh() ?? $fresh;
            }

            if ($fresh && $fresh->isSigned()) {
                if ($finalizeRequestWhenSigned) {
                    $this->promoteRequestWhenSigned($fresh);
                } else {
                    $this->ensureCachedSignedPdf($fresh);
                }
            }

            return [
                'success' => true,
                'data' => $this->toApiPayload($fresh ?? $document, $normalized),
            ];
        }

        // UUID polls failed / incomplete: Connect forwarded-documents may still expose the signed PDF.
        if ($this->needsForwardedDocumentsEnrichment($document)
            && $this->enrichFromForwardedDocuments($document, $finalizeRequestWhenSigned)
        ) {
            $fresh = $document->fresh() ?? $document;

            return [
                'success' => true,
                'data' => $this->toApiPayload($fresh),
            ];
        }

        $document->forceFill(['last_synced_at' => now()])->save();

        return [
            'success' => false,
            'message' => $result['message'] ?? $signed['message'] ?? 'Failed to fetch latest status.',
            'data' => $this->toApiPayload($document->fresh()),
        ];
    }

    /**
     * Sync the current/latest document for a request (by epirma_transaction_id when set).
     *
     * @return array{success: bool, message?: string, data?: array<string, mixed>}
     */
    public function syncLatestForRequest(AssistanceRequest $request, bool $finalizeRequestWhenSigned = true): array
    {
        $document = $this->resolveCurrentDocument($request);
        if (! $document) {
            return ['success' => false, 'message' => 'Document UUID is missing.'];
        }

        return $this->syncDocument($document, $finalizeRequestWhenSigned);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForRequest(AssistanceRequest $request): Collection
    {
        return EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->whereIn('document_type', [
                EpirmaSignedDocument::TYPE_ASSESSMENT,
                EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
            ])
            ->whereIn('routing_status', EpirmaSignedDocument::TRACKABLE_STATUSES)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EpirmaSignedDocument $document): array => $this->toApiPayload($document));
    }

    /**
     * Sync all open (non-terminal) routed documents.
     *
     * @return array{synced: int, signed: int, failed: int}
     */
    public function syncOpenDocuments(int $limit = 100, ?array $documentTypes = null): array
    {
        $synced = 0;
        $signed = 0;
        $failed = 0;

        EpirmaSignedDocument::query()
            ->when($documentTypes !== null, fn ($query) => $query->whereIn('document_type', $documentTypes))
            ->where(function ($query): void {
                $query->whereIn('routing_status', EpirmaSignedDocument::OPEN_STATUSES)
                    // Re-check recent false cancels so live e-PIRMA routes can recover.
                    ->orWhere(function ($cancelled): void {
                        $cancelled->where('routing_status', EpirmaSignedDocument::STATUS_CANCELLED)
                            ->where('updated_at', '>=', now()->subDays(2));
                    });
            })
            ->whereNotNull('document_uuid')
            ->orderBy('last_synced_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (EpirmaSignedDocument $document) use (&$synced, &$signed, &$failed): void {
                $result = $this->syncDocument($document, true);
                $synced++;
                if (! ($result['success'] ?? false)) {
                    $failed++;

                    return;
                }
                if (($result['data']['routing_status'] ?? null) === EpirmaSignedDocument::STATUS_SIGNED) {
                    $signed++;
                }
            });

        return compact('synced', 'signed', 'failed');
    }

    public function resolveCurrentDocument(AssistanceRequest $request): ?EpirmaSignedDocument
    {
        return EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->when(
                filled($request->epirma_transaction_id),
                fn ($query) => $query->where('document_uuid', $request->epirma_transaction_id)
            )
            ->latest('id')
            ->first();
    }

    public function applyCallbackStatus(EpirmaSignedDocument $document, string $normalizedStatus, ?string $signatureReference = null): void
    {
        $updates = [
            'last_synced_at' => now(),
        ];

        if (filled($signatureReference)) {
            $updates['signature_reference'] = $signatureReference;
        }

        if ($normalizedStatus === EpirmaSignedDocument::STATUS_SIGNED) {
            $updates['routing_status'] = EpirmaSignedDocument::STATUS_SIGNED;
            $updates['completed_at'] = $document->completed_at ?: now();
        } elseif ($normalizedStatus === EpirmaSignedDocument::STATUS_CANCELLED) {
            $updates['routing_status'] = EpirmaSignedDocument::STATUS_CANCELLED;
            Log::info('e-PIRMA callback marked document cancelled.', [
                'document_id' => $document->id,
                'assistance_request_id' => $document->assistance_request_id,
                'document_uuid' => $document->document_uuid,
            ]);
        } elseif ($normalizedStatus === EpirmaSignedDocument::STATUS_FAILED) {
            $updates['routing_status'] = EpirmaSignedDocument::STATUS_FAILED;
        } elseif (in_array($normalizedStatus, [EpirmaSignedDocument::STATUS_ROUTED, EpirmaSignedDocument::STATUS_PENDING, 'returned'], true)) {
            if (! $document->isSigned()) {
                $updates['routing_status'] = EpirmaSignedDocument::STATUS_ROUTED;
                $updates['routed_at'] = $document->routed_at ?: now();
            }
        }

        $document->forceFill($updates)->save();
        $this->clearRemoteNotFoundStreak($document);

        $request = $document->assistanceRequest ?: $document->assistanceRequest()->first();
        if ($request) {
            $this->refreshAaStatus($request);
        }
    }

    private function eligibleForRemoteGoneCancel(EpirmaSignedDocument $document): bool
    {
        if (! $document->isOpen()) {
            return false;
        }

        // Only handed-off routes: pre-handoff pending UUIDs may 404 before e-PIRMA indexes them.
        $handedOff = filled($document->routed_at)
            || in_array((string) $document->routing_status, [
                EpirmaSignedDocument::STATUS_ROUTED,
                EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
            ], true);

        if (! $handedOff) {
            return false;
        }

        // Brand-new handoffs often 404 briefly; waiting avoids false CANCELLED badges.
        $routedAt = $document->routed_at;
        if ($routedAt && $routedAt->greaterThan(now()->subMinutes(self::REMOTE_NOT_FOUND_GRACE_MINUTES))) {
            return false;
        }

        return true;
    }

    private function remoteNotFoundCacheKey(EpirmaSignedDocument $document): string
    {
        return 'epirma.remote_not_found.'.$document->id;
    }

    private function clearRemoteNotFoundStreak(EpirmaSignedDocument $document): void
    {
        Cache::forget($this->remoteNotFoundCacheKey($document));
    }

    /**
     * True when a remote poll clearly shows the document is still out for signature
     * (or otherwise alive) — used to correct false-positive local cancels.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function normalizedIndicatesLiveRoute(array $normalized): bool
    {
        if (in_array((string) ($normalized['routing_status'] ?? ''), [
            EpirmaSignedDocument::STATUS_SIGNED,
            EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
        ], true)) {
            return true;
        }

        if (filled($normalized['remote_document_url'] ?? null) || filled($normalized['remote_base_path'] ?? null)) {
            return true;
        }

        $signers = is_array($normalized['signers'] ?? null) ? $normalized['signers'] : [];
        if ($signers === []) {
            return false;
        }

        $statuses = collect($signers)
            ->map(fn ($signer) => strtolower(trim((string) data_get($signer, 'status', ''))))
            ->filter()
            ->values();

        if ($statuses->isEmpty()) {
            return false;
        }

        // All-cancelled signers are an explicit cancel signal, not a live route.
        if ($statuses->every(fn (string $status) => in_array($status, ['cancelled', 'canceled'], true))) {
            return false;
        }

        return $statuses->contains(fn (string $status) => ! in_array($status, ['cancelled', 'canceled', 'failed', 'error'], true));
    }

    /**
     * Increment consecutive not-found polls; cancel once the streak is confirmed.
     */
    private function registerRemoteNotFoundAndMaybeCancel(EpirmaSignedDocument $document): bool
    {
        $key = $this->remoteNotFoundCacheKey($document);
        $streak = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $streak, now()->addHours(6));

        Log::info('e-PIRMA latest-document-base-path reported document not found.', [
            'document_id' => $document->id,
            'assistance_request_id' => $document->assistance_request_id,
            'document_uuid' => $document->document_uuid,
            'streak' => $streak,
            'required' => self::REMOTE_NOT_FOUND_CANCEL_STREAK,
        ]);

        if ($streak < self::REMOTE_NOT_FOUND_CANCEL_STREAK) {
            return false;
        }

        $this->markCancelledFromRemoteGone($document);
        Cache::forget($key);

        return true;
    }

    private function markCancelledFromRemoteGone(EpirmaSignedDocument $document): void
    {
        $previousStatus = (string) ($document->routing_status ?? '');
        $document->forceFill([
            'routing_status' => EpirmaSignedDocument::STATUS_CANCELLED,
            'last_synced_at' => now(),
        ])->save();

        $request = $document->assistanceRequest ?: $document->assistanceRequest()->first();
        if (! $request) {
            return;
        }

        $this->refreshAaStatus($request);

        if ($previousStatus !== EpirmaSignedDocument::STATUS_CANCELLED) {
            app(WorkflowNotificationService::class)->broadcastEpirmaStatusChanged($request->fresh() ?? $request, [
                'routing_status' => EpirmaSignedDocument::STATUS_CANCELLED,
                'previous_routing_status' => $previousStatus !== '' ? $previousStatus : null,
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'source' => 'remote_not_found',
            ]);
        }
    }

    /**
     * Recompute DRRS AA queue status from signed/open route documents.
     * Cancelled/failed routes alone do not keep the request "in_progress".
     */
    public function refreshAaStatus(AssistanceRequest $request): string
    {
        $status = $this->deriveAaStatus($request->fresh() ?? $request);
        $fresh = $request->fresh() ?? $request;
        if ((string) $fresh->epirma_aa_status !== $status) {
            $fresh->forceFill(['epirma_aa_status' => $status])->save();
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   routing_status: string,
     *   signers: array<int, array<string, mixed>>,
     *   remote_document_url: ?string,
     *   remote_base_path: ?string,
     *   signature_reference: ?string,
     *   view_url: ?string,
     *   signed_document: array<string, mixed>,
     *   raw: array<string, mixed>
     * }
     */
    public function normalizePayload(EpirmaSignedDocument $document, array $payload): array
    {
        $signedDocument = data_get($payload, 'signed_document', $payload);
        if (is_array(data_get($signedDocument, 'signed_document'))) {
            $signedDocument = $signedDocument['signed_document'];
        }
        if (! is_array($signedDocument)) {
            $signedDocument = [];
        }

        $documentUrl = $signedDocument['document_url']
            ?? data_get($payload, 'base_path')
            ?? data_get($payload, 'document_url')
            ?? null;
        $documentUrl = filled($documentUrl) ? (string) $documentUrl : null;

        $signers = data_get($payload, 'signers');
        if (! is_array($signers) || $signers === []) {
            $signers = data_get($signedDocument, 'signers');
        }
        if (! is_array($signers)) {
            $signers = [];
        }

        if ($signers === [] && $signedDocument !== []) {
            $signers = [[
                'fullname' => $signedDocument['original_filename'] ?? $document->document_name,
                'username' => $signedDocument['uuid'] ?? (string) $document->document_uuid,
                'type' => 'signed-document',
                'status' => $signedDocument['signing_status'] ?? data_get($payload, 'status', 'unknown'),
                'date_signed' => $signedDocument['signed_at'] ?? data_get($payload, 'signed_at'),
            ]];
        }

        $routingStatus = $this->deriveRoutingStatus($payload, $signedDocument, $signers, (string) ($document->routing_status ?: EpirmaSignedDocument::STATUS_ROUTED));

        $signatureReference = $signedDocument['signed_filename']
            ?? $signedDocument['uuid']
            ?? data_get($payload, 'signature_reference')
            ?? $document->signature_reference;

        return [
            'routing_status' => $routingStatus,
            'signers' => array_values($signers),
            'remote_document_url' => $documentUrl,
            'remote_base_path' => filled(data_get($payload, 'base_path')) ? (string) data_get($payload, 'base_path') : $documentUrl,
            'signature_reference' => filled($signatureReference) ? (string) $signatureReference : null,
            'view_url' => $this->buildViewUrl($documentUrl),
            'signed_document' => $signedDocument !== [] ? $signedDocument : $payload,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function persistDocument(EpirmaSignedDocument $document, array $normalized): void
    {
        $previousStatus = (string) ($document->routing_status ?? '');
        $nextStatus = (string) $normalized['routing_status'];

        // Ambiguous or stale remote polls must not reopen a cancelled/failed route.
        // Clear live evidence (pending/signed signers, remote PDF URL, explicit signed)
        // may correct a false-positive local cancel.
        if (
            in_array($previousStatus, [
                EpirmaSignedDocument::STATUS_CANCELLED,
                EpirmaSignedDocument::STATUS_FAILED,
            ], true)
            && in_array($nextStatus, EpirmaSignedDocument::OPEN_STATUSES, true)
            && ! $this->normalizedIndicatesLiveRoute($normalized)
        ) {
            $nextStatus = $previousStatus;
        }

        $updates = [
            'routing_status' => $nextStatus,
            'signers' => $normalized['signers'],
            'remote_document_url' => $normalized['remote_document_url'],
            'remote_base_path' => $normalized['remote_base_path'],
            'signature_reference' => $normalized['signature_reference'] ?? $document->signature_reference,
            'last_synced_at' => now(),
        ];

        if ($nextStatus === EpirmaSignedDocument::STATUS_SIGNED) {
            $updates['completed_at'] = $document->completed_at ?: now();
        }

        if (in_array($nextStatus, [EpirmaSignedDocument::STATUS_ROUTED, EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED], true)) {
            $updates['routed_at'] = $document->routed_at ?: now();
        }

        $document->forceFill($updates)->save();

        if ($previousStatus !== $nextStatus) {
            $request = $document->assistanceRequest ?: $document->assistanceRequest()->first();
            if ($request) {
                $this->refreshAaStatus($request);
                app(WorkflowNotificationService::class)->broadcastEpirmaStatusChanged($request->fresh() ?? $request, [
                    'routing_status' => $nextStatus,
                    'previous_routing_status' => $previousStatus !== '' ? $previousStatus : null,
                    'document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'source' => 'status_sync',
                ]);
            }
        }
    }

    private function promoteRequestWhenSigned(EpirmaSignedDocument $document): void
    {
        $request = $document->assistanceRequest;
        if (! $request) {
            return;
        }

        $action = (string) ($document->action ?: EpirmaSignedDocument::ACTION_ROUTE);
        $documentType = (string) ($document->document_type ?: EpirmaSignedDocument::TYPE_ASSESSMENT);
        $notifications = app(WorkflowNotificationService::class);

        // Legacy self-sign rows (if any) only unlock AA tracking; do not finalize.
        if ($action === EpirmaSignedDocument::ACTION_SIGN) {
            $request->forceFill([
                'epirma_status' => 'pending',
                'epirma_signature_reference' => $document->signature_reference ?: $request->epirma_signature_reference,
                'epirma_signed_at' => $document->completed_at ?: now(),
            ])->save();

            return;
        }

        if ($documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER) {
            $alreadySent = filled($request->lgu_response_letter_sent_at);
            $request->forceFill([
                'epirma_response_letter_signed_at' => $request->epirma_response_letter_signed_at ?: ($document->completed_at ?: now()),
                'lgu_response_letter_sent_at' => $request->lgu_response_letter_sent_at ?: now(),
            ])->save();

            // Cache signed bytes into a distinct path so LGU never sees the unsigned handoff PDF.
            $this->ensureCachedSignedPdf($document->fresh() ?? $document);

            $fresh = $request->fresh();
            $fresh->forceFill([
                'epirma_aa_status' => $this->deriveAaStatus($fresh),
            ])->save();

            if (! $alreadySent) {
                $notifications->notifyRrosEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_RESPONSE_LETTER);
                $notifications->notifyPdrcEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_RESPONSE_LETTER);
                if (! $fresh->lgu_response_letter_acked_at) {
                    $notifications->notifyLguSignedResponseLetter($fresh);
                } else {
                    $notifications->broadcastLguFniProcessingUpdated($fresh, [
                        'reason' => 'signed_response_letter',
                        'copy' => 'signed',
                    ]);
                }
            }

            return;
        }

        // Assessment route fully signed → finalize assessment and notify RROS + PDRC.
        $alreadySigned = filled($request->epirma_assessment_signed_at);
        $updates = [
            'epirma_status' => 'signed',
            'epirma_signature_reference' => $document->signature_reference ?: $request->epirma_signature_reference,
            'epirma_signed_at' => $document->completed_at ?: now(),
            'epirma_assessment_signed_at' => $request->epirma_assessment_signed_at ?: ($document->completed_at ?: now()),
        ];

        if ($request->assessment_status === 'draft') {
            $updates['epirma_callback_token'] = null;
            $updates['assessment_status'] = 'final';
            $updates['status'] = 'acted';
            Cache::forget('epirma.callback_token.'.$request->id);
        }

        $request->forceFill($updates)->save();

        // Cache signed bytes into a distinct path (parity with response letter).
        $this->ensureCachedSignedPdf($document->fresh() ?? $document);

        $fresh = $request->fresh();
        $fresh->forceFill([
            'epirma_aa_status' => $this->deriveAaStatus($fresh),
        ])->save();

        if (! $alreadySigned) {
            $notifications->notifyRrosEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_ASSESSMENT);
            $notifications->notifyPdrcEpirmaDocumentSigned($fresh, EpirmaSignedDocument::TYPE_ASSESSMENT);
        }
    }

    private function deriveAaStatus(AssistanceRequest $request): string
    {
        $assessmentSigned = filled($request->epirma_assessment_signed_at)
            || EpirmaSignedDocument::query()
                ->where('assistance_request_id', $request->id)
                ->where('document_type', EpirmaSignedDocument::TYPE_ASSESSMENT)
                ->where('action', EpirmaSignedDocument::ACTION_ROUTE)
                ->where('routing_status', EpirmaSignedDocument::STATUS_SIGNED)
                ->exists();

        $responseSigned = filled($request->epirma_response_letter_signed_at)
            || EpirmaSignedDocument::query()
                ->where('assistance_request_id', $request->id)
                ->where('document_type', EpirmaSignedDocument::TYPE_RESPONSE_LETTER)
                ->where('action', EpirmaSignedDocument::ACTION_ROUTE)
                ->where('routing_status', EpirmaSignedDocument::STATUS_SIGNED)
                ->exists();

        if ($assessmentSigned && $responseSigned) {
            return 'completed';
        }

        if ($assessmentSigned || $responseSigned) {
            return 'in_progress';
        }

        $hasOpenRoute = EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->where('action', EpirmaSignedDocument::ACTION_ROUTE)
            ->whereIn('routing_status', EpirmaSignedDocument::OPEN_STATUSES)
            ->exists();

        if ($hasOpenRoute) {
            return 'in_progress';
        }

        // Forwarded with only cancelled/failed (or no) routes → awaiting re-route.
        return filled($request->epirma_forwarded_to_drrs_aa_at)
            ? 'pending'
            : (string) ($request->epirma_aa_status ?: 'pending');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $signedDocument
     * @param  array<int, array<string, mixed>>  $signers
     */
    private function deriveRoutingStatus(array $payload, array $signedDocument, array $signers, string $fallback): string
    {
        $rawStatus = strtolower(trim((string) (
            $signedDocument['signing_status']
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'signing_status')
            ?? ''
        )));

        if (in_array($rawStatus, ['cancelled', 'canceled'], true)) {
            return EpirmaSignedDocument::STATUS_CANCELLED;
        }
        if (in_array($rawStatus, ['failed', 'error'], true)) {
            return EpirmaSignedDocument::STATUS_FAILED;
        }

        if ($signers !== []) {
            $statuses = collect($signers)
                ->map(fn ($signer) => strtolower(trim((string) data_get($signer, 'status', ''))))
                ->filter()
                ->values();

            if ($statuses->isNotEmpty()) {
                if ($statuses->every(fn (string $status) => in_array($status, ['cancelled', 'canceled'], true))) {
                    return EpirmaSignedDocument::STATUS_CANCELLED;
                }

                $signedCount = $statuses->filter(fn (string $status) => in_array($status, ['signed', 'completed', 'complete', 'success', 'successful'], true))->count();
                $total = $statuses->count();

                if ($signedCount > 0 && $signedCount >= $total) {
                    return EpirmaSignedDocument::STATUS_SIGNED;
                }
                if ($signedCount > 0) {
                    return EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED;
                }

                // Pending / in-progress signers mean the route is still live — even when local
                // status was falsely marked cancelled from an earlier not-found streak.
                return in_array($fallback, EpirmaSignedDocument::OPEN_STATUSES, true)
                    ? ($fallback === EpirmaSignedDocument::STATUS_PENDING
                        ? EpirmaSignedDocument::STATUS_ROUTED
                        : $fallback)
                    : EpirmaSignedDocument::STATUS_ROUTED;
            }
        }

        // A document-level Completed/Signed value is authoritative only when the API
        // did not return assigned signatories. When signers exist, every signer must
        // independently show signed evidence before the route can be completed.
        if (in_array($rawStatus, ['signed', 'completed', 'complete', 'success', 'successful'], true)) {
            return EpirmaSignedDocument::STATUS_SIGNED;
        }

        if (filled($signedDocument['signed_at'] ?? null) || filled(data_get($payload, 'signed_at'))) {
            return EpirmaSignedDocument::STATUS_SIGNED;
        }

        // Preserve signed when remote is ambiguous. Cancelled/failed without live signers
        // stay terminal so stale polls do not silently reopen a true cancel.
        if ($fallback === EpirmaSignedDocument::STATUS_SIGNED) {
            return $fallback;
        }
        if (
            in_array($fallback, [
                EpirmaSignedDocument::STATUS_CANCELLED,
                EpirmaSignedDocument::STATUS_FAILED,
            ], true)
        ) {
            return $fallback;
        }

        if (in_array($fallback, EpirmaSignedDocument::OPEN_STATUSES, true)) {
            return $fallback === EpirmaSignedDocument::STATUS_PENDING
                ? EpirmaSignedDocument::STATUS_ROUTED
                : $fallback;
        }

        return EpirmaSignedDocument::STATUS_ROUTED;
    }

    public function buildViewUrl(?string $documentUrl): ?string
    {
        if (! filled($documentUrl)) {
            return null;
        }

        $token = $this->usableDocumentToken();
        if ($token === '') {
            return $documentUrl;
        }

        $separator = str_contains($documentUrl, '?') ? '&' : '?';

        return $documentUrl.$separator.'token='.rawurlencode($token);
    }

    /**
     * Ignore empty / misconfigured tokens (e.g. this app's APP_KEY pasted into EPIRMA_DOCUMENT_TOKEN).
     * Other base64:… values are valid public-secure-file tokens used by ePIRMA.
     */
    public function usableDocumentToken(): string
    {
        $token = trim((string) config('services.epirma.document_token', ''));
        if ($token === '') {
            return '';
        }

        $appKey = trim((string) config('app.key', ''));
        if ($appKey !== '' && hash_equals($appKey, $token)) {
            return '';
        }

        return $token;
    }

    public function isCachedSignedPath(?string $path): bool
    {
        $path = trim((string) $path);

        return $path !== '' && str_starts_with($path, self::SIGNED_CACHE_DIR);
    }

    public function hasSignedArtifact(EpirmaSignedDocument $document): bool
    {
        if (! $document->isSigned()) {
            return false;
        }

        if (filled($document->remote_document_url) || filled($document->remote_base_path)) {
            return true;
        }

        $path = (string) ($document->document_path ?? '');

        return $this->isCachedSignedPath($path) && Storage::disk('public')->exists($path);
    }

    /**
     * Download + cache signed PDFs that are marked signed but still point at the
     * unsigned handoff path (or have no local signed cache yet).
     *
     * @return array{cached: int, failed: int, skipped: int}
     */
    public function cacheMissingSignedPdfs(int $limit = 50): array
    {
        $cached = 0;
        $failed = 0;
        $skipped = 0;

        EpirmaSignedDocument::query()
            ->where('routing_status', EpirmaSignedDocument::STATUS_SIGNED)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (EpirmaSignedDocument $document) use (&$cached, &$failed, &$skipped): void {
                $path = trim((string) ($document->document_path ?? ''));
                if ($this->isCachedSignedPath($path) && Storage::disk('public')->exists($path)) {
                    $skipped++;

                    return;
                }

                if ($this->ensureCachedSignedPdf($document)) {
                    $cached++;

                    return;
                }

                // Signed but download failed — try Connect forwarded-documents for a signed URL.
                if ($this->enrichFromForwardedDocuments($document, false)
                    && $this->ensureCachedSignedPdf($document->fresh() ?? $document)
                ) {
                    $cached++;

                    return;
                }

                $failed++;
            });

        return compact('cached', 'failed', 'skipped');
    }

    /**
     * Download the e-PIRMA signed PDF (when needed) into a distinct cache path.
     * Never treats the unsigned route handoff / advance PDF as signed.
     *
     * @return string|null Relative public-disk path to cached signed PDF
     */
    public function ensureCachedSignedPdf(EpirmaSignedDocument $document): ?string
    {
        if (! $document->isSigned()) {
            return null;
        }

        $existing = trim((string) ($document->document_path ?? ''));
        if ($this->isCachedSignedPath($existing) && Storage::disk('public')->exists($existing)) {
            return $existing;
        }

        $binary = $this->downloadSignedPdfBinary($document);
        if ($binary === null && ! $this->enrichingFromForwardedDocuments) {
            // Remote URL missing or download failed — enrich from Connect forwarded-documents once.
            if ($this->enrichFromForwardedDocuments($document, false)) {
                $document = $document->fresh() ?? $document;
                $binary = $this->downloadSignedPdfBinary($document, false);
            }
        }
        if ($binary === null) {
            return null;
        }

        $newPath = self::SIGNED_CACHE_DIR.$document->id.'-'.Str::lower(Str::random(10)).'.pdf';
        Storage::disk('public')->put($newPath, $binary);

        $advancePath = trim((string) ($document->assistanceRequest?->lgu_response_letter_advance_path
            ?? $document->assistanceRequest()->value('lgu_response_letter_advance_path')
            ?? ''));

        $document->forceFill(['document_path' => $newPath])->save();

        if ($existing !== ''
            && $existing !== $newPath
            && $existing !== $advancePath
            && ! $this->isCachedSignedPath($existing)
            && Storage::disk('public')->exists($existing)
        ) {
            Storage::disk('public')->delete($existing);
        }

        return $newPath;
    }

    /**
     * Fetch signed PDF bytes from e-PIRMA. Syncs once when remote URL is missing.
     * Tries raw URL, EPIRMA_DOCUMENT_TOKEN, build-authorize JWT (docs), host fallbacks.
     *
     * @return string|null PDF bytes, or null when every candidate failed
     */
    public function downloadSignedPdfBinary(EpirmaSignedDocument $document, bool $allowSync = true): ?string
    {
        $this->lastSignedDownloadError = null;

        if (! $document->isSigned()) {
            return null;
        }

        $rawUrl = trim((string) ($document->remote_document_url ?: $document->remote_base_path ?: ''));
        if ($rawUrl === '' && $allowSync) {
            $this->syncDocument($document, false);
            $document = $document->fresh() ?? $document;
            $rawUrl = trim((string) ($document->remote_document_url ?: $document->remote_base_path ?: ''));
        }

        if ($rawUrl === '') {
            $this->lastSignedDownloadError = 'No remote signed URL is stored for this document.';
            Log::warning('e-PIRMA signed document has no remote URL to download.', [
                'document_id' => $document->id,
            ]);

            return null;
        }

        $candidates = $this->signedDownloadUrlCandidates($rawUrl, $document);
        $lastError = null;
        $sawInvalidToken = false;

        foreach ($candidates as $url) {
            try {
                $remote = $this->httpForSignedDownload($url)
                    ->withHeaders(['Accept' => 'application/pdf,application/json,*/*'])
                    ->get($url);

                $body = $remote->body();
                $apiMessage = is_string(data_get($remote->json(), 'message'))
                    ? trim((string) data_get($remote->json(), 'message'))
                    : '';

                if (! $remote->successful() || blank($body)) {
                    if ($remote->status() === 403 && (
                        str_contains(strtolower($apiMessage), 'invalid or missing token')
                        || str_contains(strtolower($body), 'invalid or missing token')
                    )) {
                        $sawInvalidToken = true;
                        $lastError = 'Invalid or missing token for public-secure-file (HTTP 403).';
                    } else {
                        $lastError = 'HTTP '.$remote->status().' for '.parse_url($url, PHP_URL_HOST)
                            .($apiMessage !== '' ? ': '.$apiMessage : '');
                    }
                    Log::warning('e-PIRMA signed document download HTTP failure.', [
                        'document_id' => $document->id,
                        'status' => $remote->status(),
                        'host' => parse_url($url, PHP_URL_HOST),
                        'api_message' => $apiMessage !== '' ? $apiMessage : null,
                    ]);

                    continue;
                }

                if (! str_starts_with($body, '%PDF')) {
                    $lastError = 'Non-PDF response from '.parse_url($url, PHP_URL_HOST);
                    Log::warning('e-PIRMA signed document download was not a PDF.', [
                        'document_id' => $document->id,
                        'content_type' => $remote->header('Content-Type'),
                        'host' => parse_url($url, PHP_URL_HOST),
                        'status' => $remote->status(),
                    ]);

                    continue;
                }

                return $body;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Failed to download signed e-PIRMA document.', [
                    'document_id' => $document->id,
                    'host' => parse_url($url, PHP_URL_HOST),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sawInvalidToken) {
            $this->lastSignedDownloadError = 'e-PIRMA rejected the file token (Invalid or missing token). '
                .'Set EPIRMA_DOCUMENT_TOKEN to the public-secure-file access token from RICTMS, '
                .'or confirm build-authorize JWTs are accepted on this e-PIRMA host.';
        } elseif ($lastError !== null) {
            $this->lastSignedDownloadError = $lastError;
        }

        if ($lastError !== null) {
            Log::warning('Exhausted e-PIRMA signed document download candidates.', [
                'document_id' => $document->id,
                'candidates' => count($candidates),
                'last_error' => $lastError,
                'invalid_token' => $sawInvalidToken,
            ]);
        }

        return null;
    }

    /**
     * Resolve signed PDF URL (+ optional completed status) via Connect forwarded-documents.
     *
     * @return bool True when remote URL (and optionally routing_status) was updated
     */
    public function enrichFromForwardedDocuments(EpirmaSignedDocument $document, bool $finalizeRequestWhenSigned = true): bool
    {
        if ($this->enrichingFromForwardedDocuments) {
            return false;
        }

        $this->enrichingFromForwardedDocuments = true;
        $this->lastForwardedDocumentsError = null;

        try {
            $username = $this->resolveForwardedDocumentsUsername($document);
            if ($username === null || $username === '') {
                $this->lastForwardedDocumentsError = 'No Connect username (set EPIRMA_FORWARDED_DOCUMENTS_USERNAME or ensure the initiating AA has a username).';
                Log::info('Skipping forwarded-documents enrichment: no Connect username.', [
                    'document_id' => $document->id,
                ]);

                return false;
            }

            $result = $this->epirmaService->fetchForwardedDocuments($username);
            if (! ($result['success'] ?? false)) {
                $this->lastForwardedDocumentsError = (string) ($result['message']
                    ?? 'Connect forwarded-documents request failed.');

                return false;
            }

            $matched = $this->matchForwardedDocument(
                $document,
                is_array($result['documents'] ?? null) ? $result['documents'] : []
            );
            if ($matched === null) {
                $this->lastForwardedDocumentsError = 'No matching forwarded document for username '.$username.'.';

                return false;
            }

            $remoteStatus = strtolower(trim((string) ($matched['status'] ?? '')));
            $signatories = is_array($matched['signatories'] ?? null) ? $matched['signatories'] : [];
            $mappedSigners = $this->mapForwardedSignatories($signatories);
            $signedCount = collect($mappedSigners)->where('status', 'signed')->count();
            $completed = $signatories !== []
                ? collect($signatories)->every(
                    fn ($signer): bool => (bool) data_get($signer, 'is_signed', false)
                        || in_array(strtolower(trim((string) data_get($signer, 'status', ''))), [
                            'signed', 'completed', 'complete', 'success', 'successful',
                        ], true)
                )
                : $remoteStatus === 'completed';
            $signedUrl = $this->extractSignedUrlFromForwardedDocument($matched);

            if ($completed && ($signedUrl === null || $signedUrl === '')) {
                $this->lastForwardedDocumentsError = 'The current completed e-PIRMA transaction has no signed_path.';

                return false;
            }

            $previousStatus = (string) ($document->routing_status ?? '');
            $updates = [
                'remote_document_url' => $completed ? $signedUrl : null,
                'remote_base_path' => $completed ? $signedUrl : null,
                'last_synced_at' => now(),
                'signature_reference' => (string) ($matched['id'] ?? $matched['original_filename'] ?? $document->signature_reference ?? ''),
                'signers' => $mappedSigners,
            ];

            if ($completed) {
                $updates['routing_status'] = EpirmaSignedDocument::STATUS_SIGNED;
                $updates['completed_at'] = $document->completed_at ?: now();
            } else {
                $updates['routing_status'] = $signedCount > 0
                    ? EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED
                    : EpirmaSignedDocument::STATUS_ROUTED;
                $updates['completed_at'] = null;

                // A reused filename may previously have attached an older signed cache.
                // Detach it so a later genuine completion must download fresh signed bytes.
                $existingPath = trim((string) $document->document_path);
                if ($this->isCachedSignedPath($existingPath) && Storage::disk('public')->exists($existingPath)) {
                    Storage::disk('public')->delete($existingPath);
                }
                $updates['document_path'] = 'epirma_signed_documents/'
                    .($document->document_type ?: 'document').'/'
                    .$document->document_uuid.'.pdf';
            }

            $document->forceFill($updates)->save();
            $this->clearRemoteNotFoundStreak($document);

            Log::info('Enriched e-PIRMA document from Connect forwarded-documents.', [
                'document_id' => $document->id,
                'username' => $username,
                'forwarded_id' => $matched['id'] ?? null,
                'promoted_signed' => $completed && $previousStatus !== EpirmaSignedDocument::STATUS_SIGNED,
            ]);

            $fresh = $document->fresh() ?? $document;
            if ($fresh->isSigned()) {
                if ($finalizeRequestWhenSigned && $previousStatus !== EpirmaSignedDocument::STATUS_SIGNED) {
                    $this->promoteRequestWhenSigned($fresh);
                } else {
                    $this->ensureCachedSignedPdf($fresh);
                }
            }

            return true;
        } finally {
            $this->enrichingFromForwardedDocuments = false;
        }
    }

    public function needsForwardedDocumentsEnrichment(?EpirmaSignedDocument $document): bool
    {
        if (! $document) {
            return false;
        }

        $hasRemote = filled($document->remote_document_url) || filled($document->remote_base_path);
        if ($hasRemote) {
            return false;
        }

        // Signed/open without a remote URL — Connect forwarded-documents may supply it.
        return $document->isSigned() || $document->isOpen();
    }

    /**
     * Prefer configured custodian username, then initiating AA Connect username / email local-part.
     */
    public function resolveForwardedDocumentsUsername(EpirmaSignedDocument $document): ?string
    {
        $configured = trim((string) config('services.epirma.forwarded_documents_username', ''));
        if ($configured !== '') {
            return $configured;
        }

        $user = $document->relationLoaded('initiator')
            ? $document->initiator
            : $document->initiator()->first();

        if ($user) {
            $username = trim((string) ($user->username ?? ''));
            if ($username !== '') {
                return $username;
            }

            $email = trim((string) ($user->email ?? ''));
            if ($email !== '' && str_contains($email, '@')) {
                return Str::before($email, '@');
            }
        }

        $myportal = trim((string) config('services.cc_idp.myportal_username', ''));

        return $myportal !== '' ? $myportal : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<string, mixed>|null
     */
    public function matchForwardedDocument(EpirmaSignedDocument $document, array $documents): ?array
    {
        if ($documents === []) {
            return null;
        }

        $localName = strtolower(trim((string) ($document->document_name ?? '')));
        $localType = (string) ($document->document_type ?: EpirmaSignedDocument::TYPE_ASSESSMENT);
        $routeTimestamp = $document->routed_at ?: $document->created_at;
        $oldestAcceptable = $routeTimestamp?->copy()->subMinutes(10);

        $scored = collect($documents)
            ->filter(fn ($row): bool => is_array($row))
            ->filter(function (array $row) use ($oldestAcceptable): bool {
                if (! $oldestAcceptable || blank($row['created_at'] ?? null)) {
                    return true;
                }

                try {
                    return \Illuminate\Support\Carbon::parse((string) $row['created_at'])
                        ->greaterThanOrEqualTo($oldestAcceptable);
                } catch (Throwable) {
                    return true;
                }
            })
            ->map(function (array $row) use ($localName, $localType): array {
                $filename = strtolower(trim((string) ($row['original_filename'] ?? '')));
                $subject = $this->normalizeDocumentSubject((string) ($row['document_subject'] ?? ''));
                $status = strtolower(trim((string) ($row['status'] ?? '')));
                $nameMatch = $localName !== '' && $filename !== '' && (
                    $filename === $localName
                    || str_contains($filename, $localName)
                    || str_contains($localName, $filename)
                    || pathinfo($filename, PATHINFO_FILENAME) === pathinfo($localName, PATHINFO_FILENAME)
                );
                $subjectMatch = $subject !== '' && $this->documentSubjectMatchesType($subject, $localType);
                $completed = $status === 'completed';
                $hasSignedUrl = $this->extractSignedUrlFromForwardedDocument($row) !== null;

                $score = 0;
                if ($nameMatch && $subjectMatch) {
                    $score += 100;
                } elseif ($nameMatch) {
                    $score += 70;
                } elseif ($subjectMatch) {
                    $score += 40;
                }
                if ($completed) {
                    $score += 20;
                }
                if ($hasSignedUrl) {
                    $score += 10;
                }

                return ['row' => $row, 'score' => $score, 'name_match' => $nameMatch, 'subject_match' => $subjectMatch];
            })
            ->filter(fn (array $item): bool => $item['score'] > 0 && ($item['name_match'] || $item['subject_match']))
            ->sortByDesc('score')
            ->values();

        $best = $scored->first();

        return is_array($best['row'] ?? null) ? $best['row'] : null;
    }

    /**
     * Map Connect document_subject (hyphenated) onto local document_type constants.
     */
    public function normalizeDocumentSubject(string $subject): string
    {
        $subject = strtolower(trim($subject));
        $subject = str_replace('_', '-', $subject);

        return $subject;
    }

    public function documentSubjectMatchesType(string $subject, string $documentType): bool
    {
        $subject = $this->normalizeDocumentSubject($subject);
        $type = strtolower(str_replace('_', '-', trim($documentType)));

        if ($subject === $type) {
            return true;
        }

        if ($subject === 'response-letter' && $documentType === EpirmaSignedDocument::TYPE_RESPONSE_LETTER) {
            return true;
        }

        if ($subject === 'assessment' && $documentType === EpirmaSignedDocument::TYPE_ASSESSMENT) {
            return true;
        }

        return false;
    }

    /**
     * Prefer first signed signatory signed_path, else top-level path under signed_forwarded_documents.
     *
     * @param  array<string, mixed>  $row
     */
    public function extractSignedUrlFromForwardedDocument(array $row): ?string
    {
        $signatories = is_array($row['signatories'] ?? null) ? $row['signatories'] : [];
        foreach ($signatories as $signer) {
            if (! is_array($signer)) {
                continue;
            }
            $signedPath = trim((string) ($signer['signed_path'] ?? ''));
            if ($signedPath === '') {
                continue;
            }
            $isSigned = (bool) ($signer['is_signed'] ?? false)
                || in_array(strtolower(trim((string) ($signer['status'] ?? ''))), [
                    'signed', 'completed', 'complete', 'success', 'successful',
                ], true);
            if ($isSigned || str_contains($signedPath, 'signed_forwarded_documents')) {
                return $signedPath;
            }
        }

        $path = trim((string) ($row['path'] ?? ''));
        if ($path !== '' && str_contains($path, 'signed_forwarded_documents')) {
            return $path;
        }

        if ($path !== '' && str_ends_with(strtolower($path), '_signed.pdf')) {
            return $path;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signatories
     * @return array<int, array<string, mixed>>
     */
    private function mapForwardedSignatories(array $signatories): array
    {
        return array_values(array_map(function ($signer): array {
            if (! is_array($signer)) {
                return ['status' => 'unknown'];
            }

            $isSigned = (bool) ($signer['is_signed'] ?? false);

            return [
                'fullname' => $signer['fullname'] ?? $signer['name'] ?? $signer['username'] ?? null,
                'username' => $signer['username'] ?? null,
                'status' => $isSigned
                    ? 'signed'
                    : strtolower(trim((string) ($signer['status'] ?? 'pending'))),
                'date_signed' => $signer['date_signed'] ?? $signer['signed_at'] ?? null,
                'signed_path' => $signer['signed_path'] ?? null,
            ];
        }, $signatories));
    }

    /**
     * @return list<string>
     */
    public function signedDownloadUrlCandidates(string $rawUrl, ?EpirmaSignedDocument $document = null): array
    {
        $tokens = $this->resolveDownloadAccessTokens($document);
        $urls = [];

        foreach ($this->expandRemoteUrlHosts($rawUrl) as $url) {
            // Prefer unauthenticated public-secure-file first; bad tokens yield 403 JSON/HTML.
            $urls[] = $url;

            foreach ($tokens as $token) {
                $urls[] = $this->epirmaService->getAuthorizedDocumentUrl($url, $token);
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Tokens to append as ?token= for public-secure-file (docs: getAuthorizedDocumentUrl).
     *
     * @return list<string>
     */
    public function resolveDownloadAccessTokens(?EpirmaSignedDocument $document = null): array
    {
        $tokens = [];

        $static = $this->usableDocumentToken();
        if ($static !== '') {
            $tokens[] = $static;
        }

        foreach ($this->resolveBuildAuthorizeIdNumbers($document) as $idNumber) {
            try {
                $auth = $this->epirmaService->buildAuthorize($idNumber);
                $jwt = trim((string) ($auth['token'] ?? ''));
                if (($auth['success'] ?? false) && $jwt !== '') {
                    $tokens[] = $jwt;
                }
            } catch (Throwable $e) {
                Log::info('buildAuthorize for signed PDF download failed.', [
                    'id_number' => $idNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @return list<string>
     */
    public function resolveBuildAuthorizeIdNumbers(?EpirmaSignedDocument $document = null): array
    {
        $ids = [];

        $configured = trim((string) config('services.epirma.download_id_number', ''));
        if ($configured !== '') {
            $ids[] = $configured;
        }

        if ($document) {
            $initiator = $document->relationLoaded('initiator')
                ? $document->initiator
                : $document->initiator()->first();
            $initiatorId = trim((string) ($initiator->id_number ?? ''));
            if ($initiatorId !== '') {
                $ids[] = $initiatorId;
            }

            $signers = is_array($document->signers) ? $document->signers : [];
            foreach ($signers as $signer) {
                if (! is_array($signer)) {
                    continue;
                }
                $username = trim((string) ($signer['username'] ?? ''));
                if ($username === '') {
                    continue;
                }
                $user = User::query()
                    ->where('username', $username)
                    ->orWhere('email', 'like', $username.'@%')
                    ->first();
                $signerId = trim((string) ($user->id_number ?? ''));
                if ($signerId !== '') {
                    $ids[] = $signerId;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return list<string>
     */
    private function expandRemoteUrlHosts(string $rawUrl): array
    {
        $urls = [$rawUrl];
        $parts = parse_url($rawUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $urls;
        }

        $fallbacks = config('services.epirma.host_fallbacks', []);
        if (! is_array($fallbacks)) {
            return $urls;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        foreach ($fallbacks as $fallbackHost) {
            $fallbackHost = strtolower(trim((string) $fallbackHost));
            if ($fallbackHost === '' || $fallbackHost === $host) {
                continue;
            }
            $urls[] = $scheme.'://'.$fallbackHost.$path.$query;
        }

        return $urls;
    }

    private function httpForSignedDownload(string $url): PendingRequest
    {
        $verifySsl = (bool) config('services.epirma.verify_ssl', ! app()->environment('local'));
        if (app()->environment('local') && (bool) config('services.epirma.allow_insecure_ssl', true)) {
            $verifySsl = false;
        }

        $request = Http::timeout(45)
            ->when(! $verifySsl, fn ($http) => $http->withoutVerifying());

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $map = config('services.epirma.resolve_map', []);
        $ip = is_array($map) ? ($map[$host] ?? null) : null;
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request = $request->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [
                        $host.':443:'.$ip,
                        $host.':80:'.$ip,
                    ],
                ],
            ]);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>|null  $normalized
     * @return array<string, mixed>
     */
    private function toApiPayload(?EpirmaSignedDocument $document, ?array $normalized = null): array
    {
        if (! $document) {
            return [];
        }

        $base = $document->toTrackingArray();
        $viewUrl = $normalized['view_url'] ?? $this->buildViewUrl($document->remote_document_url ?: $document->remote_base_path);

        return array_merge($base, [
            'document_id' => $document->id,
            'view_url' => $viewUrl,
            'authorized_view_url' => $viewUrl,
            'app_view_url' => $base['app_view_url'] ?? url("/requests/{$document->assistance_request_id}/epirma/documents/{$document->id}/view"),
            'is_signed' => $document->isSigned(),
            'preview_kind' => $document->isSigned() ? 'signed' : 'draft',
            'base_path' => $document->remote_base_path,
            'signed_document' => $normalized['signed_document'] ?? null,
        ]);
    }
}
