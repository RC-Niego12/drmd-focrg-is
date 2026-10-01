<?php

namespace App\Http\Controllers;

use App\Models\LguDirectoryEntry;
use App\Models\PsgcAddress;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Http\Request;
use Inertia\Response;

class LguPopulationController extends Controller
{
    public function index(
        Request $request,
        LguWarehousePersonnelSyncService $warehouseScope,
        PopulationController $population,
    ): Response {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('LGU') || $user->can('submit lgu dromic requests')),
            403,
            'You are not authorized to view LGU population data.',
        );

        $directory = $warehouseScope->directoryForUser($user);
        abort_unless($directory, 403, 'Your account is not linked to an LGU directory.');

        $lockedFilters = $this->lockedFiltersForDirectory($directory);
        abort_unless(
            filled($lockedFilters['province_code'] ?? null) || filled($lockedFilters['city_code'] ?? null),
            422,
            'This LGU does not have a valid PSGC code for population lookup.',
        );

        $lguName = $directory->override_lgu_name ?: $directory->lgu_name;

        return $population->renderIndex($request, $lockedFilters, [
            'workspace' => 'lgu',
            'readOnly' => true,
            'filterBasePath' => '/lgu/population',
            'lgu' => [
                'name' => $lguName,
                'level' => $directory->lgu_level,
                'psgc_code' => $directory->psgc_code,
            ],
        ]);
    }

    /**
     * @return array{province_code?:string,city_code?:string}
     */
    private function lockedFiltersForDirectory(LguDirectoryEntry $directory): array
    {
        $psgc = preg_replace('/\D+/', '', (string) $directory->psgc_code) ?? '';
        $psgc = strlen($psgc) >= 10 ? substr($psgc, 0, 10) : $psgc;
        if ($psgc === '') {
            return [];
        }

        $level = strtoupper(trim((string) $directory->lgu_level));
        if (in_array($level, ['PLGU', 'PROVINCE'], true)) {
            return ['province_code' => $psgc];
        }

        $city = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('code', $psgc)
            ->where('is_active', true)
            ->first(['code']);

        if ($city) {
            return ['city_code' => $psgc];
        }

        $province = PsgcAddress::query()
            ->where('level', 'province')
            ->where('code', $psgc)
            ->where('is_active', true)
            ->first(['code']);

        if ($province) {
            return ['province_code' => $psgc];
        }

        // Fallback: treat as city/municipality PSGC (most MLGU/CLGU rows).
        return ['city_code' => $psgc];
    }
}
