<?php

namespace Tests\Unit;

use App\Models\DistributionPlan;
use App\Services\SotexPlanningService;
use Tests\TestCase;

class SotexPlanningServiceTest extends TestCase
{
    public function test_open_allocations_reduce_the_warehouse_balance_the_way_the_sotex_sheet_does(): void
    {
        $service = new SotexPlanningService;
        $plans = collect([
            $this->plan(1, 61, 'Family Food Pack', 'Prepacked', 'Sep 2026', 300, 300),
            $this->plan(2, 61, 'Family Food Pack', 'Prepacked', 'Sep 2026', 200, 0),
        ]);

        $presented = $service->present(collect([
            [
                'warehouse_id' => 61,
                'warehouse' => 'Agusan Del Norte, Dapa01',
                'warehouse_district' => 'ADN1',
                'district' => 'ADN1',
                'partnership' => 'DSWD',
                'item' => 'Family Food Pack',
                'brand' => 'Prepacked',
                'expiry_month' => 'Sep 2026',
                'quantity' => 800,
            ],
        ]), $plans);

        $this->assertSame(600.0, $presented['stock'][0]['remaining']);
        $this->assertSame(200.0, $presented['stock'][0]['open_allocation']);
        $this->assertSame('released', $presented['plans'][0]['line_status']);
        $this->assertSame('allocated', $presented['plans'][1]['line_status']);
        $this->assertNull($service->allocationError($presented['stock'], $presented['stock'][0]['key'], 100, 0));
        $this->assertNotNull($service->allocationError($presented['stock'], $presented['stock'][0]['key'], 601, 0));
    }

    public function test_a_released_line_does_not_keep_deducting_stock_that_wit_already_issued(): void
    {
        $service = new SotexPlanningService;
        $plan = $this->plan(1, 8, 'Ready to Eat Food', 'Prepacked', 'Sep 2026', 1500, 1500);
        $presented = $service->present(collect([
            [
                'warehouse_id' => 8,
                'warehouse' => 'Butuan - Tiniwisan',
                'district' => 'ADN1',
                'partnership' => 'LGU',
                'item' => 'Ready to Eat Food',
                'brand' => 'Prepacked',
                'expiry_month' => 'Sep 2026',
                'quantity' => 1500,
            ],
        ]), collect([$plan]));

        $this->assertSame(0.0, $presented['stock'][0]['open_allocation']);
        $this->assertSame(1500.0, $presented['stock'][0]['remaining']);
    }

    public function test_a_proposal_trail_is_stored_without_an_inventory_field(): void
    {
        $service = new SotexPlanningService;
        $warehouse = new \App\Models\Warehouse([
            'name' => 'Dapa01',
            'province' => 'Agusan Del Norte',
            'district' => 'ADN1',
            'partnership' => 'DSWD',
        ]);
        $warehouse->id = 61;

        $attributes = $service->planAttributes([
            'lgu' => 'MLGU - Dapa, SDN',
            'activity' => 'Food for Work',
            'item_name' => 'Family Food Pack',
            'brand' => 'Prepacked',
            'expiry_month' => 'Sep 2026',
            'allocated_quantity' => 100,
            'released_quantity' => 0,
            'proposal_received_on' => '2026-05-05',
            'proposal_approved_on' => '2026-05-06',
            'delivery_mode' => 'c/o RROS',
            'progress_status' => 'Approved Proposal',
            'remarks' => 'Waiting for RIS',
        ], $warehouse);

        $this->assertSame('Food-for-Work', $attributes['activity']);
        $this->assertSame('2026-05-05', $attributes['proposal_received_on']);
        $this->assertSame('c/o RROS', $attributes['delivery_mode']);
        $this->assertSame('Approved Proposal', $attributes['progress_status']);
        $this->assertArrayNotHasKey('current_balance', $attributes);
        $this->assertSame(500.0, $service->present(collect([
            [
                'warehouse_id' => 61,
                'warehouse' => 'Agusan Del Norte, Dapa01',
                'district' => 'ADN1',
                'partnership' => 'DSWD',
                'item' => 'Family Food Pack',
                'brand' => 'Prepacked',
                'expiry_month' => 'Sep 2026',
                'quantity' => 800,
            ],
        ]), collect([$this->plan(1, 61, 'Family Food Pack', 'Prepacked', 'Sep 2026', 300, 0)]))['coverage']['unallocated_quantity']);
    }

    public function test_current_progress_follows_the_recorded_dates_and_releases(): void
    {
        $service = new SotexPlanningService;
        $approved = ['proposal_received_on' => '2026-05-02', 'proposal_approved_on' => '2026-05-05'];
        $working = [...$approved, 'work_schedule_on' => '2026-05-06'];
        $delivered = [...$working, 'delivered_on' => '2026-05-07', 'delivered_end_on' => '2026-05-08'];
        $completed = [...$delivered, 'work_schedule_end_on' => '2026-05-09'];
        $distributed = [...$completed, 'distributed_on' => '2026-05-10', 'distributed_end_on' => '2026-05-11'];

        $this->assertSame("Awaiting LGU's Submission", $service->derivedProgress(['proposal_received_on' => '2026-05-02'], 100, 0));
        $this->assertSame('Approved Proposal', $service->derivedProgress($approved, 100, 0));
        $this->assertSame('Ongoing Activity', $service->derivedProgress($working, 100, 0));
        $this->assertSame('SoTEx Partially Delivered', $service->derivedProgress([...$working, 'delivered_on' => '2026-05-07'], 100, 0));
        $this->assertSame('SoTEx Fully Delivered', $service->derivedProgress($delivered, 100, 0));
        $this->assertSame('Activity Completed', $service->derivedProgress($completed, 100, 0));
        $this->assertSame('SoTEx Partially Distributed', $service->derivedProgress([...$completed, 'distributed_on' => '2026-05-10'], 100, 0));
        $this->assertSame('SoTEx Partially Distributed', $service->derivedProgress($distributed, 100, 60));
        $this->assertSame('SoTEx Fully Distributed', $service->derivedProgress($distributed, 100, 100));
        $this->assertSame('Activity Completed', $service->derivedProgress([...$working, 'work_schedule_end_on' => '2026-05-09', 'delivery_target_na' => true], 100, 0));
    }

    private function plan(int $id, int $warehouseId, string $item, string $brand, string $expiry, float $allocated, float $released): DistributionPlan
    {
        $plan = new DistributionPlan([
            'warehouse_id' => $warehouseId,
            'item_name' => $item,
            'brand' => $brand,
            'expiry_month' => $expiry,
            'lgu' => 'Dapa, SUN',
            'activity' => 'Food for Work',
            'allocated_quantity' => $allocated,
            'released_quantity' => $released,
            'quantity' => $allocated,
            'source_warehouse_name' => 'Warehouse',
            'district' => 'ADN1',
            'partnership' => 'DSWD',
        ]);
        $plan->id = $id;

        return $plan;
    }
}
