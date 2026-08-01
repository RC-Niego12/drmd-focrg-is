<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegionalAlert extends Model
{
    protected $fillable = [
        'set_by', 'alert_level', 'incident_name', 'coverage', 'reason',
        'effective_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(RegionalAlertRecipient::class);
    }

    public function scopeEffective($query)
    {
        return $query
            ->where('effective_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
