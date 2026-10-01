<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LguDirectoryStaffMember extends Model
{
    public const TYPE_DROMIC_ENCODER = 'dromic_encoder';

    public const TYPE_WAREHOUSE_FOCAL = 'warehouse_focal';

    public const TYPE_WAREHOUSE_STOREKEEPER = 'warehouse_storekeeper';

    public const TYPE_DRIVER = 'driver';

    public const TYPES = [
        self::TYPE_DROMIC_ENCODER,
        self::TYPE_WAREHOUSE_FOCAL,
        self::TYPE_WAREHOUSE_STOREKEEPER,
        self::TYPE_DRIVER,
    ];

    protected $fillable = [
        'lgu_directory_entry_id',
        'staff_type',
        'sort_order',
        'office',
        'name',
        'position',
        'id_number',
        'contact_number',
        'email',
        'user_id',
        'login_username',
        'is_locally_updated',
    ];

    protected function casts(): array
    {
        return [
            'is_locally_updated' => 'boolean',
        ];
    }

    public function directoryEntry(): BelongsTo
    {
        return $this->belongsTo(LguDirectoryEntry::class, 'lgu_directory_entry_id');
    }
}
