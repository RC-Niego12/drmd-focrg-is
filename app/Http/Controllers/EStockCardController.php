<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\InventoryBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class EStockCardController extends Controller
{
    public function __invoke(Request $request, InventoryBalanceService $balanceService): Response
    {
        $currentYear = (int) now()->year;
        $filters = [
            'warehouse_id' => $this->filterArray($request, 'warehouse_id'),
            'warehouse_province' => $this->filterArray($request, 'warehouse_province'),
            'warehouse_municipality' => $this->filterArray($request, 'warehouse_municipality'),
            'item' => $this->filterArray($request, 'item'),
            'category' => $this->filterArray($request, 'category'),
            'partnership' => $this->filterArray($request, 'partnership'),
            'brand' => $this->filterArray($request, 'brand'),
            'expiry' => $this->filterArray($request, 'expiry'),
            'source_of_goods' => $this->filterArray($request, 'source_of_goods'),
            'purpose' => $this->filterArray($request, 'purpose'),
            'sender_recipient' => $this->filterArray($request, 'sender_recipient'),
            'type' => $this->filterArray($request, 'type'),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
            'transaction_year' => (int) $request->integer('transaction_year', $currentYear),
            'search' => $request->string('search')->trim()->toString(),
        ];

        $base = InventoryTransaction::query()
            ->with(['batch.item', 'batch.warehouse', 'user'])
            ->when($filters['warehouse_id'] !== [], fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->whereIn('warehouse_id', $filters['warehouse_id'])))
            ->when($filters['warehouse_province'] !== [], fn ($query) => $query->whereHas('batch.warehouse', fn ($warehouse) => $warehouse->whereIn('province', $filters['warehouse_province'])))
            ->when($filters['warehouse_municipality'] !== [], fn ($query) => $query->whereHas('batch.warehouse', fn ($warehouse) => $warehouse->whereIn('municipality', $filters['warehouse_municipality'])))
            ->when($filters['item'] !== [], fn ($query) => $query->whereHas('batch.item', fn ($item) => $item->whereIn('name', $filters['item'])))
            ->when($filters['category'] !== [], fn ($query) => $query->whereHas('batch.item', function ($item) use ($filters): void {
                $categories = collect($this->internalCategories($filters['category']));

                $item->where(function ($itemQuery) use ($categories, $filters): void {
                    if ($categories->isNotEmpty()) {
                        $itemQuery->whereIn('category', $categories->all());
                    }

                    if (in_array('Family Food Pack', $filters['category'], true)) {
                        $itemQuery->orWhereRaw('LOWER(name) LIKE ?', ['%family food pack%']);
                    }
                });
            }))
            ->when($filters['partnership'] !== [], fn ($query) => $query->whereHas('batch.warehouse', fn ($warehouse) => $warehouse->whereIn('partnership', $filters['partnership'])))
            ->when($filters['brand'] !== [], fn ($query) => $query->whereHas('batch', function ($batch) use ($filters): void {
                $brands = collect($filters['brand']);
                $batch->where(function ($query) use ($brands): void {
                    $regular = $brands->reject(fn ($brand) => $this->isBlankBrandFilter($brand))->values();
                    if ($regular->isNotEmpty()) {
                        $query->whereIn('brand_description', $regular);
                    }
                    if ($brands->contains(fn ($brand) => $this->isBlankBrandFilter($brand))) {
                        $query->orWhereNull('brand_description')
                            ->orWhere('brand_description', '')
                            ->orWhere('brand_description', 'Unspecified');
                    }
                });
            }))
            ->when($filters['expiry'] !== [], fn ($query) => $query->whereHas('batch', fn ($batch) => $this->applyExpiryFilter($batch, $filters['expiry'])))
            ->when($filters['source_of_goods'] !== [], fn ($query) => $query->whereIn('source_of_goods', $filters['source_of_goods']))
            ->when($filters['purpose'] !== [], fn ($query) => $query->whereIn('purpose', $filters['purpose']))
            ->when($filters['sender_recipient'] !== [], function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->whereIn('sender_supplier', $filters['sender_recipient'])
                        ->orWhereIn('recipient', $filters['sender_recipient']);
                });
            })
            ->when($filters['type'] !== [], fn ($query) => $query->whereIn('type', array_intersect($filters['type'], ['receipt', 'release'])))
            ->whereYear('transaction_date', $filters['transaction_year'])
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('transaction_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('transaction_date', '<=', $filters['date_to']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = "%{$filters['search']}%";
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('source_of_goods', 'like', $search)
                        ->orWhere('purpose', 'like', $search)
                        ->orWhere('reference_number', 'like', $search)
                        ->orWhere('ris_if_stf', 'like', $search)
                        ->orWhere('sender_supplier', 'like', $search)
                        ->orWhere('recipient', 'like', $search)
                        ->orWhereHas('batch.item', fn ($item) => $item->where('name', 'like', $search)->orWhere('unit', 'like', $search))
                        ->orWhereHas('batch.warehouse', fn ($warehouse) => $warehouse->where('name', 'like', $search)->orWhere('external_warehouse_id', 'like', $search))
                        ->orWhereHas('batch', fn ($batch) => $batch->where('brand_description', 'like', $search)->orWhere('batch_number', 'like', $search));
                });
            });

        $summaryTransactions = (clone $base)
            ->orderByRaw('COALESCE(transaction_date, created_at) asc')
            ->orderBy('id')
            ->get();
        $ledgerTransactions = (clone $base)
            ->orderByRaw('COALESCE(transaction_date, created_at) asc')
            ->orderBy('id')
            ->limit(1000)
            ->get();

        $receipts = $summaryTransactions->where('type', 'receipt');
        $issuances = $summaryTransactions->where('type', 'release');
        $netQuantity = $receipts->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity)
            - $issuances->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity);
        $netCost = $receipts->sum(fn (InventoryTransaction $transaction): float => $this->transactionCost($transaction))
            - $issuances->sum(fn (InventoryTransaction $transaction): float => $this->transactionCost($transaction));
        $receiptSummary = [
            'quantity' => $receipts->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            'cost' => $receipts->sum(fn (InventoryTransaction $transaction): float => $this->transactionCost($transaction)),
        ];
        $issuanceSummary = [
            'quantity' => $issuances->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            'cost' => $issuances->sum(fn (InventoryTransaction $transaction): float => $this->transactionCost($transaction)),
        ];
        $currentSummary = $balanceService->summary($this->filteredBalanceRows($balanceService->balanceRows(null, $filters['transaction_year']), $filters));

        if (! $this->hasActiveFilters($filters)) {
            $receiptSummary = $this->sheetTransactionSummary('receipt', $filters['transaction_year']) ?? $receiptSummary;
            $issuanceSummary = $this->sheetTransactionSummary('release', $filters['transaction_year']) ?? $issuanceSummary;
            $netQuantity = $receiptSummary['quantity'] - $issuanceSummary['quantity'];
            $netCost = $receiptSummary['cost'] - $issuanceSummary['cost'];
        }

        return Inertia::render('Inventory/EStockCard', [
            'filters' => $filters,
            'warehouses' => Warehouse::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'external_warehouse_id', 'province', 'municipality']),
            'items' => InventoryItem::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'unit', 'category'])
                ->unique('name')
                ->values(),
            'filterOptions' => [
                'warehouses' => $this->warehouseOptionsFor($summaryTransactions),
                'warehouse_provinces' => $this->warehouseProvinceOptionsFor($summaryTransactions),
                'warehouse_municipalities' => $this->warehouseMunicipalityOptionsFor($summaryTransactions),
                'items' => $this->itemOptionsFor($summaryTransactions),
                'categories' => $this->categoryOptionsFor($summaryTransactions),
                'partnerships' => $this->partnershipOptionsFor($summaryTransactions),
                'brands' => $this->brandOptionsFor($summaryTransactions),
                'expiries' => $this->expiryOptionsFor($summaryTransactions),
                'sources' => $this->sourceOptionsFor($summaryTransactions),
                'purposes' => $this->purposeOptionsFor($summaryTransactions),
                'senderRecipients' => $this->senderRecipientOptionsFor($summaryTransactions),
                'transaction_years' => $this->transactionYearOptions($currentYear),
            ],
            'metrics' => [
                'transactions' => $summaryTransactions->count(),

                'current_balance' => $currentSummary['current_balance'] ?? 0,
                'current_cost' => $currentSummary['cost'] ?? 0,

                'receipt_quantity' => $receiptSummary['quantity'] ?? 0,
                'issuance_quantity' => $issuanceSummary['quantity'] ?? 0,
                'net_quantity' => $netQuantity ?? 0,

                'receipt_cost' => $receiptSummary['cost'] ?? 0,
                'issuance_cost' => $issuanceSummary['cost'] ?? 0,
                'net_cost' => $netCost ?? 0,
            ],
            'movementByMonth' => $this->movementByMonth($summaryTransactions),
            'warehouseFlow' => $this->warehouseFlow($summaryTransactions),
            'itemFlow' => $this->itemFlow($summaryTransactions),
            'rows' => $this->applyRunningBalances($ledgerTransactions->map(fn (InventoryTransaction $transaction): array => $this->ledgerRowBase($transaction)))->values(),
            'sourceSheet' => [
                'url' => 'https://docs.google.com/spreadsheets/d/1fAKrf4Fu5DVYaT2whEYlrgZRMy7sJFFG1ZWS-HE3WgI/edit?gid=1005720340#gid=1005720340',
                'worksheet' => 'E-Stock Card',
            ],
        ]);
    }

    private function ledgerRowBase(InventoryTransaction $transaction): array
    {
        $batch = $transaction->batch;
        $item = $batch?->item;
        $warehouse = $batch?->warehouse;
        $isReceipt = $transaction->type === 'receipt';
        $unitCost = (float) ($transaction->unit_cost ?? 0);
        $cost = $this->transactionCost($transaction);
        if ($unitCost <= 0 && (float) $transaction->quantity > 0) {
            $unitCost = $cost / (float) $transaction->quantity;
        }

        $row = [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString() ?? $transaction->created_at?->toDateString(),
            'warehouse' => $warehouse?->display_name ?? 'Unknown Warehouse',
            'warehouse_id' => $warehouse?->external_warehouse_id,
            'warehouse_internal_id' => $warehouse?->id,
            'item_id' => $item?->id,
            'item' => $item?->name ?? 'Unknown Item',
            'category' => $this->displayItemCategory($item),
            'uom' => $item?->unit ?: '-',
            'brand_specification' => $this->brandLabel($batch?->brand_description),
            'expiry' => $batch?->expiration_date?->format('M Y') ?: 'N/A',
            'sender_recipient' => $isReceipt ? ($transaction->sender_supplier ?: '-') : ($transaction->recipient ?: '-'),
            'source_purpose' => $isReceipt ? ($transaction->source_of_goods ?: '-') : ($transaction->purpose ?: '-'),
            'ris' => $transaction->ris_if_stf ?: '-',
            'reference' => $transaction->reference_number ?: '-',
            'receipt_quantity' => $isReceipt ? (float) $transaction->quantity : null,
            'receipt_unit_cost' => $isReceipt ? $unitCost : null,
            'receipt_cost' => $isReceipt ? $cost : null,
            'receipt_expiry' => $isReceipt ? $batch?->expiration_date?->format('M Y') : null,
            'issuance_quantity' => $isReceipt ? null : (float) $transaction->quantity,
            'issuance_unit_cost' => $isReceipt ? null : $unitCost,
            'issuance_cost' => $isReceipt ? null : $cost,
            'issuance_expiry' => $isReceipt ? null : $batch?->expiration_date?->format('M Y'),
            'balance' => 0,
            'balance_cost' => 0,
            'cost' => $cost,
            'type' => $transaction->type,
            'personnel' => $transaction->user?->name ?: $transaction->encoded_by_email ?: 'System Import',
            'partnership' => $warehouse?->partnership ?: '-',
            'source_of_goods' => $transaction->source_of_goods ?: '-',
            'purpose' => $transaction->purpose ?: '-',
        ];

        return $row;
    }

    private function applyRunningBalances(Collection $rows): Collection
    {
        $previousBalance = 0;
        $previousCostBalance = 0;

        return $rows->map(function (array $row) use (&$previousBalance, &$previousCostBalance): array {
            $incomingQuantity = (float) ($row['receipt_quantity'] ?? 0);
            $outgoingQuantity = (float) ($row['issuance_quantity'] ?? 0);
            $incomingCost = (float) ($row['receipt_cost'] ?? 0);
            $outgoingCost = (float) ($row['issuance_cost'] ?? 0);

            // Match the WIT stock-card formula:
            // Balance = previous visible balance + Incoming - Outgoing.
            $nextBalance = $previousBalance + $incomingQuantity - $outgoingQuantity;
            $nextCostBalance = $previousCostBalance + $incomingCost - $outgoingCost;

            $row['balance'] = $nextBalance;
            $row['balance_cost'] = $nextCostBalance;
            $previousBalance = $nextBalance;
            $previousCostBalance = $nextCostBalance;

            return $row;
        });
    }

    private function movementByMonth($transactions): array
    {
        return $transactions
            ->groupBy(fn (InventoryTransaction $transaction): string => Carbon::parse($transaction->transaction_date ?? $transaction->created_at)->format('M Y'))
            ->map(fn ($group, string $month): array => [
                'month' => $month,
                'receipts' => $group->where('type', 'receipt')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
                'issuances' => $group->where('type', 'release')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            ])
            ->sortBy(fn (array $row): int => Carbon::createFromFormat('M Y', $row['month'])->timestamp)
            ->values()
            ->take(12)
            ->all();
    }

    private function warehouseFlow($transactions): array
    {
        return $transactions
            ->groupBy(fn (InventoryTransaction $transaction): string => $transaction->batch?->warehouse?->display_name ?? 'Unknown Warehouse')
            ->map(fn ($group, string $warehouse): array => [
                'warehouse' => $warehouse,
                'receipts' => $group->where('type', 'receipt')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
                'issuances' => $group->where('type', 'release')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            ])
            ->sortByDesc(fn (array $row): float => $row['receipts'] + $row['issuances'])
            ->take(8)
            ->values()
            ->all();
    }

    private function itemFlow($transactions): array
    {
        return $transactions
            ->groupBy(fn (InventoryTransaction $transaction): string => $transaction->batch?->item?->name ?? 'Unknown Item')
            ->map(fn ($group, string $item): array => [
                'item' => $item,
                'receipts' => $group->where('type', 'receipt')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
                'issuances' => $group->where('type', 'release')->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            ])
            ->sortByDesc(fn (array $row): float => $row['receipts'] + $row['issuances'])
            ->take(8)
            ->values()
            ->all();
    }

    private function transactionCost(InventoryTransaction $transaction): float
    {
        return (float) ($transaction->total_cost ?? ((float) $transaction->quantity * (float) ($transaction->unit_cost ?? 0)));
    }

    private function hasActiveFilters(array $filters): bool
    {
        foreach (collect($filters)->except('transaction_year') as $value) {
            if (is_array($value) ? $value !== [] : $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function filterArray(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return collect($value)
            ->flatten()
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function currentStockpileSummary(array $filters): array
    {
        $batches = InventoryBatch::query()
            ->with(['item', 'transactions' => fn ($query) => $query->latest('transaction_date')->latest('id')])
            ->when($filters['warehouse_id'] !== [], fn ($query) => $query->whereIn('warehouse_id', $filters['warehouse_id']))
            ->when($filters['item_id'] !== [], fn ($query) => $query->whereIn('inventory_item_id', $filters['item_id']))
            ->when($filters['category'] !== [], fn ($query) => $query->whereHas('item', fn ($item) => $item->whereIn('category', $this->internalCategories($filters['category']))))
            ->when($filters['brand'] !== [], fn ($query) => $query->where(function ($query) use ($filters): void {
                $brands = collect($filters['brand']);
                $regular = $brands->reject(fn ($brand) => $this->isBlankBrandFilter($brand))->values();
                if ($regular->isNotEmpty()) {
                    $query->whereIn('brand_description', $regular);
                }
                if ($brands->contains(fn ($brand) => $this->isBlankBrandFilter($brand))) {
                    $query->orWhereNull('brand_description')
                        ->orWhere('brand_description', '')
                        ->orWhere('brand_description', 'Unspecified');
                }
            }))
            ->get();

        return [
            'quantity' => $batches->sum(fn (InventoryBatch $batch): float => (float) $batch->quantity),
            'cost' => $batches->sum(function (InventoryBatch $batch): float {
                $unitCost = (float) optional($batch->transactions->first())->unit_cost;
                return (float) $batch->quantity * $unitCost;
            }),
        ];
    }

    private function warehouseOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction) => $transaction->batch?->warehouse)
            ->filter()
            ->unique('id')
            ->sortBy('display_name')
            ->map(fn (Warehouse $warehouse): array => [
                'value' => (string) $warehouse->id,
                'label' => $warehouse->display_name,
            ])
            ->values();
    }

    private function warehouseProvinceOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction) => $transaction->batch?->warehouse?->province)
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $province): array => ['value' => $province, 'label' => $province])
            ->values();
    }

    private function warehouseMunicipalityOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction) => $transaction->batch?->warehouse?->municipality)
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $municipality): array => ['value' => $municipality, 'label' => $municipality])
            ->values();
    }

    private function warehouseProvinceOptionsFromWarehouses()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->whereNotNull('province')
            ->where('province', '<>', '')
            ->distinct()
            ->orderBy('province')
            ->pluck('province')
            ->map(fn (string $province): array => ['value' => $province, 'label' => $province])
            ->values();
    }

    private function warehouseMunicipalityOptionsFromWarehouses()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->whereNotNull('municipality')
            ->where('municipality', '<>', '')
            ->distinct()
            ->orderBy('municipality')
            ->pluck('municipality')
            ->map(fn (string $municipality): array => ['value' => $municipality, 'label' => $municipality])
            ->values();
    }

    private function itemOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction) => $transaction->batch?->item)
            ->filter()
            ->unique('name')
            ->sortBy('name')
            ->map(fn (InventoryItem $item): array => [
                'value' => (string) $item->name,
                'label' => (string) $item->name,
            ])
            ->values();
    }

    private function partnershipOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction): string => $transaction->batch?->warehouse?->partnership ?: 'Unspecified')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $partnership): array => ['value' => $partnership, 'label' => $partnership])
            ->values();
    }

    private function categoryOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction): string => $this->displayItemCategory($transaction->batch?->item))
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $category): array => [
                'value' => $category,
                'label' => $category,
            ])
            ->values();
    }

    private function brandOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction): string => $this->brandLabel($transaction->batch?->brand_description))
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $brand): array => ['value' => $brand, 'label' => $brand])
            ->values();
    }

    private function sourceOptionsFor($transactions)
    {
        return $transactions
            ->pluck('source_of_goods')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $source): array => ['value' => $source, 'label' => $source])
            ->values();
    }

    private function purposeOptionsFor($transactions)
    {
        return $transactions
            ->pluck('purpose')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $purpose): array => ['value' => $purpose, 'label' => $purpose])
            ->values();
    }

    private function senderRecipientOptionsFor($transactions)
    {
        return $transactions
            ->flatMap(fn (InventoryTransaction $transaction): array => [
                $transaction->sender_supplier,
                $transaction->recipient,
            ])
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $name): array => ['value' => $name, 'label' => $name])
            ->values();
    }

    private function filteredBalanceRows(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['warehouse_id'] !== [], fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => in_array((string) ($row['warehouse_id'] ?? ''), $filters['warehouse_id'], true)))
            ->when($filters['category'] !== [], fn (Collection $rows): Collection => $rows->filter(function (array $row) use ($filters): bool {
                $category = (string) ($row['category'] ?? '');
                return in_array($category, $filters['category'], true)
                    || in_array($category, array_map(fn (string $value): string => $this->displayCategory($value), $filters['category']), true);
            }))
            ->when($filters['partnership'] !== [], fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => in_array((string) ($row['partnership'] ?? ''), $filters['partnership'], true)))
            ->when($filters['item'] !== [], fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => in_array((string) ($row['item'] ?? ''), $filters['item'], true)))
            ->when($filters['brand'] !== [], fn (Collection $rows): Collection => $rows->filter(function (array $row) use ($filters): bool {
                $brand = $this->brandLabel($row['brand_description'] ?? null);
                return in_array($brand, $filters['brand'], true);
            }))
            ->when($filters['expiry'] !== [], fn (Collection $rows): Collection => $rows->filter(function (array $row) use ($filters): bool {
                $expiry = (string) ($row['expiry'] ?? 'N/A');
                return collect($filters['expiry'])->contains(fn (string $selected): bool => str_contains($expiry, $selected));
            }))
            ->values();
    }

    private function expiryOptionsFor($transactions)
    {
        return $transactions
            ->map(fn (InventoryTransaction $transaction): string => $transaction->batch?->expiration_date?->format('M Y') ?: 'N/A')
            ->filter()
            ->unique()
            ->sortBy(fn (string $expiry): int => $this->expirySortValue($expiry))
            ->map(fn (string $expiry): array => ['value' => $expiry, 'label' => $expiry])
            ->values();
    }

    private function applyExpiryFilter($query, array $expiries): void
    {
        $query->where(function ($query) use ($expiries): void {
            foreach ($expiries as $expiry) {
                if ($expiry === 'N/A') {
                    $query->orWhereNull('expiration_date');
                    continue;
                }

                try {
                    $date = Carbon::createFromFormat('M Y', $expiry)->startOfMonth();
                    $query->orWhere(function ($query) use ($date): void {
                        $query->whereYear('expiration_date', $date->year)
                            ->whereMonth('expiration_date', $date->month);
                    });
                } catch (\Throwable) {
                    $query->orWhereRaw("strftime('%m/%Y', expiration_date) = ?", [$expiry]);
                }
            }
        });
    }

    private function expirySortValue(string $expiry): int
    {
        if ($expiry === 'N/A') {
            return PHP_INT_MAX;
        }

        try {
            return Carbon::createFromFormat('M Y', $expiry)->timestamp;
        } catch (\Throwable) {
            return PHP_INT_MAX - 1;
        }
    }

    private function internalCategories(array $categories): array
    {
        return collect($categories)
            ->map(fn (string $category): string => match ($category) {
                'Food Items' => 'food',
                'Non Food Items', 'Non-Food Items' => 'non_food',
                default => $category,
            })
            ->unique()
            ->values()
            ->all();
    }

    private function sheetTransactionSummary(string $type, ?int $year = null): ?array
    {
        $sheetImports = WarehouseSheetImport::query()
            ->where('import_status', 'imported')
            ->get()
            ->when($year, fn (Collection $imports): Collection => $imports->filter(function (WarehouseSheetImport $import) use ($year): bool {
                $date = data_get($import->raw_payload, 'transaction_date');

                if (! $date) {
                    return false;
                }

                try {
                    return Carbon::parse($date)->year === $year;
                } catch (\Throwable) {
                    return false;
                }
            })->values());

        if ($sheetImports->isEmpty()) {
            return null;
        }

        $quantityKey = $type === 'release' ? 'issuance' : 'receipt';
        $costKey = $type === 'release' ? 'issuance_cost' : 'receipt_cost';

        return [
            'quantity' => $sheetImports->sum(fn (WarehouseSheetImport $import): float => $this->sheetNumber($import, $quantityKey)),
            'cost' => $sheetImports->sum(fn (WarehouseSheetImport $import): float => $this->sheetNumber($import, $costKey)),
        ];
    }

    private function sheetNumber(WarehouseSheetImport $import, string $key): float
    {
        $value = data_get($import->raw_payload, $key, 0);

        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace([',', '₱', 'PHP', ' '], '', (string) $value);
    }

    private function transactionYearOptions(int $currentYear): Collection
    {
        return InventoryTransaction::query()
            ->whereNotNull('transaction_date')
            ->pluck('transaction_date')
            ->map(fn ($date): int => Carbon::parse($date)->year)
            ->push($currentYear)
            ->unique()
            ->sortDesc()
            ->values();
    }

    private function displayItemCategory(?\App\Models\InventoryItem $item): string
    {
        if ($item?->name && str_contains(strtolower($item->name), 'family food pack')) {
            return 'Family Food Pack';
        }

        return $this->displayCategory($item?->category);
    }

    private function displayCategory(?string $category): string
    {
        return match ($category) {
            'food' => 'Food Items',
            'non_food' => 'Non Food Items',
            default => (string) ($category ?: 'Uncategorized'),
        };
    }

    private function brandLabel(?string $brand): string
    {
        $brand = trim((string) $brand);

        return $this->isBlankBrandFilter($brand) ? '-' : $brand;
    }

    private function isBlankBrandFilter(?string $brand): bool
    {
        $brand = trim((string) $brand);

        return $brand === '' || $brand === '-' || strcasecmp($brand, 'Unspecified') === 0;
    }
}
