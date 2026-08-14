<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchPlanItem extends Model
{
    protected $fillable = [
        'dispatch_plan_id',
        'requisition_issuance_item_id',
        'request_item_id',
        'warehouse_id',
        'item_name',
        'unit',
        'warehouse_name',
        'allocated_quantity',
        'loaded_quantity',
        'received_quantity',
        'variance_disposition',
        'variance_resolution',
        'return_condition',
        'return_stock_disposition',
        'return_received_at',
        'return_inspected_by',
        'return_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'allocated_quantity' => 'integer',
            'loaded_quantity' => 'integer',
            'received_quantity' => 'integer',
            'return_received_at' => 'datetime',
        ];
    }

    public function dispatchPlan(): BelongsTo
    {
        return $this->belongsTo(DispatchPlan::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
