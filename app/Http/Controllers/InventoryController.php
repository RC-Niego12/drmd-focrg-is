<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryItemRequest;
use App\Models\FniLibraryItem;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceService;
use App\Services\WarehouseSheetImportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryBalanceService $inventoryBalances): Response
    {
        $currentYear = (int) now()->year;
        $transactionYearInput = $request->input('transaction_year', 'all');
        $transactionYear = $transactionYearInput === 'all' || $transactionYearInput === ''
            ? null
            : (int) $transactionYearInput;
        $warehouses = Warehouse::where('status', 'active')->orderBy('name')->get();
        $warehouseIds = $this->filterArray($request, 'warehouse_id');
        $selectedWarehouse = count($warehouseIds) === 1
            ? $warehouses->firstWhere('id', (int) $warehouseIds[0])
            : null;

        $batches = InventoryBatch::with(['item', 'transactions', 'warehouse'])
            ->when($warehouseIds !== [], fn ($query) => $query->whereIn('warehouse_id', $warehouseIds))
            ->orderBy('warehouse_id')
            ->orderBy('expiration_date')
            ->get();

        $transactions = InventoryTransaction::with(['batch.item', 'batch.warehouse'])
            ->when($warehouseIds !== [], fn ($query) => $query->whereHas('batch', fn ($batchQuery) => $batchQuery->whereIn('warehouse_id', $warehouseIds)))
            ->get();

        $batchBalances = $transactions
            ->groupBy('inventory_batch_id')
            ->map(fn ($group): float => $group->sum(fn (InventoryTransaction $transaction): float => $this->signedQuantity($transaction)));

        $allBalanceRows = $inventoryBalances->balanceRows(null, $transactionYear);

        $activeFilters = [
            'warehouse_id' => $warehouseIds,
            'warehouse_province' => $this->filterArray($request, 'warehouse_province'),
            'warehouse_municipality' => $this->filterArray($request, 'warehouse_municipality'),
            'category' => $this->filterArray($request, 'category'),
            'item' => $this->filterArray($request, 'item'),
            'brand' => $this->filterArray($request, 'brand'),
            'partnership' => $this->filterArray($request, 'partnership'),
            'expiry' => $this->filterArray($request, 'expiry'),
            'transaction_year' => $transactionYearInput === '' ? 'all' : $transactionYearInput,
            'search' => $request->string('search')->trim()->toString(),
        ];

        $filterRows = function ($rows, ?string $except = null) use ($activeFilters) {
            return $rows
                ->when($except !== 'warehouse_id' && $activeFilters['warehouse_id'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) ($row['warehouse_id'] ?? ''), $activeFilters['warehouse_id'], true)))
                ->when($except !== 'warehouse_province' && $activeFilters['warehouse_province'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) ($row['warehouse_province'] ?? ''), $activeFilters['warehouse_province'], true)))
                ->when($except !== 'warehouse_municipality' && $activeFilters['warehouse_municipality'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) ($row['warehouse_municipality'] ?? ''), $activeFilters['warehouse_municipality'], true)))
                ->when($except !== 'category' && $activeFilters['category'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) $row['category'], $activeFilters['category'], true)))
                ->when($except !== 'item' && $activeFilters['item'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) $row['item'], $activeFilters['item'], true)))
                ->when($except !== 'brand' && $activeFilters['brand'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) $row['brand_description'], $activeFilters['brand'], true)))
                ->when($except !== 'partnership' && $activeFilters['partnership'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => in_array((string) ($row['partnership'] ?? ''), $activeFilters['partnership'], true)))
                ->when($except !== 'expiry' && $activeFilters['expiry'] !== [], fn ($rows) => $rows->filter(fn (array $row): bool => collect($activeFilters['expiry'])->contains(fn (string $expiry): bool => str_contains((string) $row['expiry'], $expiry))))
                ->when($except !== 'search' && $activeFilters['search'] !== '', fn ($rows) => $rows->filter(function (array $row) use ($activeFilters): bool {
                    $haystack = strtolower(implode(' ', [
                        $row['warehouse'] ?? '',
                        $row['warehouse_province'] ?? '',
                        $row['warehouse_municipality'] ?? '',
                        $row['category'] ?? '',
                        $row['item'] ?? '',
                        $row['brand_description'] ?? '',
                        $row['expiry'] ?? '',
                        $row['current_balance'] ?? '',
                        $row['cost'] ?? '',
                    ]));

                    return str_contains($haystack, strtolower($activeFilters['search']));
                }));
        };

        $sortKey = $this->inventorySortKey($request->string('sort')->toString());
        $sortDirection = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $balanceRows = $this->sortBalanceRows($filterRows($allBalanceRows), $sortKey, $sortDirection);

        $warehouseOptions = $filterRows($allBalanceRows, 'warehouse_id');
        $categoryOptions = $filterRows($allBalanceRows, 'category');
        $itemOptions = $filterRows($allBalanceRows, 'item');
        $brandOptions = $filterRows($allBalanceRows, 'brand');
        $expiryOptions = $filterRows($allBalanceRows, 'expiry');

        return Inertia::render('Inventory/Index', [
            'warehouses' => $warehouses,
            'selectedWarehouse' => $selectedWarehouse,
            'balanceRows' => $balanceRows,
            'categoryReferenceRows' => $this->categoryReferenceRows($transactionYear),
            'batches' => $batches->map(fn (InventoryBatch $batch) => [
                'id' => $batch->id,
                'label' => trim(($batch->item?->name ?? 'Item').' - '.($batch->brand_description ?: $batch->batch_number)),
                'item' => $batch->item?->name,
                'warehouse' => $batch->warehouse?->display_name ?? $batch->warehouse?->name,
                'quantity' => $batchBalances->get($batch->id, 0),
                'available_quantity' => max(0, $batchBalances->get($batch->id, 0) - (float) $batch->reserved_quantity),
                'expiration_date' => $batch->expiration_date?->toDateString(),
            ]),
            'items' => InventoryItem::where('status', 'active')->orderBy('name')->get(),
            'fniLibraryItems' => FniLibraryItem::query()->orderBy('item_category')->orderBy('item_name')->get(),
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
            'filters' => array_merge([
                'sort' => $sortKey,
                'direction' => $sortDirection,
            ], $activeFilters),
            'filterOptions' => [
                'warehouses' => $warehouseOptions
                    ->map(fn (array $row): array => [
                        'value' => (string) $row['warehouse_id'],
                        'label' => (string) $row['warehouse'],
                    ])
                    ->unique('value')
                    ->sortBy('label')
                    ->values(),
                'warehouse_provinces' => $filterRows($allBalanceRows, 'warehouse_province')
                    ->pluck('warehouse_province')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
                'warehouse_municipalities' => $filterRows($allBalanceRows, 'warehouse_municipality')
                    ->pluck('warehouse_municipality')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
                'categories' => $categoryOptions->pluck('category')->filter()->unique()->sort()->values(),
                'items' => $itemOptions->pluck('item')->filter()->unique()->sort()->values(),
                'brands' => $brandOptions->pluck('brand_description')->filter()->unique()->sort()->values(),
                'partnerships' => $filterRows($allBalanceRows, 'partnership')
                    ->pluck('partnership')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
                'expiries' => $expiryOptions
                    ->flatMap(fn (array $row) => array_map('trim', explode(',', $row['expiry'])))
                    ->filter()
                    ->unique()
                    ->sortBy(fn (string $expiry): int => $this->expirySortValue($expiry))
                    ->values(),
                'transaction_years' => $this->transactionYearOptions($currentYear),
            ],
            'sync' => [
                'url' => config('services.google_sheets.url'),
                'worksheet' => config('services.google_sheets.worksheet'),
                'last_synced_at' => WarehouseSheetImport::max('updated_at'),
                'imported_rows' => WarehouseSheetImport::where('import_status', 'imported')->count(),
            ],
        ]);
    }

    private function categoryReferenceRows(?int $year = null): array
    {
        return WarehouseSheetImport::with(['warehouse', 'batch.item'])
            ->where('import_status', 'imported')
            ->get()
            ->when($year, fn ($imports) => $imports->filter(function (WarehouseSheetImport $import) use ($year): bool {
                $date = trim((string) (($import->raw_payload['transaction_date'] ?? null)
                    ?: ($import->raw_payload['receipt_date'] ?? null)
                    ?: ($import->raw_payload['issuance_date'] ?? null)
                    ?: ''));

                if ($date === '') {
                    return true;
                }

                try {
                    return Carbon::parse($date)->year === $year;
                } catch (\Throwable) {
                    return true;
                }
            }))
            ->map(fn (WarehouseSheetImport $import): array => [
                'category' => $import->raw_payload['item_category'] ?? $this->displayCategory($import->batch?->item?->category),
                'warehouse' => $import->warehouse?->display_name,
                'warehouse_id' => $import->warehouse_id,
                'warehouse_province' => $import->warehouse?->province,
                'warehouse_municipality' => $import->warehouse?->municipality,
                'warehouse_district' => $import->warehouse?->district,
                'warehouse_type' => $import->warehouse?->warehouse_type,
                'warehouse_status' => $import->warehouse?->status,
                'warehouse_ffp_capacity' => (float) ($import->warehouse?->ffp_capacity ?? 0),
                'warehouse_rtef_capacity' => (float) ($import->warehouse?->rtef_capacity ?? 0),
                'partnership' => $import->warehouse?->partnership,
                'item' => $import->raw_payload['item'] ?? $import->batch?->item?->name,
                'uom' => $import->raw_payload['uom'] ?? $import->batch?->item?->unit,
                'brand_description' => $this->brandDescription($import->raw_payload['brand_description'] ?? null, $import->batch),
                'expiry' => $this->importExpiryLabel($import),
                'expiry_sort' => $this->expirySortValue($this->importExpiryLabel($import)),
                'current_balance' => $this->signedImportQuantity($import),
                'reserved_quantity' => 0,
                'available_balance' => max(0, $this->signedImportQuantity($import)),
                'cost' => max(0, $this->signedImportCost($import)),
            ])
            ->values()
            ->all();
    }

    public function syncGoogleSheet(Request $request, WarehouseSheetImportService $importer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageInventory($request->user()), 403);

        $url = config('services.google_sheets.url');

        if (! $url) {
            return back()->with('error', 'Google Sheet URL is not configured.');
        }

        try {
            $summary = $importer->import($url, config('services.google_sheets.worksheet'));
            $audit->log('inventory.google_sheet_synced', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Google Sheet sync failed. Please try again.');
        }

        return back()->with(
            'success',
            "Sync complete: {$summary['rows_imported']} imported, {$summary['rows_skipped']} skipped, {$summary['receipts']} receipts, {$summary['issuances']} issuances, {$summary['errors']} errors."
        );
    }

    public function receipt(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageInventory($request->user()), 403);

        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'item_name' => ['required_without:inventory_item_id', 'nullable', 'string', 'max:255'],
            'category' => ['required_without:inventory_item_id', 'nullable', 'in:food,non_food'],
            'unit' => ['required_without:inventory_item_id', 'nullable', 'string', 'max:50'],
            'brand_description' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date'],
            'source_of_goods' => ['nullable', 'string', 'max:255'],
            'sender_supplier' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'expiration_date' => ['nullable', 'date'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $batch = DB::transaction(function () use ($data) {
            $item = isset($data['inventory_item_id']) && $data['inventory_item_id']
                ? InventoryItem::findOrFail($data['inventory_item_id'])
                : InventoryItem::firstOrCreate(
                    ['name' => $data['item_name'], 'unit' => $data['unit']],
                    ['category' => $data['category'], 'description' => $data['brand_description'] ?? null, 'status' => 'active']
                );

            $batch = InventoryBatch::firstOrCreate(
                [
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'batch_number' => $this->batchNumber($item->name, $data['brand_description'] ?? null, $data['expiration_date'] ?? null),
                ],
                [
                    'brand_description' => $data['brand_description'] ?? null,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'expiration_date' => $data['expiration_date'] ?? null,
                    'date_received' => $data['transaction_date'],
                    'source' => $data['source_of_goods'] ?? null,
                    'current_status' => 'available',
                ]
            );

            $batch->transactions()->create([
                'user_id' => auth()->id(),
                'type' => 'receipt',
                'transaction_date' => $data['transaction_date'],
                'source_of_goods' => $data['source_of_goods'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'sender_supplier' => $data['sender_supplier'] ?? null,
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? null,
                'total_cost' => isset($data['unit_cost']) ? (float) $data['quantity'] * (float) $data['unit_cost'] : null,
                'balance_after' => (float) $batch->quantity + (float) $data['quantity'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $batch->update([
                'quantity' => $this->ledgerQuantity($batch),
            ]);

            $freshBatch = $batch->fresh();

            return $freshBatch;
        });

        $audit->log('inventory.receipt.created', $batch);

        return back()->with('success', 'Receipt recorded and stockpile updated.');
    }

    public function release(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageInventory($request->user()), 403);

        $data = $request->validate([
            'inventory_batch_id' => ['required', 'exists:inventory_batches,id'],
            'transaction_date' => ['required', 'date'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'ris_if_stf' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'recipient' => ['required', 'string', 'max:255'],
            'delivery_site' => ['nullable', 'string', 'max:255'],
            'expected_delivery_date' => ['nullable', 'date'],
            'land_transportation_type' => ['nullable', 'string', 'max:255'],
            'land_transportation_source' => ['nullable', 'string', 'max:255'],
            'plate' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:255'],
            'sea_transportation_type' => ['nullable', 'string', 'max:255'],
            'sea_transportation_source' => ['nullable', 'string', 'max:255'],
            'sea_transportation_details' => ['nullable', 'string', 'max:255'],
            'air_transportation_type' => ['nullable', 'string', 'max:255'],
            'air_transportation_source' => ['nullable', 'string', 'max:255'],
            'air_transportation_details' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $batch = DB::transaction(function () use ($data) {
            $batch = InventoryBatch::lockForUpdate()->findOrFail($data['inventory_batch_id']);

            $transactionBalance = $batch->transactions()
                ->get()
                ->sum(fn (InventoryTransaction $transaction): float => $this->signedQuantity($transaction));

            if ((float) $transactionBalance - (float) $batch->reserved_quantity < (float) $data['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => 'Issuance quantity exceeds available stock.',
                ]);
            }

            $batch->transactions()->create([
                'user_id' => auth()->id(),
                'type' => 'release',
                'transaction_date' => $data['transaction_date'],
                'purpose' => $data['purpose'] ?? null,
                'ris_if_stf' => $data['ris_if_stf'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'quantity' => $data['quantity'],
                'balance_after' => $transactionBalance - (float) $data['quantity'],
                'recipient' => $data['recipient'],
                'delivery_site' => $data['delivery_site'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'transport_details' => [
                    'land' => [
                        'type' => $data['land_transportation_type'] ?? null,
                        'source' => $data['land_transportation_source'] ?? null,
                        'plate' => $data['plate'] ?? null,
                        'driver' => $data['driver'] ?? null,
                        'contact_number' => $data['contact_number'] ?? null,
                    ],
                    'sea' => [
                        'type' => $data['sea_transportation_type'] ?? null,
                        'source' => $data['sea_transportation_source'] ?? null,
                        'details' => $data['sea_transportation_details'] ?? null,
                    ],
                    'air' => [
                        'type' => $data['air_transportation_type'] ?? null,
                        'source' => $data['air_transportation_source'] ?? null,
                        'details' => $data['air_transportation_details'] ?? null,
                    ],
                ],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $batch->update([
                'quantity' => $this->ledgerQuantity($batch),
            ]);

            $freshBatch = $batch->fresh();

            return $freshBatch;
        });

        $audit->log('inventory.release.created', $batch);

        return back()->with('success', 'Issuance recorded and stockpile updated.');
    }

    public function store(InventoryItemRequest $request, AuditLogger $audit): RedirectResponse
    {
        $item = InventoryItem::create($request->validated());
        $audit->log('inventory.item.created', $item, [], $item->toArray());

        return back()->with('success', 'Inventory item created.');
    }

    public function update(InventoryItemRequest $request, InventoryItem $inventory, AuditLogger $audit): RedirectResponse
    {
        $old = $inventory->toArray();
        $inventory->update($request->validated());
        $audit->log('inventory.item.updated', $inventory, $old, $inventory->fresh()->toArray());

        return back()->with('success', 'Inventory item updated.');
    }

    public function batches(): Response
    {
        return Inertia::render('Inventory/Batches', [
            'batches' => InventoryBatch::with(['item', 'warehouse'])->latest()->paginate(20),
            'nearExpiry' => InventoryBatch::with(['item', 'warehouse'])->nearExpiry()->orderBy('expiration_date')->get(),
        ]);
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

    private function transactionYearOptions(int $currentYear)
    {
        $years = InventoryTransaction::query()
            ->whereNotNull('transaction_date')
            ->pluck('transaction_date')
            ->map(fn ($date): int => Carbon::parse($date)->year)
            ->push($currentYear)
            ->unique()
            ->sortDesc()
            ->values();

        return collect([
            ['value' => 'all', 'label' => 'All Years'],
        ])
            ->concat($years->map(fn (int $year): array => ['value' => (string) $year, 'label' => (string) $year]))
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

    private function batchNumber(string $itemName, ?string $brandDescription, ?string $expirationDate): string
    {
        return str($itemName.'-'.($brandDescription ?: 'standard').'-'.($expirationDate ?: 'no-expiry'))
            ->slug()
            ->limit(255, '')
            ->toString();
    }

    private function signedQuantity(InventoryTransaction $transaction): float
    {
        return $transaction->type === 'release'
            ? -1 * (float) $transaction->quantity
            : (float) $transaction->quantity;
    }

    private function signedCost(InventoryTransaction $transaction): float
    {
        $amount = (float) ($transaction->total_cost ?? ((float) $transaction->quantity * (float) $transaction->unit_cost));

        return $transaction->type === 'release' ? -1 * $amount : $amount;
    }

    private function ledgerQuantity(InventoryBatch $batch): float
    {
        return $batch->transactions()
            ->get()
            ->sum(fn (InventoryTransaction $transaction): float => $this->signedQuantity($transaction));
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

    private function payloadNumber(WarehouseSheetImport $import, string $key): float
    {
        return (float) str_replace([',', ' '], '', (string) ($import->raw_payload[$key] ?? 0));
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

    private function inventorySortKey(string $sort): string
    {
        return in_array($sort, ['warehouse', 'category', 'item', 'brand', 'expiry', 'current_balance', 'cost'], true)
            ? $sort
            : 'warehouse';
    }

    private function sortBalanceRows($rows, string $sortKey, string $direction)
    {
        $descending = $direction === 'desc';

        return $rows
            ->sort(function (array $left, array $right) use ($sortKey, $descending): int {
                $primary = $this->compareSortValues($this->sortValue($left, $sortKey), $this->sortValue($right, $sortKey));

                if ($primary !== 0) {
                    return $descending ? -1 * $primary : $primary;
                }

                foreach (['warehouse', 'category', 'item', 'brand'] as $tieBreaker) {
                    $comparison = $this->compareSortValues($this->sortValue($left, $tieBreaker), $this->sortValue($right, $tieBreaker));

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->values();
    }

    private function compareSortValues(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    private function sortValue(array $row, string $sortKey): mixed
    {
        return match ($sortKey) {
            'warehouse' => strtolower((string) ($row['warehouse'] ?? '')),
            'category' => strtolower((string) ($row['category'] ?? '')),
            'item' => strtolower((string) ($row['item'] ?? '')),
            'brand' => strtolower((string) ($row['brand_description'] ?? '')),
            'expiry' => (int) ($row['expiry_sort'] ?? PHP_INT_MAX),
            'current_balance' => (float) ($row['current_balance'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
            default => strtolower((string) ($row['warehouse'] ?? '')),
        };
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

    private function canManageInventory(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA'])
            || $user->can('manage inventory');
    }
}
