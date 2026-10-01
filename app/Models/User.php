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
        'lgu_directory_role',
        'must_change_password',
        'password_is_default',
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
            'must_change_password' => 'boolean',
            'password_is_default' => 'boolean',
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

    /**
     * Linked LGU personnel (or soft-matched full editors) may open the profile editor.
     */
    public function canEditLguProfile(?LguDirectoryEntry $directory = null): bool
    {
        return app(\App\Services\LguPersonnelAccountService::class)->canEditLguProfile($this);
    }

    public function canManageFullLguProfile(): bool
    {
        return app(\App\Services\LguPersonnelAccountService::class)->isFullProfileEditor($this);
    }

    public function canAccessLguPortalLogin(): bool
    {
        return app(\App\Services\LguPersonnelAccountService::class)->userCanAccessLguPortal($this);
    }

    public function lguProfileEditCapabilities(): array
    {
        return app(\App\Services\LguPersonnelAccountService::class)->editCapabilities($this);
    }

    public function resolveLguDirectoryEntry(): ?LguDirectoryEntry
    {
        if (filled($this->lgu_psgc_code)) {
            $byPsgc = LguDirectoryEntry::query()
                ->where('psgc_code', $this->lgu_psgc_code)
                ->first();
            if ($byPsgc) {
                return $byPsgc;
            }
        }

        if (filled($this->lgu_name)) {
            return LguDirectoryEntry::query()
                ->where(function ($query): void {
                    $query->where('lgu_name', $this->lgu_name)
                        ->orWhere('override_lgu_name', $this->lgu_name);
                })
                ->first();
        }

        return null;
    }

    /**
     * Soft match used only as transitional fallback for unlinked LSWDO/LDRRMO accounts.
     */
    public function canEditLguProfileSoftMatch(?LguDirectoryEntry $directory = null): bool
    {
        if ($this->hasRole('Super Admin')) {
            return true;
        }

        if (! ($this->hasRole('LGU') || filled($this->lgu_psgc_code) || filled($this->lgu_name))) {
            return false;
        }

        if ($this->matchesLguProfileEditorRoleHint()) {
            return true;
        }

        $directory ??= $this->resolveLguDirectoryEntry();

        return $directory ? $this->matchesLguDirectoryProfileEditor($directory) : false;
    }

    private function matchesLguProfileEditorRoleHint(): bool
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($this->position ?? ''),
            (string) ($this->designation ?? ''),
            (string) ($this->office ?? ''),
            (string) ($this->requested_role ?? ''),
            (string) ($this->lgu_directory_role ?? ''),
        ]))));

        if ($text === '') {
            return false;
        }

        $isLce = (bool) preg_match('/\b(lce|mayor|local\s+chief\s+executive)\b/u', $text);
        $isLswd = (bool) preg_match('/\b(lswdo|mswdo|cswdo|pswdo|lswd|social\s+welfare)\b/u', $text);
        $isLdrrmo = (bool) preg_match('/\b(ldrrmo|mdrrmo|cdrrmo|pdrr?mo|drrmo|disaster\s+risk)\b/u', $text);

        return $isLce || $isLswd || $isLdrrmo;
    }

    private function matchesLguDirectoryProfileEditor(LguDirectoryEntry $directory): bool
    {
        $directory->loadMissing(['officials', 'contacts', 'lswdoAlternates', 'ldrrmoOfficers']);

        $nameKey = $this->normalizePersonKey($this->name);
        $emailKey = $this->normalizeEmailKey($this->email);

        foreach ($directory->officials as $official) {
            if (! in_array($official->role, ['lce', 'lswd_officer', 'lswd_officer_alternate', 'ldrrmo_alternate'], true)) {
                continue;
            }

            $display = trim((string) ($official->override_name ?: $official->name));
            if ($nameKey !== '' && $this->normalizePersonKey($display) === $nameKey) {
                return true;
            }
        }

        foreach ($directory->lswdoAlternates as $alternate) {
            if ($nameKey !== '' && $this->normalizePersonKey((string) $alternate->name) === $nameKey) {
                return true;
            }
        }

        foreach ($directory->ldrrmoOfficers as $officer) {
            if ($nameKey !== '' && $this->normalizePersonKey((string) $officer->name) === $nameKey) {
                return true;
            }

            if ($emailKey !== '' && (
                $this->normalizeEmailKey((string) $officer->email_address) === $emailKey
                || $this->normalizeEmailKey((string) $officer->alternate_email_address) === $emailKey
            )) {
                return true;
            }
        }

        foreach ($directory->contacts as $contact) {
            if (! in_array($contact->owner_role, ['lce', 'lswd_officer', 'lswd_officer_alternate', 'ldrrmo', 'ldrrmo_alternate'], true)) {
                continue;
            }
            if (! in_array($contact->contact_type, ['email', 'alternate_email'], true)) {
                continue;
            }

            $value = trim((string) ($contact->override_value ?: $contact->value));
            if ($emailKey !== '' && $this->normalizeEmailKey($value) === $emailKey) {
                return true;
            }
        }

        return false;
    }

    private function normalizePersonKey(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
    }

    private function normalizeEmailKey(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
