<?php

namespace App\Services;

use App\Models\OperationalLibraryValue;
use RuntimeException;

/**
 * Seeds Driver / Received By operational libraries from the RIS tracking sheet.
 *
 * Spreadsheet: config services.google_sheets.ris_tracking_spreadsheet_id
 * Tab gid: config services.google_sheets.ris_tracking_gid (default 1905199506)
 *
 * Column mapping (0-based index):
 * - AU [46] Name of Driver → dispatch_driver.value
 * - AV [47] Contact Number of Driver → dispatch_driver.metadata.contact_number
 * - AW [48] Plate Number of Vehicle Driven → not imported (vehicle plate on plans)
 * - AX [49] Received By (Name of LGU Representative) → dispatch_received_by.value
 *   (position/office not present on sheet; stored as empty metadata fields)
 *
 * Uniqueness: one library row per normalized name (trim + collapse whitespace + case-insensitive).
 * Sheet duplicates are merged in memory; existing DB duplicates are collapsed before upsert.
 */
class DispatchContactSheetSyncService
{
    public const DRIVER_NAME_COLUMN = 46;

    public const DRIVER_CONTACT_COLUMN = 47;

    public const RECEIVED_BY_NAME_COLUMN = 49;

    public function __construct(private GoogleSheetCsvService $csv) {}

    /**
     * @return array{
     *     drivers_created:int,
     *     drivers_updated:int,
     *     received_by_created:int,
     *     received_by_updated:int,
     *     rows_seen:int,
     *     drivers_duplicates_removed:int,
     *     received_by_duplicates_removed:int
     * }
     */
    public function sync(): array
    {
        $sheetId = trim((string) config('services.google_sheets.ris_tracking_spreadsheet_id', ''));
        $gid = trim((string) config('services.google_sheets.ris_tracking_gid', '1905199506'));
        if ($sheetId === '') {
            throw new RuntimeException('RIS tracking Google Sheet is not configured.');
        }

        $body = $this->csv->fetch($sheetId, $gid);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '\\') ?: [];
        $header = array_map(
            fn ($value) => trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"),
            $header,
        );

        $this->assertHeaders($header);

        $drivers = [];
        $receivers = [];
        $rowsSeen = 0;

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $rowsSeen++;
            $driverName = $this->cleanCell($row[self::DRIVER_NAME_COLUMN] ?? null);
            $driverContact = $this->cleanCell($row[self::DRIVER_CONTACT_COLUMN] ?? null);
            $receivedBy = $this->cleanCell($row[self::RECEIVED_BY_NAME_COLUMN] ?? null);

            if ($driverName !== '') {
                $this->mergeContactRow($drivers, $driverName, [
                    'contact_number' => $driverContact,
                ]);
            }

