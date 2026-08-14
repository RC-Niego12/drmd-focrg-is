<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'inventory_batch_id',
        'user_id',
        'type',
        'transaction_date',
        'source_of_goods',
        'purpose',
        'reference_number',
        'ris_if_stf',
        'call_off_number',
        'sender_supplier',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'recipient',
        'delivery_site',
        'expected_delivery_date',
        'transport_details',
        'external_status',
        'reconciliation_status',
        'reconciled_at',
        'encoded_by_email',
        'encoded_at',
        'edited_by_email',
        'edited_at',
        'transactionable_type',
        'transactionable_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transaction_date' => 'date',
            'expected_delivery_date' => 'date',
            'transport_details' => 'array',
            'encoded_at' => 'datetime',
            'edited_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sheetImport(): HasOne
    {
        return $this->hasOne(WarehouseSheetImport::class, 'inventory_transaction_id');
    }

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }
}
