<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DistributionPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'proposal_key',
        'inventory_batch_id',
        'request_id',
        'program_type',
        'beneficiary',
        'lgu',
        'item_name',
        'brand',
        'expiry_month',
        'warehouse_id',
        'source_warehouse_name',
        'district',
        'partnership',
        'location',
        'quantity',
        'allocated_quantity',
        'released_quantity',
        'proposal_target_on',
        'proposal_received_on',
        'proposal_reviewed_on',
        'compliance_on',
        'compliance_target_on',
        'compliance_target_na',
        'proposal_approved_on',
        'work_schedule_target_on',
        'work_schedule_target_end_on',
        'work_schedule_on',
        'work_schedule_end_on',
        'delivery_mode',
        'delivery_mode_na',
        'delivery_target_on',
        'delivery_target_end_on',
        'delivery_target_na',
        'delivered_on',
        'delivered_end_on',
        'distribution_target_on',
        'distribution_target_end_on',
        'distributed_on',
        'distributed_end_on',
        'progress_status',
        'activity',
        'activity_date',
        'priority',
        'status',
        'assigned_to',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'allocated_quantity' => 'decimal:2',
            'released_quantity' => 'decimal:2',
            'activity_date' => 'date',
            'proposal_target_on' => 'date',
            'proposal_received_on' => 'date',
            'proposal_reviewed_on' => 'date',
            'compliance_on' => 'date',
            'compliance_target_on' => 'date',
            'compliance_target_na' => 'boolean',
            'proposal_approved_on' => 'date',
            'work_schedule_target_on' => 'date',
            'work_schedule_target_end_on' => 'date',
            'work_schedule_on' => 'date',
            'work_schedule_end_on' => 'date',
            'delivery_target_on' => 'date',
            'delivery_target_end_on' => 'date',
            'delivery_mode_na' => 'boolean',
            'delivery_target_na' => 'boolean',
            'delivered_on' => 'date',
            'delivered_end_on' => 'date',
            'distribution_target_on' => 'date',
            'distribution_target_end_on' => 'date',
            'distributed_on' => 'date',
            'distributed_end_on' => 'date',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
