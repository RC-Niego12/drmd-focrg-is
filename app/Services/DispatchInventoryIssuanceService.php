<?php

namespace App\Services;

use App\Models\DispatchPlan;
use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchInventoryIssuanceService
{
    /**
     * Post the physical release to the system ledger using the same dimensions and
     * terminology as WIT Data Entry. The later manual WIT row reconciles to these
     * pending transactions instead of deducting stock a second time.
     *
     * @param  list<int>|null  $vehicleIndexes  When set, only those vehicle rows are issued.
     * @return list<int> inventory transaction IDs
     */
    public function record(DispatchPlan $dispatch, ?User $user, ?array $vehicleIndexes = null, bool $localOnly = false): array
    {
        $dispatch->loadMissing(['items', 'request.incident', 'requisitionIssuanceSlip.allocationItems']);
        $slip = $dispatch->requisitionIssuanceSlip;
        $tracking = is_array($slip?->tracking_data) ? $slip->tracking_data : [];
        $created = [];
        $lines = $this->releaseLines($dispatch, $vehicleIndexes, $localOnly);
        $scoped = is_array($vehicleIndexes);

        foreach ($lines as $line) {
            $item = $line['item'];
            $allocation = $line['allocation'];
            $vehicle = $line['vehicle'];
            $required = (int) $line['quantity'];
            if ($required <= 0) {
                continue;
            }

            // Idempotency is per DR item line. A DR normally contains several
            // items, so checking the DR number alone incorrectly caused the
            // first posted item (often FFPs) to suppress every later item.
            if (filled($line['dr_number']) && $this->alreadyPostedForLine($dispatch, $line)) {
                continue;
            }

            $allocationExpiry = $this->expiryDate($allocation?->expiry);
            $batches = InventoryBatch::query()
                ->with('item')
                ->where('warehouse_id', $line['warehouse_id'])
                ->whereHas('item', fn ($query) => $query
                    ->whereRaw('LOWER(name) = ?', [Str::lower(trim((string) $item->item_name))]))
                ->when($this->hasDimension($allocation?->brand_description), fn ($query) => $query
                    ->whereRaw('LOWER(TRIM(COALESCE(brand_description, \'\'))) = ?', [Str::lower(trim((string) $allocation->brand_description))]))
                // RIS/WIT expiry is a month-level value (for example "Mar 2027"),
                // while inventory batches store the actual end-of-month date.
                // Match the same calendar month instead of incorrectly comparing
                // March 1 against a stored March 31 batch.
                ->when($allocationExpiry, fn ($query, $expiry) => $query
                    ->whereYear('expiration_date', Carbon::parse($expiry)->year)
                    ->whereMonth('expiration_date', Carbon::parse($expiry)->month))
                ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiration_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($batches->isEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'No matching system stock batch was found for '.$item->item_name
                        .' at '.($line['warehouse_name'] ?: 'the selected warehouse')
                        .$this->dimensionLabel($allocation).'. Review the RIS warehouse, brand, and expiry before confirming release.',
                ]);
            }

            foreach ($batches as $batch) {
                if ($required <= 0) {
                    break;
                }

                // If WIT was manually encoded before Dispatch confirmation, adopt
                // that already-deducted row instead of posting a second release.
                $existingWit = $batch->transactions()
                    ->where('type', 'release')
                    ->whereHas('sheetImport', fn ($query) => $query->where('import_status', 'imported'))
                    ->whereNull('transactionable_id')
                    ->where('quantity', '<=', $required)
                    ->when(filled($slip?->ris_number), fn ($query) => $query->where('ris_if_stf', $slip->ris_number))
                    ->when(filled($line['dr_number']), fn ($query) => $query->where('reference_number', $line['dr_number']))
                    ->oldest('id')
                    ->first();
                if ($existingWit) {
                    $lineMarker = '[Dispatch Item:'.$item->id.']';
                    $existingWit->update([
                        'transactionable_type' => DispatchPlan::class,
                        'transactionable_id' => $dispatch->id,
                        'reconciliation_status' => 'reconciled',
                        'reconciled_at' => now(),
                        'remarks' => trim(implode(' ', array_filter([$existingWit->remarks, $lineMarker]))),
                    ]);
                    $created[] = $existingWit->id;
                    $required -= (int) $existingWit->quantity;

                    continue;
                }

                $quantity = min($required, (int) floor((float) $batch->quantity));
                if ($quantity <= 0) {
                    continue;
                }

                // The RIS allocation snapshots the RROS unit cost. Dispatch may
                // deliberately override it during physical release; use that
                // persisted value for both the release ledger and DR totals.
                $unitCost = $item->unit_cost !== null
                    ? round((float) $item->unit_cost, 2)
                    : $this->unitCost($batch);
                $releaseAt = $vehicle['warehouse_released_at'] ?? $dispatch->warehouse_released_at ?? now();
                $expectedAt = $vehicle['estimated_departure'] ?? $dispatch->dispatch_date;

                $transaction = $batch->transactions()->create([
                    'user_id' => $user?->id,
                    'type' => 'release',
                    'transaction_date' => Carbon::parse($releaseAt)->toDateString(),
                    'source_of_goods' => $dispatch->source_of_goods ?: $batch->source ?: 'FO Stockpile/Prepo',
                    // WIT Purpose is a year-qualified incident classification (for
                    // example, "2026 Fire Incident"), not the broad request purpose
                    // such as "Relief Augmentation".
                    'purpose' => $dispatch->purpose ?: $this->witPurpose($dispatch, $tracking, $releaseAt),
                    // WIT Reference / DR is vehicle-specific. One RIS can therefore
                    // reconcile several DRs without merging their transport records.
                    'reference_number' => $line['dr_number'],
                    'ris_if_stf' => $slip?->ris_number,
                    'call_off_number' => $tracking['call_off_number'] ?? null,
                    'sender_supplier' => null,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost !== null ? round($unitCost * $quantity, 2) : null,
                    'balance_after' => max(0, (float) $batch->quantity - $quantity),
                    'recipient' => $slip?->recipient ?: $dispatch->receiving_agency_lgu,
                    'delivery_site' => $dispatch->destination,
                    'expected_delivery_date' => $expectedAt ? Carbon::parse($expectedAt)->toDateString() : null,
                    'transport_details' => $this->transportDetails($vehicle),
                    'external_status' => null,
                    'reconciliation_status' => 'pending_wit',
                    'encoded_by_email' => $user?->email,
                    'encoded_at' => now(),
                    'transactionable_type' => DispatchPlan::class,
                    'transactionable_id' => $dispatch->id,
                    'remarks' => trim(implode(' ', array_filter([
                        $dispatch->remarks,
                        '[Dispatch Item:'.$item->id.']',
                        '[Vehicle DR:'.($line['dr_number'] ?: 'N/A').']',
                        '[DROMIS:'.$dispatch->dispatch_number.']',
                    ]))),
                ]);

                $batch->update(['quantity' => max(0, (float) $batch->quantity - $quantity)]);
                $created[] = $transaction->id;
                $required -= $quantity;
            }

            if ($required > 0) {
                throw ValidationException::withMessages([
                    'status' => "System stock is insufficient for {$item->item_name} at {$line['warehouse_name']}{$this->dimensionLabel($allocation)}; {$required} unit(s) could not be released.",
                ]);
            }
        }

        if ($created === [] && ! $scoped && $lines !== []) {
            throw ValidationException::withMessages([
                'status' => 'No loaded or allocated item quantity was available to record as released.',
            ]);
        }

        if ($created === [] && ! $scoped && $lines === []) {
            throw ValidationException::withMessages([
                'status' => 'No loaded or allocated item quantity was available to record as released.',
            ]);
        }

        return $created;
    }

    /** @param array<string, mixed> $line */
    private function alreadyPostedForLine(DispatchPlan $dispatch, array $line): bool
    {
        $item = $line['item'];
        $drNumber = (string) $line['dr_number'];
        $marker = '[Dispatch Item:'.$item->id.']';

        $base = InventoryTransaction::query()
            ->where('transactionable_type', DispatchPlan::class)
            ->where('transactionable_id', $dispatch->id)
            ->where('type', 'release')
            ->where('reference_number', $drNumber);

        if ((clone $base)->where('remarks', 'like', '%'.$marker.'%')->exists()) {
            return true;
        }

        // Compatibility for releases created before line markers were added.
        return (clone $base)->whereHas('batch', fn ($query) => $query
            ->where('warehouse_id', $line['warehouse_id'])
            ->whereHas('item', fn ($itemQuery) => $itemQuery
                ->whereRaw('LOWER(name) = ?', [Str::lower(trim((string) $item->item_name))])))
            ->exists();
    }

    /**
     * Expand the dispatch into WIT-shaped release lines: vehicle/DR + RIS batch dimensions.
     *
     * @param  list<int>|null  $vehicleIndexes
     * @return list<array<string, mixed>>
     */
    private function releaseLines(DispatchPlan $dispatch, ?array $vehicleIndexes = null, bool $localOnly = false): array
    {
        $items = $dispatch->items;
        $allocations = $dispatch->requisitionIssuanceSlip?->allocationItems ?? collect();
        $lines = [];
        $indexFilter = is_array($vehicleIndexes)
            ? array_map('intval', $vehicleIndexes)
            : null;

        foreach ($localOnly ? [] : array_values($dispatch->resolvedVehicleDetails()) as $vehicleIndex => $vehicle) {
            if ($indexFilter !== null && ! in_array((int) $vehicleIndex, $indexFilter, true)) {
                continue;
            }
            foreach (($vehicle['loaded_items'] ?? []) as $loaded) {
                $quantity = (int) ($loaded['loaded_quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $risItemId = $loaded['requisition_issuance_item_id'] ?? null;
                $name = trim((string) ($loaded['item_name'] ?? ''));
                $item = $items->first(fn ($row): bool => ($risItemId && (int) $row->requisition_issuance_item_id === (int) $risItemId)
                    || ($name !== ''
                        && strcasecmp((string) $row->item_name, $name) === 0
                        && (int) ($row->warehouse_id ?? 0) === (int) ($vehicle['source_warehouse_id'] ?? 0))
                );
                if (! $item) {
                    throw ValidationException::withMessages([
                        'status' => 'A loaded vehicle item could not be matched to its RIS allocation. Save the plan and reload before confirming release.',
                    ]);
                }
                $allocation = $allocations->firstWhere('id', $item->requisition_issuance_item_id);
                $lines[] = [
                    'item' => $item,
                    'allocation' => $allocation,
                    'vehicle' => $vehicle,
                    'quantity' => $quantity,
                    'warehouse_id' => $allocation?->warehouse_id ?: $item->warehouse_id ?: ($vehicle['source_warehouse_id'] ?? null),
                    'warehouse_name' => $allocation?->warehouse_name ?: $item->warehouse_name ?: ($vehicle['source_warehouse_name'] ?? null),
                    'dr_number' => $vehicle['dr_number'] ?? null,
                ];
            }
        }

        // Receiving-LGU local-stock plans legitimately have no transport vehicle.
        // They still create an official system issuance using their RIS allocation.
        // Never run the local fallback when a vehicle-index scope was requested.
        if ($lines === [] && ($indexFilter === null || $localOnly)) {
            $localWarehouseId = data_get($dispatch->local_handover_details, 'source_warehouse_id');
            $localWarehouseName = trim((string) data_get($dispatch->local_handover_details, 'source_warehouse_name'));
            foreach ($items as $item) {
                if ($localOnly && ! (
                    ($localWarehouseId && (int) $item->warehouse_id === (int) $localWarehouseId)
                    || ($localWarehouseName !== '' && strcasecmp((string) $item->warehouse_name, $localWarehouseName) === 0)
                )) continue;
                $quantity = (int) ($item->loaded_quantity ?: $item->allocated_quantity);
                if ($quantity <= 0) {
                    continue;
                }
                $allocation = $allocations->firstWhere('id', $item->requisition_issuance_item_id);
                $lines[] = [
                    'item' => $item,
                    'allocation' => $allocation,
                    'vehicle' => [],
                    'quantity' => $quantity,
                    'warehouse_id' => $allocation?->warehouse_id ?: $item->warehouse_id,
                    'warehouse_name' => $allocation?->warehouse_name ?: $item->warehouse_name,
                    'dr_number' => data_get($dispatch->local_handover_details, 'dr_number'),
                ];
            }
        }

        return $lines;
    }

    private function hasDimension(mixed $value): bool
    {
        return filled($value) && ! in_array(Str::lower(trim((string) $value)), ['-', 'n/a', 'na', 'not applicable', 'none'], true);
    }

    private function expiryDate(mixed $value): ?string
    {
        if (! $this->hasDimension($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfMonth()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dimensionLabel(mixed $allocation): string
    {
        if (! $allocation) {
            return '';
        }
        $parts = array_filter([
            $this->hasDimension($allocation->brand_description) ? 'brand '.$allocation->brand_description : null,
            $this->expiryDate($allocation->expiry) ? 'expiry '.Carbon::parse($allocation->expiry)->format('M Y') : null,
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private function unitCost(InventoryBatch $batch): ?float
    {
        $transactions = InventoryTransaction::query()->where('inventory_batch_id', $batch->id)->get();
        $netQuantity = $transactions->sum(fn (InventoryTransaction $row): float => ($row->type === 'release' ? -1 : 1) * (float) $row->quantity
        );
        $netCost = $transactions->sum(fn (InventoryTransaction $row): float => ($row->type === 'release' ? -1 : 1) * (float) ($row->total_cost ?? ((float) $row->quantity * (float) $row->unit_cost))
        );

        return $netQuantity > 0 && $netCost > 0 ? round($netCost / $netQuantity, 2) : null;
    }

    /**
     * Compose the WIT Data Entry Purpose from the incident's own year and type.
     * Existing year-qualified legacy values are retained as-is.
     *
     * @param  array<string, mixed>  $tracking
     */
    private function witPurpose(DispatchPlan $dispatch, array $tracking, mixed $releaseAt): ?string
    {
        $request = $dispatch->request;
        $incidentType = trim((string) (
            $tracking['incident_type']
            ?? data_get($request?->lgu_dromic_payload, 'incident_type')
            ?? $request?->incident?->name
            ?? $request?->purpose
            ?? ''
        ));

        if ($incidentType === '') {
            return null;
        }

        if (preg_match('/^\d{4}\s+/', $incidentType) === 1) {
            return $incidentType;
        }

        $incidentDate = $request?->incident?->incident_date
            ?? data_get($request?->lgu_dromic_payload, 'occurrence_started_at')
            ?? data_get($request?->lgu_dromic_payload, 'incident_date')
            ?? $tracking['incident_date']
            ?? $releaseAt;

        try {
            $year = Carbon::parse($incidentDate)->year;
        } catch (\Throwable) {
            $year = now('Asia/Manila')->year;
        }

        return $year.' '.$incidentType;
    }

    /** @return array<string, mixed> */
    private function transportDetails(array $vehicle): array
    {
        $mode = is_array($vehicle['mode_of_transportation'] ?? null)
            ? ($vehicle['mode_of_transportation'][0] ?? null)
            : ($vehicle['mode_of_transportation'] ?? null);

        return [
            'land' => [
                'type' => $vehicle['vehicle_type'] ?? null,
                'source' => $vehicle['land_transportation_source'] ?? match ($mode) {
                    'DSWD-Owned' => 'DSWD OWNED - Field Office',
                    'Partner', 'Partner LGU' => 'PARTNER - LGU VEHICLE',
                    'Service Provider' => 'CONTRACTED - OTHERS',
                    default => $mode,
                },
                'plate' => $vehicle['vehicle_plate_number'] ?? null,
                'driver' => $vehicle['driver'] ?? null,
                'contact_number' => $vehicle['driver_contact_number'] ?? null,
            ],
            'sea' => ['type' => null, 'source' => null, 'details' => null],
            'air' => ['type' => null, 'source' => null, 'details' => null],
        ];
    }
}
