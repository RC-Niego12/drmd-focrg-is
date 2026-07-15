<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EpirmaSigningController extends Controller
{
    public function start(AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|Response
    {
        abort_unless($assistanceRequest->assessment_status === 'draft', 422, 'Only a draft assessment can be sent for e-PIRMA signing.');

        $signUrl = config('services.epirma.sign_url');

        if (blank($signUrl)) {
            return back()->with('error', 'e-PIRMA is ready for integration, but its signing URL has not yet been configured. The draft was not changed.');
        }

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
        ]);

        // An Inertia XHR cannot safely follow a cross-origin 302. This emits
        // the protocol's external-location response so the browser performs a
        // normal top-level navigation to e-PIRMA.
        return Inertia::location($handoffUrl);
    }

    public function callback(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'status' => ['required', 'in:signed,cancelled,failed'],
            'signature_reference' => ['nullable', 'string', 'max:255'],
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

        return redirect()->route('requests.index', ['tab' => 'assessments'])
            ->with($signed ? 'success' : 'error', $message);
    }
}
