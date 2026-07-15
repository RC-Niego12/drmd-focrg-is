<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestParty extends Model
{
    protected $fillable = ['directory_key', 'lgu_directory_entry_id', 'office_agency_details', 'requesting_party', 'lgu_level', 'office_head', 'source', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AssistanceRequest::class);
    }
    public function lguDirectoryEntry(): BelongsTo { return $this->belongsTo(LguDirectoryEntry::class); }
}
