<?php

namespace App\Services;

use App\Models\BarangayPopulation;
use App\Models\PsgcAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PsgcPopulationAreaCrossmatchService
{
    public function __construct(private PsgcDistrictService $districts) {}

    /**
     * Compare managed PSGC city/district assignments against population sheet area labels.
     *
     * @param  Collection<int, PsgcAddress>  $provinces
     * @param  Collection<int, PsgcAddress>  $cities
     */
    public function build(Collection $provinces, Collection $cities): array
    {
        $provinceCodes = $provinces->pluck('code')->all();
        $provinceNames = $provinces->pluck('name', 'code');
        $cityMap = $cities->keyBy('code');

        $barangays = PsgcAddress::query()
            ->where('level', 'barangay')
            ->where('is_active', true)
            ->whereIn('parent_code', $cityMap->keys())
            ->get(['code', 'parent_code'])
            ->keyBy('code');

        $byCity = [];

        BarangayPopulation::query()
            ->get(['barangay_psgc_code', 'population', 'raw_payload'])
            ->each(function (BarangayPopulation $row) use (&$byCity, $barangays, $cityMap): void {
                $barangay = $barangays->get($row->barangay_psgc_code);
                if (! $barangay) {
                    return;
                }

                $city = $cityMap->get($barangay->parent_code);
                if (! $city) {
                    return;
                }

                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
                $sheetDistrict = $this->payloadValue($payload, [
                    'leg. district',
                    'leg district',
                    'legislative district',
                    'district',
                ]);
                $sheetProvince = $this->payloadValue($payload, ['province']);
                $sheetCity = $this->payloadValue($payload, [
                    'city / municipality',
                    'city municipality',
                    'city',
                    'municipality',
                ]);

                $bucket = $byCity[$city->code] ??= [
                    'population' => 0.0,
                    'barangay_count' => 0,
                    'sheet_districts' => [],
                    'sheet_provinces' => [],
                    'sheet_cities' => [],
                ];

                $bucket['population'] += (float) $row->population;
                $bucket['barangay_count']++;

                if (filled($sheetDistrict)) {
                    $bucket['sheet_districts'][$sheetDistrict] = ($bucket['sheet_districts'][$sheetDistrict] ?? 0) + 1;
                }
                if (filled($sheetProvince)) {
                    $bucket['sheet_provinces'][$sheetProvince] = ($bucket['sheet_provinces'][$sheetProvince] ?? 0) + 1;
                }
                if (filled($sheetCity)) {
                    $bucket['sheet_cities'][$sheetCity] = ($bucket['sheet_cities'][$sheetCity] ?? 0) + 1;
                }

                $byCity[$city->code] = $bucket;
            });

        $summary = [
            'cities_checked' => $cities->count(),
            'cities_with_population' => 0,
            'match' => 0,
            'mismatch' => 0,
            'psgc_missing_district' => 0,
            'no_sheet_district' => 0,
            'no_population' => 0,
        ];

        $cityRows = [];

        foreach ($cities as $city) {
            $provinceCode = $city->code === '1630400000' ? '1600200000' : $city->parent_code;
            if (! in_array($provinceCode, $provinceCodes, true) && $city->code !== '1630400000') {
                continue;
            }

            $bucket = $byCity[$city->code] ?? null;
            $provinceName = (string) ($provinceNames[$provinceCode] ?? '');
            $psgcDistrict = trim((string) ($city->district ?: ''));

            if (! $bucket) {
                $summary['no_population']++;
                $cityRows[$city->code] = [
                    'code' => $city->code,
                    'name' => $city->name,
                    'province_code' => $provinceCode,
                    'province_name' => $provinceName,
                    'psgc_district' => $psgcDistrict ?: null,
                    'sheet_district' => null,
                    'expected_short' => null,
                    'sheet_city' => null,
                    'sheet_province' => null,
                    'population' => 0,
                    'barangay_count' => 0,
                    'status' => 'no_population',
                ];
                continue;
            }

            $summary['cities_with_population']++;
            arsort($bucket['sheet_districts']);
            arsort($bucket['sheet_provinces']);
            arsort($bucket['sheet_cities']);

            $sheetDistrict = array_key_first($bucket['sheet_districts']);
            $sheetProvince = array_key_first($bucket['sheet_provinces']);
            $sheetCity = array_key_first($bucket['sheet_cities']);
            $expected = filled($sheetDistrict)
                ? $this->districts->toShortName($provinceName !== '' ? $provinceName : (string) $sheetProvince, (string) $sheetDistrict)
                : null;

            if (! filled($sheetDistrict)) {
                $status = 'no_sheet_district';
                $summary['no_sheet_district']++;
            } elseif ($psgcDistrict === '') {
                $status = 'psgc_missing_district';
                $summary['psgc_missing_district']++;
            } elseif ($this->sameLabel($psgcDistrict, $expected)) {
                $status = 'match';
                $summary['match']++;
            } else {
                $status = 'mismatch';
                $summary['mismatch']++;
            }

            $cityRows[$city->code] = [
                'code' => $city->code,
                'name' => $city->name,
                'province_code' => $provinceCode,
                'province_name' => $provinceName,
                'psgc_district' => $psgcDistrict ?: null,
                'sheet_district' => $sheetDistrict,
                'expected_short' => $expected,
                'sheet_city' => $sheetCity,
                'sheet_province' => $sheetProvince,
                'population' => (int) round($bucket['population']),
                'barangay_count' => (int) $bucket['barangay_count'],
                'status' => $status,
            ];
        }

        $summary['aligned'] = ($summary['mismatch'] + $summary['psgc_missing_district'] + $summary['no_sheet_district']) === 0
            && $summary['cities_with_population'] > 0;

        $issues = collect($cityRows)
            ->filter(fn (array $row): bool => ! in_array($row['status'], ['match'], true))
            ->sortBy([
                fn (array $row) => match ($row['status']) {
                    'mismatch' => 0,
                    'psgc_missing_district' => 1,
                    'no_sheet_district' => 2,
                    default => 3,
                },
                fn (array $row) => $row['province_name'].'|'.$row['name'],
            ])
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'cities' => $cityRows,
            'issues' => $issues,
        ];
    }

    private function payloadValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && filled($payload[$key])) {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function sameLabel(?string $left, ?string $right): bool
    {
        $normalize = fn (?string $value): string => Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();

        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }
}
