<?php

namespace App\Services;

use App\Models\OperationalLibraryValue;
use Illuminate\Support\Facades\DB;

class WitDropdownLibrarySyncService
{
    public function __construct(private GoogleSheetCsvService $sheetCsv) {}

    /**
     * Replace WIT-controlled operational libraries with the actual option lists
     * behind the Data Entry dropdowns. Transaction history is deliberately not
     * used: a value having been encoded does not mean it remains an allowed option.
     *
     * @return array<string, int>
     */
    public function sync(string $spreadsheetId): array
    {
        $libraries = $this->csvRows($spreadsheetId, (string) config('services.google_sheets.libraries_gid'));
        $witLibraries = $this->csvRows($spreadsheetId, (string) config('services.google_sheets.wit_libraries_gid'));

        $options = [
            'transaction_purpose' => ['wit_dropdown' => $this->column($libraries, 8)],
            'source_of_goods' => ['wit_dropdown' => $this->column($libraries, 12)],
            'vehicle_type' => [
                'air' => $this->column($libraries, 15),
                'sea' => $this->column($libraries, 16),
                'land' => $this->column($libraries, 17),
            ],
            'transportation_source' => [
                'air' => $this->column($libraries, 19),
                'sea' => $this->column($libraries, 20),
                'land' => $this->column($libraries, 21),
            ],
            'delivery_site' => ['wit_dropdown' => $this->column($witLibraries, 0)],
            'recipient_requesting_party' => ['wit_dropdown' => $this->column($witLibraries, 3)],
            'supplier_sender' => ['wit_dropdown' => $this->column($witLibraries, 4)],
        ];

        return DB::transaction(function () use ($options): array {
            $counts = [];
            foreach ($options as $type => $contexts) {
                $quickAdds = OperationalLibraryValue::query()
                    ->where('library_type', $type)
                    ->get()
                    ->filter(fn (OperationalLibraryValue $row): bool => ($row->metadata['source'] ?? null) === 'dispatch_quick_add')
                    ->map(fn (OperationalLibraryValue $row): array => [
                        'value' => $row->value,
                        'context' => $row->context,
                    ])
                    ->values();
                // These types mirror WIT validation sources exactly. Remove legacy
                // values recovered from Data Entry rows or application history.
                OperationalLibraryValue::query()->where('library_type', $type)->delete();

                foreach ($contexts as $context => $values) {
                    foreach ($values as $value) {
                        OperationalLibraryValue::create([
                            'library_type' => $type,
                            'value' => $value,
                            'context' => $context,
                            'is_active' => true,
                            'metadata' => [
                                'source' => 'wit_validation_range',
                                'synced_at' => now()->toIso8601String(),
                            ],
                        ]);
                    }
                }
                foreach ($quickAdds as $quickAdd) {
                    $alreadyExists = OperationalLibraryValue::query()
                        ->where('library_type', $type)
                        ->get()
                        ->contains(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === OperationalLibraryValue::normalizeLibraryName($quickAdd['value']));
                    if (! $alreadyExists) {
                        OperationalLibraryValue::create([
                            'library_type' => $type,
                            'value' => $quickAdd['value'],
                            'context' => $quickAdd['context'],
                            'is_active' => true,
                            'metadata' => ['source' => 'dispatch_quick_add'],
                        ]);
                    }
                }
                $counts[$type] = OperationalLibraryValue::query()->where('library_type', $type)->count();
            }

            return $counts;
        });
    }

    /** @return list<array<int, string|null>> */
    private function csvRows(string $spreadsheetId, string $gid): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $this->sheetCsv->fetch($spreadsheetId, $gid));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<array<int, string|null>> $rows @return list<string> */
    private function column(array $rows, int $index): array
    {
        return collect($rows)
            ->skip(1)
            ->map(fn (array $row): string => trim((string) ($row[$index] ?? '')))
            ->filter(fn (string $value): bool => $value !== '' && $value !== '-' && ! str_starts_with($value, '#'))
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
    }
}
