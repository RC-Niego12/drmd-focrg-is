<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FniLibraryItem extends Model
{
    protected $fillable = ['item_category', 'item_name', 'brand_description', 'unit_of_measure'];
}
