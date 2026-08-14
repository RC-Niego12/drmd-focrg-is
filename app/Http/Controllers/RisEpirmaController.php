<?php

namespace App\Http\Controllers;

use App\Models\EpirmaSignedDocument;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Services\EpirmaDocumentStatusService;
use App\Services\EpirmaService;
use App\Services\RealtimePublisher;
use App\Services\RisDrDocumentPdfService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RisEpirmaController extends Controller
{
    public function __construct(
        private EpirmaService $epirma,
        private EpirmaDocumentStatusService $statuses,
        private WorkflowNotificationService $notifications,
        private RealtimePublisher $realtime,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasRole('RROS AA'), 403);
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'completed'], true) ? $request->query('status') : 'active';
        $base = RequisitionIssuanceSlip::query()
            ->with(['request.incident:id,name', 'preparer:id,name'])
            ->where('approval_routing_mode', 'epirma')
            ->where(function ($query) use ($request): void {
                $query->whereNotNull('ris_epirma_forwarded_at')
                    ->orWhereNotNull('ris_epirma_transaction_id')
                    ->orWhere('prepared_by', $request->user()->id);
            });
        if ($status === 'completed') {
            $base->where('ris_epirma_status', 'signed');
        } else {
            $base->whereIn('ris_epirma_status', ['forwarded', 'pending', 'routed', 'partially_signed', 'failed', 'cancelled']);
        }
        if ($search !== '') {
            $base->where(function ($query) use ($search): void {
                $query->where('ris_number', 'like', "%{$search}%")->orWhere('recipient', 'like', "%{$search}%")
                    ->orWhereHas('request', fn ($requestQuery) => $requestQuery->where('reference_number', 'like', "%{$search}%"));
            });
        }
        $queue = $base->orderByDesc('ris_epirma_forwarded_at')->orderByDesc('updated_at')->paginate(20)->withQueryString()
            ->through(function (RequisitionIssuanceSlip $slip): array {
                $document = $this->lookupDocument($slip);

                return [
                    'id' => $slip->id, 'request_id' => $slip->request_id, 'ris_number' => $slip->ris_number, 'dr_number' => $slip->dr_number,
                    'reference_number' => $slip->request?->reference_number, 'recipient' => $slip->recipient,
                    'delivery_site' => $slip->delivery_site,
                    // Queue classification follows the approved assessment/request.
                    // purpose_of_release is editable RIS document wording and may be
                    // more specific (or stale), so it must not reclassify this queue.
                    'purpose' => data_get($slip->tracking_data, 'purpose_of_request')
                        ?: data_get($slip->request?->assessment_form_data, 'response_purpose')
                        ?: $slip->request?->purpose,
                    'incident' => $slip->request?->incident?->name, 'prepared_by' => $slip->preparer?->name,
                    'prepared_by_id' => $slip->prepared_by, 'status' => $slip->ris_epirma_status,
                    'forwarded_at' => optional($slip->ris_epirma_forwarded_at)?->toIso8601String(),
                    'routed_at' => optional($slip->ris_epirma_routed_at)?->toIso8601String(),
                    'signed_at' => optional($slip->ris_epirma_signed_at)?->toIso8601String(),
                    'transaction_id' => $slip->ris_epirma_transaction_id,
                    'document' => $document?->toTrackingArray(),
                    'preview_url' => $slip->ris_epirma_status === 'signed'
                        ? route('rros.ris.epirma.signed-preview', $slip)
                        : route('rros.ris.preview-pdf', ['slip' => $slip, 'kind' => 'ris']),
                    'preview_kind' => $slip->ris_epirma_status === 'signed' ? 'signed' : 'draft',
                    'can_route' => in_array($slip->ris_epirma_status, ['forwarded', 'failed', 'cancelled'], true),
                ];
            });
        $summaryBase = RequisitionIssuanceSlip::query()->where('approval_routing_mode', 'epirma')
            ->where(fn ($q) => $q->whereNotNull('ris_epirma_forwarded_at')->orWhereNotNull('ris_epirma_transaction_id')->orWhere('prepared_by', $request->user()->id));

        return Inertia::render('RrosAa/Epirma/Index', [
            'queue' => $queue, 'filters' => compact('search', 'status'),
            'summary' => [
                'pending' => (clone $summaryBase)->where('ris_epirma_status', 'forwarded')->count(),
                'in_progress' => (clone $summaryBase)->whereIn('ris_epirma_status', ['pending', 'routed', 'partially_signed'])->count(),
                'completed' => (clone $summaryBase)->where('ris_epirma_status', 'signed')->count(),
            ],
        ]);
    }

    public function forward(Request $request, RequisitionIssuanceSlip $slip): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA']), 403);
        abort_unless((int) $slip->prepared_by === (int) $request->user()->id, 403, 'Only the RROS user who prepared this RIS may forward it.');
        abort_if($request->user()->hasRole('RROS AA'), 422, 'Because you prepared this RIS as the RROS AA, route it directly through e-PIRMA.');
        abort_unless(in_array($slip->status, ['prepared', 'approved'], true), 422, 'Generate the RIS / DR before routing it.');
        $slip->forceFill([
            'approval_routing_mode' => 'epirma', 'ris_epirma_status' => 'forwarded',
            'ris_epirma_forwarded_by' => $request->user()->id, 'ris_epirma_forwarded_at' => now(),
            'ardo_endorsed_at' => null, 'ardo_returned_at' => null,
        ])->save();
        $this->notifications->notifyRisEpirmaForwarded($slip, $slip->request);
        $this->publishWorkflowChanged($slip->fresh());

        return response()->json(['success' => true, 'message' => 'RIS forwarded to the RROS AA (Administrative Assistant) and is ready for e-PIRMA routing.', 'workflow' => $this->payload($slip->fresh())]);
    }

    public function route(Request $request, RequisitionIssuanceSlip $slip, RisDrDocumentPdfService $pdf): JsonResponse
    {
        abort_unless($request->user()?->hasRole('RROS AA'), 403, 'Only the RROS AA can route the RIS through e-PIRMA.');
        abort_unless(filled($slip->ris_epirma_forwarded_at) || (int) $slip->prepared_by === (int) $request->user()->id, 422, 'This RIS must first be forwarded by its preparer to the RROS AA.');
        abort_if($slip->ris_epirma_status === 'signed', 422, 'This RIS has already completed e-PIRMA signing.');
        $existingDocument = $this->lookupDocument($slip);
        abort_if($existingDocument?->isOpen(), 422, 'This RIS is already routed in e-PIRMA. Use Refresh Status instead of creating a duplicate route.');
        abort_unless($this->epirma->isConfigured(), 422, 'e-PIRMA is not configured.');
        $id = trim((string) $request->user()->id_number);
        abort_if($id === '', 422, 'Your employee ID number is required and must be registered in e-PIRMA.');
        $authorization = $this->epirma->buildAuthorize($id);
        abort_unless(($authorization['success'] ?? false) && filled($authorization['token'] ?? null), 422, $authorization['message'] ?? 'e-PIRMA authorization failed.');

        $uuid = (string) Str::uuid();
        $callbackToken = Str::random(64);
        $documentName = $pdf->advancePreviewFilename($slip, 'ris');
        $documentPath = 'epirma_signed_documents/ris/'.$uuid.'.pdf';
        $pdfResponse = $pdf->streamAdvancePreview($slip, 'ris');
        Storage::disk('public')->put($documentPath, $pdfResponse->getContent());
        $document = EpirmaSignedDocument::create([
            'assistance_request_id' => $slip->request_id, 'document_type' => EpirmaSignedDocument::TYPE_RIS,
            'action' => EpirmaSignedDocument::ACTION_ROUTE, 'document_uuid' => $uuid,
            'routing_status' => EpirmaSignedDocument::STATUS_ROUTED, 'initiated_by' => $request->user()->id,
            'routed_at' => now(), 'handoff' => 'document_routing', 'document_name' => $documentName,
            'document_path' => $documentPath, 'description_subject' => 'Approved RIS '.$slip->ris_number,
            'encoded_by' => $request->user()->name, 'timestamp' => now(),
        ]);
        $slip->forceFill([
            'approval_routing_mode' => 'epirma', 'ris_epirma_status' => 'routed', 'ris_epirma_transaction_id' => $uuid,
            'ris_epirma_callback_token' => hash('sha256', $callbackToken), 'ris_epirma_routed_at' => now(),
            'ris_epirma_forwarded_at' => $slip->ris_epirma_forwarded_at ?: now(),
            'ris_epirma_forwarded_by' => $slip->ris_epirma_forwarded_by ?: $request->user()->id,
        ])->save();
        // Use the same CORS-enabled signed-document endpoint as the proven
        // assessment e-PIRMA handoff. e-PIRMA loads this URL cross-origin.
        $documentUrl = URL::temporarySignedRoute(
            'epirma.signed-document',
            now()->addHours(6),
            ['document' => $document->id],
            absolute: false,
        );
        $documentUrl = url($documentUrl);
        $documentUrl .= (str_contains($documentUrl, '?') ? '&' : '?').'filename='.rawurlencode($documentName);
        $callbackUrl = route('ris.epirma.callback', ['slip' => $slip, 'token' => $callbackToken]);
        $redirect = $this->epirma->generateDocumentRoutingUrl(config('app.name').' RIS', $uuid, $authorization['token'], $callbackUrl, $documentUrl);
        $this->publishWorkflowChanged($slip->fresh());

        return response()->json(['success' => true, 'message' => 'RIS opened for e-PIRMA routing.', 'redirect_url' => $redirect, 'workflow' => $this->payload($slip->fresh()), 'document_id' => $document->id]);
    }

    public function status(Request $request, RequisitionIssuanceSlip $slip): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        $document = $this->lookupDocument($slip);
        $before = $this->workflowFingerprint($slip, $document);
        if ($document) {
            if ($document->isOpen()) {
                $this->statuses->syncDocument($document, false);
            }
            // The document can become terminal (signed/cancelled/failed) before
            // the RIS parent is updated. Always reconcile the parent, including
            // already-terminal documents discovered by another poller.
            $this->applyDocumentStatus($slip, $document->fresh());
        }

        $freshSlip = $slip->fresh();
        $freshDocument = $this->lookupDocument($freshSlip);
        if ($before !== $this->workflowFingerprint($freshSlip, $freshDocument)) {
            $this->publishWorkflowChanged($freshSlip);
        }

        return response()->json(['success' => true, 'workflow' => $this->payload($freshSlip)]);
    }

    public function signedPreview(Request $request, RequisitionIssuanceSlip $slip): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        abort_unless($slip->approval_routing_mode === 'epirma' && $slip->ris_epirma_status === 'signed', 409, 'The signed RIS is not available until e-PIRMA signing is complete.');

        $document = $this->lookupDocument($slip);
        abort_unless($document?->isSigned(), 404, 'The signed RIS could not be found in the local e-PIRMA tracking records.');

        return redirect()->route('requests.epirma.documents.view', [
            'assistanceRequest' => $slip->request_id,
            'document' => $document->id,
        ]);
    }

    public function callback(Request $request, RequisitionIssuanceSlip $slip): RedirectResponse|JsonResponse
    {
        abort_unless(hash_equals((string) $slip->ris_epirma_callback_token, hash('sha256', (string) $request->query('token'))), 403);
        $raw = strtolower((string) ($request->input('status') ?: $request->query('status', 'routed')));
        $status = in_array($raw, ['signed', 'completed', 'success', 'successful'], true) ? 'signed'
            : (in_array($raw, ['cancelled', 'canceled'], true) ? 'cancelled' : (in_array($raw, ['failed', 'error'], true) ? 'failed' : 'routed'));
        $document = $this->lookupDocument($slip);
        if ($document) {
            $document->forceFill(['routing_status' => $status, 'completed_at' => $status === 'signed' ? now() : null, 'signature_reference' => $request->input('signature_reference')])->save();
        }
        $this->applyDocumentStatus($slip, $document);
        $this->publishWorkflowChanged($slip->fresh());
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'workflow' => $this->payload($slip->fresh())]);
        }

        return redirect()->route('rros-aa.epirma.index', ['status' => $status === 'signed' ? 'completed' : 'active', 'search' => $slip->ris_number])->with('success', $status === 'signed' ? 'RIS e-PIRMA approval completed. Section 4 is now unlocked.' : 'RIS e-PIRMA status updated.');
    }

    public function document(Request $request, RequisitionIssuanceSlip $slip, RisDrDocumentPdfService $pdf)
    {
        abort_unless($request->hasValidSignature(), 403);

        return $pdf->streamAdvancePreview($slip, 'ris');
    }

    private function lookupDocument(RequisitionIssuanceSlip $slip): ?EpirmaSignedDocument
    {
        return EpirmaSignedDocument::where('assistance_request_id', $slip->request_id)->where('document_type', EpirmaSignedDocument::TYPE_RIS)->where('document_uuid', $slip->ris_epirma_transaction_id)->latest('id')->first();
    }

    private function applyDocumentStatus(RequisitionIssuanceSlip $slip, ?EpirmaSignedDocument $document): void
    {
        if (! $document) {
            return;
        }
        $signed = $document->routing_status === 'signed';
        $wasSigned = $slip->ris_epirma_status === 'signed' && filled($slip->ris_epirma_signed_at);
        $slip->forceFill([
            'ris_epirma_status' => $document->routing_status,
            'ris_epirma_signature_reference' => $document->signature_reference,
            'ris_epirma_remote_url' => $document->remote_document_url ?: $document->remote_base_path,
            'ris_epirma_signed_path' => $signed ? $document->document_path : null,
            'ris_epirma_signed_at' => $signed ? ($document->completed_at ?: now()) : null,
            'status' => $signed && $slip->status === 'prepared'
                ? 'approved'
                : (! $signed && $slip->status === 'approved' ? 'prepared' : $slip->status),
            'ris_epirma_callback_token' => $signed ? null : $slip->ris_epirma_callback_token,
        ])->save();
        if ($signed && ! $wasSigned) {
            $this->notifications->notifyRisEpirmaCompleted($slip, $slip->request);
        }
    }

    private function payload(RequisitionIssuanceSlip $slip): array
    {
        $document = $this->lookupDocument($slip);

        return [
            'mode' => $slip->approval_routing_mode,
            'status' => $slip->ris_epirma_status,
            'forwarded_at' => optional($slip->ris_epirma_forwarded_at)?->toIso8601String(),
            'routed_at' => optional($slip->ris_epirma_routed_at)?->toIso8601String(),
            'signed_at' => optional($slip->ris_epirma_signed_at)?->toIso8601String(),
            'complete' => $slip->ris_epirma_status === 'signed',
            'transaction_id' => $slip->ris_epirma_transaction_id,
            'signature_reference' => $slip->ris_epirma_signature_reference,
            'signed_preview_url' => $slip->ris_epirma_status === 'signed'
                ? route('rros.ris.epirma.signed-preview', $slip)
                : null,
            'document' => $document ? [
                ...$document->toTrackingArray(),
                'signers' => is_array($document->signers) ? $document->signers : [],
            ] : null,
        ];
    }

    private function workflowFingerprint(RequisitionIssuanceSlip $slip, ?EpirmaSignedDocument $document): string
    {
        return hash('sha256', json_encode([
            'status' => $slip->ris_epirma_status,
            'signed_at' => optional($slip->ris_epirma_signed_at)?->toIso8601String(),
            'document_status' => $document?->routing_status,
            'document_completed_at' => $document?->completed_at?->toIso8601String(),
            'signers' => $document?->signers,
        ], JSON_THROW_ON_ERROR));
    }

    private function publishWorkflowChanged(RequisitionIssuanceSlip $slip): void
    {
        $recipientIds = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['RROS', 'RROS AA', 'Super Admin']))
            ->pluck('id');

        $this->realtime->usersChanged($recipientIds, 'ris.epirma.status.changed', [
            'request_id' => $slip->request_id,
            'ris_id' => $slip->id,
            'ris_number' => $slip->ris_number,
            'status' => $slip->ris_epirma_status,
            'workflow' => $this->payload($slip),
        ]);

        if ($slip->ris_epirma_status === 'signed') {
            $this->realtime->usersChanged($recipientIds, 'dispatch.ready.changed', [
                'request_id' => $slip->request_id,
                'ris_id' => $slip->id,
                'ris_status' => $slip->status,
                'approval_mode' => 'epirma',
                'ready' => true,
            ]);
        }
    }
}
