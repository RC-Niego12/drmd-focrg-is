<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DromicReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'report_number',
        'request_id',
        'incident_id',
        'affected_lgu',
        'date_released',
        'purpose',
        'assessment',
        'released_items',
        'google_sheet_url',
        'worksheet_name',
        'last_synced_at',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_released' => 'date',
            'released_items' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }
}