            if ($receivedBy !== '') {
                $this->mergeContactRow($receivers, $receivedBy, [
                    'position' => '',
                    'office' => '',
                ]);
            }
        }
        fclose($stream);

        $stats = [
            'drivers_created' => 0,
            'drivers_updated' => 0,
            'received_by_created' => 0,
            'received_by_updated' => 0,
            'rows_seen' => $rowsSeen,
            'drivers_duplicates_removed' => $this->collapseDuplicateLibraryRows('dispatch_driver'),
            'received_by_duplicates_removed' => $this->collapseDuplicateLibraryRows('dispatch_received_by'),
        ];

        foreach ($drivers as $driver) {
            $result = $this->upsertLibrary(
                'dispatch_driver',
                $driver['name'],
                array_filter([
                    'contact_number' => ($driver['contact_number'] ?? '') !== '' ? $driver['contact_number'] : null,
                ], fn ($value) => $value !== null && $value !== ''),
            );
            $stats[$result === 'created' ? 'drivers_created' : 'drivers_updated']++;
        }

        foreach ($receivers as $receiver) {
            $result = $this->upsertLibrary(
                'dispatch_received_by',
                $receiver['name'],
                [
                    'position' => (string) ($receiver['position'] ?? ''),
                    'office' => (string) ($receiver['office'] ?? ''),
                ],
            );
            $stats[$result === 'created' ? 'received_by_created' : 'received_by_updated']++;
        }

        return $stats;
    }

    /**
     * Keep the first display name; fill empty metadata fields from later duplicate rows.
     *
     * @param  array<string, array{name:string}&array<string, string>>  $bucket
     * @param  array<string, string>  $metadata
     */
    private function mergeContactRow(array &$bucket, string $name, array $metadata): void
    {
        $key = OperationalLibraryValue::normalizeLibraryName($name);
        if ($key === '') {
            return;
        }

        if (! isset($bucket[$key])) {
            $bucket[$key] = array_merge(['name' => $name], $metadata);

            return;
        }

        foreach ($metadata as $field => $value) {
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $current = trim((string) ($bucket[$key][$field] ?? ''));
            if ($current === '') {
                $bucket[$key][$field] = $text;
            }
        }
    }

    /**
     * Collapse case/whitespace duplicates already stored for a library type.
     * Keeps the lowest-id row as canonical and merges non-empty metadata into it.
     */
    private function collapseDuplicateLibraryRows(string $type): int
    {
        $removed = 0;
        $groups = OperationalLibraryValue::query()
            ->where('library_type', $type)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OperationalLibraryValue $row): string => OperationalLibraryValue::normalizeLibraryName($row->value))
            ->filter(fn ($rows, $key): bool => $key !== '' && $rows->count() > 1);

        foreach ($groups as $rows) {
            /** @var OperationalLibraryValue $keep */
            $keep = $rows->first();
            $mergedMeta = is_array($keep->metadata) ? $keep->metadata : [];

            foreach ($rows->skip(1) as $extra) {
                $extraMeta = is_array($extra->metadata) ? $extra->metadata : [];
                foreach ($extraMeta as $field => $value) {
                    $text = trim((string) $value);
                    if ($text === '') {
                        continue;
                    }
                    $current = trim((string) ($mergedMeta[$field] ?? ''));
                    if ($current === '') {
                        $mergedMeta[$field] = $text;
                    }
                }
                $extra->delete();
                $removed++;
            }

            $keep->fill([
                'metadata' => $mergedMeta === [] ? null : $mergedMeta,
                'is_active' => true,
                'context' => $keep->context ?: 'all',
            ])->save();
        }

        return $removed;
    }

    /**
     * @param  list<string>  $header
     */
    private function assertHeaders(array $header): void
    {
        $checks = [
            self::DRIVER_NAME_COLUMN => 'name of driver',
            self::DRIVER_CONTACT_COLUMN => 'contact number of driver',
            self::RECEIVED_BY_NAME_COLUMN => 'received by',
        ];

        foreach ($checks as $index => $needle) {
            $actual = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($header[$index] ?? ''))) ?? '');
            if ($actual === '' || ! str_contains($actual, $needle)) {
                throw new RuntimeException(
                    "Unexpected sheet header at column index {$index}: expected to contain \"{$needle}\", got \"".($header[$index] ?? '').'".',
                );
            }
        }
    }

    private function cleanCell(mixed $value): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        if ($text === '' || $text === '-' || $text === '—' || mb_strtolower($text) === 'n/a') {
            return '';
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return 'created'|'updated'
     */
    private function upsertLibrary(string $type, string $name, array $metadata): string
    {
        $normalized = OperationalLibraryValue::normalizeLibraryName($name);
        $matches = OperationalLibraryValue::query()
            ->where('library_type', $type)
            ->orderBy('id')
            ->get()
            ->filter(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized)
            ->values();

        $existing = $matches->first();

        if (! $existing) {
            OperationalLibraryValue::create([
                'library_type' => $type,
                'value' => $name,
                'context' => 'all',
                'metadata' => $metadata === [] ? null : $metadata,
                'is_active' => true,
            ]);

            return 'created';
        }

        $currentMeta = is_array($existing->metadata) ? $existing->metadata : [];
        $merged = $currentMeta;
        foreach ($metadata as $key => $value) {
            $text = trim((string) $value);
            if ($text === '') {
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = '';
                }

                continue;
            }
            // Sheet-provided non-empty fields update the canonical row.
            $merged[$key] = $text;
        }

        foreach ($matches->skip(1) as $extra) {
            $extraMeta = is_array($extra->metadata) ? $extra->metadata : [];
            foreach ($extraMeta as $key => $value) {
                $text = trim((string) $value);
                if ($text === '') {
                    continue;
                }
                if (trim((string) ($merged[$key] ?? '')) === '') {
                    $merged[$key] = $text;
                }
            }
            $extra->delete();
        }

        $existing->fill([
            'value' => $name,
            'metadata' => $merged === [] ? null : $merged,
            'is_active' => true,
            'context' => $existing->context ?: 'all',
        ])->save();

        return 'updated';
    }
}
