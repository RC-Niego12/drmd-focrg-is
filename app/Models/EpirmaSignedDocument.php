<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpirmaSignedDocument extends Model
{
    protected $fillable = [
        'assistance_request_id',
        'document_uuid',
        'document_name',
        'document_path',
        'description_subject',
        'encoded_by',
        'timestamp',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
        ];
    }

    public function assistanceRequest(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'assistance_request_id');
    }
}
