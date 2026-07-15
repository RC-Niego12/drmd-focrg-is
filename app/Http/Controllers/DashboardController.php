<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\DispatchPlan;
use App\Models\DistributionPlan;
use App\Models\DromicReport;
use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Models\StandbyFund;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\InventoryBalanceService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __invoke(InventoryBalanceService $inventoryBalanceService): Response
    {
        $user = request()->user();
        $role = $this->dashboardRole($user);
        $isSuperAdmin = $role === 'Super Admin';
        $isRros = $role === 'RROS';
        $isDrrs = $role === 'DRRS';
        $isDrmdAa = $role === 'DRMD AA';
        $isDrims = $role === 'DRIMS';
        $isFinancialAnalyst = $role === 'DRMD Financial Analyst';

        if ($isDrmdAa) {
            return Inertia::render('Dashboard/Empty', ['dashboardRole' => $role]);
        }
        $canViewInventoryDashboard = $isRros || $isDrrs || $isSuperAdmin || $isFinancialAnalyst;

        $warehouseStatus = Warehouse::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $inventoryBalanceRows = $inventoryBalanceService->balanceRows();
        $inventoryBalances = $inventoryBalanceService->summary($inventoryBalanceRows);
        $warehouseBalances = $inventoryBalanceService->warehouseBalances();
        $totalIssuances = $canViewInventoryDashboard ? $this->transactionSummary('release') : ['quantity' => null, 'cost' => null];
        $totalReceipts = $canViewInventoryDashboard ? $this->transactionSummary('receipt') : ['quantity' => null, 'cost' => null];
        $drrsRequestQuery = AssistanceRequest::query();

        if ($isDrrs) {
            $drrsRequestQuery->where('encoded_by', $user->id);
        }

        return Inertia::render('Dashboard/Index', [
            'dashboardRole' => $role,
            'visibleSections' => [
                'inventory' => $isRros || $isDrrs || $isSuperAdmin,
                'warehouses' => $isRros || $isDrrs || $isDrims || $isSuperAdmin,
                'requests' => $isRros || $isDrrs || $isDrims || $isSuperAdmin,
                'nearExpiry' => $isRros || $isDrrs,
                'dispatch' => $isRros || $isDrrs || $isDrims,
                'dromic' => $isDrims,
                'users' => $isSuperAdmin,
                'audit' => $isSuperAdmin,
                'standbyFunds' => $isSuperAdmin || $isFinancialAnalyst,
            ],
            'metrics' => [
                'inventory_total' => $canViewInventoryDashboard ? $inventoryBalances['current_balance'] : null,
                'stockpile_cost' => $canViewInventoryDashboard ? $inventoryBalances['cost'] : null,
                'food_inventory' => $canViewInventoryDashboard ? $inventoryBalances['food_current_balance'] : null,
                'non_food_inventory' => $canViewInventoryDashboard ? $inventoryBalances['non_food_current_balance'] : null,
                'total_releases' => $totalIssuances['quantity'],
                'total_releases_cost' => $totalIssuances['cost'],
                'total_items_received' => $totalReceipts['quantity'],
                'total_items_received_cost' => $totalReceipts['cost'],
                'warehouses' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::count() : null,
                'active_warehouses' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? (int) ($warehouseStatus['active'] ?? 0) : null,
                'inactive_warehouses' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? (int) ($warehouseStatus['inactive'] ?? 0) : null,
                'pending_requests' => (clone $drrsRequestQuery)->whereIn('status', ['endorsed', 'submitted', 'under_review'])->count(),
                'approved_requests' => (clone $drrsRequestQuery)->whereIn('status', ['approved', 'partially_approved'])->count(),
                'released_requests' => (clone $drrsRequestQuery)->where('status', 'released')->count(),
                'near_expiry_items' => $isRros || $isDrrs ? InventoryBatch::nearExpiry()->count() : null,
                'dispatches' => $isRros || $isDrrs || $isDrims ? DispatchPlan::count() : null,
                'distribution_plans' => $isRros || $isDrrs ? DistributionPlan::count() : null,
                'dromic_reports' => $isDrims ? DromicReport::count() : null,
                'users' => $isSuperAdmin ? User::count() : null,
                'pending_user_access' => $isSuperAdmin ? User::where('access_status', 'pending')->count() : null,
                'audit_events_today' => $isSuperAdmin ? AuditLog::whereDate('created_at', today())->count() : null,
            ],
            'warehouseInventory' => $canViewInventoryDashboard ? $warehouseBalances : [],
            'familyFoodPackMap' => $canViewInventoryDashboard ? $this->familyFoodPackMap($inventoryBalanceRows) : [],
            'familyFoodPackDashboard' => $canViewInventoryDashboard ? $this->familyFoodPackDashboard($inventoryBalanceRows) : null,
            'nearExpirySummary' => $isRros || $isDrrs || $isSuperAdmin ? $this->nearExpirySummary($inventoryBalanceRows) : [],
            'standbyStockpileSummary' => $canViewInventoryDashboard ? $this->standbyStockpileSummary($inventoryBalanceRows) : null,
            'foodItemSummaries' => $canViewInventoryDashboard ? [
                'rtef' => $this->foodItemWarehouseSummary($inventoryBalanceRows, 'rtef'),
                'bottled_water' => $this->foodItemWarehouseSummary($inventoryBalanceRows, 'bottled_water'),
                'non_food_items' => $this->foodItemWarehouseSummary($inventoryBalanceRows, 'non_food_items'),
                'other_non_food_items' => $this->foodItemWarehouseSummary($inventoryBalanceRows, 'other_non_food_items'),
                'indirect_raw_materials' => $this->foodItemWarehouseSummary($inventoryBalanceRows, 'indirect_raw_materials'),
            ] : [],
            'recentTransactions' => $canViewInventoryDashboard ? InventoryTransaction::with(['batch.item', 'batch.warehouse', 'user'])->latest()->limit(10)->get() : [],
            'nearExpiry' => $isRros || $isDrrs ? InventoryBatch::with(['item', 'warehouse'])->nearExpiry()->orderBy('expiration_date')->limit(10)->get() : [],
            'requestTrend' => (clone $drrsRequestQuery)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'categoryBreakdown' => $canViewInventoryDashboard ? [
                'Food Items' => $inventoryBalances['food_current_balance'],
                'Non Food Items' => $inventoryBalances['non_food_current_balance'],
            ] : [],
            'warehouseBreakdown' => [
                'status' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? $warehouseStatus : [],
                'type' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::query()->selectRaw("coalesce(nullif(warehouse_type, ''), 'Unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(6)->pluck('total', 'label') : [],
                'province' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::query()->selectRaw("coalesce(nullif(province, ''), 'Unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(6)->pluck('total', 'label') : [],
                'partnership' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::query()->selectRaw("coalesce(nullif(partnership, ''), 'Unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(6)->pluck('total', 'label') : [],
                'category' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::query()->selectRaw("coalesce(nullif(category, ''), 'Unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(6)->pluck('total', 'label') : [],
                'distribution_network' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? Warehouse::query()->selectRaw("coalesce(nullif(distribution_network, ''), 'Unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(6)->pluck('total', 'label') : [],
            ],
            'warehouseSummary' => $isRros || $isDrrs || $isDrims || $isSuperAdmin ? $this->warehouseSummary() : null,
            'userLevelCounts' => $isSuperAdmin ? $this->userLevelCounts() : [],
            'accessSummary' => $isSuperAdmin ? User::query()->selectRaw('access_status, count(*) as total')->groupBy('access_status')->pluck('total', 'access_status') : [],
            'recentAuditLogs' => $isSuperAdmin ? AuditLog::with('user:id,name,office')->latest()->limit(8)->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => str($log->event)->replace(['.', '_'], ' ')->title()->toString(),
                'user' => $log->user?->name ?? 'System',
                'office' => $log->user?->office,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]) : [],
            'dromicTrend' => $isDrims ? DromicReport::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : [],
        ]);
    }

    private function dashboardRole(User $user): string
    {
        foreach (['Super Admin', 'RROS', 'DRRS', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'] as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return 'User';
    }

    private function transactionSummary(string $type): array
    {
        $sheetImports = WarehouseSheetImport::query()
            ->where('import_status', 'imported')
            ->get();

        if ($sheetImports->isNotEmpty()) {
            $quantityKey = $type === 'release' ? 'issuance' : 'receipt';
            $costKey = $type === 'release' ? 'issuance_cost' : 'receipt_cost';

            return [
                'quantity' => $sheetImports->sum(fn (WarehouseSheetImport $import): float => $this->sheetNumber($import, $quantityKey)),
                'cost' => $sheetImports->sum(fn (WarehouseSheetImport $import): float => $this->sheetNumber($import, $costKey)),
            ];
        }

        $transactions = InventoryTransaction::query()
            ->where('type', $type)
            ->get(['quantity', 'unit_cost', 'total_cost']);

        return [
            'quantity' => $transactions->sum(fn (InventoryTransaction $transaction): float => (float) $transaction->quantity),
            'cost' => $transactions->sum(fn (InventoryTransaction $transaction): float => (float) ($transaction->total_cost ?? ((float) $transaction->quantity * (float) $transaction->unit_cost))),
        ];
    }

    private function userLevelCounts()
    {
        return Role::query()
            ->whereIn('name', ['Super Admin', 'RROS', 'DRRS', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'])
            ->withCount('users')
            ->orderByRaw("case name when 'Super Admin' then 0 when 'RROS' then 1 when 'DRRS' then 2 when 'DRIMS' then 3 when 'DRMD AA' then 4 when 'DRMD Financial Analyst' then 5 else 6 end")
            ->get()
            ->map(fn (Role $role): array => [
                'role' => $role->name,
                'total' => $role->users_count,
            ]);
    }

    private function warehouseSummary(): array
    {
        $warehouses = Warehouse::query()->get();

        return [
            'total' => $warehouses->count(),
            'active' => $warehouses->where('status', 'active')->count(),
            'inactive' => $warehouses->where('status', 'inactive')->count(),
            'distribution_networks' => $this->warehouseSummaryBy($warehouses, 'distribution_network'),
            'warehouse_types' => $this->warehouseSummaryBy($warehouses, 'warehouse_type'),
            'categories' => $this->warehouseSummaryBy($warehouses, 'category'),
            'partnerships' => $this->warehouseSummaryBy($warehouses, 'partnership'),
        ];
    }

    private function warehouseSummaryBy($warehouses, string $field): array
    {
        return $warehouses
            ->groupBy(fn (Warehouse $warehouse): string => trim((string) data_get($warehouse, $field)) ?: 'Unspecified')
            ->map(fn ($group, string $label): array => [
                'label' => $label,
                'total' => $group->count(),
                'active' => $group->where('status', 'active')->count(),
                'inactive' => $group->where('status', 'inactive')->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function familyFoodPackMap($inventoryBalanceRows)
    {
        $warehouseIds = $inventoryBalanceRows
            ->filter(fn (array $row): bool => str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack'))
            ->pluck('warehouse_id')
            ->filter()
            ->unique()
            ->values();

        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->keyBy('id');

        return $inventoryBalanceRows
            ->filter(fn (array $row): bool => str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack'))
            ->groupBy('warehouse_id')
            ->map(function ($rows, $warehouseId) use ($warehouses): ?array {
                $warehouse = $warehouses->get($warehouseId);

                if (! $warehouse) {
                    return null;
                }

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'province' => $warehouse->province,
                    'municipality' => $warehouse->municipality,
                    'latitude' => (float) $warehouse->latitude,
                    'longitude' => (float) $warehouse->longitude,
                    'current_stockpile' => $rows->sum(fn (array $row): float => (float) $row['current_balance']),
                ];
            })
            ->filter()
            ->sortByDesc('current_stockpile')
            ->values();
    }

    private function familyFoodPackDashboard($inventoryBalanceRows): array
    {
        $ffpRows = $inventoryBalanceRows
            ->filter(fn (array $row): bool => str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack'));
        $warehouseIds = $ffpRows->pluck('warehouse_id')->filter()->unique()->values();
        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->get()
            ->keyBy('id');
        $provinceRows = collect(['Agusan Del Norte', 'Agusan Del Sur', 'Dinagat Islands', 'Surigao Del Norte', 'Surigao Del Sur'])
            ->map(function (string $province) use ($ffpRows, $warehouses): array {
                $rows = $ffpRows->filter(fn (array $row): bool => $warehouses->get($row['warehouse_id'])?->province === $province);

                return [
                    'province' => $province,
                    'capacity' => (float) Warehouse::where('province', $province)->sum('ffp_capacity'),
                    'current' => $rows->sum(fn (array $row): float => (float) $row['current_balance']),
                    'cost' => $rows->sum(fn (array $row): float => (float) $row['cost']),
                ];
            })
            ->values();

        $callouts = $ffpRows
            ->groupBy(function (array $row) use ($warehouses): string {
                $warehouse = $warehouses->get($row['warehouse_id']);
                $province = $warehouse?->province ?: 'Unspecified';
                $district = strtoupper(preg_replace('/\s+/', '', (string) $warehouse?->district));

                if ($province === 'Surigao Del Norte') {
                    return $district === 'SDN1' ? 'Surigao del Norte - Siargao' : 'Surigao del Norte - Mainland';
                }

                return match ($province) {
                    'Dinagat Islands' => 'Province of Dinagat Islands',
                    default => $province,
                };
            })
            ->map(fn ($rows, string $label): array => [
                'label' => $label,
                'current' => $rows->sum(fn (array $row): float => (float) $row['current_balance']),
            ])
            ->values();

        return [
            'total_current' => $provinceRows->sum('current'),
            'total_capacity' => $provinceRows->sum('capacity'),
            'total_cost' => $provinceRows->sum('cost'),
            'province_rows' => $provinceRows,
            'callouts' => $callouts,
        ];
    }

    private function nearExpirySummary($inventoryBalanceRows): array
    {
        return $inventoryBalanceRows
            ->filter(fn (array $row): bool => (float) ($row['current_balance'] ?? 0) > 0 && $this->hasExpiry($row['expiry'] ?? null))
            ->map(function (array $row): array {
                $expiryMonth = trim(explode(',', (string) ($row['expiry'] ?? ''))[0] ?? '');

                try {
                    $expiry = CarbonImmutable::createFromFormat('M Y', $expiryMonth)->endOfMonth();
                } catch (\Throwable) {
                    $expiry = CarbonImmutable::parse($expiryMonth)->endOfMonth();
                    $expiryMonth = $expiry->format('M Y');
                }

                return [
                    'id' => md5(implode('|', [$row['warehouse_id'] ?? '', $row['category'] ?? '', $row['item'] ?? '', $row['brand_description'] ?? '', $expiryMonth])),
                    'expiry_month' => $expiryMonth,
                    'expiration_date' => $expiry->toDateString(),
                    'warehouse' => $row['warehouse'] ?? '-',
                    'category' => $row['category'] ?? '-',
                    'item' => $row['item'] ?? '-',
                    'brand' => $row['brand_description'] ?? '-',
                    'quantity' => (float) ($row['current_balance'] ?? 0),
                    'cost' => (float) ($row['cost'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function hasExpiry(?string $expiry): bool
    {
        $expiry = trim((string) $expiry);

        return $expiry !== '' && strtoupper($expiry) !== 'N/A';
    }

    private function standbyStockpileSummary($inventoryBalanceRows): array
    {
        $standbyFund = StandbyFund::current()->fresh('updater:id,name');
        $ffpRows = $inventoryBalanceRows
            ->filter(fn (array $row): bool => $this->isFamilyFoodPackRow($row));
        $nonFfpRows = $inventoryBalanceRows
            ->reject(fn (array $row): bool => $this->isFamilyFoodPackRow($row));
        $warehouseIds = $ffpRows->pluck('warehouse_id')->filter()->unique()->values();
        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->get()
            ->keyBy('id');
        $ffpQuantity = $ffpRows->sum(fn (array $row): float => (float) $row['current_balance']);
        $ffpCost = $ffpRows->sum(fn (array $row): float => (float) $row['cost']);
        $otherBreakdown = $nonFfpRows
            ->groupBy(fn (array $row): string => $this->displayStockpileCategory($row))
            ->map(fn ($rows, string $label): float => $rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)))
            ->sortKeys();

        $otherFoodAndNfiCost = $otherBreakdown->sum();
        $warehouseTypeRows = $ffpRows
            ->groupBy(function (array $row) use ($warehouses): string {
                $warehouseType = trim((string) $warehouses->get($row['warehouse_id'])?->warehouse_type);

                return $warehouseType !== '' ? $warehouseType : 'Unspecified';
            })
            ->map(fn ($rows, string $warehouseType): array => [
                'warehouse_type' => $warehouseType,
                'current' => $rows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                'cost' => $rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
            ])
            ->sortByDesc('current')
            ->values()
            ->all();

        return [
            'office' => $standbyFund->office,
            'standby_funds' => (float) $standbyFund->amount,
            'source' => $standbyFund->source,
            'synced_at' => $standbyFund->synced_at?->toDateTimeString(),
            'updated_by' => $standbyFund->updater?->name,
            'ffp_quantity' => $ffpQuantity,
            'ffp_cost' => $ffpCost,
            'other_food_non_food_cost' => $otherFoodAndNfiCost,
            'total_standby_funds_stockpile' => (float) $standbyFund->amount + $ffpCost + $otherFoodAndNfiCost,
            'ffp_breakdown' => $warehouseTypeRows,
            'other_breakdown' => $otherBreakdown
                ->map(fn (float $cost, string $label): array => ['label' => $label, 'cost' => $cost])
                ->values()
                ->all(),
        ];
    }

    private function isFamilyFoodPackRow(array $row): bool
    {
        return str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack');
    }

    private function displayStockpileCategory(array $row): string
    {
        $category = trim((string) ($row['category'] ?? ''));

        return $category !== '' ? $category : 'Unspecified Category';
    }

    private function foodItemWarehouseSummary($inventoryBalanceRows, string $type): array
    {
        $groupByItem = in_array($type, ['non_food_items', 'other_non_food_items', 'indirect_raw_materials'], true);
        $rows = $inventoryBalanceRows
            ->filter(function (array $row) use ($type): bool {
                $category = strtolower((string) ($row['category'] ?? ''));
                $item = strtolower((string) ($row['item'] ?? ''));
                $brand = strtolower((string) ($row['brand_description'] ?? ''));

                return match ($type) {
                    'rtef' => str_contains($item, 'ready to eat') || str_contains($item, 'rtef'),
                    'bottled_water' => (str_contains($item, 'water') && str_contains($brand, 'bottled')) || str_contains($item, 'bottled water'),
                    'non_food_items' => str_contains($category, 'non food item'),
                    'other_non_food_items' => str_contains($category, 'other nfi') || str_contains($category, 'other non food'),
                    'indirect_raw_materials' => str_contains($category, 'indirect')
                        || str_contains($category, 'raw')
                        || str_contains($category, 'material')
                        || str_contains($item, 'rice')
                        || str_contains($brand, 'rice'),
                    default => false,
                };
            });

        if ($type === 'indirect_raw_materials') {
            $rows = $rows->concat($this->rawMaterialImportRows());
        }

        $warehouses = $groupByItem
            ? collect()
            : Warehouse::query()
            ->whereIn('id', $rows->pluck('warehouse_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return [
            'group_by' => $groupByItem ? 'item' : 'warehouse',
            'show_category' => $type === 'indirect_raw_materials',
            'total' => $rows->sum(fn (array $row): float => (float) ($row['available_balance'] ?? $row['current_balance'] ?? 0)),
            'cost' => $rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
            'rows' => $groupByItem ? $this->itemSummaryRows($rows, $type === 'indirect_raw_materials') : $rows
                ->groupBy('warehouse_id')
                ->map(function ($group, $warehouseId) use ($warehouses): array {
                    $warehouse = $warehouses->get($warehouseId);

                    return [
                        'warehouse' => $warehouse?->name ?? $group->first()['warehouse'] ?? 'Unspecified warehouse',
                        'address' => $this->warehouseLocation($warehouse),
                        'available' => $group->sum(fn (array $row): float => (float) ($row['available_balance'] ?? $row['current_balance'] ?? 0)),
                        'cost' => $group->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                    ];
                })
                ->filter(fn (array $row): bool => (float) $row['available'] > 0)
                ->sortBy('warehouse')
                ->values()
                ->all(),
        ];
    }

    private function itemSummaryRows($rows, bool $includeZero = false): array
    {
        return $rows
            ->groupBy(fn (array $row): string => implode('|', [
                $row['category'] ?? '',
                $row['item'] ?? '',
                $row['brand_description'] ?? '',
            ]))
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'category' => $first['category'] ?? 'Unspecified',
                    'item' => $first['item'] ?? 'Unspecified item',
                    'description' => $first['brand_description'] ?? $first['category'] ?? 'Unspecified',
                    'available' => $group->sum(fn (array $row): float => (float) ($row['available_balance'] ?? $row['current_balance'] ?? 0)),
                    'cost' => $group->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                ];
            })
            ->filter(fn (array $row): bool => $includeZero || (float) $row['available'] > 0)
            ->sortBy('item')
            ->values()
            ->all();
    }

    private function rawMaterialImportRows()
    {
        return WarehouseSheetImport::query()
            ->where('import_status', 'imported')
            ->get()
            ->filter(function (WarehouseSheetImport $import): bool {
                $category = strtolower((string) ($import->raw_payload['item_category'] ?? ''));

                return str_contains($category, 'raw');
            })
            ->map(fn (WarehouseSheetImport $import): array => [
                'category' => $import->raw_payload['item_category'] ?? 'Raw Materials',
                'warehouse' => $import->warehouse?->name,
                'warehouse_id' => $import->warehouse_id,
                'item' => $import->raw_payload['item'] ?? 'Unspecified item',
                'brand_description' => filled($import->raw_payload['brand_description'] ?? null)
                    ? $import->raw_payload['brand_description']
                    : 'Unspecified',
                'current_balance' => $this->sheetNumber($import, 'receipt') - $this->sheetNumber($import, 'issuance'),
                'available_balance' => $this->sheetNumber($import, 'receipt') - $this->sheetNumber($import, 'issuance'),
                'cost' => $this->sheetNumber($import, 'receipt_cost') - $this->sheetNumber($import, 'issuance_cost'),
            ]);
    }

    private function sheetNumber(WarehouseSheetImport $import, string $key): float
    {
        return (float) str_replace([',', ' '], '', (string) ($import->raw_payload[$key] ?? 0));
    }

    private function warehouseLocation(?Warehouse $warehouse): string
    {
        if (! $warehouse) {
            return 'Unspecified location';
        }

        return collect([
            $warehouse->barangay_name,
            $warehouse->municipality,
            $warehouse->province,
        ])
            ->filter(fn ($value): bool => filled($value))
            ->implode(', ') ?: 'Unspecified location';
    }

}
