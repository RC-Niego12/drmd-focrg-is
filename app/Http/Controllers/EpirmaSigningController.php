<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\EpirmaSignedDocument;
use App\Services\AuditLogger;
use App\Services\EpirmaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EpirmaSigningController extends Controller
{
    public function __construct(private EpirmaService $epirmaService) {}

    /**
     * Hand assessment signing to e-PIRMA.
     *
     * Prefer the develop-compatible EPIRMA_SIGN_URL redirect (no build-authorize).
     * Use the microservice authorize flow only when SIGN_URL is unset.
     */
    public function start(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|Response|JsonResponse
    {
        abort_unless($assistanceRequest->assessment_status === 'draft', 422, 'Only a draft assessment can be sent for e-PIRMA signing.');

        $signUrl = $this->resolvedSignUrl();
        if ($signUrl !== '') {
            return $this->startSignUrlHandoff($request, $assistanceRequest, $audit, $signUrl);
        }

        if ($this->epirmaService->isConfigured()) {
            return $this->startMicroserviceHandoff($request, $assistanceRequest, $audit);
        }

        $message = 'e-PIRMA is ready for integration, but EPIRMA_SIGN_URL (or EPIRMA_BASE_URL / EPIRMA_CLIENT_SECRET) have not yet been configured. The draft was not changed.';

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * Develop-branch handoff: redirect to EPIRMA_SIGN_URL with document/callback query params.
     * Does not call build-authorize or require an e-PIRMA employee account linkage.
     */
    private function startSignUrlHandoff(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        string $signUrl
    ): RedirectResponse|Response|JsonResponse {
        $transactionId = (string) Str::uuid();
        $callbackToken = Str::random(64);
        $assistanceRequest->update([
            'epirma_status' => 'pending',
            'epirma_transaction_id' => $transactionId,
            'epirma_callback_token' => hash('sha256', $callbackToken),
            'epirma_signature_reference' => null,
            'epirma_signed_at' => null,
        ]);

        $callbackUrl = URL::route('epirma.callback', [
            'assistanceRequest' => $assistanceRequest->id,
            'token' => $callbackToken,
        ]);
        $handoffUrl = rtrim($signUrl, '?&').(str_contains($signUrl, '?') ? '&' : '?').http_build_query([
            'transaction_id' => $transactionId,
            'reference_number' => $assistanceRequest->reference_number,
            'document_url' => URL::route('requests.assessment-pdf', $assistanceRequest),
            'callback_url' => $callbackUrl,
            'return_url' => URL::route('requests.index', ['tab' => 'assessments']),
        ]);

        $audit->log('request.epirma_signing_started', $assistanceRequest, [], [
            'transaction_id' => $transactionId,
            'assessment_status' => 'draft',
            'handoff' => 'sign_url',
        ]);

        if ($this->shouldUseLocalBypass($signUrl)) {
            return $this->completeLocalBypass(
                $request,
                $assistanceRequest,
                $audit,
                $transactionId,
                'e-PIRMA is not reachable at '.$signUrl.'. Local bypass signed the assessment so you can continue testing.'
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'redirect_url' => $handoffUrl,
            ]);
        }

        return Inertia::location($handoffUrl);
    }

    /**
     * Microservice handoff: generate PDF, build-authorize with employee ID,
     * then open /microservice/document-routing (official Caraga Connect guide).
     */
    private function startMicroserviceHandoff(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit
    ): RedirectResponse|Response|JsonResponse {
        $path = null;

        try {
            // Keep PDF generation + authorize outside a DB transaction so SQLite is not
            // locked for the multi-second DomPDF render (avoids "database is locked").
            $record = $assistanceRequest->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']);
            $pdfBinary = Pdf::loadView('documents.assessment', [
                'request' => $record,
                'pageMargin' => 18,
            ])->setPaper('a4', 'portrait')->output();

            $uuid = (string) Str::uuid();
            $originalName = "Assessment-{$record->reference_number}";
            $storedFileName = $originalName.'-'.Str::lower(Str::random(12)).'.pdf';
            $path = 'epirma_signed_documents/'.$storedFileName;
            Storage::disk('public')->put($path, $pdfBinary);

            $currentUser = $request->user();
            $responseData = $this->epirmaService->buildAuthorize(
                trim((string) ($currentUser->id_number ?? ''))
            );

            if (! isset($responseData['success']) || ! $responseData['success'] || ! isset($responseData['token'])) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }

                $fallbackSignUrl = $this->resolvedSignUrl();
                if ($fallbackSignUrl !== '' && $this->shouldFallbackToSignUrl($responseData)) {
                    Log::warning('E-Pirma microservice authorize failed; falling back to explicit SIGN_URL handoff.', [
                        'request_id' => $assistanceRequest->id,
                        'message' => $responseData['message'] ?? null,
                    ]);

                    return $this->startSignUrlHandoff($request, $assistanceRequest, $audit, $fallbackSignUrl);
                }

                $idUsed = trim((string) ($currentUser->id_number ?? ''));
                $message = $this->authorizeFailureMessage($responseData, $idUsed);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'details' => $responseData,
                        'id_number' => $idUsed !== '' ? $idUsed : null,
                    ], 422);
                }

                return back()->with('error', $message);
            }

            $callbackToken = Str::random(64);

            $document = DB::transaction(function () use (
                $assistanceRequest,
                $request,
                $path,
                $originalName,
                $uuid,
                $callbackToken,
                $audit
            ) {
                $document = EpirmaSignedDocument::create([
                    'assistance_request_id' => $assistanceRequest->id,
                    'document_name' => $originalName.'.pdf',
                    'document_path' => $path,
                    'description_subject' => 'Assessment form for '.$assistanceRequest->reference_number,
                    'encoded_by' => (string) ($request->user()->name ?? $request->user()->id),
                    'timestamp' => now(),
                    'document_uuid' => $uuid,
                ]);

                $assistanceRequest->update([
                    'epirma_status' => 'pending',
                    'epirma_transaction_id' => $uuid,
                    'epirma_callback_token' => hash('sha256', $callbackToken),
                    'epirma_signature_reference' => null,
                    'epirma_signed_at' => null,
                ]);

                $audit->log('request.epirma_signing_started', $assistanceRequest, [], [
                    'transaction_id' => $uuid,
                    'document_id' => $document->id,
                    'assessment_status' => 'draft',
                    'handoff' => 'microservice',
                    'return_url' => url('/requests?default_tab=assessments'),
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

            $callbackUrl = url('/requests').'?'.http_build_query([
                'default_tab' => 'assessments',
                'epirma_request' => $assistanceRequest->id,
                'token' => $callbackToken,
            ]);

            $redirectUrl = $this->epirmaService->generateDocumentRoutingUrl(
                (string) config('services.epirma.app_name', 'DRIMS'),
                $uuid,
                $responseData['token'],
                $callbackUrl,
                $documentUrl
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => $redirectUrl,
                ]);
            }

            return Inertia::location($redirectUrl);
        } catch (Throwable $e) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            Log::error('E-Pirma assessment signing failed', [
                'error' => $e->getMessage(),
                'request_id' => $assistanceRequest->id,
            ]);

            $message = $this->transactionFailureMessage($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            return back()->with('error', $message);
        }
    }

    public function latestSignedStatus(AssistanceRequest $assistanceRequest): JsonResponse
    {
        $document = EpirmaSignedDocument::query()
            ->where('assistance_request_id', $assistanceRequest->id)
            ->when(
                filled($assistanceRequest->epirma_transaction_id),
                fn ($query) => $query->where('document_uuid', $assistanceRequest->epirma_transaction_id)
            )
            ->latest('id')
            ->first();

        if (! $document || blank($document->document_uuid)) {
            return response()->json([
                'success' => false,
                'message' => 'Document UUID is missing.',
            ], 422);
        }

        $result = $this->epirmaService->fetchSignedDocumentStatus((string) $document->document_uuid);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to fetch latest status.',
            ], 422);
        }

        $payload = $result['data'] ?? [];
        $signedDocument = data_get($payload, 'signed_document', $payload);
        if (is_array(data_get($signedDocument, 'signed_document'))) {
            $signedDocument = $signedDocument['signed_document'];
        }

        $documentUrl = is_array($signedDocument)
            ? ($signedDocument['document_url'] ?? null)
            : null;

        $viewUrl = null;
        if (filled($documentUrl)) {
            $token = trim((string) config('services.epirma.document_token', ''));
            $separator = str_contains($documentUrl, '?') ? '&' : '?';
            $viewUrl = $token !== ''
                ? $documentUrl.$separator.'token='.rawurlencode($token)
                : $documentUrl;
        }

        $normalizedPayload = [
            'document_id' => $document->id,
            'base_path' => $documentUrl,
            'view_url' => $viewUrl,
            'signers' => [[
                'fullname' => $signedDocument['original_filename'] ?? $document->document_name,
                'username' => $signedDocument['uuid'] ?? (string) $document->document_uuid,
                'type' => 'signed-document',
                'status' => $signedDocument['signing_status'] ?? 'unknown',
                'date_signed' => $signedDocument['signed_at'] ?? null,
            ]],
            'signed_document' => $signedDocument,
        ];

        $assistanceRequest->forceFill([
            'epirma_status' => 'completed',
            'epirma_signature_reference' => $signedDocument['signed_filename']
                ?? $signedDocument['uuid']
                ?? $assistanceRequest->epirma_signature_reference,
            'epirma_signed_at' => filled($signedDocument['signed_at'] ?? null)
                ? $signedDocument['signed_at']
                : ($assistanceRequest->epirma_signed_at ?: now()),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => $normalizedPayload,
        ]);
    }

    public function retrySigning(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        abort_unless($assistanceRequest->assessment_status === 'draft', 422, 'Only a draft assessment can retry e-PIRMA signing.');

        $request->headers->set('Accept', 'application/json');
        $result = $this->start($request, $assistanceRequest, app(AuditLogger::class));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $location = $result->headers->get('X-Inertia-Location')
            ?: $result->headers->get('Location');

        if (filled($location)) {
            return response()->json([
                'success' => true,
                'redirect_url' => $location,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to retry e-PIRMA signing.',
        ], 422);
    }

    public function serveSignedDocument(Request $request, EpirmaSignedDocument $document)
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];

        if (! $request->hasValidSignatureWhileIgnoring(['filename'], false)) {
            return response('Invalid or expired document link.', 403, $corsHeaders);
        }

        if (! $document->document_path || ! Storage::disk('public')->exists($document->document_path)) {
            return response('Document file not found.', 404, $corsHeaders);
        }

        $filePath = Storage::disk('public')->path($document->document_path);
        $safeFileName = str_replace('"', '', $document->document_name ?? basename($document->document_path));

        return response()->file($filePath, [
            ...$corsHeaders,
            'Content-Disposition' => 'inline; filename="'.$safeFileName.'"; filename*=UTF-8\'\''.rawurlencode($safeFileName),
        ]);
    }

    public function callback(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'status' => ['required', 'in:signed,cancelled,failed'],
            'signature_reference' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'string', 'max:2048'],
        ]);
        $expected = (string) $assistanceRequest->epirma_callback_token;

        abort_unless($expected !== '' && hash_equals($expected, hash('sha256', $data['token'])), 403, 'Invalid or expired e-PIRMA callback.');
        abort_unless($assistanceRequest->assessment_status === 'draft', 409, 'This assessment is no longer awaiting signature.');

        $signed = $data['status'] === 'signed';
        $assistanceRequest->update([
            'epirma_status' => $data['status'],
            'epirma_callback_token' => null,
            'epirma_signature_reference' => $data['signature_reference'] ?? null,
            'epirma_signed_at' => $signed ? now() : null,
            'assessment_status' => $signed ? 'final' : 'draft',
            'status' => $signed ? 'acted' : 'under_review',
        ]);

        $audit->log('request.epirma_signing_completed', $assistanceRequest, [], [
            'epirma_status' => $data['status'],
            'signature_reference' => $data['signature_reference'] ?? null,
        ]);

        $message = $signed
            ? 'The e-PIRMA signature was verified. The assessment is now Final and the request is Acted.'
            : 'The e-PIRMA signing attempt was '.$data['status'].'. The assessment remains a draft.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'status' => $data['status']]);
        }

        return redirect()
            ->to(url('/requests?default_tab=assessments'))
            ->with($signed ? 'success' : 'error', $message);
    }

    private function authorizeFailureMessage(array $responseData, string $idUsed): string
    {
        $apiMessage = trim((string) ($responseData['message'] ?? $responseData['error'] ?? ''));
        if ($apiMessage !== '') {
            if (stripos($apiMessage, 'invalid secret') !== false) {
                return 'e-PIRMA rejected EPIRMA_CLIENT_SECRET (Invalid secret). Update .env with the microservice secret registered for this app in Caraga e-PIRMA / RICTMS, then run php artisan config:clear.';
            }

            return $apiMessage;
        }

        if ($idUsed === '') {
            return 'Failed to initialize e-PIRMA signing. Your DROMIS profile has no employee ID number.';
        }

        return 'Failed to initialize e-PIRMA signing for employee ID '.$idUsed.'. Confirm e-PIRMA is running and this ID is registered there, then try again.';
    }

    private function transactionFailureMessage(Throwable $e): string
    {
        $raw = $e->getMessage();

        if (str_contains($raw, 'database is locked')) {
            return 'Could not save the e-PIRMA document because the database is busy. Wait a moment and click Sign with e-PIRMA again.';
        }

        if (str_contains($raw, 'Failed to connect') || str_contains($raw, 'Could not reach e-PIRMA')) {
            return 'Could not reach e-PIRMA at '.config('services.epirma.base_url').'. Start the e-PIRMA service, then try signing again.';
        }

        return 'Failed to process document transaction. '.$raw;
    }

    /**
     * Only an explicit EPIRMA_SIGN_URL is used for the develop-compatible handoff.
     */
    private function resolvedSignUrl(bool $allowBaseUrlFallback = false): string
    {
        return trim((string) config('services.epirma.sign_url', ''));
    }

    private function shouldFallbackToSignUrl(array $responseData): bool
    {
        return trim((string) config('services.epirma.sign_url', '')) !== '';
    }

    private function localBypassEnabled(): bool
    {
        return (bool) config('services.epirma.local_bypass', app()->environment('local'));
    }

    private function shouldUseLocalBypass(string $targetUrl): bool
    {
        if (! $this->localBypassEnabled() || $targetUrl === '') {
            return false;
        }

        return ! $this->hostReachable($targetUrl);
    }

    private function hostReachable(string $url): bool
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if (! is_resource($connection)) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function completeLocalBypass(
        Request $request,
        AssistanceRequest $assistanceRequest,
        AuditLogger $audit,
        string $transactionId,
        string $message
    ): RedirectResponse|JsonResponse {
        $reference = 'LOCAL-BYPASS-'.Str::upper(Str::random(8));
        $assistanceRequest->update([
            'epirma_status' => 'signed',
            'epirma_transaction_id' => $transactionId,
            'epirma_callback_token' => null,
            'epirma_signature_reference' => $reference,
            'epirma_signed_at' => now(),
            'assessment_status' => 'final',
            'status' => 'acted',
        ]);

        $audit->log('request.epirma_signing_completed', $assistanceRequest, [], [
            'epirma_status' => 'signed',
            'signature_reference' => $reference,
            'handoff' => 'local_bypass',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'status' => 'signed',
                'local_bypass' => true,
            ]);
        }

        return redirect()
            ->to(url('/requests?default_tab=assessments'))
            ->with('success', $message);
    }
}
