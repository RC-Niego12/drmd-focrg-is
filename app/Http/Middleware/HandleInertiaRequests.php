<?php

namespace App\Http\Middleware;

use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Services\AccessNotificationCenter;
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
                    'myportal_profile' => [
                        'verified' => is_array($myPortalPayload) && $myPortalPayload !== [],
                        'last_checked_at' => data_get($profilePayload, 'myportal_checked_at'),
                    ],
                    'is_active' => $user->is_active,
                    'theme_mode' => $user->theme_mode,
                    'access_status' => $user->access_status,
                    'requested_role' => $user->requested_role,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
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
            'notificationCenter' => fn () => $user
                ? app(AccessNotificationCenter::class)->forUser($user)
                : null,
        ];
    }
}
