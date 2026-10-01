<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreparednessQrtSpecialization extends Model
{
    use SoftDeletes;

    protected $fillable = ['preparedness_report_id', 'specialization', 'members', 'sort_order'];

    protected function casts(): array
    {
        return ['members' => 'integer', 'sort_order' => 'integer'];
    }
}
