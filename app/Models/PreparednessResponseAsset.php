<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreparednessResponseAsset extends Model
{
    use SoftDeletes;

    protected $fillable = ['preparedness_report_id', 'label', 'quantity', 'status', 'image_path', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }
}
