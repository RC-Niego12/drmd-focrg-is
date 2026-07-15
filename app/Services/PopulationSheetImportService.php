<?php

namespace App\Services;

use App\Models\BarangayPopulation;
use App\Models\PsgcAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PopulationSheetImportService
{
    public function import(?string $sheetUrl = null): array
    {
        $sheetUrl ??= (string) config('services.google_sheets.population_url');
        $csvUrl = $this->toCsvExportUrl($sheetUrl);
        try {
            $response = Http::timeout(60)->retry(2, 1000)->get($csvUrl);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Population sheet could not be accessed. Please confirm the Google Sheet is public or published as CSV.', previous: $exception);
        }

        if (! $response->successful()) {
            if (in_array($response->status(), [401, 403], true)) {
                throw new RuntimeException('Population sheet could not be accessed. Please confirm the Google Sheet is public or published as CSV.');
            }

            throw new RuntimeException("Population sheet import failed with HTTP {$response->status()}.");
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->body());
        rewind($handle);

        $headers = null;
        $summary = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unmatched' => [],
        ];
        $districtAssignments = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);
                continue;
            }

            $data = $this->combine($headers, $row);
            $psgcCode = $this->digits($this->value($data, ['barangay psgc code', 'psgc code', 'barangay code', 'code']));
            $provinceName = $this->value($data, ['province']);
            $cityName = $this->value($data, ['city / municipality', 'city municipality', 'city', 'municipality']);
            $barangayName = $this->value($data, ['barangay']);
            $population = $this->number($this->value($data, ['population', 'total population', 'pop']));
            $estimatedFamilies = $this->number($this->value($data, ['estimated no. of families', 'estimated no of families', 'families']));
            $year = $this->number($this->value($data, ['census year', 'year', 'population year'])) ?: 2024;

            if ($psgcCode === '') {
                $psgcCode = $this->resolveBarangayCode($provinceName, $cityName, $barangayName);
            }

            if ($psgcCode === '' || $population === null || ! PsgcAddress::query()->where('code', $psgcCode)->where('level', 'barangay')->exists()) {
                $summary['skipped']++;
                if (count($summary['unmatched']) < 20) {
                    $summary['unmatched'][] = array_filter([
                        'province' => $provinceName,
                        'city_municipality' => $cityName,
                        'barangay' => $barangayName,
                        'psgc_code' => $psgcCode,
                    ]);
                }
                continue;
            }

            $record = BarangayPopulation::updateOrCreate(
                [
                    'barangay_psgc_code' => $psgcCode,
                    'census_year' => $year,
                ],
                [
                    'population' => $population,
                    'estimated_families' => $estimatedFamilies,
                    'poor_families' => null,
                    'poor_individuals' => null,
                    'source' => $this->value($data, ['source']) ?: 'Population Google Sheet',
                    'last_updated' => now()->toDateString(),
                    'raw_payload' => $data,
                ]
            );

            $assignmentKey = $this->normalizeName("{$provinceName}|{$cityName}");
            if (! isset($districtAssignments[$assignmentKey])) {
                app(PsgcDistrictService::class)->syncAssignment(
                    (string) $provinceName,
                    (string) $cityName,
                    (string) ($this->value($data, ['leg. district', 'leg district', 'legislative district', 'district']) ?? '')
                );
                $districtAssignments[$assignmentKey] = true;
            }

            $summary[$record->wasRecentlyCreated ? 'imported' : 'updated']++;
        }

        fclose($handle);

        return $summary;
    }

    private function toCsvExportUrl(string $url): string
    {
        if (str_contains($url, '/export?')) {
            return $url;
        }

        preg_match('~/spreadsheets/d/([^/]+)~', $url, $sheetMatches);
        preg_match('~gid=([0-9]+)~', $url, $gidMatches);

        if (empty($sheetMatches[1])) {
            throw new RuntimeException('Population Google Sheet URL is invalid.');
        }

        $gid = $gidMatches[1] ?? '0';

        return "https://docs.google.com/spreadsheets/d/{$sheetMatches[1]}/export?format=csv&gid={$gid}";
    }

    private function combine(array $headers, array $row): array
    {
        $values = array_pad($row, count($headers), null);
        $data = [];

        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $data[$header] = $values[$index] ?? null;
            }
        }

        return $data;
    }

    private function value(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function resolveBarangayCode(?string $provinceName, ?string $cityName, ?string $barangayName): string
    {
        if (! $provinceName || ! $cityName || ! $barangayName) {
            return '';
        }

        $province = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->get(['code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->sameProvinceName($row->name, $provinceName));

        if (! $province) {
            return '';
        }

        $city = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->where(function ($query) use ($province): void {
                $query->where('parent_code', $province->code)
                    // Butuan is a highly urbanized city directly under Caraga in
                    // PSGC, but the population reference groups it with ADN.
                    ->when($province->code === '1600200000', fn ($query) => $query->orWhere('code', '1630400000'));
            })
            ->get(['code', 'name', 'parent_code'])
            ->first(fn (PsgcAddress $row): bool => $this->sameCityName($row->name, $cityName));

        if (! $city) {
            return '';
        }

        $knownNewBarangays = [
            '1606801000|guinhalinan' => '1606801027',
        ];
        $knownKey = "{$city->code}|{$this->normalizeName($barangayName)}";

        $this->ensureBarangaysLoaded($city->code);

        $barangay = PsgcAddress::query()
            ->where('level', 'barangay')
            ->where('is_active', true)
            ->where('parent_code', $city->code)
            ->get(['code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->sameName($row->name, $barangayName));

        if ($barangay) {
            return $barangay->code;
        }

        if (isset($knownNewBarangays[$knownKey])) {
            PsgcAddress::updateOrCreate(
                ['code' => $knownNewBarangays[$knownKey]],
                [
                    'parent_code' => $city->code,
                    'level' => 'barangay',
                    'name' => $barangayName,
                    'short_name' => null,
                    'type' => null,
                    'raw_payload' => [
                        'source_note' => 'Created from PSA CPH 2024 population sheet and PSA PSGC Barobo page.',
                    ],
                    'source' => 'psa.gov.ph',
                    'source_version' => 'PSGC as of 31 July 2025',
                    'synced_at' => now(),
                    'is_active' => true,
                ]
            );

            return $knownNewBarangays[$knownKey];
        }

        return '';
    }

    private function ensureBarangaysLoaded(string $cityCode): void
    {
        if (PsgcAddress::query()->where('level', 'barangay')->where('parent_code', $cityCode)->exists()) {
            return;
        }

        $baseUrl = rtrim((string) config('services.psgc.base_url', 'https://psgc.cloud/api'), '/');

        try {
            $response = Http::timeout(60)
                ->retry(3, 1000)
                ->acceptJson()
                ->get("{$baseUrl}/cities-municipalities/{$cityCode}/barangays");
        } catch (\Throwable) {
            return;
        }

        if (! $response->successful()) {
            return;
        }

        foreach ($response->json() ?? [] as $row) {
            $code = (string) ($row['code'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));

            if ($code === '' || $name === '') {
                continue;
            }

            PsgcAddress::updateOrCreate(
                ['code' => $code],
                [
                    'parent_code' => $cityCode,
                    'level' => 'barangay',
                    'name' => $name,
                    'short_name' => null,
                    'type' => $row['type'] ?? null,
                    'raw_payload' => $row,
                    'source' => 'psgc.cloud',
                    'source_version' => 'PSA PSGC latest public mirror',
                    'synced_at' => now(),
                    'is_active' => true,
                ]
            );
        }
    }

    private function sameCityName(string $left, string $right): bool
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);

        return count(array_intersect($this->localityAliases($left), $this->localityAliases($right))) > 0;
    }

    private function sameProvinceName(string $left, string $right): bool
    {
        $stripPrefix = fn (string $value): string => preg_replace('/^province of /', '', $this->normalizeName($value)) ?? '';

        return $stripPrefix($left) === $stripPrefix($right);
    }

    private function localityAliases(string $value): array
    {
        return array_values(array_unique([
            $value,
            preg_replace('/^city of /', '', $value) ?? $value,
            preg_replace('/ city$/', '', $value) ?? $value,
        ]));
    }

    private function sameName(string $left, string $right): bool
    {
        return $this->normalizeName($left) === $this->normalizeName($right);
    }

    private function normalizeName(string $value): string
    {
        $value = str_replace(['Ã±', 'Ã‘'], ['ñ', 'Ñ'], $value);
        $value = preg_replace('/\([^)]*\)/', '', $value) ?? $value;
        $value = Str::ascii($value);

        $value = Str::of($value)
            ->lower()
            ->replaceMatches('/\bsaint\b/', 'santo')
            ->replaceMatches('/\bsto\b/', 'santo')
            ->replace(['.', ',', '-', '_'], ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return $value;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(str_replace(['_', '-'], ' ', $header))));
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function number(?string $value): ?int
    {
        $number = preg_replace('/[^\d]/', '', (string) $value);

        return $number === '' ? null : (int) $number;
    }
}
