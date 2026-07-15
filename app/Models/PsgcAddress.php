<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsgcAddress extends Model
{
    protected $fillable = [
        'code',
        'parent_code',
        'level',
        'name',
        'short_name',
        'type',
        'district',
        'district_code',
        'zip_code',
        'raw_payload',
        'source',
        'source_version',
        'sync_batch',
        'synced_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
