<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DispatchPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dispatch_number',
        'request_id',
        'vehicle_id',
        'receiver_id',
        'destination',
        'receiving_agency_lgu',
        'driver',
        'dispatcher',
        'dispatch_date',
        'estimated_arrival',
        'actual_arrival',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_date' => 'date',
            'estimated_arrival' => 'datetime',
            'actual_arrival' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }
}
