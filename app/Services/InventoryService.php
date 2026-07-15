<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    public function reserveForRequest(AssistanceRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->items()->whereNotNull('inventory_item_id')->get() as $item) {
                $remaining = (float) ($item->approved_quantity ?? 0);

                if ($remaining <= 0) {
                    continue;
                }

                $batches = InventoryBatch::query()
                    ->where('inventory_item_id', $item->inventory_item_id)
                    ->where('current_status', 'available')
                    ->orderByRaw('expiration_date is null')
                    ->orderBy('expiration_date')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    $allocatable = min($remaining, $batch->available_quantity);

                    if ($allocatable <= 0) {
                        continue;
                    }

                    $batch->increment('reserved_quantity', $allocatable);
                    $batch->transactions()->create([
                        'user_id' => auth()->id(),
                        'type' => 'reservation',
                        'quantity' => $allocatable,
                        'balance_after' => $batch->fresh()->quantity,
                        'transactionable_type' => $request::class,
                        'transactionable_id' => $request->id,
                        'remarks' => "Reserved for {$request->reference_number}",
                    ]);

                    $remaining -= $allocatable;
                }

                if ($remaining > 0) {
                    throw new RuntimeException("Insufficient stock for {$item->item_name}.");
                }
            }
        });
    }
}
