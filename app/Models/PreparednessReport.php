<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparednessReport extends Model
{
    protected $hidden = ['data_snapshot'];

    protected $fillable = ['title', 'status', 'reporting_as_of', 'revision_deadline', 'data_snapshot', 'created_by', 'finalized_at', 'finalized_by'];

    protected function casts(): array
    {
        return ['reporting_as_of' => 'datetime', 'revision_deadline' => 'datetime', 'data_snapshot' => 'array', 'finalized_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return in_array($this->status, ['draft', 'revised'], true);
    }

    public function canBeRevised(): bool
    {
        // Finalized reports can be explicitly reopened for corrections, including
        // after their reporting date. Keep those corrections editable until finalized.
        if (in_array($this->status, ['finalized', 'revised'], true)) {
            return true;
        }

        return $this->revision_deadline === null || now()->lte($this->revision_deadline);
    }
}
