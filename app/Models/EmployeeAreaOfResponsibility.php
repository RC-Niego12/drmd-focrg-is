<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class EmployeeAreaOfResponsibility extends Model
{
    protected $table = 'employee_area_of_responsibilities';

    protected $fillable = [
        'user_id',
        'level',
        'psgc_code',
        'name',
        'parent_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function aorPeerRolesFor(User $user): array
    {
        if ($user->hasRole('DRRS')) {
            return ['DRRS'];
        }

        if ($user->hasRole('DRIMS')) {
            return ['DRIMS'];
        }

        return [];
    }

    public static function syncForUser(User $user, array $provinceCodes = [], array $districtCodes = [], array $cityCodes = []): void
    {
        $user->aorEntries()->delete();

        $groups = [
            'province' => $provinceCodes,
            'district' => $districtCodes,
            'city_municipality' => $cityCodes,
        ];

        $rows = [];
        $now = now();

        foreach ($groups as $level => $codes) {
            foreach (array_values(array_unique(array_filter(array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : null, $codes), fn (string $value) => $value !== ''))) as $code) {
                $address = PsgcAddress::query()->where('code', $code)->first(['code', 'name', 'parent_code', 'district_code']);

                $rows[] = [
                    'user_id' => $user->getKey(),
                    'level' => $level,
                    'psgc_code' => $code,
                    'name' => $address?->name,
                    'parent_code' => $address?->parent_code ?: $address?->district_code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            static::insert($rows);
        }
    }

    public static function scopeConflictsForUser(User $user, array $provinceCodes = [], array $districtCodes = [], array $cityCodes = []): array
    {
        $peerRoles = static::aorPeerRolesFor($user);
        if ($peerRoles === []) {
            return [];
        }

        $groups = [
            ['level' => 'province', 'codes' => $provinceCodes],
            ['level' => 'district', 'codes' => $districtCodes],
            ['level' => 'city_municipality', 'codes' => $cityCodes],
        ];

        $selectedScopes = collect($groups)
            ->flatMap(function (array $group): array {
                $codes = array_values(array_unique(array_filter(array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : null, $group['codes']), fn (string $value) => $value !== '')));

                return collect($codes)
                    ->map(fn (string $code): array => ['level' => $group['level'], 'psgc_code' => $code])
                    ->all();
            })
            ->unique(fn (array $row): string => $row['level'].':'.$row['psgc_code'])
            ->values()
            ->all();

        if ($selectedScopes === []) {
            return [];
        }

        $otherScopes = static::query()
            ->where('user_id', '!=', $user->getKey())
            ->whereHas('user', function ($query) use ($peerRoles): void {
                $query->where('is_active', true)
                    ->whereHas('roles', function ($rolesQuery) use ($peerRoles): void {
                        $rolesQuery->whereIn('name', $peerRoles);
                    });
            })
            ->with('user:id,name,office')
            ->get(['id', 'user_id', 'level', 'psgc_code']);

        $occupiedByCode = $otherScopes->groupBy('psgc_code');
        $occupiedByLevel = [
            'province' => $otherScopes->where('level', 'province')->pluck('psgc_code')->unique()->values()->all(),
            'district' => $otherScopes->where('level', 'district')->pluck('psgc_code')->unique()->values()->all(),
            'city_municipality' => $otherScopes->where('level', 'city_municipality')->pluck('psgc_code')->unique()->values()->all(),
        ];

        $selectedByLevel = collect($selectedScopes)->groupBy('level');
        $conflicts = [];

        foreach ($selectedByLevel as $level => $scopes) {
            foreach ($scopes as $scope) {
                $code = $scope['psgc_code'];
                if ($level === 'city_municipality') {
                    if (in_array($code, $occupiedByLevel['city_municipality'], true)) {
                        $conflicts[] = static::conflictPayload($scope['level'], $code, $occupiedByCode->get($code));
                        continue;
                    }

                    $districtCode = PsgcAddress::query()
                        ->whereIn('level', ['city', 'municipality', 'city_municipality'])
                        ->where('code', $code)
                        ->value('district_code');

                    // Selecting a city under another user's claimed district is not allowed.
                    if ($districtCode && in_array((string) $districtCode, $occupiedByLevel['district'], true)) {
                        $conflicts[] = static::conflictPayload(
                            $scope['level'],
                            $code,
                            $occupiedByCode->get((string) $districtCode) ?? collect()
                        );
                    }
                    continue;
                }

                if ($level === 'district') {
                    // A claimed district is fully reserved for its owner, including all cities under it.
                    if (in_array($code, $occupiedByLevel['district'], true)) {
                        $conflicts[] = static::conflictPayload($scope['level'], $code, $occupiedByCode->get($code));
                        continue;
                    }

                    // Cities already claimed under this district also block selecting the whole district.
                    $freeCities = static::freeCitiesUnderDistrict($code, $occupiedByLevel);
                    $allCities = PsgcAddress::query()
                        ->whereIn('level', ['city', 'municipality', 'city_municipality'])
                        ->where('district_code', $code)
                        ->pluck('code')
                        ->values()
                        ->all();

                    if ($allCities !== [] && $freeCities === []) {
                        $conflicts[] = static::conflictPayload($scope['level'], $code, $occupiedByCode->get($code) ?? collect());
                    }
                    continue;
                }

                if ($level === 'province') {
                    $parentFreeDescendants = static::freeDescendantsUnderProvince($code, $occupiedByLevel);
                    if ($parentFreeDescendants === []) {
                        $conflicts[] = static::conflictPayload(
                            $scope['level'],
                            $code,
                            $occupiedByCode->get($code) ?? collect()
                        );
                    }
                }
            }
        }

        return collect($conflicts)
            ->unique(fn (array $conflict): string => $conflict['level'].':'.$conflict['psgc_code'])
            ->values()
            ->all();
    }

    protected static function conflictPayload(string $level, string $code, Collection $rows): array
    {
        $owner = $rows?->first()?->user;

        return [
            'level' => $level,
            'psgc_code' => $code,
            'owner_name' => $owner?->name ?? 'Another user',
            'owner_office' => $owner?->office ?? 'Unknown office',
        ];
    }

    protected static function freeCitiesUnderDistrict(string $districtCode, array $occupiedByLevel): array
    {
        if (in_array($districtCode, $occupiedByLevel['district'], true)) {
            return [];
        }

        return PsgcAddress::query()
            ->whereIn('level', ['city', 'municipality', 'city_municipality'])
            ->where('district_code', $districtCode)
            ->pluck('code')
            ->filter(fn (string $cityCode): bool => ! in_array($cityCode, $occupiedByLevel['city_municipality'], true))
            ->values()
            ->all();
    }

    protected static function freeDescendantsUnderProvince(string $provinceCode, array $occupiedByLevel): array
    {
        $districtCodes = PsgcAddress::query()
            ->where('level', 'district')
            ->where('parent_code', $provinceCode)
            ->pluck('code')
            ->values()
            ->all();

        $freeDistricts = collect($districtCodes)
            ->filter(fn (string $districtCode): bool => ! in_array($districtCode, $occupiedByLevel['district'], true))
            ->values()
            ->all();

        $districtFreeCities = [];
        foreach ($districtCodes as $districtCode) {
            $districtFreeCities = [...$districtFreeCities, ...static::freeCitiesUnderDistrict($districtCode, $occupiedByLevel)];
        }

        return [...$freeDistricts, ...$districtFreeCities];
    }
}
