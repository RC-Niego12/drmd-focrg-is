<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\WarehouseSheetImport;
use Inertia\Inertia;
use Inertia\Response;

class InventoryProcessFlowController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Inventory/ProcessFlow', [
            'sheet' => [
                'url' => config('services.google_sheets.url'),
                'worksheet' => config('services.google_sheets.worksheet'),
                'title' => 'FO CARAGA Warehouse Inventory Tool',
            ],
            'importSummary' => [
                'imported_rows' => WarehouseSheetImport::where('import_status', 'imported')->count(),
                'failed_rows' => WarehouseSheetImport::where('import_status', 'failed')->count(),
                'receipt_transactions' => InventoryTransaction::where('type', 'receipt')->count(),
                'release_transactions' => InventoryTransaction::where('type', 'release')->count(),
            ],
            'recentImports' => WarehouseSheetImport::with(['warehouse', 'item', 'transaction'])
                ->latest()
                ->limit(12)
                ->get(),
            'recentTransactions' => InventoryTransaction::with(['batch.item', 'batch.warehouse'])
                ->latest()
                ->limit(12)
                ->get(),
        ]);
    }
}
