<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreparednessQrtCoverageArea extends Model
{
    use SoftDeletes;

    protected $fillable = ['preparedness_report_id', 'area', 'coverage_type', 'members', 'sort_order'];

    protected function casts(): array
    {
        return ['members' => 'integer', 'sort_order' => 'integer'];
    }
}
