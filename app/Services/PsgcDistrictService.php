<?php

namespace App\Services;

use App\Models\PsgcAddress;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PsgcDistrictService
{
    public function syncAssignment(string $provinceName, string $localityName, string $districtName): ?PsgcAddress
    {
        if (trim($districtName) === '') {
            return null;
        }

        $province = PsgcAddress::query()->where('level', 'province')->where('is_active', true)
            ->get(['code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->normalizeProvince($row->name) === $this->normalizeProvince($provinceName));

        if (! $province) {
            return null;
        }

        $city = PsgcAddress::query()->where('level', 'city_municipality')->where('is_active', true)
            ->where(fn ($query) => $query->where('parent_code', $province->code)
                ->when($province->code === '1600200000', fn ($query) => $query->orWhere('code', '1630400000')))
            ->get(['id', 'code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->sameLocalityName($row->name, $localityName));

        if (! $city) {
            return null;
        }

        $shortDistrict = $this->toShortName($provinceName, $districtName);
        $district = PsgcAddress::updateOrCreate(
            ['code' => $this->districtCode($province->code, $shortDistrict)],
            [
                'parent_code' => $province->code,
                'level' => 'district',
                'name' => $shortDistrict,
                'short_name' => $shortDistrict,
                'type' => 'Legislative District',
                'district' => $shortDistrict,
                'source' => 'google sheet',
                'source_version' => 'Population 2024 district reference',
                'synced_at' => now(),
                'is_active' => true,
                'raw_payload' => compact('provinceName', 'localityName', 'districtName'),
            ]
        );

        $city->update(['district' => $shortDistrict, 'district_code' => $district->code]);

        return $district;
    }

    public function syncFromReferenceSheet(?string $sheetUrl = null): array
    {
        [$sheetId, $gid] = $this->extractSheetParts($sheetUrl ?: (string) config('services.google_sheets.district_reference_url'));
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        $response = Http::timeout(60)->get($csvUrl);

        if (! $response->successful()) {
            throw new RuntimeException("District reference sheet export failed with HTTP {$response->status()}.");
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->body());
        rewind($handle);

        $headers = fgetcsv($handle, 0, ',', '"', '\\') ?: [];
        $headerMap = $this->headerMap($headers);
        $required = ['lgu', 'province', 'district'];

        foreach ($required as $field) {
            if (! array_key_exists($field, $headerMap)) {
                throw new RuntimeException('District reference sheet must contain LGU, Province, and District columns.');
            }
        }

        $created = 0;
        $updated = 0;
        $assignedCities = 0;
        $skipped = 0;

        $provinceCodes = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->get(['code', 'name'])
            ->flatMap(fn (PsgcAddress $province): array => $this->provinceAliases($province->name)
                ->mapWithKeys(fn (string $alias): array => [$alias => $province])
                ->all());

        $cities = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->get(['id', 'code', 'parent_code', 'name']);

        while (($columns = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lgu = trim((string) ($columns[$headerMap['lgu']] ?? ''));
            $provinceName = trim((string) ($columns[$headerMap['province']] ?? ''));
            $districtName = trim((string) ($columns[$headerMap['district']] ?? ''));

            if ($lgu === '' || $provinceName === '' || $districtName === '' || $this->normalize($districtName) === 'na') {
                $skipped++;
                continue;
            }

            $province = $provinceCodes[$this->normalizeProvince($provinceName)] ?? null;

            if (! $province) {
                $skipped++;
                continue;
            }

            $shortDistrict = $this->toShortName($provinceName, $districtName);
            $district = PsgcAddress::updateOrCreate(
                ['code' => $this->districtCode($province->code, $shortDistrict)],
                [
                    'parent_code' => $province->code,
                    'level' => 'district',
                    'name' => $shortDistrict,
                    'short_name' => $shortDistrict,
                    'type' => 'Warehouse District',
                    'district' => $shortDistrict,
                    'source' => 'google sheet',
                    'source_version' => 'DSWD Caraga district reference',
                    'synced_at' => now(),
                    'is_active' => true,
                    'raw_payload' => [
                        'lgu' => $lgu,
                        'province' => $provinceName,
                        'district' => $districtName,
                        'short_district' => $shortDistrict,
                    ],
                ]
            );

            $district->wasRecentlyCreated ? $created++ : $updated++;

            $city = $cities
                ->where('parent_code', $province->code)
                ->first(fn (PsgcAddress $row): bool => $this->sameLocalityName($row->name, $lgu));

            if (! $city) {
                $skipped++;
                continue;
            }

            PsgcAddress::query()
                ->whereKey($city->id)
                ->update([
                    'district' => $shortDistrict,
                    'district_code' => $district->code,
                ]);

            $assignedCities++;
        }

        fclose($handle);

        $warehouseDistricts = $this->normalizeWarehouseDistricts();

        return [
            'created' => $created,
            'updated' => $updated,
            'assigned_cities' => $assignedCities,
            'skipped' => $skipped,
            'source' => $csvUrl,
            'warehouses_updated' => $warehouseDistricts['updated'],
        ];
    }

    public function syncFromWarehouses(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $assignedCities = 0;

        $this->normalizeWarehouseDistricts();

        $provinceCodes = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->get(['code', 'name'])
            ->keyBy(fn (PsgcAddress $province): string => $this->normalizeProvince($province->name));

        $districtRows = Warehouse::query()
            ->whereNotNull('province')
            ->whereNotNull('district')
            ->get(['province', 'district', 'municipality'])
            ->map(fn (Warehouse $warehouse): array => [
                'province' => trim((string) $warehouse->province),
                'district' => $this->resolveForLocality(
                    (string) $warehouse->province,
                    (string) $warehouse->district,
                    (string) $warehouse->municipality,
                ) ?: trim((string) $warehouse->district),
            ])
            ->filter(fn (array $row): bool => $row['province'] !== '' && $row['district'] !== '' && $row['district'] !== '-')
            ->unique(fn (array $row): string => $this->normalize($row['province']).'|'.$this->normalize($row['district']))
            ->values();

        foreach ($districtRows as $row) {
            $province = $provinceCodes[$this->normalizeProvince($row['province'])] ?? null;

            if (! $province) {
                $skipped++;
                continue;
            }

            $shortDistrict = $this->toShortName($row['province'], $row['district']);

            $district = PsgcAddress::updateOrCreate(
                ['code' => $this->districtCode($province->code, $shortDistrict)],
                [
                    'parent_code' => $province->code,
                    'level' => 'district',
                    'name' => $shortDistrict,
                    'short_name' => $shortDistrict,
                    'type' => 'Warehouse District',
                    'district' => $shortDistrict,
                    'source' => 'warehouse database',
                    'source_version' => 'Current warehouse district values',
                    'synced_at' => now(),
                    'is_active' => true,
                    'raw_payload' => $row,
                ]
            );

            $district->wasRecentlyCreated ? $created++ : $updated++;
        }

        $districts = PsgcAddress::query()
            ->where('level', 'district')
            ->where('is_active', true)
            ->get(['code', 'parent_code', 'name', 'short_name'])
            ->groupBy('parent_code');

        $cities = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->get(['id', 'code', 'parent_code', 'name']);

        Warehouse::query()
            ->whereNotNull('province')
            ->whereNotNull('district')
            ->whereNotNull('municipality')
            ->get(['province', 'district', 'municipality'])
            ->each(function (Warehouse $warehouse) use ($provinceCodes, $districts, $cities, &$assignedCities): void {
                $province = $provinceCodes[$this->normalizeProvince((string) $warehouse->province)] ?? null;

                if (! $province) {
                    return;
                }

                $district = ($districts[$province->code] ?? collect())
                    ->first(fn (PsgcAddress $row): bool => $this->same($row->name, $warehouse->district) || $this->same((string) $row->short_name, $warehouse->district));

                if (! $district) {
                    return;
                }

                $city = $cities
                    ->where('parent_code', $province->code)
                    ->first(fn (PsgcAddress $row): bool => $this->sameLocalityName($row->name, (string) $warehouse->municipality));

                if (! $city) {
                    return;
                }

                PsgcAddress::query()
                    ->whereKey($city->id)
                    ->update([
                        'district' => $district->short_name ?: $district->name,
                        'district_code' => $district->code,
                    ]);

                $assignedCities++;
            });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'assigned_cities' => $assignedCities,
        ];
    }

    public function districtCode(string $provinceCode, string $district): string
    {
        return 'D'.substr(md5($provinceCode.'|'.$this->normalize($district)), 0, 19);
    }

    /**
     * Resolve the managed short district label (ADS1, Lone ADN, …) for a warehouse locality.
     * Prefers the city/municipality assignment from psgc_addresses when available.
     */
    public function resolveForLocality(?string $province, ?string $district, ?string $municipality = null): ?string
    {
        $province = trim((string) $province);
        $district = trim((string) $district);
        $municipality = trim((string) $municipality);

        if ($province === '' && $district === '' && $municipality === '') {
            return null;
        }

        if ($province !== '' && $municipality !== '' && $municipality !== 'Unspecified') {
            $provinceRow = PsgcAddress::query()
                ->where('level', 'province')
                ->where('is_active', true)
                ->get(['code', 'name'])
                ->first(fn (PsgcAddress $row): bool => $this->normalizeProvince($row->name) === $this->normalizeProvince($province));

            if ($provinceRow) {
                $city = PsgcAddress::query()
                    ->where('level', 'city_municipality')
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->where('parent_code', $provinceRow->code)
                        ->when($provinceRow->code === '1600200000', fn ($query) => $query->orWhere('code', '1630400000')))
                    ->get(['district', 'district_code', 'name'])
                    ->first(fn (PsgcAddress $row): bool => $this->sameLocalityName($row->name, $municipality));

                if ($city) {
                    if (filled($city->district_code)) {
                        $managed = PsgcAddress::query()
                            ->where('level', 'district')
                            ->where('code', $city->district_code)
                            ->first(['name', 'short_name']);

                        if ($managed) {
                            return $managed->short_name ?: $managed->name;
                        }
                    }

                    if (filled($city->district)) {
                        return (string) $city->district;
                    }
                }
            }
        }

        if ($district === '' || $district === '-') {
            return $district === '-' ? '-' : null;
        }

        $short = $this->toShortName($province, $district);
        $managed = $this->managedDistrictNamesForProvince($province);

        if ($managed->isEmpty()) {
            return $short !== '' ? $short : null;
        }

        return $managed->first(
            fn (string $name): bool => $this->same($name, $short) || $this->same($name, $district)
        );
    }

    /**
     * Rewrite warehouses.district to the managed short labels used by PSGC district options.
     */
    public function normalizeWarehouseDistricts(): array
    {
        $updated = 0;
        $unchanged = 0;

        Warehouse::query()
            ->where(fn ($query) => $query->whereNotNull('district')->orWhereNotNull('municipality'))
            ->get(['id', 'province', 'district', 'municipality'])
            ->each(function (Warehouse $warehouse) use (&$updated, &$unchanged): void {
                $resolved = $this->resolveForLocality(
                    (string) $warehouse->province,
                    (string) $warehouse->district,
                    (string) $warehouse->municipality,
                );

                if ($resolved === null || $resolved === trim((string) $warehouse->district)) {
                    $unchanged++;

                    return;
                }

                $warehouse->update(['district' => $resolved]);
                $updated++;
            });

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }

    public function toShortName(string $province, string $district): string
    {
        $province = $this->normalizeProvince($province);
        $district = $this->normalize($district);

        if ($district === '' || $district === '-') {
            return $district === '-' ? '-' : '';
        }

        if ($province === 'agusandelnorte' && str_contains($district, 'butuan')) {
            return 'Lone Butuan City';
        }

        if ($province === 'agusandelnorte') {
            return 'Lone ADN';
        }

        if ($province === 'provinceofdinagatislands' || $province === 'dinagatislands') {
            return 'Lone PDI';
        }

        $prefix = match ($province) {
            'agusandelsur' => 'ADS',
            'surigaodelnorte' => 'SDN',
            'surigaodelsur' => 'SDS',
            default => '',
        };

        if ($prefix !== '') {
            if (preg_match('/district([0-9]+)/', $district, $match)) {
                return $prefix.$match[1];
            }

            if (preg_match('/([0-9]+)(?:st|nd|rd|th)/', $district, $match)) {
                return $prefix.$match[1];
            }

            if (preg_match('/^(?:ads|sdn|sds)([0-9]+)$/', $district, $match)) {
                return $prefix.$match[1];
            }
        }

        return (string) Str::of($district)->replaceMatches('/([a-z])([0-9])/', '$1 $2')->title();
    }

    private function managedDistrictNamesForProvince(string $province): \Illuminate\Support\Collection
    {
        $provinceRow = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->get(['code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->normalizeProvince($row->name) === $this->normalizeProvince($province));

        if (! $provinceRow) {
            return collect();
        }

        return PsgcAddress::query()
            ->where('level', 'district')
            ->where('is_active', true)
            ->where('parent_code', $provinceRow->code)
            ->get(['name', 'short_name'])
            ->map(fn (PsgcAddress $row): string => (string) ($row->short_name ?: $row->name))
            ->filter()
            ->unique()
            ->values();
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->trim();
    }

    private function normalizeProvince(string $value): string
    {
        return $this->normalize((string) Str::of($value)->replaceMatches('/^province\s+of\s+/i', ''));
    }

    private function provinceAliases(string $province): \Illuminate\Support\Collection
    {
        $normalized = $this->normalizeProvince($province);

        return collect([
            $normalized,
            $this->normalize('Province of '.$province),
        ])->unique()->values();
    }

    private function same(?string $left, ?string $right): bool
    {
        return $this->normalize((string) $left) !== '' && $this->normalize((string) $left) === $this->normalize((string) $right);
    }

    private function sameLocalityName(string $left, string $right): bool
    {
        $left = preg_replace('/\([^)]*\)/', '', $left) ?? $left;
        $right = preg_replace('/\([^)]*\)/', '', $right) ?? $right;

        $aliases = function (string $value): array {
            $normalized = $this->normalize($value);
            $variants = [
                $normalized,
                preg_replace('/^cityof/', '', $normalized) ?? $normalized,
                preg_replace('/city$/', '', $normalized) ?? $normalized,
                preg_replace('/^sta(?!nto)/', 'santa', $normalized) ?? $normalized,
                preg_replace('/^sto/', 'santo', $normalized) ?? $normalized,
                preg_replace('/^santa/', 'sta', $normalized) ?? $normalized,
                preg_replace('/^santo/', 'sto', $normalized) ?? $normalized,
            ];

            $expanded = [];
            foreach ($variants as $variant) {
                if (! is_string($variant) || $variant === '') {
                    continue;
                }
                $expanded[] = $variant;
                $expanded[] = preg_replace('/^cityof/', '', $variant) ?? $variant;
                $expanded[] = preg_replace('/city$/', '', $variant) ?? $variant;
            }

            return array_values(array_unique($expanded));
        };

        return count(array_intersect($aliases($left), $aliases($right))) > 0;
    }

    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize((string) $header);

            if ($normalized === 'lgu') {
                $map['lgu'] = $index;
            }

            if ($normalized === 'province') {
                $map['province'] = $index;
            }

            if ($normalized === 'district') {
                $map['district'] = $index;
            }
        }

        return $map;
    }

    private function extractSheetParts(string $sheetUrl): array
    {
        preg_match('/\/spreadsheets\/d\/([^\/]+)/', $sheetUrl, $sheetIdMatch);
        preg_match('/gid=([0-9]+)/', $sheetUrl, $gidMatch);

        if (! isset($sheetIdMatch[1])) {
            throw new RuntimeException('Invalid Google Sheet URL for district reference.');
        }

        return [$sheetIdMatch[1], $gidMatch[1] ?? '0'];
    }
}
