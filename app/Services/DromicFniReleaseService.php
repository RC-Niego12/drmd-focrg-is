<?php

namespace App\Services;

use App\Models\DispatchPlan;
use App\Models\FniLibraryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Collection;

class DromicFniReleaseService
{
    public function eligible(): Collection
    {
        $fniLibrary = FniLibraryItem::query()->get()
            ->groupBy(fn (FniLibraryItem $item): string => strtolower(trim($item->item_name)));

        return InventoryTransaction::query()->with(['batch.item', 'batch.warehouse', 'sheetImport', 'transactionable.request.items', 'transactionable.deliveryUpdates'])
            ->where('type', 'release')
            ->where('transactionable_type', DispatchPlan::class)
            ->whereNotNull('transactionable_id')
            ->latest('transaction_date')->latest('id')->limit(1500)->get()
            ->map(fn ($row) => $this->row($row, $fniLibrary));
    }

    public function confirmedForSources(Collection $sources, string $asOf): Collection
    {
        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        $incidentIds = $sources->pluck('incident_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $this->eligible()->filter(function (array $release) use ($sourceIds, $incidentIds, $sources, $asOf): bool {
            if (count($release['linked_incident_references'] ?? []) > 1 && empty($release['incident_allocations'])) {
                return false;
            }
            $linked = array_intersect($sourceIds, $release['source_report_ids'] ?? []) !== [];
            $sameIncident = ($release['incident_id'] ?? null) && in_array((int) $release['incident_id'], $incidentIds, true);
            $allocated = collect($release['incident_allocations'] ?? [])->contains(fn (array $allocation): bool =>
                $sources->contains(fn (array $source): bool =>
                    filled($allocation['source_reference'] ?? null) && strcasecmp((string) $source['reference'], (string) $allocation['source_reference']) === 0
                    || filled($allocation['series_key'] ?? null) && (strcasecmp((string) $source['series'], (string) $allocation['series_key']) === 0 || str_ends_with(strtolower((string) $source['series']), strtolower((string) $allocation['series_key'])))
                )
            );

            return ($linked || $sameIncident || $allocated) && ($release['date'] ?? '9999-12-31') <= substr($asOf, 0, 10);
        })->flatMap(function (array $release) use ($sources): array {
            $allocated = collect($release['incident_allocations'] ?? [])->filter(function (array $allocation) use ($sources): bool {
                return $sources->contains(fn (array $source): bool =>
                    filled($allocation['source_reference'] ?? null) && strcasecmp((string) $source['reference'], (string) $allocation['source_reference']) === 0
                    || filled($allocation['series_key'] ?? null) && (strcasecmp((string) $source['series'], (string) $allocation['series_key']) === 0 || str_ends_with(strtolower((string) $source['series']), strtolower((string) $allocation['series_key'])))
                );
            });
            if ($allocated->isNotEmpty()) {
                $allocationTotal = collect($release['incident_allocations'])->sum('quantity');
                if ($allocationTotal > 0) {
                    return $allocated->map(function (array $allocation) use ($release, $sources, $allocationTotal): array {
                        $source = $sources->first(fn (array $candidate): bool =>
                            filled($allocation['source_reference'] ?? null) && strcasecmp((string) $candidate['reference'], (string) $allocation['source_reference']) === 0
                            || filled($allocation['series_key'] ?? null) && (strcasecmp((string) $candidate['series'], (string) $allocation['series_key']) === 0 || str_ends_with(strtolower((string) $candidate['series']), strtolower((string) $allocation['series_key'])))
                        );
                        return [...$release,
                            'id' => $release['id'].'-'.$source['id'],
                            'transaction_id' => $release['id'],
                            'quantity' => (float) ($allocation['released_quantity'] ?? 0),
                            'cost' => (float) ($allocation['released_cost'] ?? 0),
                            'source_id' => $source['id'], 'province' => $source['province'], 'municipality' => $source['municipality'],
                        ];
                    })->all();
                }
            }
            $source = $sources->first(fn (array $candidate): bool => in_array((int) $candidate['id'], $release['source_report_ids'] ?? [], true))
                ?? $sources->firstWhere('incident_id', $release['incident_id']);

            return [[...$release, 'source_id' => $source['id'], 'province' => $source['province'], 'municipality' => $source['municipality']]];
        })->values();
    }

    /** @param Collection<string, Collection<int, FniLibraryItem>> $fniLibrary */
    private function row(InventoryTransaction $transaction, Collection $fniLibrary): array
    {
        $quantity = (float) $transaction->quantity;
        $unitCost = (float) ($transaction->unit_cost ?? 0);
        $dispatch = $transaction->transactionable instanceof DispatchPlan ? $transaction->transactionable : null;
        $request = $dispatch?->request;
        $sourceReportIds = collect([$request?->source_lgu_dromic_request_id])
            ->filter()->map(fn ($id) => (int) $id)->values()->all();
        $linkedIncidentReferences = collect(data_get($request?->assessment_form_data, 'incidents', []))
            ->flatMap(fn (array $incident): array => array_filter([$incident['source_reference'] ?? null, $incident['series_key'] ?? null]))
            ->map(fn ($reference) => (string) $reference)->unique()->values()->all();
        $inventoryItemName = trim((string) ($transaction->batch?->item?->name ?: ''));
        $itemName = strtolower($inventoryItemName);
        $brand = trim((string) ($transaction->batch?->brand_description ?: ''));
        $libraryCandidates = $fniLibrary->get($itemName, collect());
        $libraryItem = $libraryCandidates->first(fn (FniLibraryItem $item): bool => strcasecmp(trim((string) $item->brand_description), $brand) === 0)
            ?? $libraryCandidates->first(fn (FniLibraryItem $item): bool => blank(trim((string) $item->brand_description)))
            ?? $libraryCandidates->first();
        $incidentAllocations = collect(data_get($request?->assessment_form_data, 'incident_fni_allocations', []))
            ->map(function (array $allocation) use ($itemName): ?array {
                $item = collect($allocation['items'] ?? [])->first(fn (array $entry): bool => strtolower(trim((string) ($entry['item_name'] ?? ''))) === $itemName);
                if (! $item || (float) ($item['quantity'] ?? 0) <= 0) return null;

                return [
                    'source_reference' => $allocation['source_reference'] ?? null,
                    'series_key' => $allocation['series_key'] ?? null,
                    'incident_type' => $allocation['incident_type'] ?? null,
                    'barangay' => $allocation['barangay'] ?? null,
                    'quantity' => (float) $item['quantity'],
                ];
            })->filter()->values();
        $allocationTotal = $incidentAllocations->sum('quantity');
        if ($allocationTotal > 0) {
            $incidentAllocations = $incidentAllocations->map(fn (array $allocation): array => [
                ...$allocation,
                '_raw_release_quantity' => $quantity * ((float) $allocation['quantity'] / $allocationTotal),
            ]);
            $remainingUnits = max(0, (int) round($quantity) - $incidentAllocations->sum(fn (array $allocation): int => (int) floor($allocation['_raw_release_quantity'])));
            $remainderOrder = $incidentAllocations->keys()->sortByDesc(fn (int $index): float => $incidentAllocations[$index]['_raw_release_quantity'] - floor($incidentAllocations[$index]['_raw_release_quantity']))->values();
            $incidentAllocations = $incidentAllocations->map(function (array $allocation, int $index) use ($remainingUnits, $remainderOrder, $unitCost): array {
                $releasedQuantity = (int) floor($allocation['_raw_release_quantity']) + ($remainderOrder->take($remainingUnits)->contains($index) ? 1 : 0);
                unset($allocation['_raw_release_quantity']);

                return [...$allocation, 'released_quantity' => $releasedQuantity, 'released_cost' => round($releasedQuantity * $unitCost, 2)];
            });
        }
        $incidentAllocations = $incidentAllocations->values()->all();
        $deliveryDates = collect([
            $dispatch?->received_at,
            $dispatch?->delivered_at,
            data_get($dispatch?->local_handover_details, 'received_at'),
            ...collect($dispatch?->vehicle_details ?? [])->pluck('received_at')->all(),
            ...collect($dispatch?->deliveryUpdates ?? [])->pluck('occurred_at')->all(),
        ])->filter()->map(fn ($date) => \Illuminate\Support\Carbon::parse($date))->sort();
        return [
            'id' => $transaction->id, 'date' => ($transaction->transaction_date ?? $transaction->created_at)?->format('Y-m-d'),
            'reference' => $transaction->reference_number ?: $transaction->ris_if_stf ?: $transaction->call_off_number ?: 'No reference',
            'dr_number' => $transaction->reference_number, 'ris_if_stf' => $transaction->ris_if_stf, 'call_off_number' => $transaction->call_off_number,
            'source_of_goods' => $transaction->source_of_goods, 'warehouse' => $transaction->batch?->warehouse?->display_name,
            'warehouse_type' => $transaction->batch?->warehouse?->warehouse_type, 'warehouse_partnership' => $transaction->batch?->warehouse?->partnership,
            'category' => $libraryItem?->item_category ?: $transaction->batch?->item?->category,
            'item' => $libraryItem?->item_name ?: ($inventoryItemName ?: 'Unspecified item'),
            'brand' => $brand, 'unit' => $libraryItem?->unit_of_measure ?: $transaction->batch?->item?->unit,
            'quantity' => $quantity, 'unit_cost' => $unitCost, 'cost' => (float) ($transaction->total_cost ?? ($quantity * $unitCost)),
            'recipient' => $transaction->recipient, 'delivery_site' => $transaction->delivery_site, 'purpose' => $transaction->purpose,
            'expected_delivery_date' => $transaction->expected_delivery_date?->format('Y-m-d'), 'remarks' => $transaction->remarks,
            'encoded_at' => $transaction->encoded_at?->format('Y-m-d H:i') ?? $transaction->created_at?->format('Y-m-d H:i'),
            'edited_at' => $transaction->edited_at?->format('Y-m-d H:i') ?? $transaction->updated_at?->format('Y-m-d H:i'),
            'record_source' => $transaction->sheetImport ? 'WIT Data Entry' : 'System Release',
            'reconciliation_status' => $transaction->reconciliation_status,
            'dispatch_id' => $dispatch?->id,
            'request_id' => $request?->id,
            'response_letter_date' => $request?->epirma_response_letter_signed_at?->format('Y-m-d')
                ?? $request?->lgu_response_letter_sent_at?->format('Y-m-d'),
            'delivery_or_receipt_date' => $deliveryDates->last()?->format('Y-m-d'),
            'incident_id' => $request?->incident_id,
            'source_report_ids' => $sourceReportIds,
            'linked_incident_references' => $linkedIncidentReferences,
            'incident_allocations' => $incidentAllocations,
        ];
    }
}
