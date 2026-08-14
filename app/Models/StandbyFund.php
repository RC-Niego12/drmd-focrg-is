<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandbyFund extends Model
{
    protected $fillable = [
        'office',
        'amount',
        'source',
        'google_sheet_url',
        'cell_reference',
        'synced_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['office' => 'DSWD Field Office Caraga'],
            [
                'amount' => 3000000,
                'source' => 'Initial system value',
                'cell_reference' => 'M2',
            ],
        );
    }
}
