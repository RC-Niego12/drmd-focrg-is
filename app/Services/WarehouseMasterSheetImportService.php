<?php

namespace App\Services;

use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseMasterSheetImportService
{
    private array $columnIndexes = [];

    public function __construct(private GoogleSheetCsvService $sheetCsv) {}

    public function import(string $sheetUrl, ?string $worksheetName = null): array
    {
        [$sheetId, $gid] = $this->extractSheetParts($sheetUrl);
        try {
            $csv = $this->sheetCsv->fetch($sheetId, $gid);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Could not connect to the warehouse master Google Sheet. Please check the internet connection or DNS settings, then try syncing again.', previous: $exception);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        $this->columnIndexes = $this->detectColumnIndexes($headers ?: []);

        $summary = [
            'sheet_id' => $sheetId,
            'gid' => $gid,
            'worksheet_name' => $worksheetName,
            'rows_seen' => 0,
            'warehouses_synced' => 0,
            'rows_skipped' => 0,
        ];

        while (($columns = fgetcsv($handle)) !== false) {
            $summary['rows_seen']++;

            $warehouseId = $this->text($columns, 0);
            $province = $this->text($columns, 4) ?: 'CARAGA';
            $warehouseName = $this->warehouseName($columns, $province);

            if (! Str::startsWith($warehouseId, 'PH') || $warehouseName === '') {
                $summary['rows_skipped']++;

                continue;
            }

            Warehouse::updateOrCreate(
                ['external_warehouse_id' => $warehouseId],
                $this->warehouseAttributes($columns)
            );

            $summary['warehouses_synced']++;
        }

        fclose($handle);

        return $summary;
    }

    private function warehouseAttributes(array $columns): array
    {
        $ffpCapacity = $this->number($columns, 19);
        $rtefCapacity = $this->number($columns, 76);

        $province = $this->text($columns, 4) ?: 'CARAGA';

        return [
            'name' => $this->warehouseName($columns, $province),
            'warehouse_number' => $this->text($columns, 2),
            'office' => $this->text($columns, 3),
            'province' => $province,
            'municipality' => $this->text($columns, 5) ?: 'Unspecified',
            'district' => $this->text($columns, 71) ?: $this->text($columns, 6),
            'barangay_name' => $this->text($columns, 7),
            'barangay_code' => $this->text($columns, 8),
            'contact_person' => $this->text($columns, 10),
            'contact_number' => $this->normalizeContactNumber($this->text($columns, 11)),
            'email' => $this->email($this->text($columns, 149) ?: $this->text($columns, 12)),
            'distribution_network' => $this->text($columns, 13),
            'warehouse_type' => $this->text($columns, 154) ?: $this->text($columns, 15),
            'category' => $this->text($columns, 16),
            'ownership' => $this->text($columns, 17),
            'partnership' => $this->text($columns, 18),
            'capacity' => (int) round($ffpCapacity),
            'rtef_capacity' => $rtefCapacity,
            'ffp_capacity' => $ffpCapacity,
            'sheet_ffp_current' => $this->number($columns, 20),
            'sheet_ffp_cost' => $this->number($columns, 24),
            'sheet_total_items' => $this->number($columns, 45),
            'sheet_total_cost' => $this->number($columns, 46),
            'longitude' => $this->number($columns, 56) ?: null,
            'latitude' => $this->number($columns, 57) ?: null,
            'status' => $this->status($this->text($columns, 58)),
            'rpa_start_date' => $this->date($this->text($columns, 59)),
            'rpa_end_date' => $this->date($this->text($columns, 60)),
            'validity' => $this->text($columns, 61),
            'designated_storekeepers' => $this->text($columns, 72),
            'storekeeper_contact_number' => $this->normalizeContactNumber($this->text($columns, 73)),
            'sheet_payload' => [
                'rtef_current' => $this->number($columns, 74),
                'rtef_cost' => $this->number($columns, 75),
                'rtef_capacity' => $this->number($columns, 76),
                'raw_materials_current' => $this->number($columns, 50),
                'raw_materials_cost' => $this->number($columns, 52),
                'other_nfis_current' => $this->number($columns, 54),
                'other_nfis_cost' => $this->number($columns, 55),
                'mswdo_username' => $this->text($columns, 148),
                'zip_code' => $this->text($columns, 149),
                'focal_email' => $this->text($columns, 149),
                'sheet_warehouse_type' => $this->text($columns, 15),
                'actual_warehouse_type' => $this->text($columns, 154),
            ],
            'master_synced_at' => now(),
        ];
    }

    private function text(array $columns, int $index): string
    {
        $value = trim((string) ($columns[$index] ?? ''));

        return in_array(strtolower($value), ['n/a', 'na', '-'], true) ? '' : $value;
    }

    private function number(array $columns, int $index): float
    {
        return (float) str_replace([',', '%', ' '], '', $this->text($columns, $index));
    }

    private function email(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function status(string $value): string
    {
        return strtolower($value) === 'inactive' ? 'inactive' : 'active';
    }

    private function normalizeContactNumber(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return Str::startsWith($value, '0') || Str::startsWith($value, '+63') ? $value : '0'.$value;
    }

    private function detectColumnIndexes(array $headers): array
    {
        $indexes = [];

        foreach ($headers as $index => $header) {
            $key = strtolower(trim((string) $header));

            if (preg_match('/wh\s*name\s*2|warehouse\s*name\s*2/', $key)) {
                $indexes['warehouse_name2'] = $index;
            }

            if ($key === 'warehouse name' && ! isset($indexes['warehouse_name'])) {
                $indexes['warehouse_name'] = $index;
            }
        }

        return $indexes;
    }

    private function warehouseName(array $columns, string $province): string
    {
        $name = '';

        if (isset($this->columnIndexes['warehouse_name2'])) {
            $name = $this->text($columns, $this->columnIndexes['warehouse_name2']);
        }

        if ($name === '') {
            $name = $this->text($columns, $this->columnIndexes['warehouse_name'] ?? 1);
        }

        return $this->normalizeWarehouseName($name, $province);
    }

    private function normalizeWarehouseName(string $name, string $province): string
    {
        $name = trim($name);
        $province = trim($province);

        if ($name === '') {
            return $province;
        }

        $lowerName = strtolower($name);
        $prefix = strtolower($province).',';

        if ($province !== '' && str_starts_with($lowerName, $prefix)) {
            return preg_replace('/\s*,\s*/', ', ', $name);
        }

        return $province !== '' ? "{$province}, {$name}" : $name;
    }

    private function extractSheetParts(string $sheetUrl): array
    {
        preg_match('/\/spreadsheets\/d\/([^\/]+)/', $sheetUrl, $sheetIdMatch);
        preg_match('/gid=([0-9]+)/', $sheetUrl, $gidMatch);

        if (! isset($sheetIdMatch[1])) {
            throw new RuntimeException('Invalid Google Sheet URL.');
        }

        return [$sheetIdMatch[1], $gidMatch[1] ?? '0'];
    }
}
