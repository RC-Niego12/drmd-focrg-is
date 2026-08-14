<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StfSheetTransaction extends Model
{
    protected $fillable = ['stf_number', 'stf_date', 'recipient', 'delivery_site', 'purpose', 'status', 'source_row_number', 'tracking_data', 'items', 'sheet_synced_at'];

    protected function casts(): array
    {
        return ['stf_date' => 'date', 'tracking_data' => 'array', 'items' => 'array', 'sheet_synced_at' => 'datetime'];
    }
}
