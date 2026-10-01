<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionIssuanceItem extends Model
{
    protected $fillable = ['request_item_id', 'warehouse_id', 'unit', 'item_name', 'brand_description', 'expiry', 'quantity', 'unit_cost', 'warehouse_name', 'warehouse_type', 'allocation_guide', 'wit_stock_balance', 'remaining_balance', 'allocation_status', 'remarks', 'source_row_number'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'allocation_guide' => 'integer',
            // Stock balances may be fractional in WIT; UI truncates for display.
            'wit_stock_balance' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(RequisitionIssuanceSlip::class, 'requisition_issuance_slip_id');
    }
}
