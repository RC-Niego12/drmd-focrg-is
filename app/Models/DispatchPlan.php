<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class DispatchPlan extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RELEASED = 'released';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PLANNED,
        self::STATUS_RELEASED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
    ];

    public const FULFILLMENT_FIELD_DELIVERY = 'field_delivery';

    public const FULFILLMENT_WAREHOUSE_PICKUP = 'warehouse_pickup';

    public const FULFILLMENT_TYPES = [
        self::FULFILLMENT_FIELD_DELIVERY,
        self::FULFILLMENT_WAREHOUSE_PICKUP,
    ];

    public const STILL_FOR_ACTION = [self::STATUS_DRAFT];

    public const IN_PROGRESS = [self::STATUS_PLANNED, self::STATUS_RELEASED, self::STATUS_IN_TRANSIT];

    public const COMPLETED = [self::STATUS_RECEIVED];

    /** Variances that keep the RIS open until replacement quantities are delivered. */
    public const REPLACEMENT_REQUIRED_DISPOSITIONS = ['deferred', 'returned', 'cancelled'];

    protected $fillable = [
        'dispatch_number',
        'parent_dispatch_plan_id',
        'delivery_sequence',
        'dr_series_offset',
        'request_id',
        'requisition_issuance_slip_id',
        'vehicle_id',
        'receiver_id',
        'destination',
        'receiving_agency_lgu',
        'source_of_goods',
        'purpose',
        'driver',
        'driver_contact_number',
        'vehicle_plate_number',
        'dispatcher',
        'mode_of_transportation',
        'vehicle_types',
        'number_of_vehicles',
        'vehicle_details',
        'dispatch_date',
        'estimated_arrival',
        'actual_arrival',
        'warehouse_released_at',
        'warehouse_released_by',
        'loaded_at',
        'loading_remarks',
        'departed_at',
        'received_by',
        'received_at',
        'receiver_contact',
        'receipt_acknowledged',
        'receipt_remarks',
        'delivered_at',
        'release_witnessed_by',
        'fully_delivered',
        'has_returned_items',
        'returned_particulars',
        'returned_quantity',
        'returned_reason',
        'status',
        'fulfillment_type',
        'fulfillment_type_confirmed',
        'local_handover_details',
        'status_timeline',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_date' => 'date',
            'estimated_arrival' => 'datetime',
            'actual_arrival' => 'datetime',
            'warehouse_released_at' => 'datetime',
            'loaded_at' => 'datetime',
            'departed_at' => 'datetime',
            'received_at' => 'datetime',
            'delivered_at' => 'date',
            'mode_of_transportation' => 'array',
            'vehicle_types' => 'array',
            'vehicle_details' => 'array',
            'status_timeline' => 'array',
            'local_handover_details' => 'array',
            'receipt_acknowledged' => 'boolean',
            'fully_delivered' => 'boolean',
            'has_returned_items' => 'boolean',
            'fulfillment_type_confirmed' => 'boolean',
            'number_of_vehicles' => 'integer',
            'returned_quantity' => 'integer',
            'delivery_sequence' => 'integer',
            'dr_series_offset' => 'integer',
        ];
    }

    /**
     * Resolve multi-vehicle ops rows, backfilling vehicles[0] from legacy plan columns when needed.
     *
     * @return list<array<string, mixed>>
     */
    public function resolvedVehicleDetails(): array
    {
        $stored = is_array($this->vehicle_details) ? $this->vehicle_details : [];
        $types = is_array($this->vehicle_types) ? $this->vehicle_types : [];
        $modes = is_array($this->mode_of_transportation) ? $this->mode_of_transportation : [];

        if ($stored === []) {
            $first = $this->normalizeVehicleDetailRow([
                'vehicle_type' => $types[0] ?? null,
                'driver' => $this->driver,
                'driver_contact_number' => $this->driver_contact_number,
                'vehicle_plate_number' => $this->vehicle_plate_number,
                'mode_of_transportation' => $modes[0] ?? null,
            ], backfillFromPlan: true);

            $count = max(1, (int) ($this->number_of_vehicles ?: 1));
            $rows = [$first];
            while (count($rows) < $count) {
                $index = count($rows);
                $rows[] = $this->normalizeVehicleDetailRow([
                    'vehicle_type' => $types[$index] ?? null,
                ]);
            }

            return $rows;
        }

        return collect($stored)
            ->values()
            ->map(fn ($row, $index) => $this->normalizeVehicleDetailRow(
                is_array($row) ? $row : [],
                backfillFromPlan: $index === 0,
            ))
            ->all();
    }

    /**
     * Unique modes for printable DR checkboxes (vehicle rows first, then plan-level).
     *
     * @return list<string>
     */
    public function printableModesOfTransportation(): array
    {
        $normalize = static fn (string $mode): string => $mode === 'Partner LGU' ? 'Partner' : $mode;

        $fromVehicles = collect($this->resolvedVehicleDetails())
            ->flatMap(fn (array $row) => $this->asStringList($row['mode_of_transportation'] ?? null))
            ->map($normalize)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($fromVehicles !== []) {
            return $fromVehicles;
        }

        return collect($this->asStringList($this->mode_of_transportation))
            ->map($normalize)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Merge a crew/print field across all vehicle rows (unique, non-blank), then plan legacy.
     * Multiple values are joined with newlines for multi-line printable cells.
     */
    public function printableMergedVehicleField(string $vehicleKey, ?string $planAttribute = null, string $separator = "\n"): ?string
    {
        $fromVehicles = collect($this->resolvedVehicleDetails())
            ->map(function (array $row) use ($vehicleKey) {
                $value = $row[$vehicleKey] ?? null;
                if ($value === null || $value === '') {
                    return null;
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                $text = trim((string) $value);
                if ($vehicleKey === 'driver') {
                    $warehouse = trim((string) ($row['source_warehouse_name'] ?? ''));
                    if ($warehouse !== '') {
                        $text = "{$text} ({$warehouse})";
                    }
                    $hasEscort = ($row['has_dswd_escort'] ?? false) === true
                        || ($row['has_dswd_escort'] ?? null) === 1
                        || ($row['has_dswd_escort'] ?? null) === '1'
                        || ($row['has_dswd_escort'] ?? null) === 'Yes';
                    $escortName = trim((string) ($row['escort_name'] ?? ''));
                    if ($hasEscort && $escortName !== '') {
                        $text = "{$text} / Escort: {$escortName}";
                    }
                }

                return $text;
            })
            ->filter()
            ->unique()
            ->values();

        if ($fromVehicles->isNotEmpty()) {
            return $fromVehicles->implode($separator);
        }

        $attr = $planAttribute ?? $vehicleKey;
        $legacy = $this->{$attr} ?? null;
        if ($legacy === null || $legacy === '') {
            return null;
        }
        if ($legacy instanceof \DateTimeInterface) {
            return $legacy->format('Y-m-d');
        }
        if (is_bool($legacy)) {
            return $legacy ? 'Yes' : 'No';
        }

        return trim((string) $legacy);
    }

    /**
     * @return array{driver_name: ?string, driver_contact_number: ?string, vehicle_plate_number: ?string, source_warehouse: ?string, release_witnessed_by: ?string, delivered_at: ?string, received_by: ?string, fully_delivered: ?string}
     */
    public function printableMergedTransportFields(string $separator = "\n"): array
    {
        $fully = collect($this->resolvedVehicleDetails())
            ->map(fn (array $row) => $row['fully_delivered'] ?? null)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(function ($value) {
                if ($value === true || $value === 1 || $value === '1' || $value === 'Yes') {
                    return 'Yes';
                }
                if ($value === false || $value === 0 || $value === '0' || $value === 'No') {
                    return 'No';
                }

                return (string) $value;
            })
            ->unique()
            ->values();

        $fullyMerged = $fully->isNotEmpty()
            ? $fully->implode($separator)
            : ($this->fully_delivered === null
                ? null
                : ($this->fully_delivered ? 'Yes' : 'No'));

        return [
            'driver_name' => $this->printableMergedVehicleField('driver', 'driver', $separator),
            'driver_contact_number' => $this->printableMergedVehicleField('driver_contact_number', 'driver_contact_number', $separator),
            'vehicle_plate_number' => $this->printableMergedVehicleField('vehicle_plate_number', 'vehicle_plate_number', $separator),
            'source_warehouse' => $this->printableMergedVehicleField('source_warehouse_name', null, $separator),
            'release_witnessed_by' => $this->printableMergedVehicleField('release_witnessed_by', 'release_witnessed_by', $separator),
            'delivered_at' => $this->printableMergedVehicleField('delivered_at', 'delivered_at', $separator),
            'received_by' => $this->printableMergedVehicleField('received_by', 'received_by', $separator),
            'fully_delivered' => $fullyMerged,
        ];
    }

    /**
     * Normalize a stored/legacy vehicle row. Old JSON may include vehicle_id; it is ignored.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeVehicleDetailRow(array $row, bool $backfillFromPlan = false): array
    {
        $mode = collect($this->asStringList($row['mode_of_transportation'] ?? null))
            ->map(fn ($value) => $value === 'Partner LGU' ? 'Partner' : $value)
            ->filter()
            ->first();
        $hasEscort = $this->vehicleHasDswdEscort($row);
        $loadedItems = collect($row['loaded_items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'requisition_issuance_item_id' => $item['requisition_issuance_item_id'] ?? null,
                'item_name' => filled($item['item_name'] ?? null) ? trim((string) $item['item_name']) : null,
                'loaded_quantity' => array_key_exists('loaded_quantity', $item) && $item['loaded_quantity'] !== null && $item['loaded_quantity'] !== ''
                    ? (int) $item['loaded_quantity']
                    : (array_key_exists('loaded_qty', $item) && $item['loaded_qty'] !== null && $item['loaded_qty'] !== ''
                        ? (int) $item['loaded_qty']
                        : null),
                'remarks' => filled($item['remarks'] ?? $item['line_remarks'] ?? null)
                    ? trim((string) ($item['remarks'] ?? $item['line_remarks']))
                    : null,
            ])
            ->values()
            ->all();

        $normalized = [
            'dr_number' => filled($row['dr_number'] ?? null) ? trim((string) $row['dr_number']) : null,
            'source_warehouse_id' => filled($row['source_warehouse_id'] ?? null)
                ? (int) $row['source_warehouse_id']
                : null,
            'source_warehouse_name' => filled($row['source_warehouse_name'] ?? null)
                ? trim((string) $row['source_warehouse_name'])
                : null,
            'vehicle_type' => filled($row['vehicle_type'] ?? null) ? trim((string) $row['vehicle_type']) : null,
            'driver' => filled($row['driver'] ?? null) ? trim((string) $row['driver']) : null,
            'driver_contact_number' => filled($row['driver_contact_number'] ?? null)
                ? trim((string) $row['driver_contact_number'])
                : null,
            'driver_id_number' => filled($row['driver_id_number'] ?? null)
                ? trim((string) $row['driver_id_number'])
                : null,
            'driver_position' => filled($row['driver_position'] ?? null)
                ? trim((string) $row['driver_position'])
                : null,
            'driver_office' => filled($row['driver_office'] ?? null)
                ? trim((string) $row['driver_office'])
                : null,
            'vehicle_plate_number' => filled($row['vehicle_plate_number'] ?? null)
                ? trim((string) $row['vehicle_plate_number'])
                : null,
            'has_dswd_escort' => $hasEscort,
            'escort_name' => $hasEscort && filled($row['escort_name'] ?? null)
                ? trim((string) $row['escort_name'])
                : null,
            'escort_contact_number' => $hasEscort && filled($row['escort_contact_number'] ?? null)
                ? trim((string) $row['escort_contact_number'])
                : null,
            'escort_id_number' => $hasEscort && filled($row['escort_id_number'] ?? null)
                ? trim((string) $row['escort_id_number'])
                : null,
            'escort_position' => $hasEscort && filled($row['escort_position'] ?? null)
                ? trim((string) $row['escort_position'])
                : null,
            'escort_office' => $hasEscort && filled($row['escort_office'] ?? null)
                ? trim((string) $row['escort_office'])
                : null,
            'estimated_departure' => $this->estimatedDateTime(
                $row['estimated_departure']
                    ?? (filled($row['dispatch_date'] ?? null) ? (string) $row['dispatch_date'] : null)
            ),
            'estimated_arrival' => $this->dateTimeLocal($row['estimated_arrival'] ?? null),
            'allows_multi_day_run' => $this->truthyFlag($row['allows_multi_day_run'] ?? false),
            'mode_of_transportation' => filled($mode) ? (string) $mode : null,
            'land_transportation_source' => filled($row['land_transportation_source'] ?? null) ? trim((string) $row['land_transportation_source']) : null,
            'warehouse_released_at' => $this->dateTimeLocal($row['warehouse_released_at'] ?? null),
            'warehouse_released_by' => filled($row['warehouse_released_by'] ?? null)
                ? trim((string) $row['warehouse_released_by'])
                : null,
            'release_authorized_by' => filled($row['release_authorized_by'] ?? null) ? trim((string) $row['release_authorized_by']) : null,
            'release_authorizer_position' => filled($row['release_authorizer_position'] ?? null) ? trim((string) $row['release_authorizer_position']) : null,
            'release_authorizer_office' => filled($row['release_authorizer_office'] ?? null) ? trim((string) $row['release_authorizer_office']) : null,
            'release_witnessed_by' => filled($row['release_witnessed_by'] ?? null)
                ? trim((string) $row['release_witnessed_by'])
                : null,
            'release_witness_affiliation' => in_array($row['release_witness_affiliation'] ?? null, ['dswd', 'lgu'], true)
                ? $row['release_witness_affiliation']
                : null,
            'release_witness_contact_number' => filled($row['release_witness_contact_number'] ?? null)
                ? trim((string) $row['release_witness_contact_number'])
                : null,
            'release_witness_id_number' => filled($row['release_witness_id_number'] ?? null)
                ? trim((string) $row['release_witness_id_number'])
                : null,
            'release_witness_position' => filled($row['release_witness_position'] ?? null)
                ? trim((string) $row['release_witness_position'])
                : null,
            'release_witness_office' => filled($row['release_witness_office'] ?? null)
                ? trim((string) $row['release_witness_office'])
                : null,
            'loaded_at' => $this->dateTimeLocal($row['loaded_at'] ?? null),
            'loading_remarks' => filled($row['loading_remarks'] ?? null)
                ? trim((string) $row['loading_remarks'])
                : null,
            'loaded_items' => $loadedItems,
            'departed_at' => $this->dateTimeLocal($row['departed_at'] ?? null),
            'actual_arrival' => $this->dateTimeLocal($row['actual_arrival'] ?? null),
            'delivered_at' => $this->dateOnly($row['delivered_at'] ?? null),
            'fully_delivered' => $this->yesNoLabel($row['fully_delivered'] ?? null),
            'received_by' => filled($row['received_by'] ?? null) ? trim((string) $row['received_by']) : null,
            'received_by_position' => filled($row['received_by_position'] ?? null)
                ? trim((string) $row['received_by_position'])
                : null,
            'received_by_office' => filled($row['received_by_office'] ?? null)
                ? trim((string) $row['received_by_office'])
                : null,
            'received_at' => $this->dateTimeLocal($row['received_at'] ?? null),
            'receiver_contact' => filled($row['receiver_contact'] ?? null)
                ? trim((string) $row['receiver_contact'])
                : null,
            'receipt_acknowledged' => (bool) ($row['receipt_acknowledged'] ?? false),
            'receipt_remarks' => filled($row['receipt_remarks'] ?? null)
                ? trim((string) $row['receipt_remarks'])
                : null,
        ];

        if ($backfillFromPlan) {
            $planTypes = is_array($this->vehicle_types) ? $this->vehicle_types : [];
            $planModes = is_array($this->mode_of_transportation) ? $this->mode_of_transportation : [];
            $planMode = collect($planModes)
                ->map(fn ($value) => $value === 'Partner LGU' ? 'Partner' : $value)
                ->filter()
                ->first();
            $planFallback = [
                'vehicle_type' => $planTypes[0] ?? null,
                'driver' => $this->driver,
                'driver_contact_number' => $this->driver_contact_number,
                'vehicle_plate_number' => $this->vehicle_plate_number,
                'estimated_departure' => $this->dispatch_date
                    ? $this->dispatch_date->format('Y-m-d').'T08:00'
                    : null,
                'estimated_arrival' => optional($this->estimated_arrival)?->format('Y-m-d\TH:i'),
                'mode_of_transportation' => filled($planMode) ? (string) $planMode : null,
                'warehouse_released_at' => optional($this->warehouse_released_at)?->format('Y-m-d\TH:i'),
                'warehouse_released_by' => $this->warehouse_released_by,
                'release_witnessed_by' => $this->release_witnessed_by,
                'loaded_at' => optional($this->loaded_at)?->format('Y-m-d\TH:i'),
                'loading_remarks' => $this->loading_remarks,
                'departed_at' => optional($this->departed_at)?->format('Y-m-d\TH:i'),
                'actual_arrival' => optional($this->actual_arrival)?->format('Y-m-d\TH:i'),
                'delivered_at' => optional($this->delivered_at)?->format('Y-m-d'),
                'fully_delivered' => $this->yesNoLabel($this->fully_delivered),
                'received_by' => $this->received_by,
                'received_at' => optional($this->received_at)?->format('Y-m-d\TH:i'),
                'receiver_contact' => $this->receiver_contact,
                'receipt_acknowledged' => (bool) $this->receipt_acknowledged,
                'receipt_remarks' => $this->receipt_remarks,
            ];

            foreach ($planFallback as $key => $value) {
                if ($key === 'receipt_acknowledged') {
                    if (! ($normalized[$key] ?? false) && $value) {
                        $normalized[$key] = true;
                    }

                    continue;
                }
                if (($normalized[$key] ?? null) === null || $normalized[$key] === '') {
                    $normalized[$key] = $value;
                }
            }

            if (($normalized['loaded_items'] ?? []) === [] && $this->relationLoaded('items')) {
                $normalized['loaded_items'] = $this->items->map(fn (DispatchPlanItem $item) => [
                    'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                    'item_name' => $item->item_name,
                    'loaded_quantity' => $item->loaded_quantity,
                    'remarks' => $item->remarks,
                ])->values()->all();
            }
        }

        return $normalized;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'request_id');
    }

    public function requisitionIssuanceSlip(): BelongsTo
    {
        return $this->belongsTo(RequisitionIssuanceSlip::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispatchPlanItem::class);
    }

    public function deliveryUpdates(): HasMany
    {
        return $this->hasMany(DispatchDeliveryUpdate::class)->orderByDesc('occurred_at');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isWarehousePickup(): bool
    {
        return ($this->fulfillment_type ?: self::FULFILLMENT_FIELD_DELIVERY)
            === self::FULFILLMENT_WAREHOUSE_PICKUP;
    }

    public function isFieldDelivery(): bool
    {
        return ! $this->isWarehousePickup();
    }

    public function bucket(): string
    {
        if (in_array($this->status, self::COMPLETED, true)) {
            if ($this->relationLoaded('items')
                && $this->items->contains(fn (DispatchPlanItem $item): bool => in_array($item->variance_disposition, self::REPLACEMENT_REQUIRED_DISPOSITIONS, true)
                    && max(0, (int) $item->allocated_quantity - (int) $item->received_quantity) > 0)) {
                return 'in_progress';
            }
            return 'completed';
        }

        if (in_array($this->status, self::IN_PROGRESS, true)) {
            return 'in_progress';
        }

        return 'still_for_action';
    }

    /**
     * Append a status / revision history entry (who, when, from→to, optional note).
     *
     * @param  'status_change'|'plan_update'  $type
     */
    public function appendTimeline(
        string $status,
        ?int $userId = null,
        ?string $note = null,
        ?string $fromStatus = null,
        string $type = 'status_change',
        ?string $byName = null,
    ): void {
        $timeline = collect($this->status_timeline ?? [])->values()->all();
        $resolvedName = $byName;
        if ($resolvedName === null && $userId) {
            $resolvedName = User::query()->whereKey($userId)->value('name');
        }

        $timeline[] = array_filter([
            'type' => $type,
            'status' => $status,
            'from_status' => $fromStatus,
            'to_status' => $status,
            'at' => now()->timezone(config('app.timezone', 'Asia/Manila'))->toIso8601String(),
            'by' => $userId,
            'by_name' => filled($resolvedName) ? (string) $resolvedName : null,
            'note' => $note,
        ], fn ($value) => $value !== null && $value !== '');

        $this->status_timeline = $timeline;
    }

    /**
     * Whether estimated departure and arrival fall on the same Asia/Manila calendar date.
     */
    public static function isSameManilaCalendarDate(mixed $departure, mixed $arrival): bool
    {
        $dep = self::manilaCalendarDate($departure);
        $arr = self::manilaCalendarDate($arrival);

        return $dep !== null && $arr !== null && $dep === $arr;
    }

    public static function manilaCalendarDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse(str_replace(' ', 'T', (string) $value))
                ->timezone(config('app.timezone', 'Asia/Manila'))
                ->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function vehicleAllowsMultiDayRun(array $row): bool
    {
        $flag = $row['allows_multi_day_run'] ?? false;

        return $flag === true
            || $flag === 1
            || $flag === '1'
            || $flag === 'Yes'
            || $flag === 'yes'
            || $flag === 'true';
    }

    private function asStringList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => trim((string) $item))->filter()->values()->all();
        }

        if ($value === null || $value === '') {
            return [];
        }

        $text = trim((string) $value);
        if (str_contains($text, ',')) {
            return collect(explode(',', $text))->map(fn ($item) => trim($item))->filter()->values()->all();
        }

        return [$text === 'Partner LGU' ? 'Partner' : $text];
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10) ?: null;
    }

    private function dateTimeLocal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = str_replace(' ', 'T', (string) $value);

        return substr($text, 0, 16) ?: null;
    }

    /** Date-only legacy values become datetime at 08:00 for planning fields. */
    private function estimatedDateTime(mixed $value): ?string
    {
        $local = $this->dateTimeLocal($value);
        if ($local === null) {
            return null;
        }
        if (strlen($local) === 10) {
            return $local.'T08:00';
        }

        return $local;
    }

    private function yesNoLabel(mixed $value): ?string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'Yes') {
            return 'Yes';
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'No') {
            return 'No';
        }

        return null;
    }

    private function truthyFlag(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'Yes'
            || $value === 'yes'
            || $value === 'true';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function vehicleHasDswdEscort(array $row): bool
    {
        return $this->truthyFlag($row['has_dswd_escort'] ?? false);
    }
}
