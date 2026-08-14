<?php

namespace App\Services;

use App\Models\DispatchPlan;
use App\Models\InventoryBatch;
use App\Models\User;
use Illuminate\Support\Carbon;

class DispatchInventoryReturnService
{
    public function record(DispatchPlan $dispatch, ?User $user): array
    {
        $dispatch->loadMissing(['items', 'requisitionIssuanceSlip.allocationItems']);
        $created = [];

        foreach ($dispatch->items->where('variance_disposition', 'returned') as $item) {
            $quantity = max(0, (int) $item->allocated_quantity - (int) ($item->received_quantity ?? 0));
            if ($quantity === 0 || $item->return_stock_disposition !== 'restock_available') {
                // Near-expiry and damaged/expired returns stay outside usable stock.
                // Their inspection result remains on the dispatch item audit record.
                continue;
            }
            $allocation = $dispatch->requisitionIssuanceSlip?->allocationItems
                ?->firstWhere('id', $item->requisition_issuance_item_id);
            $batch = InventoryBatch::query()
                ->where('warehouse_id', $item->warehouse_id)
                ->whereHas('item', fn ($query) => $query->whereRaw('LOWER(name) = ?', [strtolower(trim($item->item_name))]))
                ->when(filled($allocation?->brand_description), fn ($query) => $query->where('brand_description', $allocation->brand_description))
                ->oldest('id')->lockForUpdate()->first();
            if (! $batch) continue;

            $alreadyRecorded = $batch->transactions()->where('type', 'return')
                ->where('transactionable_type', DispatchPlan::class)
                ->where('transactionable_id', $dispatch->id)
                ->where('reference_number', 'RETURN-'.$item->id)->exists();
            if ($alreadyRecorded) continue;

            $unitCost = $batch->transactions()->whereNotNull('unit_cost')->latest('id')->value('unit_cost');
            $transaction = $batch->transactions()->create([
                'user_id' => $user?->id,
                'type' => 'return',
                'transaction_date' => Carbon::parse($item->return_received_at)->toDateString(),
                'reference_number' => 'RETURN-'.$item->id,
                'ris_if_stf' => $dispatch->requisitionIssuanceSlip?->ris_number,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost !== null ? round((float) $unitCost * $quantity, 2) : null,
                'balance_after' => (float) $batch->quantity + $quantity,
                'recipient' => $dispatch->receiving_agency_lgu,
                'delivery_site' => $dispatch->destination,
                'reconciliation_status' => 'system_return',
                'encoded_by_email' => $user?->email,
                'encoded_at' => now(),
                'transactionable_type' => DispatchPlan::class,
                'transactionable_id' => $dispatch->id,
                'remarks' => '[SERVICEABLE RETURN RESTOCKED] '.$item->variance_resolution,
            ]);
            $batch->update([
                'quantity' => (float) $batch->quantity + $quantity,
                'current_status' => $batch->current_status,
            ]);
            $created[] = $transaction->id;
        }

        return $created;
    }
}
