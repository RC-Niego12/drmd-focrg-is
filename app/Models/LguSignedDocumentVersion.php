<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LguSignedDocumentVersion extends Model
{
    protected $hidden = ['path'];

    protected $fillable = [
        'request_id',
        'kind',
        'path',
        'original_name',
        'uploaded_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function assistanceRequest(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }
}
