<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'incident_date',
        'province',
        'municipality',
        'barangay',
        'summary',
    ];

    protected function casts(): array
    {
        return ['incident_date' => 'date'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AssistanceRequest::class, 'incident_id');
    }
}
