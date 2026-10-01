<?php

namespace App\Http\Controllers;

use App\Services\InventoryBalanceService;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Http\Request;
use Inertia\Response;

class LguInventoryController extends Controller
{
    public function index(
        Request $request,
        InventoryBalanceService $inventoryBalances,
        LguWarehousePersonnelSyncService $warehouseScope,
        InventoryController $inventory,
    ): Response {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('LGU') || $user->can('submit lgu dromic requests')),
            403,
            'You are not authorized to view LGU inventory.',
        );

        $warehouses = $warehouseScope->warehousesForUser($user, partnershipLguOnly: true);

        return $inventory->renderInventoryIndex($request, $inventoryBalances, $warehouses, [
            'workspace' => 'lgu',
            'filterBasePath' => '/lgu/inventory',
            'sync' => null,
        ]);
    }
}
