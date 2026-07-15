<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'external_warehouse_id',
        'warehouse_number',
        'office',
        'province',
        'municipality',
        'district',
        'barangay_name',
        'barangay_code',
        'capacity',
        'rtef_capacity',
        'ffp_capacity',
        'sheet_ffp_current',
        'sheet_ffp_cost',
        'sheet_total_items',
        'sheet_total_cost',
        'contact_person',
        'contact_number',
        'email',
        'distribution_network',
        'warehouse_type',
        'category',
        'ownership',
        'partnership',
        'longitude',
        'latitude',
        'rpa_start_date',
        'rpa_end_date',
        'validity',
        'designated_storekeepers',
        'storekeeper_contact_number',
        'sheet_payload',
        'master_synced_at',
        'status',
    ];

    protected $appends = ['display_name'];

    protected $casts = [
        'rtef_capacity' => 'decimal:2',
        'ffp_capacity' => 'decimal:2',
        'sheet_ffp_current' => 'decimal:2',
        'sheet_ffp_cost' => 'decimal:2',
        'sheet_total_items' => 'decimal:2',
        'sheet_total_cost' => 'decimal:2',
        'longitude' => 'decimal:8',
        'latitude' => 'decimal:8',
        'rpa_start_date' => 'date',
        'rpa_end_date' => 'date',
        'sheet_payload' => 'array',
        'master_synced_at' => 'datetime',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function population(): HasOne
    {
        return $this->hasOne(BarangayPopulation::class, 'barangay_psgc_code', 'barangay_code');
    }

    public function getDisplayNameAttribute(): string
    {
        $name = trim((string) $this->name);
        $province = trim((string) $this->province);

        if ($name === '') {
            return $province;
        }

        $prefix = strtolower($province).',';

        if ($province !== '' && str_starts_with(strtolower($name), $prefix)) {
            return preg_replace('/\s*,\s*/', ', ', $name);
        }

        return $province !== '' ? "{$province}, {$name}" : $name;
    }
}
