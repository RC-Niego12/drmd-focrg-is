<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AgencyProfile extends Model
{
    protected $fillable = [
        'user_id', 'agency_name', 'acronym', 'office_address', 'contact_person',
        'contact_designation', 'email', 'alternate_email', 'contact_number',
        'hotline_number', 'website', 'facebook', 'logo_path',
    ];

    protected $appends = ['logo_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
