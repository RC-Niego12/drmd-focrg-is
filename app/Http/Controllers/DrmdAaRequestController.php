<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\RequestParty;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DrmdAaRequestController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkflowNotificationService $workflowNotifications,
    ) {}

    public function index(Request $request): Response
    {
        return $this->renderIndex($request, 'fni_request');
    }

    public function proposals(Request $request): Response
    {
        return $this->renderIndex($request, 'proposal');
    }

    private function renderIndex(Request $request, string $submissionType): Response
    {
        $requestParties = RequestParty::query()
            ->with('lguDirectoryEntry:id,psgc_code,lgu_name,override_lgu_name')
            ->where('is_active', true)
            ->orderBy('requesting_party')
            ->orderBy('office_agency_details')
            ->get();

        return Inertia::render('DrmdAa/Requests/Upload', [
            'requestParties' => $requestParties,
            'defaultReceivedAt' => now()->toDateString(),
            'submissionType' => $submissionType,
            'transactions' => AssistanceRequest::query()
                ->with(['requestParty:id,requesting_party,office_agency_details', 'encoder:id,name'])
                ->where('encoded_by', $request->user()->id)
                ->where('submission_type', $submissionType)
                ->latest('submitted_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->storeSubmission($request, 'fni_request');
    }

    public function storeProposal(Request $request): RedirectResponse
    {
        return $this->storeSubmission($request, 'proposal');
    }

    private function storeSubmission(Request $request, string $submissionType): RedirectResponse
    {
        $validated = $request->validate([
            'date_received_by_drmd' => ['required', 'date'],
            'request_drn' => ['required', 'string', 'max:255'],
            'request_party_id' => ['required', 'exists:request_parties,id'],
            'office_agency_details' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'proposal_type' => $submissionType === 'proposal' ? ['required', 'in:FFT/W,NFFT/W'] : ['nullable', 'in:FFT/W,NFFT/W'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);

        $party = RequestParty::query()->where('is_active', true)->findOrFail($validated['request_party_id']);
        $officeDetails = trim((string) ($party->office_agency_details ?: ($validated['office_agency_details'] ?? '')));

        if ($officeDetails === '') {
            throw ValidationException::withMessages([
                'office_agency_details' => 'Specify the Office / Agency Details because this requesting party has no saved details.',
            ]);
        }

        $path = $request->file('document')->store('requests/documents', 'public');
        // Store a host-independent path. Documents are served through the
        // authenticated request source-document route rather than APP_URL.
        $documentUrl = '/storage/'.$path;

        $requestRecord = DB::transaction(function () use ($validated, $documentUrl, $request, $party, $officeDetails, $submissionType): AssistanceRequest {
            return AssistanceRequest::create([
                'reference_number' => ($submissionType === 'proposal' ? 'PROP-' : 'REQ-').now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'submission_type' => $submissionType,
                'proposal_type' => $submissionType === 'proposal' ? $validated['proposal_type'] : null,
                'request_party_id' => $validated['request_party_id'],
                'requesting_agency' => $party->requesting_party,
                'lgu_level' => $party->lgu_level,
                'office_agency_details' => $officeDetails,
                'requester' => $party->office_head ?: $party->requesting_party,
                'date_requested' => $validated['date_received_by_drmd'],
                'date_received_by_drmd' => $validated['date_received_by_drmd'],
                'request_drn' => $validated['request_drn'],
                'purpose' => $submissionType === 'proposal' ? $validated['proposal_type'].' proposal intake' : 'FNI request document intake',
                'remarks' => $validated['remarks'] ?? null,
                'source_document_url' => $documentUrl,
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => now()->toDateString(),
                'encoded_by' => $request->user()->id,
                'status' => 'endorsed',
                'submitted_at' => now(),
            ]);
        });

        $this->audit->log($submissionType === 'proposal' ? 'proposal.drmd_aa_submitted' : 'request.drmd_aa_submitted', $requestRecord, [], $requestRecord->toArray());
        $this->workflowNotifications->notifyDrmdAaEndorsed($requestRecord->fresh(['encoder']));

        $route = $submissionType === 'proposal' ? 'drmd-aa.proposals.index' : 'drmd-aa.requests.index';
        $label = $submissionType === 'proposal' ? 'Proposal' : 'FNI Request';

        return redirect()->route($route)->with('success', "{$label} {$requestRecord->reference_number} endorsed and is now available to DRRS.");
    }
}
