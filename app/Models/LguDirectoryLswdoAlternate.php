<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LguDirectoryLswdoAlternate extends Model
{
    protected $fillable = [
        'sort_order',
        'name',
        'position',
        'contact_number',
        'is_locally_updated',
    ];

    protected function casts(): array
    {
        return ['is_locally_updated' => 'boolean'];
    }
}
