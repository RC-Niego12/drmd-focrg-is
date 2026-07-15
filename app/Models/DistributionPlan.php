<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DistributionPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'inventory_batch_id',
        'request_id',
        'program_type',
        'beneficiary',
        'location',
        'quantity',
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
            'activity_date' => 'date',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
