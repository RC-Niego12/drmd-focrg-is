<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RisSyncRun extends Model
{
    protected $fillable = ['started_by', 'trigger', 'status', 'rows_seen', 'records_created', 'records_updated', 'items_synced', 'changes', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
