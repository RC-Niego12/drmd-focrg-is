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

        $shortDistrict = $this->shortDistrictName($provinceName, $districtName);
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

            $shortDistrict = $this->shortDistrictName($provinceName, $districtName);
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

        return [
            'created' => $created,
            'updated' => $updated,
            'assigned_cities' => $assignedCities,
            'skipped' => $skipped,
            'source' => $csvUrl,
        ];
    }

    public function syncFromWarehouses(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $assignedCities = 0;

        $provinceCodes = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->get(['code', 'name'])
            ->keyBy(fn (PsgcAddress $province): string => $this->normalize($province->name));

        $districtRows = Warehouse::query()
            ->whereNotNull('province')
            ->whereNotNull('district')
            ->get(['province', 'district'])
            ->map(fn (Warehouse $warehouse): array => [
                'province' => trim((string) $warehouse->province),
                'district' => trim((string) $warehouse->district),
            ])
            ->filter(fn (array $row): bool => $row['province'] !== '' && $row['district'] !== '' && $row['district'] !== '-')
            ->unique(fn (array $row): string => $this->normalize($row['province']).'|'.$this->normalize($row['district']))
            ->values();

        foreach ($districtRows as $row) {
            $province = $provinceCodes[$this->normalize($row['province'])] ?? null;

            if (! $province) {
                $skipped++;
                continue;
            }

            $district = PsgcAddress::updateOrCreate(
                ['code' => $this->districtCode($province->code, $row['district'])],
                [
                    'parent_code' => $province->code,
                    'level' => 'district',
                    'name' => $row['district'],
                    'short_name' => $row['district'],
                    'type' => 'Warehouse District',
                    'district' => $row['district'],
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
                $province = $provinceCodes[$this->normalize((string) $warehouse->province)] ?? null;

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

    private function shortDistrictName(string $province, string $district): string
    {
        $province = $this->normalizeProvince($province);
        $district = $this->normalize($district);

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

        if ($prefix !== '' && preg_match('/district([0-9]+)/', $district, $match)) {
            return $prefix.$match[1];
        }

        return (string) Str::of($district)->replaceMatches('/([a-z])([0-9])/', '$1 $2')->title();
    }

    private function same(?string $left, ?string $right): bool
    {
        return $this->normalize((string) $left) !== '' && $this->normalize((string) $left) === $this->normalize((string) $right);
    }

    private function sameLocalityName(string $left, string $right): bool
    {
        $left = preg_replace('/\([^)]*\)/', '', $left) ?? $left;
        $right = preg_replace('/\([^)]*\)/', '', $right) ?? $right;
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        if ($left === $right) {
            return true;
        }

        $aliases = fn (string $value): array => array_unique([
            $value,
            preg_replace('/^cityof/', '', $value),
            preg_replace('/city$/', '', $value),
        ]);

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
