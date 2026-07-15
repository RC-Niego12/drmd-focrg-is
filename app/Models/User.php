<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'sso_sub',
        'username',
        'id_number',
        'password',
        'office',
        'position',
        'designation',
        'contact_number',
        'mobile_no',
        'avatar',
        'mfa_enabled',
        'mfa_verified',
        'is_active',
        'theme_mode',
        'access_status',
        'requested_role',
        'access_requested_at',
        'access_approved_at',
        'access_approved_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_verified' => 'boolean',
            'access_requested_at' => 'datetime',
            'access_approved_at' => 'datetime',
        ];
    }
}
