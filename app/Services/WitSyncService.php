<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StandbyFund;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class WitSyncService
{
    public function __construct(
        private WarehouseMasterSheetImportService $warehouses,
        private WarehouseSheetImportService $inventory,
        private StandbyFundSheetSyncService $standbyFunds,
        private InventoryBalanceService $balances,
        private AuditLogger $audit,
        private RealtimePublisher $realtime,
    ) {}

    public function run(string $trigger = 'automatic', ?int $userId = null): array
    {
        $lock = Cache::lock('wit.full_sync', 240);
        if (! $lock->get()) {
            throw new RuntimeException('A WIT synchronization is already running.');
        }

        $before = $this->snapshot();
        $startedAt = now();

        try {
            $warehouseUrl = (string) config('services.google_sheets.warehouse_master_url');
            $inventoryUrl = (string) config('services.google_sheets.url');
            $standbyUrl = (string) config('services.google_sheets.standby_fund_url');
            if ($warehouseUrl === '' || $inventoryUrl === '' || $standbyUrl === '') {
                throw new RuntimeException('One or more WIT Google Sheet sources are not configured.');
            }

            $stages = [
                'warehouses' => $this->warehouses->import($warehouseUrl, config('services.google_sheets.warehouse_master_worksheet')),
                'inventory' => $this->inventory->import($inventoryUrl, config('services.google_sheets.worksheet')),
            ];
            $cell = (string) config('services.google_sheets.standby_fund_cell', 'M2');
            $amount = $this->standbyFunds->fetchAmount($standbyUrl, $cell);
            $fund = StandbyFund::current();
            $fundValues = [
                'amount' => $amount,
                'source' => "Synced from WIT {$cell}",
                'google_sheet_url' => $standbyUrl,
                'cell_reference' => $cell,
                'synced_at' => now(),
            ];
            if ($userId !== null) {
                $fundValues['updated_by'] = $userId;
            }
            $fund->update($fundValues);
            $stages['standby_funds'] = ['cell' => $cell, 'amount' => $amount];

            $after = $this->snapshot();
            $result = [
                'trigger' => $trigger,
                'started_at' => $startedAt->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'changed' => $before !== $after,
                'stages' => $stages,
                'state' => $after,
            ];
            $this->audit->log('wit.sync.completed', null, $before, $result, $userId);
            $this->broadcast($result);

            return $result;
        } catch (\Throwable $exception) {
            $failure = [
                'trigger' => $trigger,
                'started_at' => $startedAt->toIso8601String(),
                'failed_at' => now()->toIso8601String(),
                'message' => $exception->getMessage(),
            ];
            $this->audit->log('wit.sync.failed', null, $before, $failure, $userId);
            $this->broadcast($failure, 'wit.sync.failed');
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function history(int $limit = 30)
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->whereIn('event', ['wit.sync.completed', 'wit.sync.failed'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function snapshot(): array
    {
        $rows = collect($this->balances->balanceRows());
        $fund = StandbyFund::current();
        $warehouseState = Warehouse::query()
            ->orderBy('external_warehouse_id')
            ->get([
                'external_warehouse_id', 'name', 'province', 'municipality', 'district',
                'distribution_network', 'warehouse_type', 'ownership', 'partnership',
                'ffp_capacity', 'rtef_capacity', 'sheet_ffp_current', 'sheet_ffp_cost',
                'sheet_total_items', 'sheet_total_cost', 'longitude', 'latitude', 'status',
            ])
            ->toJson();

        return [
            'warehouses' => Warehouse::count(),
            'warehouse_master_checksum' => hash('sha256', $warehouseState),
            'inventory_rows' => WarehouseSheetImport::where('import_status', 'imported')->count(),
            'stockpile_quantity' => round($rows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)), 2),
            'stockpile_cost' => round($rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)), 2),
            'standby_funds' => round((float) $fund->amount, 2),
            'grand_total' => round((float) $fund->amount + $rows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)), 2),
        ];
    }

    private function broadcast(array $payload, string $event = 'wit.sync.completed'): void
    {
        $this->realtime->usersChanged(User::query()->pluck('id'), $event, $payload);
    }
}
