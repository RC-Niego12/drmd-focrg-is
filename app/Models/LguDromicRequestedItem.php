<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LguDromicRequestedItem extends Model
{
    protected $fillable = [
        'request_id',
        'fni_library_item_id',
        'requested_quantity',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }

    public function fniLibraryItem(): BelongsTo
    {
        return $this->belongsTo(FniLibraryItem::class);
    }
}
