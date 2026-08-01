<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'area_of_assignment',
        'employment_status',
        'sso_profile_payload',
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
        'access_response_message',
        'access_decided_at',
        'lgu_psgc_code',
        'lgu_level',
        'lgu_name',
        'aor_provinces',
        'aor_districts',
        'aor_cities_municipalities',
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
            'access_decided_at' => 'datetime',
            'sso_profile_payload' => 'array',
            'aor_provinces' => 'array',
            'aor_districts' => 'array',
            'aor_cities_municipalities' => 'array',
        ];
    }

    public function sentSystemMessages(): HasMany
    {
        return $this->hasMany(SystemMessage::class, 'sender_id');
    }

    public function receivedSystemMessages(): HasMany
    {
        return $this->hasMany(SystemMessage::class, 'recipient_id');
    }

    public function agencyProfile(): HasOne
    {
        return $this->hasOne(AgencyProfile::class);
    }

    public function aorEntries(): HasMany
    {
        return $this->hasMany(EmployeeAreaOfResponsibility::class)
            ->orderByRaw("CASE level WHEN 'province' THEN 1 WHEN 'district' THEN 2 WHEN 'city_municipality' THEN 3 ELSE 4 END")
            ->orderBy('psgc_code');
    }
}
