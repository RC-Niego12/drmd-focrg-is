<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreparednessBriefingIntro extends Model
{
    protected $fillable = ['preparedness_report_id', 'content', 'synopsis_image_path', 'dashboard_snapshot_path'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
