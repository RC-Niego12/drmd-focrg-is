<?php

namespace App\Services;

use App\Models\StandbyFund;
use App\Models\Warehouse;

class StandbyStockpileSummaryService
{
    public function __construct(private readonly InventoryBalanceService $balances) {}

    public function current(): array
    {
        // The official standby summary must reproduce WIT. Pending system-only
        // dispatch transactions remain visible in inventory reconciliation, but
        // are excluded here until they have a WIT counterpart.
        $rows = $this->balances->balanceRows(includeSystemOnly: false);
        $standbyFund = StandbyFund::current()->fresh('updater:id,name');
        $ffpRows = $rows->filter(fn (array $row): bool => str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack'));
        $nonFfpRows = $rows->reject(fn (array $row): bool => str_contains(strtolower((string) ($row['item'] ?? '')), 'family food pack'));
        $warehouses = Warehouse::query()->whereIn('id', $ffpRows->pluck('warehouse_id')->filter()->unique())->get()->keyBy('id');
        $ffpQuantity = $ffpRows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0));
        $ffpCost = $ffpRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0));
        $otherBreakdown = $nonFfpRows->groupBy(fn (array $row): string => trim((string) ($row['category'] ?? '')) ?: 'Unspecified Category')
            ->map(fn ($group): float => $group->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)))->sortKeys();
        $otherCost = $otherBreakdown->sum();

        return [
            'office' => $standbyFund->office,
            'standby_funds' => (float) $standbyFund->amount,
            'source' => $standbyFund->source,
            'synced_at' => $standbyFund->synced_at?->toDateTimeString(),
            'updated_by' => $standbyFund->updater?->name,
            'ffp_quantity' => $ffpQuantity,
            'ffp_cost' => $ffpCost,
            'other_food_non_food_cost' => $otherCost,
            'total_standby_funds_stockpile' => (float) $standbyFund->amount + $ffpCost + $otherCost,
            'ffp_breakdown' => $ffpRows->groupBy(fn (array $row): string => trim((string) $warehouses->get($row['warehouse_id'] ?? null)?->warehouse_type) ?: 'Unspecified')
                ->map(fn ($group, string $type): array => ['warehouse_type' => $type, 'current' => $group->sum('current_balance'), 'cost' => $group->sum('cost')])->values()->all(),
            'other_breakdown' => $otherBreakdown->map(fn (float $cost, string $label): array => compact('label', 'cost'))->values()->all(),
        ];
    }
}
