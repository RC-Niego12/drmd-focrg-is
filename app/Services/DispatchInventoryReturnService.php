<?php

namespace App\Services;

use App\Models\DispatchPlan;
use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

class DispatchInventoryReturnService
{
    public function record(DispatchPlan $dispatch, ?User $user): array
    {
        $dispatch->loadMissing(['items', 'requisitionIssuanceSlip.allocationItems']);
        $created = [];

        foreach ($dispatch->items as $item) {
            $quantity = max(0, (int) $item->allocated_quantity - (int) ($item->received_quantity ?? 0));
            $shouldRestock = $item->variance_disposition === 'returned'
                && $item->return_stock_disposition === 'restock_available'
                && $quantity > 0;
            $reference = 'RETURN-'.$item->id;
            $existing = InventoryTransaction::query()
                ->where('type', 'return')
                ->where('transactionable_type', DispatchPlan::class)
                ->where('transactionable_id', $dispatch->id)
                ->where('reference_number', $reference)
                ->lockForUpdate()
                ->first();

            // Updating a confirmed receipt must also undo a previously-restocked
            // return when it is removed, cancelled, or changed to non-serviceable.
            if (! $shouldRestock) {
                if ($existing) {
                    $batch = InventoryBatch::query()->lockForUpdate()->find($existing->inventory_batch_id);
                    if ($batch) {
                        $batch->update(['quantity' => max(0, (float) $batch->quantity - (float) $existing->quantity)]);
                    }
                    $existing->delete();
                }
                continue;
            }

            $batch = $existing
                ? InventoryBatch::query()->lockForUpdate()->find($existing->inventory_batch_id)
                : null;
            if (! $batch) {
                $allocation = $dispatch->requisitionIssuanceSlip?->allocationItems
                    ?->firstWhere('id', $item->requisition_issuance_item_id);
                $batch = InventoryBatch::query()
                    ->where('warehouse_id', $item->warehouse_id)
                    ->whereHas('item', fn ($query) => $query->whereRaw('LOWER(name) = ?', [strtolower(trim($item->item_name))]))
                    ->when(filled($allocation?->brand_description), fn ($query) => $query->where('brand_description', $allocation->brand_description))
                    ->oldest('id')->lockForUpdate()->first();
            }
            if (! $batch) continue;

            $oldQuantity = $existing ? (float) $existing->quantity : 0;
            $delta = $quantity - $oldQuantity;
            $newBatchQuantity = max(0, (float) $batch->quantity + $delta);
            $unitCost = $existing?->unit_cost
                ?? $batch->transactions()->whereNotNull('unit_cost')->latest('id')->value('unit_cost');
            $values = [
                'user_id' => $user?->id,
                'type' => 'return',
                'transaction_date' => Carbon::parse($item->return_received_at)->toDateString(),
                'reference_number' => $reference,
                'ris_if_stf' => $dispatch->requisitionIssuanceSlip?->ris_number,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost !== null ? round((float) $unitCost * $quantity, 2) : null,
                'balance_after' => $newBatchQuantity,
                'recipient' => $dispatch->receiving_agency_lgu,
                'delivery_site' => $dispatch->destination,
                'reconciliation_status' => 'system_return',
                'encoded_by_email' => $user?->email,
                'encoded_at' => now(),
                'transactionable_type' => DispatchPlan::class,
                'transactionable_id' => $dispatch->id,
                'remarks' => '[SERVICEABLE RETURN RESTOCKED] '.$item->variance_resolution,
            ];
            if ($existing) {
                $existing->update([
                    ...$values,
                    'edited_by_email' => $user?->email,
                    'edited_at' => now(),
                ]);
                $transaction = $existing;
            } else {
                $transaction = $batch->transactions()->create($values);
                $created[] = $transaction->id;
            }
            $batch->update(['quantity' => $newBatchQuantity]);
        }

        return $created;
    }
}
