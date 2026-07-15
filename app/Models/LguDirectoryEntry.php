<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class LguDirectoryEntry extends Model
{
    protected $fillable=['source_sheet','lgu_name','psgc_code','congressional_district','office_address','source_updated_label','is_active','override_lgu_name','override_congressional_district','override_office_address','source_hash','source_seen_at','missing_from_source'];
    protected function casts(): array { return ['is_active'=>'boolean','missing_from_source'=>'boolean','source_seen_at'=>'datetime']; }
    public function officials(): HasMany { return $this->hasMany(LguDirectoryOfficial::class); }
    public function contacts(): HasMany { return $this->hasMany(LguDirectoryContact::class); }
}
