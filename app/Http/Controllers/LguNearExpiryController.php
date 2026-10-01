<?php

namespace App\Http\Controllers;

use App\Services\InventoryBalanceService;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Http\Request;
use Inertia\Response;

class LguNearExpiryController extends Controller
{
    public function index(
        Request $request,
        InventoryBalanceService $inventoryBalances,
        LguWarehousePersonnelSyncService $warehouseScope,
        DistributionPlanController $nearExpiry,
    ): Response {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('LGU') || $user->can('submit lgu dromic requests')),
            403,
            'You are not authorized to view LGU near-expiry stock.',
        );

        $warehouseIds = $warehouseScope->warehouseIdsForUser($user, partnershipLguOnly: true);

        return $nearExpiry->renderNearExpiry($inventoryBalances, $warehouseIds, [
            'workspace' => 'lgu',
            'include_plans' => false,
        ]);
    }
}
