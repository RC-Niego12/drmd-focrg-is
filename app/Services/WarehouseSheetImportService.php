<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseSheetImportService
{
    public function __construct(
        private GoogleSheetCsvService $sheetCsv,
        private WitDropdownLibrarySyncService $dropdownLibraries,
    ) {}

    private const HEADERS = [
        'row_no',
        'warehouse_id',
        'warehouse_name',
        'item_category',
        'item',
        'brand_description',
        'transaction_date',
        'source_of_goods',
        'purpose',
        'ris_if_stf',
        'call_off_number',
        'reference_dr',
        'uom',
        'sender_supplier',
        'receipt_expiry',
        'receipt',
        'receipt_unit_cost',
        'receipt_cost',
        'issuance_expiry',
        'available_stock',
        'issuance',
        'issuance_unit_cost',
        'issuance_cost',
        'recipient',
        'delivery_site',
        'expected_delivery_date',
        'land_transportation_type',
        'land_transportation_source',
        'plate',
        'driver',
        'contact_number',
        'sea_transportation_type',
        'sea_transportation_source',
        'sea_transportation_details',
        'air_transportation_type',
        'air_transportation_source',
        'air_transportation_details',
        'encoded_by',
        'encoded_at',
        'edited_by',
        'edited_at',
        'status',
        'remarks',
    ];

    public function import(string $sheetUrl, ?string $worksheetName = null): array
    {
        [$sheetId, $gid] = $this->extractSheetParts($sheetUrl);
        try {
            $libraryCounts = $this->dropdownLibraries->sync($sheetId);
            $csv = $this->sheetCsv->fetch($sheetId, $gid);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Could not connect to Google Sheets. Please check the internet connection or DNS settings, then try syncing again.', previous: $exception);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        fgetcsv($handle); // Google Sheet header row has duplicate names, so positional mapping is safer.

        $summary = [
            'sheet_id' => $sheetId,
            'gid' => $gid,
            'worksheet_name' => $worksheetName,
            'rows_seen' => 0,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'receipts' => 0,
            'issuances' => 0,
            'errors' => 0,
            'library_options_synced' => array_sum($libraryCounts),
            'library_option_counts' => $libraryCounts,
        ];

        while (($columns = fgetcsv($handle)) !== false) {
            $summary['rows_seen']++;
            $payload = $this->mapColumns($columns);

            if (blank($payload['warehouse_id']) || blank($payload['item'])) {
                $summary['rows_skipped']++;

                continue;
            }

            try {
                $result = $this->importRow($sheetId, $gid, $summary['rows_seen'] + 1, $payload);
                $summary[$result]++;

                if ($result !== 'rows_skipped') {
                    $summary['rows_imported']++;
                }
            } catch (\Throwable $exception) {
                $summary['errors']++;
                WarehouseSheetImport::updateOrCreate(
                    [
                        'sheet_id' => $sheetId,
                        'gid' => $gid,
                        'sheet_row_number' => $summary['rows_seen'] + 1,
                        'row_hash' => hash('sha256', json_encode($payload)),
                    ],
                    [
                        'raw_payload' => $payload,
                        'import_status' => 'failed',
                        'error_message' => $exception->getMessage(),
                    ]
                );
            }
        }

        fclose($handle);

        return $summary;
    }

    private function importRow(string $sheetId, string $gid, int $rowNumber, array $payload): string
    {
        return DB::transaction(function () use ($sheetId, $gid, $rowNumber, $payload): string {
            $rowHash = hash('sha256', json_encode($payload));

            $existingRows = WarehouseSheetImport::where([
                'sheet_id' => $sheetId,
                'gid' => $gid,
                'sheet_row_number' => $rowNumber,
            ])->get();

            $existing = $existingRows->firstWhere('row_hash', $rowHash);

            if ($existing) {
                $existingRows
                    ->where('id', '!=', $existing->id)
                    ->each
                    ->update(['import_status' => 'superseded']);

                if ($existing->import_status !== 'imported') {
                    $existing->update(['import_status' => 'imported', 'error_message' => null]);
                }

                if ($existing->inventory_batch_id && blank($existing->batch?->brand_description) && filled($payload['brand_description'])) {
                    $existing->batch()->update(['brand_description' => $payload['brand_description']]);
                }

                return 'rows_skipped';
            }

            $existingRows->each->update(['import_status' => 'superseded']);

            $warehouse = $this->warehouse($payload);
            $item = $this->item($payload);
            $batch = $this->batch($payload, $warehouse, $item);
            $transaction = $this->reconcilePendingDispatchIssuance($payload, $batch)
                ?? $this->transaction($payload, $batch);

            WarehouseSheetImport::create([
                'sheet_id' => $sheetId,
                'gid' => $gid,
                'sheet_row_number' => $rowNumber,
                'row_hash' => $rowHash,
                'warehouse_id' => $warehouse->id,
                'inventory_item_id' => $item->id,
                'inventory_batch_id' => $batch->id,
                'inventory_transaction_id' => $transaction?->id,
                'raw_payload' => $payload,
                'import_status' => 'imported',
            ]);

            if (! $transaction) {
                return 'rows_skipped';
            }

            return $transaction->type === 'release' ? 'issuances' : 'receipts';
        });
    }

    private function warehouse(array $payload): Warehouse
    {
        $existingWarehouse = Warehouse::withTrashed()
            ->where('external_warehouse_id', $payload['warehouse_id'])
            ->first();

        [$parsedProvince, $municipality] = $this->splitWarehouseLocation($payload['warehouse_name']);
        $province = $existingWarehouse?->province ?: $parsedProvince;

        return Warehouse::updateOrCreate(
            ['external_warehouse_id' => $payload['warehouse_id']],
            [
                'name' => $this->normalizeWarehouseName($payload['warehouse_name'] ?: $payload['warehouse_id'], $province),
                'province' => $province,
                'municipality' => $municipality,
                'capacity' => 0,
                'status' => 'active',
            ]
        );
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

    private function item(array $payload): InventoryItem
    {
        $category = Str::contains(Str::lower($payload['item_category']), 'food') &&
            ! Str::contains(Str::lower($payload['item_category']), 'non')
                ? 'food'
                : 'non_food';

        return InventoryItem::firstOrCreate(
            [
                'name' => $payload['item'],
                'unit' => $payload['uom'] ?: 'unit',
            ],
            [
                'category' => $category,
                'status' => 'active',
                'description' => $payload['brand_description'],
            ]
        );
    }

    private function batch(array $payload, Warehouse $warehouse, InventoryItem $item): InventoryBatch
    {
        $expiry = $this->parseMonthDate($payload['receipt_expiry'] ?: $payload['issuance_expiry']);
        $batchNumber = implode('-', array_filter([
            $payload['warehouse_id'],
            Str::slug($payload['item']),
            Str::slug($payload['brand_description'] ?: 'standard'),
            $expiry?->format('Ym') ?? 'no-expiry',
        ]));

        return InventoryBatch::firstOrCreate(
            [
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'batch_number' => Str::limit($batchNumber, 255, ''),
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
                'brand_description' => $payload['brand_description'],
                'expiration_date' => $expiry?->toDateString(),
                'date_received' => $this->parseDate($payload['transaction_date'])?->toDateString() ?? now()->toDateString(),
                'source' => $payload['source_of_goods'],
                'current_status' => 'available',
            ]
        );
    }

    private function transaction(array $payload, InventoryBatch $batch): ?InventoryTransaction
    {
        $receipt = $this->number($payload['receipt']);
        $issuance = $this->number($payload['issuance']);

        if ($receipt <= 0 && $issuance <= 0) {
            return null;
        }

        $isIssuance = $issuance > 0;
        $quantity = $isIssuance ? $issuance : $receipt;
        $unitCost = $this->number($isIssuance ? $payload['issuance_unit_cost'] : $payload['receipt_unit_cost']);
        $totalCost = $this->number($isIssuance ? $payload['issuance_cost'] : $payload['receipt_cost']);

        if ($isIssuance) {
            $batch->quantity = max(0, (float) $batch->quantity - $quantity);
        } else {
            $batch->quantity = (float) $batch->quantity + $quantity;
        }

        $batch->save();

        $transaction = $batch->transactions()->create([
            'user_id' => auth()->id(),
            'type' => $isIssuance ? 'release' : 'receipt',
            'transaction_date' => $this->parseDate($payload['transaction_date'])?->toDateString(),
            'source_of_goods' => $payload['source_of_goods'],
            'purpose' => $payload['purpose'],
            'reference_number' => $payload['reference_dr'],
            'ris_if_stf' => $payload['ris_if_stf'],
            'call_off_number' => $payload['call_off_number'],
            'sender_supplier' => $payload['sender_supplier'],
            'quantity' => $quantity,
            'unit_cost' => $unitCost ?: null,
            'total_cost' => $totalCost ?: null,
            'balance_after' => $this->number($payload['available_stock']) ?: (float) $batch->quantity,
            'recipient' => $payload['recipient'],
            'delivery_site' => $payload['delivery_site'],
            'expected_delivery_date' => $this->parseDate($payload['expected_delivery_date'])?->toDateString(),
            'transport_details' => [
                'land' => [
                    'type' => $payload['land_transportation_type'],
                    'source' => $payload['land_transportation_source'],
                    'plate' => $payload['plate'],
                    'driver' => $payload['driver'],
                    'contact_number' => $payload['contact_number'],
                ],
                'sea' => [
                    'type' => $payload['sea_transportation_type'],
                    'source' => $payload['sea_transportation_source'],
                    'details' => $payload['sea_transportation_details'],
                ],
                'air' => [
                    'type' => $payload['air_transportation_type'],
                    'source' => $payload['air_transportation_source'],
                    'details' => $payload['air_transportation_details'],
                ],
            ],
            'external_status' => $payload['status'],
            'encoded_by_email' => $payload['encoded_by'],
            'encoded_at' => $this->parseDateTime($payload['encoded_at']),
            'edited_by_email' => $payload['edited_by'],
            'edited_at' => $this->parseDateTime($payload['edited_at']),
            'remarks' => $payload['remarks'],
        ]);

        $batch->update([
            'quantity' => $batch->transactions()->get()->sum(
                fn (InventoryTransaction $transaction): float => $transaction->type === 'release'
                    ? -1 * (float) $transaction->quantity
                    : (float) $transaction->quantity
            ),
        ]);

        return $transaction;
    }

    /**
     * Match a manually encoded WIT issuance to the system deduction posted at
     * Dispatch release. Brand/description and expiry are inherently part of the
     * InventoryBatch key; warehouse, item, UOM, RIS/DR and quantity are checked here.
     * No stock mutation occurs because the system transaction already deducted it.
     */
    private function reconcilePendingDispatchIssuance(array $payload, InventoryBatch $batch): ?InventoryTransaction
    {
        $issuance = $this->number($payload['issuance']);
        if ($issuance <= 0) {
            return null;
        }

        $query = InventoryTransaction::query()
            ->where('inventory_batch_id', $batch->id)
            ->where('type', 'release')
            ->where('reconciliation_status', 'pending_wit')
            ->where('quantity', $issuance)
            ->whereDoesntHave('sheetImport');

        if (filled($payload['ris_if_stf'])) {
            $query->where('ris_if_stf', $payload['ris_if_stf']);
        }
        if (filled($payload['reference_dr'])) {
            $query->where(function ($reference) use ($payload): void {
                $reference->where('reference_number', $payload['reference_dr'])
                    ->orWhereNull('reference_number');
            });
        }

        $transaction = $query->oldest('id')->first();
        if (! $transaction) {
            return null;
        }

        $unitCost = $this->number($payload['issuance_unit_cost']);
        $totalCost = $this->number($payload['issuance_cost']);
        $transaction->update([
            'transaction_date' => $this->parseDate($payload['transaction_date'])?->toDateString()
                ?: $transaction->transaction_date,
            'source_of_goods' => $payload['source_of_goods'] ?: $transaction->source_of_goods,
            'purpose' => $payload['purpose'] ?: $transaction->purpose,
            'reference_number' => $payload['reference_dr'] ?: $transaction->reference_number,
            'ris_if_stf' => $payload['ris_if_stf'] ?: $transaction->ris_if_stf,
            'call_off_number' => $payload['call_off_number'] ?: $transaction->call_off_number,
            'sender_supplier' => $payload['sender_supplier'] ?: $transaction->sender_supplier,
            'unit_cost' => $unitCost ?: $transaction->unit_cost,
            'total_cost' => $totalCost ?: $transaction->total_cost,
            'balance_after' => $this->number($payload['available_stock']) ?: $transaction->balance_after,
            'recipient' => $payload['recipient'] ?: $transaction->recipient,
            'delivery_site' => $payload['delivery_site'] ?: $transaction->delivery_site,
            'expected_delivery_date' => $this->parseDate($payload['expected_delivery_date'])?->toDateString()
                ?: $transaction->expected_delivery_date,
            'transport_details' => [
                'land' => [
                    'type' => $payload['land_transportation_type'],
                    'source' => $payload['land_transportation_source'],
                    'plate' => $payload['plate'],
                    'driver' => $payload['driver'],
                    'contact_number' => $payload['contact_number'],
                ],
                'sea' => [
                    'type' => $payload['sea_transportation_type'],
                    'source' => $payload['sea_transportation_source'],
                    'details' => $payload['sea_transportation_details'],
                ],
                'air' => [
                    'type' => $payload['air_transportation_type'],
                    'source' => $payload['air_transportation_source'],
                    'details' => $payload['air_transportation_details'],
                ],
            ],
            'external_status' => $payload['status'],
            'reconciliation_status' => 'reconciled',
            'reconciled_at' => now(),
            'encoded_by_email' => $payload['encoded_by'] ?: $transaction->encoded_by_email,
            'encoded_at' => $this->parseDateTime($payload['encoded_at']) ?: $transaction->encoded_at,
            'edited_by_email' => $payload['edited_by'] ?: null,
            'edited_at' => $this->parseDateTime($payload['edited_at']),
            'remarks' => $payload['remarks'] ?: $transaction->remarks,
        ]);

        return $transaction;
    }

    private function mapColumns(array $columns): array
    {
        $payload = [];

        foreach (self::HEADERS as $index => $key) {
            $payload[$key] = trim((string) ($columns[$index] ?? ''));
        }

        return $payload;
    }

    private function extractSheetParts(string $url): array
    {
        preg_match('/\/d\/([^\/]+)/', $url, $sheetMatches);
        preg_match('/gid=(\d+)/', $url, $gidMatches);

        if (empty($sheetMatches[1])) {
            throw new RuntimeException('Invalid Google Sheet URL.');
        }

        return [$sheetMatches[1], $gidMatches[1] ?? '0'];
    }

    private function splitWarehouseLocation(string $warehouseName): array
    {
        $parts = array_map('trim', explode(',', $warehouseName, 2));
        $province = $parts[0] ?: 'Caraga';
        $municipality = $parts[1] ?? $parts[0] ?? 'Unspecified';
        $municipality = preg_replace('/\d+$/', '', $municipality) ?: $municipality;

        return [$province, trim($municipality)];
    }

    private function number(?string $value): float
    {
        return (float) str_replace([',', ' '], '', (string) $value);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        foreach (['d M Y', 'm/d/Y', 'n/j/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value));
            } catch (\Throwable) {
                //
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(?string $value): ?Carbon
    {
        return $this->parseDate($value);
    }

    private function parseMonthDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse('01 '.$value)->endOfMonth();
        } catch (\Throwable) {
            return $this->parseDate($value);
        }
    }
}
