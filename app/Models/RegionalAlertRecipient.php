<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionalAlertRecipient extends Model
{
    protected $fillable = [
        'regional_alert_id',
        'user_id',
        'recipient_category',
        'recipient_name',
        'recipient_role',
        'office',
        'lgu_name',
        'notified_at',
        'acknowledged_at',
        'superseded_at',
        'acknowledgement_method',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RegionalAlert::class, 'regional_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
