<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LguDirectoryEntry extends Model
{
    protected $fillable = ['source_sheet', 'lgu_name', 'psgc_code', 'lgu_level', 'managed_district_code', 'managed_district_name', 'congressional_district', 'office_address', 'lgu_logo_path', 'ldrrmc_logo_path', 'lce_photo_path', 'lswd_photo_path', 'ldrrmo_photo_path', 'lswd_email', 'lswd_contact_number', 'lswd_alternate_email', 'lswd_facebook', 'lswd_alternate_name', 'lswd_alternate_position', 'lswd_alternate_contact_number', 'ldrrmo_name', 'ldrrmo_position', 'ldrrmo_contact', 'ldrrmo_email', 'ldrrmo_payload', 'source_updated_label', 'is_active', 'override_lgu_name', 'override_congressional_district', 'override_office_address', 'source_hash', 'source_seen_at', 'missing_from_source'];

    protected $appends = ['logo_url', 'ldrrmc_logo_url', 'lce_photo_url', 'lswd_photo_url', 'ldrrmo_photo_url'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'missing_from_source' => 'boolean', 'source_seen_at' => 'datetime', 'ldrrmo_payload' => 'array'];
    }

    public function officials(): HasMany
    {
        return $this->hasMany(LguDirectoryOfficial::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(LguDirectoryContact::class);
    }

    public function ldrrmoOfficers(): HasMany
    {
        return $this->hasMany(LguDirectoryLdrrmoOfficer::class)->orderBy('sort_order');
    }

    public function lswdoAlternates(): HasMany
    {
        return $this->hasMany(LguDirectoryLswdoAlternate::class)->orderBy('sort_order');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->publicUrl($this->lgu_logo_path);
    }

    public function getLdrrmcLogoUrlAttribute(): ?string
    {
        return $this->publicUrl($this->ldrrmc_logo_path);
    }

    public function getLcePhotoUrlAttribute(): ?string
    {
        return $this->publicUrl($this->lce_photo_path);
    }

    public function getLswdPhotoUrlAttribute(): ?string
    {
        return $this->publicUrl($this->lswd_photo_path);
    }

    public function getLdrrmoPhotoUrlAttribute(): ?string
    {
        return $this->publicUrl($this->ldrrmo_photo_path);
    }

    private function publicUrl(?string $path): ?string
    {
        return filled($path) ? '/storage/'.ltrim($path, '/') : null;
    }
}
