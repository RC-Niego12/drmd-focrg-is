<?php

namespace App\Console\Commands;

use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use Illuminate\Console\Command;

class ReconcileInventoryBalances extends Command
{
    protected $signature = 'inventory:reconcile-balances';

    protected $description = 'Recompute batch quantities from receipt and release transaction history.';

    public function handle(): int
    {
        $updated = 0;

        InventoryBatch::with('transactions')->chunkById(100, function ($batches) use (&$updated): void {
            foreach ($batches as $batch) {
                $quantity = $batch->transactions->sum(
                    fn (InventoryTransaction $transaction): float => $transaction->type === 'release'
                        ? -1 * (float) $transaction->quantity
                        : (float) $transaction->quantity
                );

                if ((float) $batch->quantity !== (float) $quantity) {
                    $batch->update(['quantity' => $quantity]);
                    $updated++;
                }
            }
        });

        $this->info("Reconciled {$updated} inventory batches.");

        return self::SUCCESS;
    }
}
