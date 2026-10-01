<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreparednessActionPage extends Model
{
    protected $fillable = ['preparedness_report_id', 'actions', 'captions', 'image_path', 'image_paths', 'sort_order'];

    protected function casts(): array
    {
        return ['actions' => 'array', 'captions' => 'array', 'image_paths' => 'array', 'sort_order' => 'integer'];
    }
}
