<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Models\User;
use Illuminate\Support\Collection;

class EpirmaWorkflowService
{
    /**
     * @return array{
     *   assessment: array<string, mixed>,
     *   response_letter: array<string, mixed>,
     *   forward: array<string, mixed>,
     *   documents: list<array<string, mixed>>,
     *   forwarded: bool,
     *   aa_status: ?string
     * }
     */
    public function capabilitiesFor(AssistanceRequest $request, ?User $actor = null): array
    {
        // Full history (including cancelled/failed) for can_route / resume logic.
        $allDocuments = EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->whereIn('document_type', [
                EpirmaSignedDocument::TYPE_ASSESSMENT,
                EpirmaSignedDocument::TYPE_RESPONSE_LETTER,
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (EpirmaSignedDocument $document): array => $document->toTrackingArray())
            ->values()
            ->all();

        // Track e-PIRMA Status UI: only successfully saved routing transactions (both document types).
        $documents = array_values(array_filter(
            $allDocuments,
            static fn (array $document): bool => in_array(
                (string) ($document['routing_status'] ?? ''),
                EpirmaSignedDocument::TRACKABLE_STATUSES,
                true
            )
        ));

        $actor ??= request()->user();
        $isAa = $actor && ($actor->hasRole('DRRS AA') || $actor->hasRole('Super Admin') || $actor->can('route epirma documents'));
        $isPdrc = $actor && ($actor->hasRole('DRRS') || $actor->hasRole('Super Admin'));

        return [
            'assessment' => $this->documentCapabilities($request, EpirmaSignedDocument::TYPE_ASSESSMENT, $allDocuments, $isAa),
            'response_letter' => $this->documentCapabilities($request, EpirmaSignedDocument::TYPE_RESPONSE_LETTER, $allDocuments, $isAa),
            'forward' => $this->forwardCapabilities($request, $isPdrc),
            'documents' => $documents,
            'forwarded' => filled($request->epirma_forwarded_to_drrs_aa_at),
            'read_only' => filled($request->epirma_forwarded_to_drrs_aa_at),
            'aa_status' => $request->epirma_aa_status,
            'assessment_signed_at' => $request->epirma_assessment_signed_at?->toIso8601String(),
            'response_letter_signed_at' => $request->epirma_response_letter_signed_at?->toIso8601String(),
            'lgu_response_letter_sent_at' => $request->lgu_response_letter_sent_at?->toIso8601String(),
            'lgu_response_letter_acked_at' => $request->lgu_response_letter_acked_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{
     *   document_type: string,
     *   can_sign: bool,
     *   can_route: bool,
     *   can_continue: bool,
     *   can_open_remote: bool,
     *   handoff_complete: bool,
     *   is_in_epirma: bool,
     *   sign_blocked_reason: string,
     *   route_blocked_reason: ?string,
     *   continue_blocked_reason: ?string,
     *   route_document: ?array<string, mixed>,
     *   is_signed: bool
     * }
     */
    private function documentCapabilities(AssistanceRequest $request, string $documentType, array $documents, bool $isAa): array
    {
        $routeDoc = $this->latestFor($documents, $documentType, EpirmaSignedDocument::ACTION_ROUTE);
        $forwarded = filled($request->epirma_forwarded_to_drrs_aa_at);
        $routingStatus = (string) ($routeDoc['routing_status'] ?? '');
        $isSigned = $routingStatus === EpirmaSignedDocument::STATUS_SIGNED;
        $isCancelledOrFailed = in_array($routingStatus, [
            EpirmaSignedDocument::STATUS_CANCELLED,
            EpirmaSignedDocument::STATUS_FAILED,
        ], true);
        // Cancelled/failed routes must not stay "in e-PIRMA" just because routed_at was set earlier.
        $handoffComplete = $routeDoc && ! $isCancelledOrFailed && (
            in_array($routingStatus, [
                EpirmaSignedDocument::STATUS_ROUTED,
                EpirmaSignedDocument::STATUS_PARTIALLY_SIGNED,
                EpirmaSignedDocument::STATUS_SIGNED,
            ], true)
            || filled($routeDoc['routed_at'] ?? null)
        );
        $isPendingResume = $routeDoc
            && $routingStatus === EpirmaSignedDocument::STATUS_PENDING
            && blank($routeDoc['routed_at'] ?? null);
        $alreadyRoutedMessage = 'Already routed in e-PIRMA. Use Track, or open e-PIRMA Out for Signature. Re-routing is blocked to avoid duplicate documents.';

        $canRoute = false;
        $canContinue = false;
        $routeBlockedReason = null;
        $continueBlockedReason = null;

        if (! $isAa) {
            $routeBlockedReason = 'Only DRRS AA can route documents through e-PIRMA.';
            $continueBlockedReason = $routeBlockedReason;
        } elseif (! $forwarded) {
            $routeBlockedReason = 'Waiting for the DRRS PDRC to forward this assessment to DRRS AA.';
            $continueBlockedReason = $routeBlockedReason;
        } elseif ($request->assessment_status !== 'draft' && $documentType === EpirmaSignedDocument::TYPE_ASSESSMENT) {
            $routeBlockedReason = 'Only a draft assessment can still be routed with e-PIRMA.';
            $continueBlockedReason = $routeBlockedReason;
        } elseif (! $this->hasCompleteDocumentDrns($request)) {
            $routeBlockedReason = 'DRRS AA must supply both the Assessment DRN and Response Letter DRN before routing either document.';
            $continueBlockedReason = $routeBlockedReason;
        } elseif ($isSigned) {
            $routeBlockedReason = 'This document already has an e-PIRMA route transaction. Multiple routing is not allowed.';
            $continueBlockedReason = 'This document is already signed in e-PIRMA.';
        } elseif ($handoffComplete) {
            $routeBlockedReason = $alreadyRoutedMessage;
            $continueBlockedReason = $alreadyRoutedMessage;
        } elseif ($isPendingResume) {
            $canContinue = true;
            $routeBlockedReason = 'This document already has a pending e-PIRMA route. Use Continue to resume before handoff completes.';
        } else {
            // Includes cancelled/failed latest rows — BLOCKING_STATUSES excludes them so re-route is allowed.
            $canRoute = true;
            $continueBlockedReason = $isCancelledOrFailed
                ? 'Previous e-PIRMA route ended. Start a new route.'
                : 'There is no open e-PIRMA route to continue.';
        }

        $canOpenRemote = $handoffComplete && (
            filled($routeDoc['remote_document_url'] ?? null)
            || filled($routeDoc['remote_base_path'] ?? null)
        );

        return [
            'document_type' => $documentType,
            'can_sign' => false,
            'can_route' => $canRoute,
            'can_continue' => $canContinue,
            'can_open_remote' => $canOpenRemote,
            'handoff_complete' => (bool) $handoffComplete,
            'is_in_epirma' => (bool) $handoffComplete,
            'sign_blocked_reason' => 'Signing is handled by DRRS AA through e-PIRMA document routing.',
            'route_blocked_reason' => $routeBlockedReason,
            'continue_blocked_reason' => $continueBlockedReason,
            'route_document' => $routeDoc,
            'is_signed' => $isSigned,
        ];
    }

    public function hasCompleteDocumentDrns(AssistanceRequest $request): bool
    {
        return preg_match('/^.+-\d{2}-\d{2}-.+$/', trim((string) $request->assessment_drn)) === 1
            && preg_match('/^.+-\d{2}-\d{2}-.+$/', trim((string) $request->response_drn)) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function forwardCapabilities(AssistanceRequest $request, bool $isPdrc): array
    {
        $canForward = false;
        $blockedReason = null;

        if (! $isPdrc) {
            $blockedReason = 'Only the DRRS PDRC can forward documents to DRRS AA.';
        } elseif ($request->assessment_status !== 'draft') {
            $blockedReason = 'Only a draft assessment can be forwarded to DRRS AA.';
        } elseif (filled($request->epirma_forwarded_to_drrs_aa_at)) {
            $blockedReason = 'This assessment was already forwarded to DRRS AA for e-PIRMA routing.';
        } else {
            $canForward = true;
        }

        return [
            'can_forward' => $canForward,
            'blocked_reason' => $blockedReason,
            'forwarded_at' => $request->epirma_forwarded_to_drrs_aa_at?->toIso8601String(),
            'forwarded_by' => $request->epirma_forwarded_by,
        ];
    }

    public function isForwardedToDrrsAa(AssistanceRequest $request): bool
    {
        return filled($request->epirma_forwarded_to_drrs_aa_at);
    }

    public function findBlockingDocument(AssistanceRequest $request, string $documentType, string $action): ?EpirmaSignedDocument
    {
        return EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->where('document_type', $documentType)
            ->where('action', $action)
            ->whereIn('routing_status', EpirmaSignedDocument::BLOCKING_STATUSES)
            ->latest('id')
            ->first();
    }

    public function findOpenDocument(
        AssistanceRequest $request,
        string $documentType,
        string $action = EpirmaSignedDocument::ACTION_ROUTE
    ): ?EpirmaSignedDocument {
        return EpirmaSignedDocument::query()
            ->where('assistance_request_id', $request->id)
            ->where('document_type', $documentType)
            ->where('action', $action)
            ->whereIn('routing_status', EpirmaSignedDocument::OPEN_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, AssistanceRequest>
     */
    public function queueForDrrsAa(): Collection
    {
        return AssistanceRequest::query()
            ->with([
                'incident:id,name',
                'encoder:id,name',
                'epirmaForwarder:id,name',
                'epirmaSignedDocuments',
            ])
            ->whereNotNull('epirma_forwarded_to_drrs_aa_at')
            ->whereIn('epirma_aa_status', ['pending', 'in_progress', 'completed'])
            ->orderByDesc('epirma_forwarded_to_drrs_aa_at')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>|null
     */
    private function latestFor(array $documents, string $documentType, string $action): ?array
    {
        $matching = [];
        foreach ($documents as $document) {
            if (($document['document_type'] ?? null) === $documentType && ($document['action'] ?? null) === $action) {
                $matching[] = $document;
            }
        }

        if ($matching === []) {
            return null;
        }

        // Prefer the newest open/signed row over a newer cancelled/failed duplicate handoff.
        foreach ($matching as $document) {
            $status = (string) ($document['routing_status'] ?? '');
            if (in_array($status, [
                ...EpirmaSignedDocument::OPEN_STATUSES,
                EpirmaSignedDocument::STATUS_SIGNED,
            ], true)) {
                return $document;
            }
        }

        return $matching[0];
    }
}
