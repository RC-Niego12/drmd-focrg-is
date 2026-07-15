<?php

namespace App\Services;

use App\Models\PsgcAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class PsgcSyncService
{
    private array $regionShortNames = [
        '0100000000' => 'REGION I',
        '0200000000' => 'REGION II',
        '0300000000' => 'REGION III',
        '0400000000' => 'CALABARZON',
        '0500000000' => 'REGION V',
        '0600000000' => 'REGION VI',
        '0700000000' => 'REGION VII',
        '0800000000' => 'REGION VIII',
        '0900000000' => 'REGION IX',
        '1000000000' => 'REGION X',
        '1100000000' => 'REGION XI',
        '1200000000' => 'REGION XII',
        '1300000000' => 'NCR',
        '1400000000' => 'CAR',
        '1600000000' => 'CARAGA',
        '1700000000' => 'MIMAROPA',
        '1800000000' => 'NIR',
        '1900000000' => 'BARMM',
    ];

    public function sync(bool $includeBarangays = true): array
    {
        set_time_limit(0);

        $batch = (string) Str::uuid();
        $syncedAt = now();

        if (! (bool) config('services.psgc.allow_api_fallback', false)) {
            return $this->syncFromPsaPublication($includeBarangays, $batch, $syncedAt);
        }

        try {
            return $this->syncFromPsaPublication($includeBarangays, $batch, $syncedAt);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->syncFromApiMirror($includeBarangays, $batch, $syncedAt);
        }
    }

    public function syncUploadedPublication(string $filePath, bool $includeBarangays = true): array
    {
        set_time_limit(0);

        return $this->syncFromPsaPublication($includeBarangays, (string) Str::uuid(), now(), $filePath);
    }

    private function syncFromPsaPublication(bool $includeBarangays, string $batch, $syncedAt, ?string $filePath = null): array
    {
        $rows = $this->readPsaPublicationRows($filePath);

        if ($rows->isEmpty()) {
            throw new RuntimeException('The PSA PSGC publication file did not contain readable address rows.');
        }

        $summary = [
            'batch' => $batch,
            'regions' => 0,
            'provinces' => 0,
            'cities_municipalities' => 0,
            'barangays' => 0,
            'include_barangays' => $includeBarangays,
            'source' => 'psa.gov.ph',
            'source_version' => (string) config('services.psgc.publication_name'),
        ];

        $knownCodes = $rows->pluck('code')->flip();

        foreach ($rows as $row) {
            if (! $includeBarangays && $row['level'] === 'barangay') {
                continue;
            }

            $parentCode = $this->deriveParentCode($row['code'], $row['level'], $knownCodes);
            $this->upsertAddress($row, $row['level'], $parentCode, $batch, $syncedAt, 'psa.gov.ph', $summary['source_version']);
            $summary[$this->summaryKey($row['level'])]++;
        }

        $this->deactivateOldRows($batch);

        return $summary;
    }

    private function syncFromApiMirror(bool $includeBarangays, string $batch, $syncedAt): array
    {
        $summary = [
            'batch' => $batch,
            'regions' => 0,
            'provinces' => 0,
            'cities_municipalities' => 0,
            'barangays' => 0,
            'include_barangays' => $includeBarangays,
            'source' => 'psgc.cloud',
            'source_version' => 'PSA PSGC latest public mirror',
        ];

        $regions = $this->fetch('regions');

        foreach ($regions as $region) {
            $this->upsertAddress($region, 'region', null, $batch, $syncedAt, $summary['source'], $summary['source_version']);
            $summary['regions']++;

            $provinces = $this->fetch("regions/{$region['code']}/provinces");

            foreach ($provinces as $province) {
                $this->upsertAddress($province, 'province', $region['code'], $batch, $syncedAt, $summary['source'], $summary['source_version']);
                $summary['provinces']++;

                $citiesMunicipalities = $this->fetch("provinces/{$province['code']}/cities-municipalities");

                foreach ($citiesMunicipalities as $cityMunicipality) {
                    $this->upsertAddress($cityMunicipality, 'city_municipality', $province['code'], $batch, $syncedAt, $summary['source'], $summary['source_version']);
                    $summary['cities_municipalities']++;

                    if (! $includeBarangays) {
                        continue;
                    }

                    foreach ($this->fetch("cities-municipalities/{$cityMunicipality['code']}/barangays") as $barangay) {
                        $this->upsertAddress($barangay, 'barangay', $cityMunicipality['code'], $batch, $syncedAt, $summary['source'], $summary['source_version']);
                        $summary['barangays']++;
                    }
                }
            }
        }

        $this->deactivateOldRows($batch);

        return $summary;
    }

    private function readPsaPublicationRows(?string $filePath = null): Collection
    {
        $filePath ??= $this->downloadPublicationFile();
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        $headerRow = null;
        $headers = [];

        for ($row = 1; $row <= min($highestRow, 20); $row++) {
            $values = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $values);

            if ($this->findHeaderIndex($normalized, ['psgc code', '10-digit psgc', 'code']) !== null
                && $this->findHeaderIndex($normalized, ['name', 'geographic name', 'correspondence code/name']) !== null) {
                $headerRow = $row;
                $headers = $normalized;
                break;
            }
        }

        if (! $headerRow) {
            throw new RuntimeException('Could not find the PSGC header row in the PSA publication file.');
        }

        $codeIndex = $this->findHeaderIndex($headers, ['psgc code', '10-digit psgc', 'code']);
        $nameIndex = $this->findHeaderIndex($headers, ['name', 'geographic name', 'correspondence code/name']);
        $levelIndex = $this->findHeaderIndex($headers, ['geographic level', 'level']);
        $typeIndex = $this->findHeaderIndex($headers, ['city class', 'urban/rural', 'type']);

        $rows = collect();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $code = preg_replace('/\D/', '', (string) ($values[$codeIndex] ?? ''));
            $name = trim((string) ($values[$nameIndex] ?? ''));

            if ($code === '' || $name === '' || strtoupper($name) === 'TOTAL') {
                continue;
            }

            $code = str_pad($code, 10, '0', STR_PAD_LEFT);
            $level = $this->normalizeLevel((string) ($values[$levelIndex] ?? ''), $code);

            if (! $level) {
                continue;
            }

            $rows->push([
                'code' => $code,
                'name' => $name,
                'level' => $level,
                'type' => isset($values[$typeIndex]) ? trim((string) $values[$typeIndex]) : null,
                'raw_payload' => array_combine($headers, array_slice(array_pad($values, count($headers), null), 0, count($headers))),
            ]);
        }

        return $rows
            ->unique('code')
            ->sortBy(fn (array $row): string => "{$this->levelSort($row['level'])}-{$row['code']}")
            ->values();
    }

    private function downloadPublicationFile(): string
    {
        $url = (string) config('services.psgc.publication_url');

        if ($url === '') {
            throw new RuntimeException('PSGC_PUBLICATION_URL is not configured.');
        }

        $path = storage_path('app/psgc/publication.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $response = Http::timeout(180)
            ->retry(2, 1000)
            ->withHeaders(['User-Agent' => 'DRMD-FOCRG-Integrated-System/1.0'])
            ->get($url);

        if (! $response->successful() || strlen($response->body()) < 1000) {
            throw new RuntimeException("PSA PSGC publication download failed with HTTP {$response->status()}.");
        }

        file_put_contents($path, $response->body());

        return $path;
    }

    private function fetch(string $path): array
    {
        $baseUrl = rtrim((string) config('services.psgc.base_url', 'https://psgc.cloud/api'), '/');
        $response = Http::timeout(60)->retry(2, 500)->acceptJson()->get("{$baseUrl}/{$path}");

        if (! $response->successful()) {
            throw new RuntimeException("PSGC sync failed for {$path} with HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    private function upsertAddress(array $row, string $level, ?string $parentCode, string $batch, $syncedAt, string $source, string $sourceVersion): void
    {
        $code = (string) ($row['code'] ?? '');
        $name = trim((string) ($row['name'] ?? ''));

        if ($code === '' || $name === '') {
            return;
        }

        PsgcAddress::updateOrCreate(
            ['code' => $code],
            [
                'parent_code' => $parentCode,
                'level' => $level,
                'name' => $name,
                'short_name' => $level === 'region' ? ($this->regionShortNames[$code] ?? strtoupper($name)) : null,
                'type' => $row['type'] ?? null,
                'district' => $row['district'] ?? null,
                'zip_code' => $row['zip_code'] ?? null,
                'raw_payload' => $row['raw_payload'] ?? $row,
                'source' => $source,
                'source_version' => $sourceVersion,
                'sync_batch' => $batch,
                'synced_at' => $syncedAt,
                'is_active' => true,
            ]
        );
    }

    private function deactivateOldRows(string $batch): void
    {
        PsgcAddress::query()
            ->where('sync_batch', '!=', $batch)
            ->update(['is_active' => false]);
    }

    private function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($header)));
    }

    private function findHeaderIndex(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            foreach ($needles as $needle) {
                if ($header === $needle || str_contains($header, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function normalizeLevel(string $level, string $code): ?string
    {
        $value = strtolower($level);

        return match (true) {
            str_contains($value, 'region') => 'region',
            str_contains($value, 'prov') => 'province',
            str_contains($value, 'city'), str_contains($value, 'mun') => 'city_municipality',
            str_contains($value, 'barangay'), str_contains($value, 'bgy') => 'barangay',
            substr($code, 2) === '00000000' => 'region',
            substr($code, 4) === '000000' => 'province',
            substr($code, 6) === '0000' => 'city_municipality',
            default => 'barangay',
        };
    }

    private function deriveParentCode(string $code, string $level, Collection $knownCodes): ?string
    {
        if ($level === 'region') {
            return null;
        }

        $candidates = match ($level) {
            'province' => [substr($code, 0, 2).'00000000'],
            'city_municipality' => [substr($code, 0, 4).'000000', substr($code, 0, 2).'00000000'],
            'barangay' => [substr($code, 0, 6).'0000', substr($code, 0, 4).'000000', substr($code, 0, 2).'00000000'],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if ($knownCodes->has($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    private function summaryKey(string $level): string
    {
        return match ($level) {
            'city_municipality' => 'cities_municipalities',
            default => $level.'s',
        };
    }

    private function levelSort(string $level): int
    {
        return match ($level) {
            'region' => 1,
            'province' => 2,
            'city_municipality' => 3,
            'barangay' => 4,
            default => 9,
        };
    }
}
