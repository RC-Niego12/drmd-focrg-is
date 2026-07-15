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
     * Build an assessment PDF and hand it off to e-PIRMA for signing
     * (same flow as sign_document in the e-PIRMA integration sample).
     */
    public function start(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|Response|JsonResponse
    {
        abort_unless($assistanceRequest->assessment_status === 'draft', 422, 'Only a draft assessment can be sent for e-PIRMA signing.');

        if (! $this->epirmaService->isConfigured()) {
            $message = 'e-PIRMA is ready for integration, but EPIRMA_BASE_URL / EPIRMA_CLIENT_SECRET have not yet been configured. The draft was not changed.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        $path = null;

        try {
            DB::beginTransaction();

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

            $document = EpirmaSignedDocument::create([
                'assistance_request_id' => $assistanceRequest->id,
                'document_name' => $originalName.'.pdf',
                'document_path' => $path,
                'description_subject' => 'Assessment form for '.$record->reference_number,
                'encoded_by' => (string) ($request->user()->name ?? $request->user()->id),
                'timestamp' => now(),
                'document_uuid' => $uuid,
            ]);

            $documentUrl = URL::temporarySignedRoute(
                'epirma.signed-document',
                now()->addHours(6),
                ['document' => $document->id],
                absolute: false
            );
            $documentUrl = url($documentUrl);
            $separator = str_contains($documentUrl, '?') ? '&' : '?';
            $documentUrl .= $separator.'filename='.rawurlencode((string) $document->document_name);

            $currentUser = $request->user();
            $responseData = $this->epirmaService->buildAuthorize(
                (string) ($currentUser->id_number ?? $currentUser->id)
            );

            if (! isset($responseData['success']) || ! $responseData['success'] || ! isset($responseData['token'])) {
                DB::rollBack();

                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }

                $message = 'Failed to initialize E-Pirma document creation. You must have an ePIRMA Account to use this feature.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'details' => $responseData,
                    ], 422);
                }

                return back()->with('error', $message);
            }

            $callbackToken = Str::random(64);
            $assistanceRequest->update([
                'epirma_status' => 'pending',
                'epirma_transaction_id' => $uuid,
                'epirma_callback_token' => hash('sha256', $callbackToken),
                'epirma_signature_reference' => null,
                'epirma_signed_at' => null,
            ]);

            // e-PIRMA redirects here after signing. Keep the opaque token + request id so
            // /requests can verify the callback and open the Created Assessments tab.
            $callbackUrl = url('/requests').'?'.http_build_query([
                'default_tab' => 'assessments',
                'epirma_request' => $assistanceRequest->id,
                'token' => $callbackToken,
            ]);

            $redirectUrl = $this->epirmaService->generateDocumentRoutingUrlforSigning(
                (string) config('services.epirma.app_name', config('app.name')),
                $uuid,
                $responseData['token'],
                $callbackUrl,
                $documentUrl
            );

            $audit->log('request.epirma_signing_started', $assistanceRequest, [], [
                'transaction_id' => $uuid,
                'document_id' => $document->id,
                'assessment_status' => 'draft',
                'return_url' => url('/requests?default_tab=assessments'),
            ]);

            DB::commit();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => $redirectUrl,
                ]);
            }

            // An Inertia XHR cannot safely follow a cross-origin 302. This emits
            // the protocol's external-location response so the browser performs a
            // normal top-level navigation to e-PIRMA.
            return Inertia::location($redirectUrl);
        } catch (Throwable $e) {
            DB::rollBack();

            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            Log::error('E-Pirma assessment signing failed', [
                'error' => $e->getMessage(),
                'request_id' => $assistanceRequest->id,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process document transaction.',
                ], 500);
            }

            return back()->with('error', 'Failed to process document transaction.');
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

        if (! $this->epirmaService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'e-PIRMA is not configured.',
            ], 422);
        }

        $document = EpirmaSignedDocument::query()
            ->where('assistance_request_id', $assistanceRequest->id)
            ->when(
                filled($assistanceRequest->epirma_transaction_id),
                fn ($query) => $query->where('document_uuid', $assistanceRequest->epirma_transaction_id)
            )
            ->latest('id')
            ->first();

        if (! $document || ! $document->document_path || ! Storage::disk('public')->exists($document->document_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Document file not found.',
            ], 422);
        }

        $uuid = (string) ($document->document_uuid ?: Str::uuid());
        if (! $document->document_uuid) {
            $document->document_uuid = $uuid;
            $document->save();
        }

        $documentUrl = URL::temporarySignedRoute(
            'epirma.signed-document',
            now()->addHours(6),
            [
                'document' => $document->id,
            ],
            absolute: false
        );

        $documentUrl = url($documentUrl);
        $separator = str_contains($documentUrl, '?') ? '&' : '?';
        $documentUrl .= $separator.'filename='.rawurlencode((string) $document->document_name);

        $currentUser = $request->user();
        $responseData = $this->epirmaService->buildAuthorize(
            (string) ($currentUser->id_number ?? $currentUser->id)
        );

        if (! isset($responseData['success']) || ! $responseData['success'] || ! isset($responseData['token'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize E-Pirma document creation. You must have an ePIRMA Account to use this feature.',
                'details' => $responseData,
            ], 422);
        }

        $callbackToken = Str::random(64);
        $assistanceRequest->update([
            'epirma_status' => 'pending',
            'epirma_transaction_id' => $uuid,
            'epirma_callback_token' => hash('sha256', $callbackToken),
            'epirma_signature_reference' => null,
            'epirma_signed_at' => null,
        ]);

        $callbackUrl = url('/requests').'?'.http_build_query([
            'default_tab' => 'assessments',
            'epirma_request' => $assistanceRequest->id,
            'token' => $callbackToken,
        ]);

        $redirectUrl = $this->epirmaService->generateDocumentRoutingUrlforSigning(
            (string) config('services.epirma.app_name', config('app.name')),
            $uuid,
            $responseData['token'],
            $callbackUrl,
            $documentUrl
        );

        return response()->json([
            'success' => true,
            'redirect_url' => $redirectUrl,
        ]);
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
}
