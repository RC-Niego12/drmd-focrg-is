<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequisitionIssuanceSlip extends Model
{
    public const DISPATCH_READY_STATUSES = ['approved', 'completed'];

    protected $fillable = [
        'request_id', 'prepared_by', 'ris_number', 'ris_date', 'purpose_of_release',
        'recipient', 'delivery_site', 'receiving_representative', 'contact_number',
        'remarks', 'items', 'tracking_data', 'status', 'reservation_status', 'assessment_drn_for_ris', 'prepared_by_name', 'purpose_of_request',
        'incident_type', 'incident_specification', 'dr_number', 'ris_drn', 'item_category',
        'ardo_endorsed_at', 'ardo_returned_at', 'delivered_at', 'release_witnessed_by', 'driver_name',
        'driver_contact_number', 'vehicle_plate_number', 'received_by', 'date_received', 'fully_delivered',
        'has_returned_items', 'returned_particulars', 'returned_quantity', 'returned_reason',
        'forwarded_to_accounting', 'forwarded_to_accounting_at', 'accounting_received_by',
        'assessment_link', 'ris_link', 'rds_link', 'csmr_link', 'source_row_number', 'sync_source', 'sheet_synced_at',
        'ris_dr_path', 'ris_dr_name', 'rds_path', 'rds_name', 'csmr_path', 'csmr_name',
        'approval_routing_mode', 'ris_epirma_status', 'ris_epirma_forwarded_by', 'ris_epirma_forwarded_at',
        'ris_epirma_transaction_id', 'ris_epirma_callback_token', 'ris_epirma_signature_reference',
        'ris_epirma_routed_at', 'ris_epirma_signed_at', 'ris_epirma_signed_path', 'ris_epirma_remote_url',
    ];

    protected function casts(): array
    {
        return ['ris_date' => 'date', 'items' => 'array', 'tracking_data' => 'array', 'ardo_endorsed_at' => 'date', 'ardo_returned_at' => 'date', 'delivered_at' => 'date', 'date_received' => 'date', 'forwarded_to_accounting_at' => 'date', 'fully_delivered' => 'boolean', 'has_returned_items' => 'boolean', 'forwarded_to_accounting' => 'boolean', 'sheet_synced_at' => 'datetime', 'ris_epirma_forwarded_at' => 'datetime', 'ris_epirma_routed_at' => 'datetime', 'ris_epirma_signed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function allocationItems(): HasMany
    {
        return $this->hasMany(RequisitionIssuanceItem::class);
    }

    public function dispatchPlan(): HasOne
    {
        return $this->hasOne(DispatchPlan::class);
    }

    /**
     * Whether post RIS/DR document sections have all data required before Approved/Completed.
     * Delivery / receipt logistics live on the Dispatch Plan and are not required here.
     */
    public function hasCompletePostRisData(): bool
    {
        $tracking = is_array($this->tracking_data) ? $this->tracking_data : [];
        $value = function (string $key) use ($tracking): mixed {
            $column = $this->getAttribute($key);
            if ($column !== null && $column !== '') {
                return $column;
            }

            return $tracking[$key] ?? null;
        };
        $filled = static fn (mixed $v): bool => ! blank($v) || $v === false || $v === 0 || $v === '0';
        $yesNo = static function (mixed $v): ?string {
            if ($v === true || $v === 'Yes' || $v === 1 || $v === '1') {
                return 'Yes';
            }
            if ($v === false || $v === 'No' || $v === 0 || $v === '0') {
                return 'No';
            }

            return null;
        };

        if ($this->approval_routing_mode === 'epirma') {
            if ($this->ris_epirma_status !== 'signed' || ! $this->ris_epirma_signed_at) {
                return false;
            }
        } else {
            foreach (['ardo_endorsed_at', 'ardo_returned_at'] as $key) {
                if (! $filled($value($key))) {
                    return false;
                }
            }
        }

        $forwarded = $yesNo($value('forwarded_to_accounting'));
        if ($forwarded === null) {
            return false;
        }

        if ($forwarded === 'Yes') {
            foreach (['forwarded_to_accounting_at', 'accounting_received_by'] as $key) {
                if (! $filled($value($key))) {
                    return false;
                }
            }
        }

        $hasRisDr = filled($this->ris_dr_path);
        $hasRds = filled($this->rds_path) || filled($this->rds_link) || filled($tracking['rds_link'] ?? null);
        $hasCsmr = filled($this->csmr_path) || filled($this->csmr_link) || filled($tracking['csmr_link'] ?? null);
        if (! $hasRisDr || ! $hasRds || ! $hasCsmr) {
            return false;
        }

        return $filled($this->remarks);
    }
}
