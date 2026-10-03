<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InventoryBalanceService
{
    public function availableTotalsByItem(): Collection
    {
        // Match Warehouse Stockpile / WIT current stockpile (receipts − issuances).
        // Do not sum per-row available_balance: negative batch rows floor to 0 there and
        // overstate item totals versus the inventory cards.
        return $this->balanceRows()
            ->groupBy(fn (array $row): string => $this->itemKey($row['item'] ?? ''))
            ->map(fn (Collection $rows): float => max(0, $rows->sum(
                fn (array $row): float => (float) ($row['current_balance'] ?? 0)
            )));
    }

    public function balanceRows(?int $warehouseId = null, ?int $year = null, bool $includeSystemOnly = true): Collection
    {
        $sheetImports = WarehouseSheetImport::with(['batch.item', 'warehouse'])
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->where('import_status', 'imported')
            ->get()
            ->when($year, fn (Collection $imports): Collection => $imports->filter(
                fn (WarehouseSheetImport $import): bool => $this->payloadYear($import) === $year
            )->values());

        // Every transaction created by a sheet import remains sheet-derived even
        // after that import version is superseded. Excluding only current imports
        // caused superseded transactions to reappear as "manual" activity and
        // double-count receipts/issuances.
        $sheetTransactionIds = WarehouseSheetImport::query()
            ->whereNotNull('inventory_transaction_id')
            ->pluck('inventory_transaction_id')
            ->unique();

        $sheetBalanceRows = $sheetImports
            ->groupBy(fn (WarehouseSheetImport $import): string => implode('|', [
                $warehouseId ? null : $import->warehouse_id,
                $import->raw_payload['item_category'] ?? $import->batch?->item?->category,
                $import->raw_payload['item'] ?? $import->batch?->item?->name,
                $this->brandDescription($import->raw_payload['brand_description'] ?? null, $import->batch),
                $this->importExpiryLabel($import),
                $this->storedImportUnitCost($import),
            ]))
            ->map(function ($group): array {
                /** @var WarehouseSheetImport $first */
                $first = $group->first();
                $balance = $group->sum(fn (WarehouseSheetImport $import): float => $this->signedImportQuantity($import));
                $cost = $group->sum(fn (WarehouseSheetImport $import): float => $this->signedImportCost($import));
                $batches = $group->pluck('batch')->filter()->unique('id');

                return [
                    'category' => $first->raw_payload['item_category'] ?? $this->displayCategory($first->batch?->item?->category),
                    'warehouse' => $first->warehouse?->display_name,
                    'warehouse_id' => $first->warehouse_id,
                    'warehouse_province' => $first->warehouse?->province,
                    'warehouse_municipality' => $first->warehouse?->municipality,
                    'warehouse_district' => $first->warehouse?->district,
                    'warehouse_type' => $first->warehouse?->warehouse_type,
                    'warehouse_status' => $first->warehouse?->status,
                    'warehouse_ffp_capacity' => (float) ($first->warehouse?->ffp_capacity ?? 0),
                    'warehouse_rtef_capacity' => (float) ($first->warehouse?->rtef_capacity ?? 0),
                    'partnership' => $first->warehouse?->partnership,
                    'item' => $first->raw_payload['item'] ?? $first->batch?->item?->name,
                    'uom' => trim((string) ($first->raw_payload['uom'] ?? ''))
                        ?: (trim((string) ($first->batch?->item?->unit ?? '')) ?: 'unit'),
                    'brand_description' => $this->brandDescription($first->raw_payload['brand_description'] ?? null, $first->batch),
                    'expiry' => $this->importExpiryLabel($first),
                    'expiry_sort' => $this->expirySortValue($this->importExpiryLabel($first)),
                    'unit_cost' => $this->storedImportUnitCost($first),
                    'current_balance' => $balance,
                    'reserved_quantity' => $batches->sum('reserved_quantity'),
                    'available_balance' => max(0, $balance - $batches->sum('reserved_quantity')),
                    // Keep signed WIT cost until rows are aggregated. Flooring an
                    // issuance-only group here discards the WIT deduction and
                    // overstates the current stockpile value.
                    'cost' => $cost,
                ];
            });

        if (! $includeSystemOnly) {
            return $sheetBalanceRows
                ->filter(fn (array $row): bool => (float) $row['current_balance'] !== 0.0 || (float) $row['cost'] !== 0.0)
                ->values();
        }

        $transactions = InventoryTransaction::with(['batch.item', 'batch.warehouse'])
            ->when($warehouseId, fn ($query) => $query->whereHas('batch', fn ($batchQuery) => $batchQuery->where('warehouse_id', $warehouseId)))
            ->when($sheetTransactionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $sheetTransactionIds))
            ->when($year, fn ($query) => $query->whereYear('transaction_date', $year))
            ->get();

        $manualBalanceRows = $transactions
            ->groupBy(fn (InventoryTransaction $transaction) => implode('|', [
                $warehouseId ? null : $transaction->batch?->warehouse_id,
                $transaction->batch?->item?->category,
                $transaction->batch?->item?->name,
                $this->brandDescription(null, $transaction->batch),
                $transaction->batch?->expiration_date?->format('M Y') ?? 'N/A',
                (float) ($transaction->unit_cost ?? 0),
            ]))
            ->map(function ($group): array {
                /** @var InventoryTransaction $first */
                $first = $group->first();
                $balance = $group->sum(fn (InventoryTransaction $transaction): float => $this->signedTransactionQuantity($transaction));
                $cost = $group->sum(fn (InventoryTransaction $transaction): float => $this->signedTransactionCost($transaction));
                $batches = $group->pluck('batch')->filter()->unique('id');

                return [
                    'category' => $this->displayCategory($first->batch?->item?->category),
                    'warehouse' => $first->batch?->warehouse?->display_name,
                    'warehouse_id' => $first->batch?->warehouse_id,
                    'warehouse_province' => $first->batch?->warehouse?->province,
                    'warehouse_municipality' => $first->batch?->warehouse?->municipality,
                    'warehouse_district' => $first->batch?->warehouse?->district,
                    'warehouse_type' => $first->batch?->warehouse?->warehouse_type,
                    'warehouse_status' => $first->batch?->warehouse?->status,
                    'warehouse_ffp_capacity' => (float) ($first->batch?->warehouse?->ffp_capacity ?? 0),
                    'warehouse_rtef_capacity' => (float) ($first->batch?->warehouse?->rtef_capacity ?? 0),
                    'partnership' => $first->batch?->warehouse?->partnership,
                    'item' => $first->batch?->item?->name,
                    'uom' => $first->batch?->item?->unit,
                    'brand_description' => $this->brandDescription(null, $first->batch),
                    'expiry' => $first->batch?->expiration_date?->format('M Y') ?? 'N/A',
                    'expiry_sort' => $first->batch?->expiration_date?->timestamp ?? PHP_INT_MAX,
                    'unit_cost' => (float) ($first->unit_cost ?? 0),
                    'current_balance' => $balance,
                    'reserved_quantity' => $batches->sum('reserved_quantity'),
                    'available_balance' => max(0, $balance - $batches->sum('reserved_quantity')),
                    'cost' => max(0, $cost),
                ];
            });

        // Combine synchronized WIT activity with system-only transactions (such as
        // a Dispatch release pending manual WIT reconciliation). Once reconciled,
        // the transaction ID is linked to its sheet import and automatically drops
        // out of the system-only side, preventing a double deduction.
        return $sheetBalanceRows
            ->concat($manualBalanceRows)
            ->groupBy(fn (array $row): string => implode('|', [
                $row['warehouse_id'] ?? null,
                $row['category'] ?? null,
                $row['item'] ?? null,
                $row['brand_description'] ?? null,
                $row['expiry'] ?? null,
                $row['unit_cost'] ?? null,
            ]))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $current = $rows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0));
                $reserved = $rows->max(fn (array $row): float => (float) ($row['reserved_quantity'] ?? 0));

                return [
                    ...$first,
                    'current_balance' => $current,
                    'reserved_quantity' => $reserved,
                    'available_balance' => max(0, $current - $reserved),
                    'cost' => $rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                ];
            })
            ->filter(fn (array $row): bool => (float) $row['current_balance'] !== 0.0 || (float) $row['cost'] !== 0.0)
            ->values();
    }

    /**
     * Near-expiry stock on hand, netted the way the WIT / RROS dashboard does.
     *
     * Balance rows stay split by unit cost so valuation lines remain distinct.
     * An issuance is often posted at a rounded unit cost that does not match
     * its receipt, which leaves a positive receipt beside a separate negative
     * issuance. Keeping only the positive side shows stock WIT has already
     * issued. Net by warehouse, item, brand, and expiry month before display.
     */
    public function netExpiryBalances(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => $this->hasRecordedExpiry($row['expiry'] ?? null))
            ->groupBy(fn (array $row): string => implode('|', [
                (string) ($row['warehouse_id'] ?? ''),
                $this->itemKey($row['category'] ?? ''),
                $this->itemKey($row['item'] ?? ''),
                $this->itemKey($row['brand_description'] ?? ''),
                $this->itemKey(trim(explode(',', (string) ($row['expiry'] ?? ''))[0])),
            ]))
            ->map(function (Collection $group): array {
                $first = $group
                    ->sortByDesc(fn (array $row): float => (float) ($row['current_balance'] ?? 0))
                    ->first();
                $quantity = round($group->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)), 4);
                $reserved = (float) $group->max(fn (array $row): float => (float) ($row['reserved_quantity'] ?? 0));

                return [
                    ...$first,
                    'current_balance' => $quantity,
                    'reserved_quantity' => $reserved,
                    'available_balance' => max(0, $quantity - $reserved),
                    'cost' => round($group->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)), 2),
                ];
            })
            ->filter(fn (array $row): bool => (float) $row['current_balance'] > 0)
            ->values();
    }

    public function summary(?Collection $rows = null): array
    {
        $rows ??= $this->balanceRows();

        $foodCurrentBalance = $rows
            ->filter(function (array $row): bool {
                $category = strtolower((string) ($row['category'] ?? ''));

                return str_contains($category, 'food')
                    && ! str_contains($category, 'non food');
            })
            ->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0));

        $currentBalance = $rows
            ->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0));

        $currentCost = $rows
            ->sum(fn (array $row): float => (float) ($row['cost'] ?? 0));

        return [
            /*
            |--------------------------------------------------------------------------
            | Current structure
            |--------------------------------------------------------------------------
            */
            'current_balance' => $currentBalance,

            'food_current_balance' => $foodCurrentBalance,

            'non_food_current_balance' => $currentBalance - $foodCurrentBalance,

            'cost' => $currentCost,

            /*
            |--------------------------------------------------------------------------
            | Backward compatibility
            |--------------------------------------------------------------------------
            | Some existing controllers still use quantity.
            | Keep this temporarily to avoid breaking old components.
            |--------------------------------------------------------------------------
            */
            'quantity' => $currentBalance,

            'total_cost' => $currentCost,
        ];
    }

    public function warehouseBalances(int $limit = 8): Collection
    {
        $balances = $this->balanceRows()
            ->groupBy('warehouse_id')
            ->map(fn (Collection $rows): float => $rows->sum(fn (array $row): float => (float) $row['current_balance']));

        return Warehouse::query()
            ->whereIn('id', $balances->keys()->filter())
            ->get()
            ->map(function (Warehouse $warehouse) use ($balances): Warehouse {
                $warehouse->setAttribute('current_balance', $balances[$warehouse->id] ?? 0);

                return $warehouse;
            })
            ->sortByDesc('current_balance')
            ->take($limit)
            ->values();
    }

    private function displayCategory(?string $category): string
    {
        return match ($category) {
            'food' => 'Food Items',
            'non_food' => 'Non Food Items',
            default => (string) $category,
        };
    }

    private function itemKey(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '';
    }

    private function brandDescription(?string $sheetBrandDescription, ?InventoryBatch $batch): string
    {
        $description = trim((string) ($sheetBrandDescription ?: $batch?->brand_description ?: $batch?->item?->description));

        return $this->blankBrandLabel($description);
    }

    private function blankBrandLabel(?string $description): string
    {
        $description = trim((string) $description);

        return $description === '' || strcasecmp($description, 'Unspecified') === 0
            ? '-'
            : $description;
    }

    private function signedImportQuantity(WarehouseSheetImport $import): float
    {
        return $this->payloadNumber($import, 'receipt') - $this->payloadNumber($import, 'issuance');
    }

    private function signedImportCost(WarehouseSheetImport $import): float
    {
        return $this->payloadNumber($import, 'receipt_cost') - $this->payloadNumber($import, 'issuance_cost');
    }

    private function storedImportUnitCost(WarehouseSheetImport $import): ?float
    {
        if ($this->payloadNumber($import, 'receipt') !== 0.0) {
            return $this->payloadOptionalNumber($import, 'receipt_unit_cost');
        }

        if ($this->payloadNumber($import, 'issuance') !== 0.0) {
            return $this->payloadOptionalNumber($import, 'issuance_unit_cost');
        }

        return $this->payloadOptionalNumber($import, 'receipt_unit_cost')
            ?? $this->payloadOptionalNumber($import, 'issuance_unit_cost');
    }

    private function payloadOptionalNumber(WarehouseSheetImport $import, string $key): ?float
    {
        $value = trim((string) ($import->raw_payload[$key] ?? ''));
        return $value === '' ? null : (float) str_replace([',', ' '], '', $value);
    }

    private function payloadNumber(WarehouseSheetImport $import, string $key): float
    {
        return (float) str_replace([',', ' '], '', (string) ($import->raw_payload[$key] ?? 0));
    }

    private function payloadYear(WarehouseSheetImport $import): ?int
    {
        $date = trim((string) ($import->raw_payload['transaction_date'] ?? ''));

        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->year;
        } catch (\Throwable) {
            return null;
        }
    }

    private function signedTransactionQuantity(InventoryTransaction $transaction): float
    {
        return $transaction->type === 'release'
            ? -1 * (float) $transaction->quantity
            : (float) $transaction->quantity;
    }

    private function signedTransactionCost(InventoryTransaction $transaction): float
    {
        $amount = (float) ($transaction->total_cost ?? ((float) $transaction->quantity * (float) $transaction->unit_cost));

        return $transaction->type === 'release' ? -1 * $amount : $amount;
    }

    private function hasRecordedExpiry(?string $expiry): bool
    {
        $expiry = trim((string) $expiry);

        return $expiry !== '' && strtoupper($expiry) !== 'N/A';
    }

    private function importExpiryLabel(WarehouseSheetImport $import): string
    {
        $expiry = trim((string) ($import->raw_payload['receipt_expiry'] ?: $import->raw_payload['issuance_expiry'] ?: ''));

        if ($expiry !== '') {
            return $this->formatExpiryLabels($expiry);
        }

        return $import->batch?->expiration_date?->format('M Y') ?? 'N/A';
    }

    private function formatExpiryLabels(string $expiry): string
    {
        $labels = collect(explode(',', $expiry))
            ->map(fn (string $value): string => $this->formatExpiryLabel($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sortBy(fn (string $value): int => $this->expirySortValue($value))
            ->values();

        return $labels->isEmpty() ? 'N/A' : $labels->implode(', ');
    }

    private function formatExpiryLabel(string $expiry): string
    {
        $expiry = trim($expiry);

        if ($expiry === '' || strtoupper($expiry) === 'N/A') {
            return 'N/A';
        }

        try {
            return Carbon::parse($expiry)->format('M Y');
        } catch (\Throwable) {
            return $expiry;
        }
    }

    private function expirySortValue(string $expiry): int
    {
        $expiry = trim(explode(',', $expiry)[0] ?? $expiry);

        if ($expiry === '' || strtoupper($expiry) === 'N/A') {
            return PHP_INT_MAX;
        }

        try {
            return Carbon::createFromFormat('M Y', $expiry)->timestamp;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
