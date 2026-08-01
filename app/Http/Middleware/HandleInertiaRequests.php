<?php

namespace App\Http\Middleware;

use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Services\AccessNotificationCenter;
use App\Services\RealtimePublisher;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $activeRegionCode = SystemSetting::getValue('default_region_code', '1600000000');
        $activeRegion = PsgcAddress::query()
            ->where('level', 'region')
            ->where('code', $activeRegionCode)
            ->first(['code', 'name', 'short_name']);
        $fieldOfficeLabel = SystemSetting::getValue('field_office_label', 'DSWD Field Office Caraga');
        $systemNames = OperationalLibraryValue::systemNameConfiguration();
        $profilePayload = is_array($user?->sso_profile_payload) ? $user->sso_profile_payload : [];
        $myPortalPayload = data_get($profilePayload, 'myportal');
        $identityProfile = $this->identityProfileData($profilePayload);
        $lguProfile = $user ? $this->lguProfileData($user) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'id_number' => $user->id_number,
                    'sso_sub' => $user->sso_sub,
                    'office' => $user->office,
                    'position' => $user->position,
                    'designation' => $user->designation,
                    'area_of_assignment' => $user->area_of_assignment,
                    'employment_status' => $user->employment_status,
                    'contact_number' => $user->contact_number,
                    'mobile_no' => $user->mobile_no,
                    'avatar' => filled($user->avatar) ? route('profile.photo') : null,
                    'avatar_source' => $user->avatar,
                    'sso_profile' => $user->sso_profile_payload,
                    'identity_profile' => $identityProfile,
                    'lgu_profile' => $lguProfile,
                    'agency_profile' => $user->hasRole('OCD Caraga')
                        ? $user->agencyProfile?->append('logo_url')
                        : null,
                    'myportal_profile' => [
                        'verified' => is_array($myPortalPayload) && $myPortalPayload !== [],
                        'last_checked_at' => data_get($profilePayload, 'myportal_checked_at'),
                    ],
                    'is_active' => $user->is_active,
                    'theme_mode' => $user->theme_mode,
                    'access_status' => $user->access_status,
                    'requested_role' => $user->requested_role,
                    'is_lgu' => $user->hasRole('LGU')
                        || filled($user->lgu_psgc_code)
                        || filled($user->lgu_level)
                        || filled($user->lgu_name),
                    'is_agency' => $user->hasRole('OCD Caraga'),
                    'lgu_psgc_code' => $user->lgu_psgc_code,
                    'lgu_level' => $user->lgu_level,
                    'lgu_name' => $user->lgu_name,
                    'aor_provinces' => $user->aor_provinces ?? [],
                    'aor_districts' => $user->aor_districts ?? [],
                    'aor_cities_municipalities' => $user->aor_cities_municipalities ?? [],
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'preview_report_id' => fn () => $request->session()->get('preview_report_id'),
                'correction_draft_id' => fn () => $request->session()->get('correction_draft_id'),
            ],
            'activeRegion' => [
                'code' => $activeRegion?->code ?? '1600000000',
                'name' => $activeRegion?->name ?? 'Caraga',
                'short_name' => $activeRegion?->short_name ?? 'CARAGA',
                'field_office_label' => $fieldOfficeLabel,
            ],
            'systemName' => $systemNames['long_name'],
            'systemNameLong' => $systemNames['long_name'],
            'systemNameShort' => $systemNames['short_name'],
            'sessionPolicy' => [
                'lifetime_minutes' => (int) config('session.lifetime', 120),
                'inactivity_timeout_minutes' => max(15, (int) config('session.inactivity_timeout', 90)),
                'inactivity_warning_minutes' => max(1, (int) config('session.inactivity_warning', 5)),
            ],
            'notificationCenter' => fn () => $user
                ? app(AccessNotificationCenter::class)->forUser($user)
                : null,
            'realtime' => fn () => app(RealtimePublisher::class)->connectionFor($user),
        ];
    }

    private function identityProfileData(array $profilePayload): array
    {
        $source = data_get($profilePayload, 'myportal.data');
        $sourceName = 'MyPortal';

        if (! is_array($source) || $source === []) {
            $source = data_get($profilePayload, 'sso.data');
            $sourceName = 'Caraga Connect SSO';
        }

        if (! is_array($source) || $source === []) {
            $source = data_get($profilePayload, 'sso');
            $sourceName = 'Caraga Connect SSO';
        }

        return [
            'source' => $sourceName,
            'data' => is_array($source) ? $source : [],
            'checked_at' => data_get($profilePayload, 'myportal_checked_at')
                ?: data_get($profilePayload, 'sso_checked_at'),
        ];
    }

    private function lguProfileData($user): ?array
    {
        if (! $user->lgu_psgc_code && ! $user->lgu_name && ! $user->hasRole('LGU')) {
            return null;
        }

        $directory = null;

        if ($user->lgu_psgc_code) {
            $directory = LguDirectoryEntry::query()
                ->with(['officials', 'contacts', 'ldrrmoOfficers', 'lswdoAlternates'])
                ->where('psgc_code', $user->lgu_psgc_code)
                ->first();
        }

        if (! $directory && $user->lgu_name) {
            $directory = LguDirectoryEntry::query()
                ->with(['officials', 'contacts', 'ldrrmoOfficers', 'lswdoAlternates'])
                ->where(function ($query) use ($user): void {
                    $query->where('lgu_name', $user->lgu_name)
                        ->orWhere('override_lgu_name', $user->lgu_name);
                })
                ->first();
        }

        $payload = is_array($directory?->ldrrmo_payload) ? $directory->ldrrmo_payload : [];
        $ldrrmoSourceValues = collect(data_get($payload, 'source_values', []))
            ->filter(fn ($value) => is_array($value))
            ->values()
            ->all();

        $contactsByOwner = $directory?->contacts
            ->groupBy('owner_role')
            ->map(fn ($group) => $group->mapWithKeys(fn ($contact) => [
                $contact->contact_type => $contact->override_value ?: $contact->value,
            ])->all())
            ->all() ?? [];

        if ($directory) {
            $contactsByOwner['lswd_officer'] = array_filter([
                ...($contactsByOwner['lswd_officer'] ?? []),
                'email' => data_get($contactsByOwner, 'lswd_officer.email') ?: $directory->lswd_email,
                'phone' => data_get($contactsByOwner, 'lswd_officer.phone') ?: $directory->lswd_contact_number,
                'alternate_email' => data_get($contactsByOwner, 'lswd_officer.alternate_email') ?: $directory->lswd_alternate_email,
                'facebook' => data_get($contactsByOwner, 'lswd_officer.facebook') ?: $directory->lswd_facebook,
            ], fn ($value) => filled($value));
        }

        $officials = $directory?->officials
            ->mapWithKeys(function ($official) use ($directory, $contactsByOwner) {
                $ownerContacts = $contactsByOwner[$official->role] ?? [];
                $photoUrl = match ($official->role) {
                    'lce' => $directory?->lce_photo_url,
                    'lswd_officer' => $directory?->lswd_photo_url,
                    'lswd_officer_alternate' => $directory?->lswd_photo_url,
                    'ldrrmo_alternate' => $directory?->ldrrmo_photo_url,
                    default => null,
                };

                return [
                    $official->role => [
                        'name' => $official->override_name ?: $official->name,
                        'position' => $official->override_position_designation ?: $official->position_designation,
                        'email' => $ownerContacts['email'] ?? null,
                        'alternate_email' => $ownerContacts['alternate_email'] ?? null,
                        'phone' => $ownerContacts['phone'] ?? $ownerContacts['contact'] ?? null,
                        'facebook' => $ownerContacts['facebook'] ?? null,
                        'vhf' => $ownerContacts['vhf'] ?? null,
                        'photo_url' => $photoUrl,
                    ],
                ];
            })
            ->all() ?? [];

        if ($directory) {
            $alternate = $officials['lswd_officer_alternate'] ?? [];
            $officials['lswd_officer_alternate'] = [
                ...$alternate,
                'name' => $alternate['name'] ?? $directory->lswd_alternate_name,
                'position' => $alternate['position'] ?? $directory->lswd_alternate_position,
                'phone' => $alternate['phone'] ?? $directory->lswd_alternate_contact_number,
            ];
        }

        $contacts = $directory?->contacts
            ->map(fn ($contact) => [
                'owner_role' => $contact->owner_role,
                'contact_type' => $contact->contact_type,
                'value' => $contact->override_value ?: $contact->value,
            ])
            ->filter(fn (array $contact): bool => filled($contact['value']))
            ->values()
            ->all() ?? [];

        $managedDistrict = $directory?->managed_district_name
            ?: $this->managedDistrictForPsgc($user->lgu_psgc_code ?: $directory?->psgc_code);
        $provinceName = $this->provinceNameForDirectory($directory, $user->lgu_psgc_code ?: $directory?->psgc_code);
        $logoUrl = $directory?->logo_url;

        return [
            'name' => $directory?->override_lgu_name ?: $directory?->lgu_name ?: $user->lgu_name ?: $user->area_of_assignment ?: $user->name,
            'level' => $directory?->lgu_level ?: $this->normalizeLguLevel($user->lgu_level) ?: 'LGU',
            'psgc_code' => $user->lgu_psgc_code ?: $directory?->psgc_code,
            'logo_url' => $logoUrl,
            'ldrrmc_logo_url' => $directory?->ldrrmc_logo_url,
            'lce_photo_url' => $directory?->lce_photo_url,
            'lswd_photo_url' => $directory?->lswd_photo_url,
            'ldrrmo_photo_url' => $directory?->ldrrmo_photo_url,
            'official_photos' => [
                'lce' => $directory?->lce_photo_url,
                'lswd_officer' => $directory?->lswd_photo_url,
                'ldrrmo' => $directory?->ldrrmo_photo_url,
            ],
            'source_sheet' => $directory?->source_sheet,
            'province_name' => $provinceName,
            'managed_district_code' => $directory?->managed_district_code,
            'managed_district_name' => $managedDistrict,
            'province_district_label' => $this->lguProvinceDistrictLabel($provinceName, $managedDistrict),
            'office_address' => $directory?->override_office_address ?: $directory?->office_address,
            'source_updated_label' => $directory?->source_updated_label,
            'missing_from_source' => (bool) ($directory?->missing_from_source ?? false),
            'source_seen_at' => $directory?->source_seen_at?->toISOString(),
            'officials' => $officials,
            'lswdo_alternates' => $directory?->lswdoAlternates
                ->map(fn ($alternate) => $alternate->only(['id', 'name', 'position', 'contact_number']))
                ->values()
                ->all() ?? [],
            'ldrrmo' => [
                'name' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->name ?: $directory?->ldrrmo_name,
                'position' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->designation ?: $directory?->ldrrmo_position,
                'contact' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->mobile_number ?: $directory?->ldrrmo_contact,
                'email' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->email_address ?: $directory?->ldrrmo_email,
                'facebook' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->facebook,
                'vhf' => $directory?->ldrrmoOfficers->firstWhere('is_primary', true)?->vhf_radio_frequency,
                'photo_url' => $directory?->ldrrmo_photo_url,
                'officers' => $directory?->ldrrmoOfficers
                    ->map(fn ($officer) => $officer->only([
                        'id',
                        'is_primary',
                        'office',
                        'name',
                        'designation',
                        'mobile_number',
                        'hotline_number',
                        'landline_number',
                        'email_address',
                        'alternate_email_address',
                        'vhf_radio_frequency',
                        'facebook',
                    ]))
                    ->values()
                    ->all() ?? [],
                'payload' => $directory?->ldrrmo_payload ?: [],
            ],
            'contacts' => $contacts,
        ];
    }

    private function lguProvinceDistrictLabel(?string $province, ?string $managedDistrict): ?string
    {
        return filled($managedDistrict) ? "{$province} - {$managedDistrict}" : $province;
    }

    private function provinceNameForDirectory(?LguDirectoryEntry $directory, ?string $psgcCode): ?string
    {
        if ($directory?->source_sheet) {
            return match (strtoupper((string) $directory->source_sheet)) {
                'ADN' => 'Agusan del Norte',
                'ADS' => 'Agusan del Sur',
                'PDI' => 'Province of Dinagat Islands',
                'SDN' => 'Surigao del Norte',
                'SDS' => 'Surigao del Sur',
                default => $directory->source_sheet,
            };
        }

        if (! $psgcCode) {
            return null;
        }

        $address = PsgcAddress::query()->where('code', $psgcCode)->first(['level', 'name', 'parent_code']);
        if (! $address) {
            return null;
        }

        if ($address->level === 'province') {
            return $address->name;
        }

        return PsgcAddress::query()
            ->where('level', 'province')
            ->where('code', $address->parent_code)
            ->value('name');
    }

    private function managedDistrictForPsgc(?string $psgcCode): ?string
    {
        if (! $psgcCode) {
            return null;
        }

        $address = PsgcAddress::query()->where('code', $psgcCode)->first(['district', 'district_code']);
        if (! $address) {
            return null;
        }

        $district = filled($address->district_code)
            ? PsgcAddress::query()
                ->where('level', 'district')
                ->where('code', $address->district_code)
                ->first(['name', 'short_name'])
            : null;

        return $district?->short_name ?: $district?->name ?: $address->district;
    }

    private function normalizeLguLevel(?string $level): ?string
    {
        return match (strtolower((string) $level)) {
            'province', 'plgu' => 'PLGU',
            'city', 'city_municipality', 'clgu' => 'CLGU',
            'municipality', 'mlgu' => 'MLGU',
            default => filled($level) ? strtoupper((string) $level) : null,
        };
    }
}
