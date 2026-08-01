<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LguDirectoryLdrrmoOfficer extends Model
{
    protected $fillable = [
        'sort_order',
        'is_primary',
        'office',
        'name',
        'designation',
        'mobile_number',
        'hotline_number',
        'landline_number',
        'email_address',
        'alternate_email_address',
        'vhf_radio_frequency',
        'facebook',
        'is_locally_updated',
        'source_signature',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_locally_updated' => 'boolean',
        ];
    }
}
