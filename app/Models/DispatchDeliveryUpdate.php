<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchDeliveryUpdate extends Model
{
    protected $fillable = ['dispatch_plan_id', 'vehicle_index', 'reported_by', 'reporter_role', 'stage', 'occurred_at', 'location', 'latitude', 'longitude', 'accuracy_meters', 'message', 'photo_paths'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_meters' => 'decimal:1', 'photo_paths' => 'array'];
    }

    public function dispatchPlan(): BelongsTo { return $this->belongsTo(DispatchPlan::class); }
    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reported_by'); }
}
