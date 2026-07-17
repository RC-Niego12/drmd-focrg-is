<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssistanceRequest extends Model
{
    use SoftDeletes;

    protected $table = 'requests';

    protected $fillable = [
        'reference_number',
        'submission_type',
        'proposal_type',
        'incident_id',
        'assessment_type_id',
        'encoded_by',
        'lgu_submitted_by',
        'request_party_id',
        'requesting_agency',
        'lgu',
        'lgu_level',
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
        'submitted_at',
        'completed_at',
        'date_received_by_drmd', 'request_drn', 'office_agency_details', 'endorsed_to_drrs', 'date_endorsed_to_drrs',
        'incident_details', 'incident_count', 'response_drn', 'assessment_drn', 'source_document_url', 'response_letter_url',
        'coordinated_with_rros', 'date_coordinated_with_rros', 'requester_position', 'requester_address', 'contact_number',
        'affected_families', 'assigned_social_worker',
        'lgu_dromic_payload', 'lgu_dromic_narrative', 'drmd_aa_remarks', 'drmd_aa_routed_by', 'drmd_aa_routed_at',
        'drmd_chief_remarks', 'drmd_chief_routed_by', 'drmd_chief_routed_at', 'drmd_assigned_to', 'drmd_assigned_section',
        'epirma_status', 'epirma_transaction_id', 'epirma_callback_token', 'epirma_signature_reference', 'epirma_signed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_requested' => 'date',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'date_received_by_drmd' => 'date', 'date_endorsed_to_drrs' => 'date', 'date_coordinated_with_rros' => 'date',
            'endorsed_to_drrs' => 'boolean', 'coordinated_with_rros' => 'boolean',
            'assessment_form_data' => 'array',
            'lgu_dromic_payload' => 'array',
            'drmd_aa_routed_at' => 'datetime',
            'drmd_chief_routed_at' => 'datetime',
            'epirma_signed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id');
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

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'request_id');
    }

    public function dispatchPlans(): HasMany
    {
        return $this->hasMany(DispatchPlan::class, 'request_id');
    }
}
