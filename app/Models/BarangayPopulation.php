<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarangayPopulation extends Model
{
    protected $fillable = [
        'barangay_psgc_code',
        'population',
        'estimated_families',
        'census_year',
        'poor_families',
        'poor_individuals',
        'source',
        'last_updated',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'estimated_families' => 'integer',
            'census_year' => 'integer',
            'poor_families' => 'integer',
            'poor_individuals' => 'integer',
            'last_updated' => 'date',
            'raw_payload' => 'array',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(PsgcAddress::class, 'barangay_psgc_code', 'code');
    }
}
