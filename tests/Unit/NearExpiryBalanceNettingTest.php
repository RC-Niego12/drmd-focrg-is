<?php

namespace Tests\Unit;

use App\Services\InventoryBalanceService;
use Tests\TestCase;

class NearExpiryBalanceNettingTest extends TestCase
{
    public function test_it_drops_expiry_stock_that_wit_has_already_issued_at_a_different_unit_cost(): void
    {
        $rows = collect([
            $this->row('Ready to Eat Food', 'Jun 2026', 940, 858220, 913),
            $this->row('Ready to Eat Food', 'Jun 2026', 2, 1716, 858),
            $this->row('Ready to Eat Food', 'Jun 2026', -942, -859936, 912.88),
            $this->row('Family Food Pack', 'Aug 2026', 5, 2786.65, 557.33, 'Produced - Vacuum'),
            $this->row('Family Food Pack', 'Aug 2026', -5, -2786.65, 557.33, 'Produced - Vacuum'),
            $this->row('Family Food Pack', 'Jan 2027', 8000, 4400000, 550, 'Produced - Vacuum'),
        ]);

        $net = (new InventoryBalanceService)->netExpiryBalances($rows);

        $this->assertCount(1, $net);
        $this->assertSame('Family Food Pack', $net[0]['item']);
        $this->assertSame('Jan 2027', $net[0]['expiry']);
        $this->assertSame(8000.0, $net[0]['current_balance']);
    }

    public function test_it_reduces_a_partial_issuance_instead_of_keeping_the_original_receipt(): void
    {
        $rows = collect([
            $this->row('Family Food Pack', 'Apr 2027', 1000, 550000, 550, 'Produced - Vacuum'),
            $this->row('Family Food Pack', 'Apr 2027', -576, -316800, 550.01, 'Produced - Vacuum'),
        ]);

        $net = (new InventoryBalanceService)->netExpiryBalances($rows);

        $this->assertCount(1, $net);
        $this->assertSame(424.0, $net[0]['current_balance']);
        $this->assertSame(233200.0, $net[0]['cost']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $item, string $expiry, float $quantity, float $cost, float $unitCost, string $brand = '-'): array
    {
        return [
            'warehouse_id' => 61,
            'warehouse' => 'Butuan - Villa Kanangga',
            'category' => 'Food Items',
            'item' => $item,
            'brand_description' => $brand,
            'expiry' => $expiry,
            'unit_cost' => $unitCost,
            'current_balance' => $quantity,
            'reserved_quantity' => 0,
            'cost' => $cost,
        ];
    }
}
