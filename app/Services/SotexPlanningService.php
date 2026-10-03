<?php

namespace App\Services;

use App\Models\DistributionPlan;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SotexPlanningService
{
    public const ACTIVITIES = [
        'Food-for-Work',
        'Food-for-Training',
        'Non-Food-for-Work',
        'Relief Distribution',
    ];

    public const DELIVERY_MODES = [
        'c/o LGU',
        'c/o RROS',
    ];

    public const PROGRESS = [
        "Awaiting LGU's Submission",
        'Approved Proposal',
        'Ongoing Activity',
        'SoTEx Partially Delivered',
        'SoTEx Fully Delivered',
        'Activity Completed',
        'SoTEx Partially Distributed',
        'SoTEx Fully Distributed',
    ];

    public const TARGET_DATES = [
        'proposal_target_on',
        'compliance_target_on',
        'work_schedule_target_on',
        'delivery_target_on',
        'distribution_target_on',
    ];

    /** Start field => end field. These three targets are ranges; the other targets stay a single date. */
    public const TARGET_RANGE_ENDS = [
        'work_schedule_target_on' => 'work_schedule_target_end_on',
        'delivery_target_on' => 'delivery_target_end_on',
        'distribution_target_on' => 'distribution_target_end_on',
    ];

    /** Flag => fields cleared when the selector is marked not applicable. */
    public const NOT_APPLICABLE = [
        'compliance_target_na' => ['compliance_target_on', 'compliance_on'],
        'delivery_target_na' => ['delivery_target_on', 'delivery_target_end_on', 'delivered_on', 'delivered_end_on'],
        'delivery_mode_na' => ['delivery_mode'],
    ];

    /** Start field => end field for the actual dates recorded as ranges. */
    public const ACTUAL_RANGE_ENDS = [
        'work_schedule_on' => 'work_schedule_end_on',
        'delivered_on' => 'delivered_end_on',
        'distributed_on' => 'distributed_end_on',
    ];

    public const ACTUAL_DATES = [
        'proposal_received_on',
        'proposal_reviewed_on',
        'compliance_on',
        'proposal_approved_on',
        'work_schedule_on',
        'work_schedule_end_on',
        'delivered_on',
        'delivered_end_on',
        'distributed_on',
        'distributed_end_on',
    ];

    /** Stage label => [actual date fields, flag that marks the stage not applicable]. */
    public const ACTUAL_STAGES = [
        'Receipt of proposal' => [['proposal_received_on'], null],
        'Review' => [['proposal_reviewed_on'], null],
        'LGU compliance on findings' => [['compliance_on'], 'compliance_target_na'],
        'Approval of proposal' => [['proposal_approved_on'], null],
        'Work schedule' => [['work_schedule_on', 'work_schedule_end_on'], null],
        'Delivery of items' => [['delivered_on', 'delivered_end_on'], 'delivery_target_na'],
        'Distribution of items' => [['distributed_on', 'distributed_end_on'], null],
    ];

    /**
     * Actual date => [earlier actual date it depends on, flag that waives it].
     * The work schedule has no entry: an activity may start, or finish, while the proposal is still
     * under review or revision. Delivery and distribution move stockpile goods, so they need approval.
     */
    public const ACTUAL_PREREQUISITES = [
        'proposal_reviewed_on' => [['proposal_received_on', null]],
        'compliance_on' => [['proposal_reviewed_on', null]],
        'proposal_approved_on' => [['proposal_reviewed_on', null], ['compliance_on', 'compliance_target_na']],
        'delivered_on' => [['proposal_approved_on', null]],
        'distributed_on' => [['proposal_approved_on', null], ['delivered_on', 'delivery_target_na']],
        'distributed_end_on' => [['delivered_end_on', 'delivery_target_na']],
    ];

    public const ACTUAL_LABELS = [
        'proposal_received_on' => 'receipt of proposal',
        'proposal_reviewed_on' => 'review',
        'compliance_on' => 'LGU compliance on findings',
        'proposal_approved_on' => 'approval of proposal',
        'delivered_on' => 'delivery start',
        'delivered_end_on' => 'delivery end',
        'distributed_on' => 'distribution start',
        'distributed_end_on' => 'distribution end',
    ];

    public const PROPOSAL_DATES = [
        'proposal_target_on',
        'proposal_received_on',
        'proposal_reviewed_on',
        'compliance_on',
        'compliance_target_on',
        'proposal_approved_on',
        'work_schedule_target_on',
        'work_schedule_target_end_on',
        'work_schedule_on',
        'work_schedule_end_on',
        'delivery_target_on',
        'delivery_target_end_on',
        'delivered_on',
        'delivered_end_on',
        'distribution_target_on',
        'distribution_target_end_on',
        'distributed_on',
        'distributed_end_on',
    ];

    /**
     * @param  Collection<int, array<string, mixed>>  $expiryRows
     * @param  Collection<int, DistributionPlan>  $plans
     * @return array{activities: list<string>, stock: list<array<string, mixed>>, plans: list<array<string, mixed>>}
     */
    public function present(Collection $expiryRows, Collection $plans): array
    {
        $openByKey = [];
        foreach ($plans as $plan) {
            $key = $this->stockKey(
                $plan->warehouse_id,
                (string) ($plan->item_name ?: $plan->batch?->item?->name),
                (string) ($plan->brand ?: $plan->batch?->brand_description),
                (string) $plan->expiry_month,
            );
            if ($plan->warehouse_id === null || ($plan->item_name === null && $plan->batch?->item?->name === null)) {
                continue;
            }
            $openByKey[$key] = ($openByKey[$key] ?? 0) + $this->openQuantity($plan);
        }

        $stock = $expiryRows->map(function (array $row) use ($openByKey): array {
            $key = $this->stockKey($row['warehouse_id'] ?? null, (string) ($row['item'] ?? ''), (string) ($row['brand'] ?? ''), (string) ($row['expiry_month'] ?? ''));
            $onHand = round((float) ($row['quantity'] ?? 0), 2);
            $open = round((float) ($openByKey[$key] ?? 0), 2);

            return [
                'key' => $key,
                'warehouse_id' => $row['warehouse_id'] ?? null,
                'warehouse' => $row['warehouse'] ?? '-',
                'district' => $row['district'] ?? '-',
                'partnership' => $row['partnership'] ?? '-',
                'category' => $row['category'] ?? '-',
                'item' => $row['item'] ?? '-',
                'brand' => $this->brandLabel($row['brand'] ?? null),
                'expiry_month' => $row['expiry_month'] ?? '',
                'status' => $row['status'] ?? null,
                'on_hand' => $onHand,
                'open_allocation' => $open,
                'remaining' => max(0, round($onHand - $open, 2)),
                'over_allocated' => $open > $onHand + 0.001,
            ];
        })->sortBy(fn (array $row): string => sprintf('%010d|%s|%s', $this->expirySortKey((string) $row['expiry_month']), $row['item'], $row['warehouse']))->values();

        $stockByKey = $stock->keyBy('key');

        $progressByProposal = $plans
            ->groupBy(fn (DistributionPlan $plan): string => $plan->proposal_key ?: 'plan-'.$plan->id)
            ->map(function (Collection $lines): string {
                $first = $lines->first();

                return $this->derivedProgress(
                    [
                        'delivery_target_na' => (bool) $first->delivery_target_na,
                        ...collect(self::ACTUAL_DATES)->mapWithKeys(fn (string $field): array => [$field => $first->{$field}])->all(),
                    ],
                    (float) $lines->sum(fn (DistributionPlan $plan): float => (float) ($plan->allocated_quantity > 0 ? $plan->allocated_quantity : $plan->quantity)),
                    (float) $lines->sum(fn (DistributionPlan $plan): float => (float) $plan->released_quantity),
                );
            });

        return [
            'activities' => self::ACTIVITIES,
            'delivery_modes' => self::DELIVERY_MODES,
            'progress' => self::PROGRESS,
            'coverage' => [
                'on_hand' => round((float) $stock->sum('on_hand'), 2),
                'open_allocation' => round((float) $stock->sum('open_allocation'), 2),
                'unallocated_quantity' => round((float) $stock->sum('remaining'), 2),
                'unallocated_lines' => $stock->filter(fn (array $row): bool => (float) $row['remaining'] > 0)->count(),
                'over_allocated_lines' => $stock->filter(fn (array $row): bool => (bool) $row['over_allocated'])->count(),
            ],
            'stock' => $stock->all(),
            'plans' => $plans->map(fn (DistributionPlan $plan): array => [
                ...$this->presentPlan($plan, $stockByKey),
                'progress_status' => $progressByProposal->get($plan->proposal_key ?: 'plan-'.$plan->id),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stock
     */
    public function allocationError(array $stock, string $key, float $allocated, float $released, float $ignoredOpen = 0, ?string $ignoredKey = null): ?string
    {
        if ($released - $allocated > 0.001) {
            return 'Released quantity cannot be higher than the number allocated.';
        }

        $line = collect($stock)->firstWhere('key', $key);
        if ($line === null) {
            return 'Choose a near-expiry item that is still on hand at that warehouse.';
        }

        $credit = $ignoredKey === $key ? $ignoredOpen : 0;
        $available = round((float) $line['remaining'] + $credit, 2);
        $open = max(0, round($allocated - $released, 2));
        if ($open - $available > 0.001) {
            return 'Only '.rtrim(rtrim(number_format($available, 2), '0'), '.').' can still be allocated from this warehouse.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function planAttributes(array $input, Warehouse $warehouse): array
    {
        $allocated = round((float) $input['allocated_quantity'], 2);
        $released = round((float) ($input['released_quantity'] ?? 0), 2);
        $activity = $this->canonicalActivity((string) $input['activity']);
        $lgu = trim((string) $input['lgu']);

        return [
            'lgu' => $lgu,
            'beneficiary' => $lgu,
            'location' => $lgu,
            'activity' => $activity,
            'program_type' => $this->programType($activity),
            'item_name' => trim((string) $input['item_name']),
            'brand' => $this->brandLabel($input['brand'] ?? null),
            'expiry_month' => trim((string) $input['expiry_month']),
            'warehouse_id' => $warehouse->id,
            'source_warehouse_name' => $warehouse->display_name,
            'district' => filled($warehouse->district) ? $warehouse->district : '-',
            'partnership' => filled($warehouse->partnership) ? $warehouse->partnership : '-',
            'allocated_quantity' => $allocated,
            'released_quantity' => $released,
            'quantity' => $allocated,
            'status' => $this->storedStatus($allocated, $released),
            'priority' => 'high',
            'remarks' => filled($input['remarks'] ?? null) ? $input['remarks'] : null,
            'delivery_mode' => filled($input['delivery_mode'] ?? null) ? $input['delivery_mode'] : null,
            'progress_status' => filled($input['progress_status'] ?? null) ? $input['progress_status'] : $this->derivedProgress($input, $allocated, $released),
            ...collect(self::PROPOSAL_DATES)->mapWithKeys(fn (string $field): array => [
                $field => filled($input[$field] ?? null) ? $input[$field] : null,
            ])->all(),
            ...$this->notApplicableAttributes($input),
        ];
    }

    /**
     * Current progress follows the recorded dates and releases, checked from the last stage back,
     * so it can never claim a stage the dates do not support.
     *
     * @param  array<string, mixed>  $proposal  actual date fields and not-applicable flags
     */
    public function derivedProgress(array $proposal, float $allocated, float $released): string
    {
        $has = fn (string $field): bool => filled($proposal[$field] ?? null);
        $deliveryNotApplicable = filter_var($proposal['delivery_target_na'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $delivered = $has('delivered_on') && $has('delivered_end_on');
        $fullyReleased = $allocated > 0 && $released >= $allocated - 0.001;

        return match (true) {
            $has('distributed_on') && $has('distributed_end_on') && $fullyReleased => 'SoTEx Fully Distributed',
            $has('distributed_on') || $released > 0 => 'SoTEx Partially Distributed',
            $has('work_schedule_on') && $has('work_schedule_end_on') && ($delivered || $deliveryNotApplicable) => 'Activity Completed',
            $delivered && ! $deliveryNotApplicable => 'SoTEx Fully Delivered',
            $has('delivered_on') && ! $deliveryNotApplicable => 'SoTEx Partially Delivered',
            $has('work_schedule_on') => 'Ongoing Activity',
            $has('proposal_approved_on') => 'Approved Proposal',
            default => "Awaiting LGU's Submission",
        };
    }

    /**
     * @param  array<string, mixed>  $input  validated proposal with its sources
     */
    public function progressForInput(array $input): string
    {
        $sources = collect($input['sources'] ?? []);

        return $this->derivedProgress(
            $input,
            (float) $sources->sum(fn (array $source): float => (float) ($source['allocated_quantity'] ?? 0)),
            (float) $sources->sum(fn (array $source): float => (float) ($source['released_quantity'] ?? 0)),
        );
    }

    /**
     * Stages that still need an actual date before anything can be marked released.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function missingActualStages(array $input): array
    {
        $missing = [];
        foreach (self::ACTUAL_STAGES as $label => [$fields, $flag]) {
            if ($flag !== null && filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            foreach ($fields as $field) {
                if (blank($input[$field] ?? null)) {
                    $missing[] = $label;
                    break;
                }
            }
        }

        return $missing;
    }

    /**
     * Actual dates recorded out of order, keyed by the field that is out of place.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function actualOrderErrors(array $input): array
    {
        $errors = [];
        foreach (self::ACTUAL_PREREQUISITES as $field => $prerequisites) {
            if (blank($input[$field] ?? null)) {
                continue;
            }
            foreach ($prerequisites as [$prerequisite, $flag]) {
                if ($flag !== null && filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $label = self::ACTUAL_LABELS[$prerequisite];
                if (blank($input[$prerequisite] ?? null)) {
                    $errors[$field] = "Record the actual {$label} first.";
                    break;
                }
                $earliest = CarbonImmutable::parse($input[$prerequisite])->startOfDay();
                if (CarbonImmutable::parse($input[$field])->startOfDay()->lt($earliest)) {
                    $errors[$field] = 'This cannot be earlier than the actual '.$label.' ('.$earliest->format('M j, Y').').';
                    break;
                }
            }
        }

        return $errors;
    }

    public function stockKey(mixed $warehouseId, string $item, string $brand, string $expiryMonth): string
    {
        return implode('|', [
            (string) ((int) $warehouseId),
            $this->normalize($item),
            $this->normalize($this->brandLabel($brand)),
            $this->normalize($expiryMonth),
        ]);
    }

    public function openQuantity(DistributionPlan $plan): float
    {
        $allocated = (float) ($plan->allocated_quantity > 0 ? $plan->allocated_quantity : $plan->quantity);

        return max(0, round($allocated - (float) $plan->released_quantity, 2));
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $stockByKey
     * @return array<string, mixed>
     */
    private function presentPlan(DistributionPlan $plan, Collection $stockByKey): array
    {
        $item = (string) ($plan->item_name ?: $plan->batch?->item?->name ?: '-');
        $brand = $this->brandLabel($plan->brand ?: $plan->batch?->brand_description);
        $expiry = (string) ($plan->expiry_month ?: ($plan->batch?->expiration_date?->format('M Y') ?? ''));
        $allocated = round((float) ($plan->allocated_quantity > 0 ? $plan->allocated_quantity : $plan->quantity), 2);
        $released = round((float) $plan->released_quantity, 2);
        $key = $this->stockKey($plan->warehouse_id, $item, $brand, $expiry);
        $stock = $plan->warehouse_id ? $stockByKey->get($key) : null;
        $lineStatus = match (true) {
            $stock === null => 'no_stock',
            $allocated > 0 && $released >= $allocated => 'released',
            $released > 0 => 'partial',
            default => 'allocated',
        };

        return [
            'id' => $plan->id,
            'proposal_key' => $plan->proposal_key,
            'lgu' => $plan->lgu ?: $plan->beneficiary ?: $plan->location,
            'activity' => $this->canonicalActivity((string) ($plan->activity ?: $this->activityFromProgram($plan->program_type))),
            'item' => $item,
            'brand' => $brand,
            'expiry_month' => $expiry,
            'warehouse_id' => $plan->warehouse_id,
            'warehouse' => $plan->source_warehouse_name ?: $plan->warehouse?->display_name ?: $plan->batch?->warehouse?->display_name ?: '-',
            'district' => $plan->district ?: ($stock['district'] ?? '-'),
            'partnership' => $plan->partnership ?: ($stock['partnership'] ?? '-'),
            'allocated_quantity' => $allocated,
            'released_quantity' => $released,
            'still_to_release' => max(0, round($allocated - $released, 2)),
            'still_available' => $stock['remaining'] ?? null,
            'stock_key' => $stock['key'] ?? null,
            'line_status' => $lineStatus,
            'remarks' => $plan->remarks,
            'delivery_mode' => $plan->delivery_mode,
            'delivery_mode_na' => (bool) $plan->delivery_mode_na,
            'compliance_target_na' => (bool) $plan->compliance_target_na,
            'delivery_target_na' => (bool) $plan->delivery_target_na,
            'progress_status' => $plan->progress_status,
            ...collect(self::PROPOSAL_DATES)->mapWithKeys(fn (string $field): array => [
                $field => $this->sheetDate($plan->{$field}),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function notApplicableAttributes(array $input): array
    {
        $attributes = [];
        foreach (self::NOT_APPLICABLE as $flag => $fields) {
            $notApplicable = filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
            $attributes[$flag] = $notApplicable;
            if (! $notApplicable) {
                continue;
            }
            foreach ($fields as $field) {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }

    public function canonicalActivity(string $activity): string
    {
        return match (trim($activity)) {
            'Food for Work', 'Food-for-Work' => 'Food-for-Work',
            'Food for Training', 'Food-for-Training' => 'Food-for-Training',
            'Non Food for Work', 'Non-Food-for-Work' => 'Non-Food-for-Work',
            default => trim($activity),
        };
    }

    private function sheetDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function storedStatus(float $allocated, float $released): string
    {
        if ($allocated > 0 && $released >= $allocated) {
            return 'distributed';
        }

        return $released > 0 ? 'scheduled' : 'for_distribution';
    }

    private function programType(string $activity): string
    {
        $activity = strtolower($activity);
        if (str_contains($activity, 'non-food') || str_contains($activity, 'non food')) {
            return 'non_food_for_work';
        }
        if (str_contains($activity, 'relief')) {
            return 'relief_distribution';
        }
        if (str_contains($activity, 'food')) {
            return 'food_for_work';
        }

        return 'other';
    }

    private function activityFromProgram(?string $program): string
    {
        return match ($program) {
            'non_food_for_work' => 'Non-Food-for-Work',
            'relief_distribution' => 'Relief Distribution',
            'food_for_work' => 'Food-for-Work',
            default => 'Food-for-Work',
        };
    }

    private function expirySortKey(string $month): int
    {
        $time = strtotime('1 '.$month);

        return $time === false ? PHP_INT_MAX : $time;
    }

    private function brandLabel(mixed $brand): string
    {
        $brand = trim((string) $brand);

        return $brand === '' || strcasecmp($brand, 'Unspecified') === 0 ? '-' : $brand;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === '-' || $value === 'unspecified') {
            return '-';
        }

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
