<?php

namespace App\Services;

use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseMasterSheetImportService
{
    private array $columnIndexes = [];

    public function __construct(
        private GoogleSheetCsvService $sheetCsv,
        private PsgcDistrictService $districts,
        private LguWarehousePersonnelSyncService $lguWarehousePersonnel,
    ) {}

    public function import(string $sheetUrl, ?string $worksheetName = null): array
    {
        [$sheetId, $gid] = $this->extractSheetParts($sheetUrl);
        try {
            $csv = filled($worksheetName)
                ? $this->sheetCsv->fetchWorksheet($sheetId, $worksheetName)
                : $this->sheetCsv->fetch($sheetId, $gid);
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
            'storekeepers_updated' => 0,
            'storekeepers_worksheet' => null,
        ];

        while (($columns = fgetcsv($handle)) !== false) {
            $summary['rows_seen']++;

            $warehouseId = $this->columnText($columns, ['warehouse id'], 0);
            $province = $this->columnText($columns, ['province'], 4) ?: 'CARAGA';
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

        if ($summary['warehouses_synced'] === 0) {
            throw new RuntimeException("The '{$worksheetName}' worksheet did not contain any valid warehouse records. No warehouse master data was synchronized.");
        }

        $storekeepersWorksheet = (string) config(
            'services.google_sheets.warehouse_storekeepers_worksheet',
            'Managed Warehouses 2'
        );

        if (filled($storekeepersWorksheet) && $storekeepersWorksheet !== (string) $worksheetName) {
            $storekeeperSummary = $this->syncStorekeepersFromWorksheet($sheetUrl, $storekeepersWorksheet);
            $summary['storekeepers_worksheet'] = $storekeepersWorksheet;
            $summary['storekeepers_updated'] = $storekeeperSummary['storekeepers_updated'];
            $summary['storekeepers_rows_seen'] = $storekeeperSummary['rows_seen'];
            $summary['storekeepers_rows_skipped'] = $storekeeperSummary['rows_skipped'];
        }

        $lguPersonnelSummary = $this->lguWarehousePersonnel->syncFromWarehouses();
        $summary['lgu_profiles_updated'] = $lguPersonnelSummary['lgus_updated'];
        $summary['lgu_focals_synced'] = $lguPersonnelSummary['focals_synced'];
        $summary['lgu_storekeepers_synced'] = $lguPersonnelSummary['storekeepers_synced'];
        $summary['lgu_warehouses_matched'] = $lguPersonnelSummary['warehouses_matched'];

        return $summary;
    }

    /**
     * Overlay designated storekeeper name/contact from the companion WIT tab.
     *
     * @return array{rows_seen:int,rows_skipped:int,storekeepers_updated:int,rtef_capacities_updated:int,worksheet_name:string}
     */
    public function syncStorekeepersFromWorksheet(string $sheetUrl, string $worksheetName): array
    {
        [$sheetId] = $this->extractSheetParts($sheetUrl);

        try {
            $csv = $this->sheetCsv->fetchWorksheet($sheetId, $worksheetName);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "Could not read storekeeper data from the '{$worksheetName}' worksheet. Check the tab name and sheet access, then sync again.",
                previous: $exception
            );
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        $this->columnIndexes = $this->detectColumnIndexes($headers ?: []);

        $summary = [
            'worksheet_name' => $worksheetName,
            'rows_seen' => 0,
            'rows_skipped' => 0,
            'storekeepers_updated' => 0,
            'rtef_capacities_updated' => 0,
        ];

        while (($columns = fgetcsv($handle)) !== false) {
            $summary['rows_seen']++;

            $warehouseId = $this->columnText($columns, ['warehouse id'], 0);
            if (! Str::startsWith($warehouseId, 'PH')) {
                $summary['rows_skipped']++;

                continue;
            }

            $warehouse = Warehouse::query()->where('external_warehouse_id', $warehouseId)->first();
            if (! $warehouse) {
                $summary['rows_skipped']++;

                continue;
            }

            $storekeepers = $this->columnText($columns, [
                'designated storekeepers',
                'designated storekeeper',
                'storekeepers',
                'storekeeper',
            ], 72);
            $contact = $this->normalizeContactNumber($this->columnText($columns, [
                'contactnum_storekeepers',
                'contactnum storekeepers',
                'contact num storekeepers',
                'contact number storekeepers',
                'storekeeper contact',
                'storekeepers contact',
            ], 73));
            $rtefCapacity = $this->columnNumber($columns, [
                'rtef capacity',
                'rtef full capacity',
                'ready to eat capacity',
                'ready to eat food capacity',
                'ready to eat foods capacity',
            ], 76);
            $sheetPayload = is_array($warehouse->sheet_payload) ? $warehouse->sheet_payload : [];
            $updates = [
                'designated_storekeepers' => $storekeepers !== '' ? $storekeepers : null,
                'storekeeper_contact_number' => $contact,
            ];
            if (array_key_exists('rtef capacity', $this->columnIndexes)) {
                // Managed Warehouses 2 is the authoritative WIT source for
                // RTEF_CAPACITY (column BY). Overlay it after the base tab when
                // that companion worksheet actually exposes the field.
                $updates['rtef_capacity'] = $rtefCapacity;
                $updates['sheet_payload'] = [...$sheetPayload, 'rtef_capacity' => $rtefCapacity];
                $summary['rtef_capacities_updated']++;
            }

            $warehouse->forceFill($updates)->save();

            $summary['storekeepers_updated']++;
        }

        fclose($handle);

        return $summary;
    }

    private function warehouseAttributes(array $columns): array
    {
        $ffpCapacity = $this->columnNumber($columns, ['ffps capacity'], 19);
        $rtefCapacity = $this->columnNumber($columns, [
            'rtef capacity',
            'rtef full capacity',
            'ready to eat capacity',
            'ready to eat food capacity',
            'ready to eat foods capacity',
        ], 76);

        $province = $this->columnText($columns, ['province'], 4) ?: 'CARAGA';
        $municipality = $this->columnText($columns, ['municipality'], 5) ?: 'Unspecified';
        $district = $this->columnText($columns, ['district'], 6);

        return [
            'name' => $this->warehouseName($columns, $province),
            'warehouse_number' => $this->columnText($columns, ['warehouse number'], 2),
            'office' => $this->columnText($columns, ['office'], 3),
            'province' => $province,
            'municipality' => $municipality,
            'district' => $this->districts->resolveForLocality($province, $district, $municipality) ?? $district,
            'barangay_name' => $this->columnText($columns, ['barangay name'], 7),
            'barangay_code' => $this->columnText($columns, ['barangay code'], 8),
            'contact_person' => $this->columnText($columns, ['focal'], 10),
            'contact_number' => $this->normalizeContactNumber($this->columnText($columns, ['contact number'], 11)),
            'email' => $this->email($this->columnText($columns, ['wh focal email', 'email'], 12)),
            'distribution_network' => $this->columnText($columns, ['distribution network'], 13),
            'warehouse_type' => $this->columnText($columns, ['actual wh type', 'warehouse type'], 15),
            'category' => $this->columnText($columns, ['category'], 16),
            'ownership' => $this->columnText($columns, ['ownership'], 17),
            'partnership' => $this->columnText($columns, ['partnership'], 18),
            'capacity' => (int) round($ffpCapacity),
            'rtef_capacity' => $rtefCapacity,
            'ffp_capacity' => $ffpCapacity,
            'sheet_ffp_current' => $this->columnNumber($columns, ['ffps current'], 20),
            'sheet_ffp_cost' => $this->columnNumber($columns, ['ffps cost'], 24),
            'sheet_total_items' => $this->columnNumber($columns, ['total'], 45),
            'sheet_total_cost' => $this->columnNumber($columns, ['total cost'], 46),
            'longitude' => $this->columnNumber($columns, ['longitude (x)', 'longitude'], 56) ?: null,
            'latitude' => $this->columnNumber($columns, ['latitude (y)', 'latitude'], 57) ?: null,
            'status' => $this->status($this->columnText($columns, ['status'], 58)),
            'rpa_start_date' => $this->date($this->columnText($columns, ['rpa start date'], 59)),
            'rpa_end_date' => $this->date($this->columnText($columns, ['rpa end date'], 60)),
            'validity' => $this->columnText($columns, ['validity'], 61),
            'designated_storekeepers' => $this->columnText($columns, [
                'designated storekeepers',
                'designated storekeeper',
                'storekeepers',
                'storekeeper',
            ], 72),
            'storekeeper_contact_number' => $this->normalizeContactNumber($this->columnText($columns, [
                'contactnum_storekeepers',
                'contactnum storekeepers',
                'contact num storekeepers',
                'contact number storekeepers',
                'storekeeper contact',
                'storekeepers contact',
            ], 73)),
            'sheet_payload' => [
                'rtef_current' => $this->columnNumber($columns, ['rtef current'], 74),
                'rtef_cost' => $this->columnNumber($columns, ['rtef cost'], 75),
                'rtef_capacity' => $rtefCapacity,
                'raw_materials_current' => $this->columnNumber($columns, ['raw mats current'], 50),
                'raw_materials_cost' => $this->columnNumber($columns, ['raw mats cost'], 52),
                'other_nfis_current' => $this->columnNumber($columns, ['other nfis current'], 54),
                'other_nfis_cost' => $this->columnNumber($columns, ['other nfis cost'], 55),
                'mswdo_username' => $this->columnText($columns, ['mswdo username'], 148),
                'zip_code' => $this->columnText($columns, ['wh zip code'], 149),
                'focal_email' => $this->columnText($columns, ['wh focal email'], 149),
                'sheet_warehouse_type' => $this->columnText($columns, ['warehouse type'], 15),
                'actual_warehouse_type' => $this->columnText($columns, ['actual wh type'], 154),
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

    private function columnText(array $columns, array $headers, int $fallback): string
    {
        foreach ($headers as $header) {
            $key = $this->normalizeHeader($header);
            if (array_key_exists($key, $this->columnIndexes)) {
                return $this->text($columns, $this->columnIndexes[$key]);
            }
        }

        return $this->text($columns, $fallback);
    }

    private function columnNumber(array $columns, array $headers, int $fallback): float
    {
        $value = $this->columnText($columns, $headers, $fallback);

        return (float) str_replace([',', '%', ' '], '', $value);
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
            $key = $this->normalizeHeader((string) $header);

            if ($key !== '' && ! array_key_exists($key, $indexes)) {
                $indexes[$key] = $index;
            }

            if (preg_match('/wh\s*name\s*2|warehouse\s*name\s*2/', $key)) {
                $indexes['warehouse_name2'] = $index;
            }

            if ($key === 'warehouse name' && ! isset($indexes['warehouse_name'])) {
                $indexes['warehouse_name'] = $index;
            }

            $isRtef = str_contains($key, 'rtef')
                || str_contains($key, 'ready to eat');
            if ($isRtef && str_contains($key, 'capacity')) {
                $indexes['rtef capacity'] = $index;
            } elseif ($isRtef && str_contains($key, 'current')) {
                $indexes['rtef current'] = $index;
            } elseif ($isRtef && str_contains($key, 'cost')) {
                $indexes['rtef cost'] = $index;
            }
        }

        return $indexes;
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
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
