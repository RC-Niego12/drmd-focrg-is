<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssistanceRequest extends Model
{
    use SoftDeletes;

    protected $table = 'requests';

    protected $fillable = [
        'reference_number',
        'submission_type',
        'source_lgu_dromic_request_id',
        'proposal_type',
        'incident_id',
        'assessment_type_id',
        'encoded_by',
        'lgu_submitted_by',
        'request_party_id',
        'requesting_agency',
        'lgu',
        'lgu_level',
        'lgu_psgc_code',
        'province',
        'municipality',
        'barangay',
        'requester',
        'date_requested',
        'purpose',
        'assessment_summary',
        'assessment_form_data',
        'assessment_status',
        'recommendations',
        'remarks',
        'status',
        'lgu_routing_status',
        'lgu_report_status', 'lgu_dromic_validation_status', 'lgu_dromic_reviewed_by',
        'lgu_dromic_reviewed_at', 'lgu_dromic_review_note', 'lgu_dromic_review_screenshots', 'lgu_dromic_review_history',
        'lgu_dromic_correction_scope', 'lgu_dromic_correction_resolved_at',
        'lgu_dromic_seen_at', 'lgu_dromic_seen_by',
        'lgu_dromic_acked_at', 'lgu_dromic_acked_by',
        'lgu_relief_validation_status', 'lgu_relief_reviewed_by', 'lgu_relief_reviewed_at', 'lgu_relief_review_note', 'lgu_relief_review_screenshots', 'lgu_relief_review_history',
        'lgu_relief_correction_scope', 'lgu_relief_correction_resolved_at',
        'lgu_correction_of_id', 'lgu_correction_target',
        'lgu_amendment_request_status', 'lgu_amendment_request_target', 'lgu_amendment_request_reason',
        'lgu_amendment_requested_by', 'lgu_amendment_requested_at',
        'lgu_amendment_reviewed_by', 'lgu_amendment_reviewed_at', 'lgu_amendment_review_note',
        'lgu_relief_seen_at', 'lgu_relief_seen_by',
        'lgu_relief_acked_at', 'lgu_relief_acked_by',
        'lgu_dromic_series_key', 'lgu_dromic_report_number', 'lgu_dromic_revision_number',
        'lgu_dromic_report_classification', 'lgu_relief_request_reference', 'lgu_dromic_draft_save_count', 'lgu_dromic_terminal_at',
        'lgu_finalized_at', 'lgu_submitted_to_dswd_at',
        'lgu_signed_report_path', 'lgu_signed_report_name', 'lgu_signed_report_uploaded_at',
        'lgu_signed_request_path', 'lgu_signed_request_name', 'lgu_signed_request_uploaded_at',
        'lgu_signed_copy_reminder_sent_at',
        'submitted_at',
        'completed_at',
        'date_received_by_drmd', 'request_drn', 'office_agency_details', 'endorsed_to_drrs', 'date_endorsed_to_drrs',
        'incident_details', 'incident_count', 'response_drn', 'assessment_drn', 'source_document_url', 'drmd_aa_photo_paths', 'response_letter_url',
        'coordinated_with_rros', 'date_coordinated_with_rros', 'requester_position', 'requester_address', 'contact_number',
        'affected_families', 'assigned_social_worker',
        'assessment_acted_by', 'assessment_on_behalf_of', 'assessment_on_behalf_reason', 'assessment_acted_at',
        'lgu_dromic_payload', 'lgu_dromic_narrative', 'drmd_aa_remarks', 'drmd_aa_routed_by', 'drmd_aa_routed_at',
        'drmd_chief_remarks', 'drmd_chief_routed_by', 'drmd_chief_routed_at', 'drmd_assigned_to', 'drmd_assigned_section',
        'epirma_status', 'epirma_transaction_id', 'epirma_callback_token', 'epirma_signature_reference', 'epirma_signed_at',
        'epirma_forwarded_to_drrs_aa_at', 'epirma_forwarded_by', 'epirma_aa_status',
        'epirma_assessment_signed_at', 'epirma_response_letter_signed_at',
        'lgu_response_letter_sent_at', 'lgu_response_letter_acked_at', 'lgu_response_letter_acked_by',
        'lgu_response_letter_advance_path', 'lgu_response_letter_advance_name', 'lgu_response_letter_advance_sent_at',
        'lgu_response_letter_advance_acked_at', 'lgu_response_letter_advance_acked_by',
    ];

    protected function casts(): array
    {
        return [
            'date_requested' => 'date',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'lgu_finalized_at' => 'datetime',
            'lgu_dromic_report_number' => 'integer',
            'lgu_dromic_revision_number' => 'integer',
            'lgu_dromic_draft_save_count' => 'integer',
            'lgu_dromic_terminal_at' => 'datetime',
            'lgu_submitted_to_dswd_at' => 'datetime',
            'lgu_dromic_reviewed_at' => 'datetime',
            'lgu_dromic_review_screenshots' => 'array',
            'lgu_dromic_review_history' => 'array',
            'lgu_dromic_correction_resolved_at' => 'datetime',
            'lgu_dromic_seen_at' => 'datetime',
            'lgu_dromic_acked_at' => 'datetime',
            'lgu_relief_reviewed_at' => 'datetime',
            'lgu_relief_review_screenshots' => 'array',
            'lgu_relief_review_history' => 'array',
            'lgu_relief_correction_resolved_at' => 'datetime',
            'lgu_amendment_requested_at' => 'datetime',
            'lgu_amendment_reviewed_at' => 'datetime',
            'lgu_relief_seen_at' => 'datetime',
            'lgu_relief_acked_at' => 'datetime',
            'lgu_signed_report_uploaded_at' => 'datetime',
            'lgu_signed_request_uploaded_at' => 'datetime',
            'lgu_signed_copy_reminder_sent_at' => 'datetime',
            'date_received_by_drmd' => 'date', 'date_endorsed_to_drrs' => 'date', 'date_coordinated_with_rros' => 'date',
            'endorsed_to_drrs' => 'boolean', 'coordinated_with_rros' => 'boolean',
            'assessment_form_data' => 'array',
            'drmd_aa_photo_paths' => 'array',
            'lgu_dromic_payload' => 'array',
            'drmd_aa_routed_at' => 'datetime',
            'drmd_chief_routed_at' => 'datetime',
            'assessment_acted_at' => 'datetime',
            'epirma_signed_at' => 'datetime',
            'epirma_forwarded_to_drrs_aa_at' => 'datetime',
            'epirma_assessment_signed_at' => 'datetime',
            'epirma_response_letter_signed_at' => 'datetime',
            'lgu_response_letter_sent_at' => 'datetime',
            'lgu_response_letter_acked_at' => 'datetime',
            'lgu_response_letter_advance_sent_at' => 'datetime',
            'lgu_response_letter_advance_acked_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id');
    }

    public function requisitionIssuanceSlip(): HasOne
    {
        return $this->hasOne(RequisitionIssuanceSlip::class, 'request_id');
    }

    public function lguDromicRequestedItems(): HasMany
    {
        return $this->hasMany(LguDromicRequestedItem::class, 'request_id');
    }

    public function signedDocumentVersions(): HasMany
    {
        return $this->hasMany(LguSignedDocumentVersion::class, 'request_id')->latest();
    }

    public function epirmaSignedDocuments(): HasMany
    {
        return $this->hasMany(EpirmaSignedDocument::class, 'assistance_request_id')->latest('id');
    }

    public function epirmaForwarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'epirma_forwarded_by');
    }

    public function lguResponseLetterAcker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_response_letter_acked_by');
    }

    public function lguResponseLetterAdvanceAcker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_response_letter_advance_acked_by');
    }

    public function lguDromicAcker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_dromic_acked_by');
    }

    public function lguReliefAcker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_relief_acked_by');
    }

    public function lguDromicReviewComments(): HasMany
    {
        return $this->hasMany(LguDromicReviewComment::class, 'request_id')->latest();
    }

    public function lguDromicReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_dromic_reviewed_by');
    }

    public function lguReliefReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_relief_reviewed_by');
    }

    public function lguAmendmentRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_amendment_requested_by');
    }

    public function lguAmendmentReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_amendment_reviewed_by');
    }

    public function lguDromicViewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_dromic_seen_by');
    }

    public function lguReliefViewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_relief_seen_by');
    }

    public function reliefAugmentationRequest(): HasOne
    {
        return $this->hasOne(self::class, 'source_lgu_dromic_request_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    public function assessmentActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessment_acted_by');
    }

    public function assessmentOnBehalfOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessment_on_behalf_of');
    }

    public function lguSubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lgu_submitted_by');
    }

    public function drmdAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drmd_assigned_to');
    }

    public function requestParty(): BelongsTo
    {
        return $this->belongsTo(RequestParty::class);
    }

    public function sourceLguDromicReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_lgu_dromic_request_id');
    }

    /**
     * Resolve affected persons for assessment PDF / worksheet display.
     * Prefers worksheet meta, then DROMIC payload / area-row totals (never invents from families).
     */
    public function resolvedAffectedPersons(): int
    {
        $meta = (array) ($this->assessment_form_data ?? []);

        $payload = [];
        if (is_array($this->lgu_dromic_payload) && $this->lgu_dromic_payload !== []) {
            $payload = $this->lgu_dromic_payload;
        } else {
            $source = $this->relationLoaded('sourceLguDromicReport')
                ? $this->sourceLguDromicReport
                : $this->sourceLguDromicReport()->first(['id', 'lgu_dromic_payload']);
            $payload = (array) ($source?->lgu_dromic_payload ?? []);
        }

        $areaTotal = collect((array) data_get($payload, 'area_rows', []))
            ->sum(fn ($row): int => (int) data_get($row, 'affected_persons', 0));
        $linkedTotal = collect((array) data_get($payload, 'linked_incidents', []))
            ->sum(fn ($row): int => (int) data_get($row, 'affected_persons', 0));
        $incidentRowsTotal = collect((array) data_get($meta, 'incidents', []))
            ->sum(fn ($row): int => (int) data_get($row, 'affected_persons', 0));

        foreach ([
            data_get($meta, 'affected_persons'),
            data_get($meta, 'source_lgu_snapshot.affected_persons'),
            data_get($payload, 'affected_persons'),
            $linkedTotal > 0 ? $linkedTotal : null,
            $incidentRowsTotal > 0 ? $incidentRowsTotal : null,
            $areaTotal > 0 ? $areaTotal : null,
        ] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $value = (int) $candidate;
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * LGU-linked FNI rows need a validated signed request letter before a new assessment can start.
     */
    public function blocksNewAssessmentForUnsignedReliefValidation(): bool
    {
        $source = $this->relationLoaded('sourceLguDromicReport')
            ? $this->sourceLguDromicReport
            : $this->sourceLguDromicReport()->first([
                'id',
                'lgu_signed_request_path',
                'lgu_relief_validation_status',
            ]);

        if (! $source || blank($source->lgu_signed_request_path)) {
            return false;
        }

        return $source->lgu_relief_validation_status !== 'validated_no_findings';
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'request_id');
    }

    public function dispatchPlans(): HasMany
    {
        return $this->hasMany(DispatchPlan::class, 'request_id');
    }
}
