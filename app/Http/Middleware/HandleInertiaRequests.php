<?php

namespace App\Http\Middleware;

use App\Models\OperationalLibraryValue;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office' => $user->office,
                    'position' => $user->position,
                    'designation' => $user->designation,
                    'avatar' => $user->avatar,
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
        ];
    }
}
