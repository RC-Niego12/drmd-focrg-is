<?php

namespace App\Http\Controllers;

use App\Models\DistributionPlan;
use App\Models\InventoryBatch;
use App\Models\OperationalLibraryValue;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DistributionPlanController extends Controller
{
    public function index(InventoryBalanceService $inventoryBalances): Response
    {
        $batches = InventoryBatch::with(['item', 'warehouse', 'transactions' => fn ($query) => $query->latest('transaction_date')])
            ->where('quantity', '>', 0)
            ->orderBy('expiration_date')
            ->get();

        $expiryRows = $inventoryBalances->balanceRows()
            ->filter(fn (array $row): bool => (float) ($row['current_balance'] ?? 0) > 0 && $this->hasExpiry($row['expiry'] ?? null))
            ->map(fn (array $row): array => $this->expiryRowFromBalance($row))
            ->values();

        $ageingMonths = $expiryRows
            ->pluck('expiry_month')
            ->filter()
            ->unique()
            ->sortBy(fn (string $month): int => CarbonImmutable::parse('01 '.$month)->timestamp)
            ->values();

        return Inertia::render('Inventory/NearExpiry', [
            'monitoring' => [
                'expiryRows' => $expiryRows,
                'ageingRows' => $expiryRows->map(fn (array $row): array => $this->ageingRowFromExpiry($row, $ageingMonths->all()))->values(),
                'ageingMonths' => $ageingMonths,
                'expiryBuckets' => $expiryRows->groupBy('status')->map->sum('quantity'),
                'itemBreakdown' => $expiryRows->groupBy('item')->map->sum('quantity')->sortDesc()->take(10),
                'warehouseBreakdown' => $expiryRows->groupBy('warehouse')->map->sum('quantity')->sortDesc()->take(10),
                'filters' => [
                    'categories' => $expiryRows->pluck('category')->filter()->unique()->sort()->values(),
                    'items' => $expiryRows->pluck('item')->filter()->unique()->sort()->values(),
                    'brands' => $expiryRows->pluck('brand')->filter()->unique()->sort()->values(),
                    'warehouses' => $expiryRows->pluck('warehouse')->filter()->unique()->sort()->values(),
                    'statuses' => $expiryRows->pluck('status')->filter()->unique()->values(),
                ],
            ],
            'nearExpiry' => InventoryBatch::with(['item', 'warehouse'])->nearExpiry()->orderBy('expiration_date')->paginate(15),
            'plans' => DistributionPlan::with(['batch.item', 'batch.warehouse'])->latest()->paginate(15),
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
        ]);
    }

    private function expiryRowFromBalance(array $row): array
    {
        $expiryMonth = trim(explode(',', (string) ($row['expiry'] ?? ''))[0] ?? '');
        try {
            $expiry = CarbonImmutable::createFromFormat('M Y', $expiryMonth)->endOfMonth();
        } catch (\Throwable) {
            $expiry = CarbonImmutable::parse($expiryMonth)->endOfMonth();
            $expiryMonth = $expiry->format('M Y');
        }
        $quantity = (float) ($row['current_balance'] ?? 0);
        $cost = (float) ($row['cost'] ?? 0);

        return [
            'id' => md5(implode('|', [$row['warehouse_id'] ?? '', $row['category'] ?? '', $row['item'] ?? '', $row['brand_description'] ?? '', $expiryMonth])),
            'status' => $this->expiryStatus($expiry),
            'status_months' => round(now()->floatDiffInMonths($expiry, false), 2),
            'expiry_month' => $expiryMonth,
            'expiration_date' => $expiry->toDateString(),
            'warehouse' => $row['warehouse'] ?? '-',
            'warehouse_id' => $row['warehouse_id'] ?? null,
            'partnership' => $row['partnership'] ?? '-',
            'category' => $row['category'] ?? '-',
            'item' => $row['item'] ?? '-',
            'brand' => $this->brandLabel($row['brand_description'] ?? null),
            'quantity' => $quantity,
            'unit_cost' => $quantity > 0 ? $cost / $quantity : 0,
            'cost' => $cost,
        ];
    }

    private function ageingRowFromExpiry(array $expiryRow, array $months): array
    {
        $row = [
            'id' => $expiryRow['id'],
            'warehouse' => $expiryRow['warehouse'],
            'warehouse_id' => $expiryRow['warehouse_id'],
            'partnership' => $expiryRow['partnership'] ?? '-',
            'category' => $expiryRow['category'],
            'item' => $expiryRow['item'],
            'brand' => $expiryRow['brand'],
            'total' => (float) $expiryRow['quantity'],
            'cost' => (float) $expiryRow['cost'],
            'months' => [],
        ];

        foreach ($months as $month) {
            $row['months'][$month] = $expiryRow['expiry_month'] === $month ? (float) $expiryRow['quantity'] : 0;
        }

        return $row;
    }

    private function hasExpiry(?string $expiry): bool
    {
        $expiry = trim((string) $expiry);

        return $expiry !== '' && strtoupper($expiry) !== 'N/A';
    }

    private function expiryStatus(CarbonImmutable $expiry): string
    {
        $months = now()->floatDiffInMonths($expiry, false);

        return match (true) {
            $months <= 1 => 'expiring within the month / expired',
            $months < 2 => 'expiring less than 2 months month',
            $months < 4 => 'expiring within 2-3 months',
            $months < 6 => 'expiring within 4-5 months',
            default => 'expiring within 6 months and up',
        };
    }

    private function brandLabel(?string $brand): string
    {
        $brand = trim((string) $brand);

        return $brand === '' || strcasecmp($brand, 'Unspecified') === 0 ? '-' : $brand;
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('manage near expiry'), 403);

        $data = $request->validate([
            'inventory_batch_id' => ['nullable', 'exists:inventory_batches,id'],
            'request_id' => ['nullable', 'exists:requests,id'],
            'program_type' => ['nullable', 'in:food_for_work,non_food_for_work,relief_distribution,other'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'activity' => ['nullable', 'string', 'max:255'],
            'activity_date' => ['nullable', 'date'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'status' => ['required', 'in:for_distribution,scheduled,distributed,cancelled'],
            'remarks' => ['nullable', 'string'],
        ]);

        $plan = DistributionPlan::create([...$data, 'assigned_to' => auth()->id()]);
        $audit->log('distribution_plan.created', $plan, [], $plan->toArray());

        return back()->with('success', 'Distribution plan saved.');
    }
}
