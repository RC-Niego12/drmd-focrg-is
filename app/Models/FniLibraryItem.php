<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FniLibraryItem extends Model
{
    protected $fillable = ['item_category', 'item_name', 'brand_description', 'unit_of_measure'];

    public function lguDromicRequestedItems(): HasMany
    {
        return $this->hasMany(LguDromicRequestedItem::class);
    }
}
