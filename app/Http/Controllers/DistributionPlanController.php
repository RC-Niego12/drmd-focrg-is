<?php

namespace App\Http\Controllers;

use App\Models\DistributionPlan;
use App\Models\InventoryBatch;
use App\Models\OperationalLibraryValue;
use App\Models\RequestParty;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceService;
use App\Services\RequestPartySheetService;
use App\Services\SotexPlanningService;
use App\Services\SotexRecipientLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DistributionPlanController extends Controller
{
    public function index(InventoryBalanceService $inventoryBalances): Response
    {
        return $this->renderNearExpiry($inventoryBalances);
    }

    /**
     * @param  list<int>|null  $warehouseIds
     * @param  array<string, mixed>  $viewProps
     */
    public function renderNearExpiry(
        InventoryBalanceService $inventoryBalances,
        ?array $warehouseIds = null,
        array $viewProps = [],
        ?SotexPlanningService $sotex = null,
    ): Response {
        $includePlans = $viewProps['include_plans'] ?? true;
        $scopedIds = $warehouseIds === null ? null : collect($warehouseIds)->map(fn ($id): int => (int) $id);

        $expiryRows = $inventoryBalances->netExpiryBalances(
            $inventoryBalances->balanceRows()->when(
                $scopedIds !== null,
                fn ($rows) => $rows->filter(fn (array $row): bool => $scopedIds->contains((int) ($row['warehouse_id'] ?? 0))),
            ),
        )
            ->map(fn (array $row): array => $this->expiryRowFromBalance($row))
            ->values();

        $ageingMonths = $expiryRows
            ->pluck('expiry_month')
            ->filter()
            ->unique()
            ->sortBy(fn (string $month): int => CarbonImmutable::parse('01 '.$month)->timestamp)
            ->values();

        $nearExpiryQuery = InventoryBatch::with(['item', 'warehouse'])
            ->nearExpiry()
            ->when(
                $scopedIds !== null,
                fn ($query) => $query->whereIn('warehouse_id', $scopedIds->all()),
            )
            ->orderBy('expiration_date');

        $sotexPayload = $includePlans
            ? $this->sotexPayload($sotex ?? app(SotexPlanningService::class), $expiryRows)
            : ['activities' => [], 'stock' => [], 'plans' => [], 'recipients' => []];

        return Inertia::render('Inventory/NearExpiry', array_merge([
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
            'nearExpiry' => $nearExpiryQuery->paginate(15),
            'plans' => ['data' => $sotexPayload['plans']],
            'sotex' => $sotexPayload,
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
            'canManageSotex' => $includePlans && self::canPlan(auth()->user()),
            'workspace' => 'rros',
        ], $viewProps));
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
            'district' => $row['warehouse_district'] ?? '-',
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

    public function store(Request $request, AuditLogger $audit, InventoryBalanceService $balances, SotexPlanningService $sotex): RedirectResponse
    {
        $this->authorizePlanner($request);
        $input = $this->validatedProposal($request);
        foreach (SotexPlanningService::ACTUAL_DATES as $field) {
            $input[$field] = null;
        }
        foreach ($input['sources'] as $index => $source) {
            $input['sources'][$index]['released_quantity'] = 0;
        }
        $input['progress_status'] = $sotex->progressForInput($input);
        $error = $this->proposalError($balances, $sotex, $input['sources']);
        if ($error !== null) {
            return back()->withErrors(['allocated_quantity' => $error])->withInput();
        }

        $proposalKey = (string) Str::uuid();
        DB::transaction(function () use ($request, $audit, $sotex, $input, $proposalKey): void {
            foreach ($input['sources'] as $source) {
                $warehouse = Warehouse::query()->findOrFail($source['warehouse_id']);
                $plan = DistributionPlan::create([
                    ...$sotex->planAttributes($this->lineInput($input, $source), $warehouse),
                    'proposal_key' => $proposalKey,
                    'assigned_to' => $request->user()->id,
                ]);
                $audit->log('distribution_plan.created', $plan, [], $plan->toArray());
            }
        });

        return back()->with('success', 'SoTEx proposal saved. The stockpile is unchanged; release still goes through RIS, dispatch, and delivery.');
    }

    public function update(Request $request, DistributionPlan $distributionPlan, AuditLogger $audit, InventoryBalanceService $balances, SotexPlanningService $sotex): RedirectResponse
    {
        $this->authorizePlanner($request);
        $input = $this->validatedProposal($request);
        $group = $this->proposalLines($distributionPlan);
        $outOfOrder = $sotex->actualOrderErrors($input);
        if ($outOfOrder !== []) {
            return back()->withErrors($outOfOrder)->withInput();
        }
        $releasing = collect($input['sources'])->contains(fn (array $source): bool => (float) ($source['released_quantity'] ?? 0) > 0);
        $missing = $sotex->missingActualStages($input);
        if ($releasing && $missing !== []) {
            return back()->withErrors(['released_quantity' => 'Fill in every actual date before recording a release. Still missing: '.implode(', ', $missing).'.'])->withInput();
        }
        $input['progress_status'] = $sotex->progressForInput($input);
        $error = $this->proposalError($balances, $sotex, $input['sources'], $group);
        if ($error !== null) {
            return back()->withErrors(['allocated_quantity' => $error])->withInput();
        }

        $proposalKey = $distributionPlan->proposal_key ?: (string) Str::uuid();
        DB::transaction(function () use ($request, $audit, $sotex, $input, $group, $proposalKey): void {
            $remaining = $group->keyBy('id');
            foreach ($input['sources'] as $source) {
                $warehouse = Warehouse::query()->findOrFail($source['warehouse_id']);
                $attributes = [
                    ...$sotex->planAttributes($this->lineInput($input, $source), $warehouse),
                    'proposal_key' => $proposalKey,
                ];
                $sourceKey = $sotex->stockKey($source['warehouse_id'], (string) $source['item_name'], (string) ($source['brand'] ?? ''), (string) $source['expiry_month']);
                $match = $remaining->first(fn (DistributionPlan $plan): bool => $sotex->stockKey($plan->warehouse_id, (string) $plan->item_name, (string) $plan->brand, (string) $plan->expiry_month) === $sourceKey);
                if ($match instanceof DistributionPlan) {
                    $before = $match->toArray();
                    $match->update($attributes);
                    $audit->log('distribution_plan.updated', $match, $before, $match->fresh()->toArray());
                    $remaining->forget($match->id);
                    continue;
                }

                $plan = DistributionPlan::create([
                    ...$attributes,
                    'assigned_to' => $request->user()->id,
                ]);
                $audit->log('distribution_plan.created', $plan, [], $plan->toArray());
            }

            foreach ($remaining as $plan) {
                $before = $plan->toArray();
                $plan->delete();
                $audit->log('distribution_plan.deleted', $plan, $before, []);
            }
        });

        return back()->with('success', 'SoTEx proposal updated. The stockpile is unchanged.');
    }

    public function storeRecipient(Request $request, AuditLogger $audit, SotexRecipientLibrary $library): RedirectResponse|JsonResponse
    {
        $this->authorizePlanner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
        ]);
        $name = trim((string) $data['name']);

        $lgu = $library->matchingLgu($name);
        if ($lgu !== null) {
            throw ValidationException::withMessages([
                'name' => "LGUs come from the LGU directory. Pick \"{$lgu}\" from the list instead.",
            ]);
        }

        $value = $library->add($name, trim((string) ($data['office'] ?? '')));
        if ($value->wasRecentlyCreated) {
            $audit->log('operational_library.created', $value, [], $value->toArray());
        }
        if ($value->wasRecentlyCreated || $value->wasChanged()) {
            $library->announceChanged($value->value);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'recipient' => [
                    'name' => $value->value,
                    'details' => trim((string) data_get($value->metadata, 'office')),
                    'level' => '',
                ],
                'recipients' => $library->options(),
            ], $value->wasRecentlyCreated ? 201 : 200);
        }

        return back();
    }

    public function recipientOptions(Request $request, SotexRecipientLibrary $library): JsonResponse
    {
        $this->authorizePlanner($request);

        return response()->json(['recipients' => $library->options()]);
    }

    public function destroy(Request $request, DistributionPlan $distributionPlan, AuditLogger $audit): RedirectResponse
    {
        $this->authorizePlanner($request);
        foreach ($this->proposalLines($distributionPlan) as $plan) {
            $before = $plan->toArray();
            $plan->delete();
            $audit->log('distribution_plan.deleted', $plan, $before, []);
        }

        return back()->with('success', 'SoTEx proposal removed. The stockpile was not changed.');
    }

    private function authorizePlanner(Request $request): void
    {
        abort_unless(self::canPlan($request->user()), 403);
    }

    /** Only DRRS records and updates SoTEx proposals; every other role with page access is view-only. */
    public static function canPlan(?User $user): bool
    {
        return $user !== null && $user->hasRole('DRRS');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProposal(Request $request): array
    {
        if (! $request->exists('sources') && $request->filled('warehouse_id')) {
            $request->merge([
                'sources' => [[
                    'warehouse_id' => $request->input('warehouse_id'),
                    'item_name' => $request->input('item_name'),
                    'brand' => $request->input('brand'),
                    'expiry_month' => $request->input('expiry_month'),
                    'allocated_quantity' => $request->input('allocated_quantity'),
                    'released_quantity' => $request->input('released_quantity'),
                ]],
            ]);
        }

        $cleared = [];
        foreach (SotexPlanningService::NOT_APPLICABLE as $flag => $fields) {
            $notApplicable = $request->boolean($flag);
            $cleared[$flag] = $notApplicable;
            foreach ($fields as $field) {
                if ($notApplicable || ! $request->filled($field)) {
                    $cleared[$field] = null;
                }
            }
        }
        $request->merge($cleared);

        $rules = [
            'lgu' => ['required', 'string', 'max:255'],
            'activity' => ['required', 'string', Rule::in([...SotexPlanningService::ACTIVITIES, 'Food for Work'])],
            'remarks' => ['nullable', 'string'],
            'delivery_mode' => ['nullable', 'string', 'required_unless:delivery_mode_na,true', Rule::in(SotexPlanningService::DELIVERY_MODES)],
            'delivery_mode_na' => ['boolean'],
            'compliance_target_na' => ['boolean'],
            'delivery_target_na' => ['boolean'],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'sources.*.item_name' => ['required', 'string', 'max:255'],
            'sources.*.brand' => ['nullable', 'string', 'max:255'],
            'sources.*.expiry_month' => ['required', 'string', 'max:32'],
            'sources.*.allocated_quantity' => ['required', 'numeric', 'min:0.01'],
            'sources.*.released_quantity' => ['nullable', 'numeric', 'min:0'],
            ...collect(SotexPlanningService::PROPOSAL_DATES)->mapWithKeys(fn (string $field): array => [
                $field => ['nullable', 'date'],
            ])->all(),
        ];

        foreach (SotexPlanningService::TARGET_RANGE_ENDS as $start => $end) {
            if ($start === 'delivery_target_on') {
                continue;
            }
            $rules[$start][] = 'required_with:'.$end;
            $rules[$end] = ['nullable', 'date', 'after_or_equal:'.$start];
        }

        foreach (SotexPlanningService::ACTUAL_RANGE_ENDS as $start => $end) {
            $rules[$start][] = 'required_with:'.$end;
            $rules[$end] = ['nullable', 'date', 'after_or_equal:'.$start];
        }

        $rules['compliance_target_on'] = ['nullable', 'date', 'required_unless:compliance_target_na,true'];
        $rules['delivery_target_on'] = ['nullable', 'date', 'required_unless:delivery_target_na,true'];
        $rules['delivery_target_end_on'] = ['nullable', 'date', 'required_unless:delivery_target_na,true', 'after_or_equal:delivery_target_on'];

        return $request->validate($rules, [
            'compliance_target_on.required_unless' => 'Enter the compliance target date, or mark it N/A.',
            'delivery_target_on.required_unless' => 'Enter the delivery date range, or mark it N/A.',
            'delivery_target_end_on.required_unless' => 'Enter the delivery date range, or mark it N/A.',
            'delivery_target_end_on.after_or_equal' => 'The delivery end date must be on or after the start date.',
            'delivery_mode.required_unless' => 'Select who delivers, or mark delivery N/A.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function lineInput(array $input, array $source): array
    {
        return [
            ...collect($input)->except('sources')->all(),
            'item_name' => $source['item_name'],
            'brand' => $source['brand'] ?? null,
            'expiry_month' => $source['expiry_month'],
            'allocated_quantity' => $source['allocated_quantity'],
            'released_quantity' => $source['released_quantity'] ?? 0,
        ];
    }

    /**
     * @return Collection<int, DistributionPlan>
     */
    private function proposalLines(DistributionPlan $plan): Collection
    {
        if (! filled($plan->proposal_key)) {
            return collect([$plan]);
        }

        return DistributionPlan::query()
            ->where('proposal_key', $plan->proposal_key)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  Collection<int, DistributionPlan>|null  $group
     */
    private function proposalError(InventoryBalanceService $balances, SotexPlanningService $sotex, array $sources, ?Collection $group = null): ?string
    {
        $expiryRows = $balances->netExpiryBalances($balances->balanceRows())
            ->map(fn (array $row): array => $this->expiryRowFromBalance($row));
        $plans = DistributionPlan::with(['batch.item'])->get();
        $presented = $sotex->present($expiryRows, $plans);
        $credits = [];
        foreach ($group ?? [] as $plan) {
            if ($plan->warehouse_id === null) {
                continue;
            }
            $key = $sotex->stockKey($plan->warehouse_id, (string) $plan->item_name, (string) $plan->brand, (string) $plan->expiry_month);
            $credits[$key] = ($credits[$key] ?? 0) + $sotex->openQuantity($plan);
        }

        $seen = [];
        foreach ($sources as $source) {
            $key = $sotex->stockKey($source['warehouse_id'], (string) $source['item_name'], (string) ($source['brand'] ?? ''), (string) $source['expiry_month']);
            if (isset($seen[$key])) {
                return 'Each warehouse line can appear only once on a proposal.';
            }
            $seen[$key] = true;
            $error = $sotex->allocationError(
                $presented['stock'],
                $key,
                (float) $source['allocated_quantity'],
                (float) ($source['released_quantity'] ?? 0),
                (float) ($credits[$key] ?? 0),
                array_key_exists($key, $credits) ? $key : null,
            );
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $expiryRows
     * @return array{activities: list<string>, stock: list<array<string, mixed>>, plans: list<array<string, mixed>>, recipients: list<array<string, mixed>>}
     */
    private function sotexPayload(SotexPlanningService $sotex, $expiryRows): array
    {
        $plans = DistributionPlan::with(['batch.item', 'batch.warehouse', 'warehouse'])->latest('id')->get();

        return [
            ...$sotex->present($expiryRows, $plans),
            'recipients' => $this->recipients(),
        ];
    }

    /**
     * LGUs from the LGU directory followed by the SoTEx recipient library (DSWD offices, NGAs, NGOs).
     *
     * @return list<array{name: string, details: string, level: string}>
     */
    private function recipients(): array
    {
        if (! app()->runningUnitTests() && RequestParty::query()->where('is_active', true)->doesntExist()) {
            try {
                app(RequestPartySheetService::class)->sync();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return app(SotexRecipientLibrary::class)->options();
    }
}
