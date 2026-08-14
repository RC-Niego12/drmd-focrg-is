<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    protected $fillable = [
        'request_id',
        'inventory_item_id',
        'fni_library_item_id',
        'source_warehouse_id',
        'item_name',
        'requested_quantity',
        'available_quantity',
        'approved_quantity',
        'unit',
        'priority',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'approved_quantity' => 'integer',
            'available_quantity' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function fniLibraryItem(): BelongsTo
    {
        return $this->belongsTo(FniLibraryItem::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }
}
