<?php

namespace App\Console\Commands;

use App\Models\InventoryBatch;
use App\Models\InventoryTransaction;
use App\Services\InventoryBalanceService;
use App\Services\WitSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ResetDispatchWorkflowData extends Command
{
    protected $signature = 'data:reset-dispatch-workflow {--force : Confirm the destructive reset}';

    protected $description = 'Back up and remove request, RIS/DR, dispatch, delivery, and LGU DROMIC operational records.';

    public function handle(WitSyncService $witSync, InventoryBalanceService $balances): int
    {
        if (! $this->option('force')) {
            $this->error('This command is destructive. Run it again with --force after confirming the reset.');

            return self::FAILURE;
        }

        $backup = $this->backupDatabase();
        $before = $this->counts();

        DB::transaction(function (): void {
            $dispatchTransactions = InventoryTransaction::query()
                ->where('transactionable_type', 'App\\Models\\DispatchPlan')
                ->lockForUpdate()
                ->get();

            foreach ($dispatchTransactions as $transaction) {
                $adjustment = $transaction->type === 'release'
                    ? (float) $transaction->quantity
                    : -(float) $transaction->quantity;
                InventoryBatch::whereKey($transaction->inventory_batch_id)->increment('quantity', $adjustment);
            }
            InventoryTransaction::whereKey($dispatchTransactions->pluck('id'))->delete();

            DB::table('file_attachments')->whereIn('attachable_type', [
                'App\\Models\\AssistanceRequest',
                'App\\Models\\DispatchPlan',
                'App\\Models\\DispatchDeliveryUpdate',
                'App\\Models\\RequisitionIssuanceSlip',
            ])->delete();
            DB::table('epirma_signed_documents')->delete();
            DB::table('distribution_plans')->delete();
            DB::table('dispatch_delivery_updates')->delete();
            DB::table('dispatch_plan_items')->delete();
            DB::table('dispatch_plans')->delete();
            DB::table('dromic_reports')->delete();
            DB::table('requisition_issuance_items')->delete();
            DB::table('requisition_issuance_slips')->delete();
            DB::table('lgu_dromic_requested_items')->delete();
            DB::table('lgu_dromic_review_comments')->delete();
            DB::table('lgu_signed_document_versions')->delete();
            DB::table('approvals')->delete();
            DB::table('request_items')->delete();
            DB::table('requests')->update(['source_lgu_dromic_request_id' => null, 'lgu_correction_of_id' => null]);
            DB::table('requests')->delete();

            DB::table('notifications')
                ->where('data', 'like', '%"request_id"%')
                ->orWhere('data', 'like', '%"dispatch_id"%')
                ->orWhere('data', 'like', '%"ris_id"%')
                ->delete();
            DB::table('audit_logs')
                ->whereIn('auditable_type', [
                    'App\\Models\\AssistanceRequest',
                    'App\\Models\\DispatchPlan',
                    'App\\Models\\DispatchDeliveryUpdate',
                    'App\\Models\\RequisitionIssuanceSlip',
                    'App\\Models\\DromicReport',
                ])
                ->orWhere('event', 'like', 'request.%')
                ->orWhere('event', 'like', 'dispatch.%')
                ->orWhere('event', 'like', 'ris.%')
                ->orWhere('event', 'like', 'lgu.%')
                ->orWhere('event', 'like', 'dromic.%')
                ->delete();

        });

        $witSync->run('post_operational_reset');
        $after = $this->counts();
        $summary = $balances->summary();

        $this->info("Operational workflow reset completed. Backup: {$backup}");
        $this->table(['Record type', 'Before', 'After'], collect($before)->map(
            fn (int $count, string $table): array => [$table, $count, $after[$table] ?? 0]
        )->values()->all());
        $this->info('Current synchronized stockpile: '.number_format((float) $summary['current_balance'], 2));

        return self::SUCCESS;
    }

    private function backupDatabase(): string
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('Automatic safety backup is currently supported only for SQLite.');
        }

        $database = (string) config('database.connections.sqlite.database');
        $database = realpath($database) ?: $database;
        if (! is_file($database)) {
            throw new RuntimeException('SQLite database file was not found.');
        }

        $directory = database_path('backups');
        File::ensureDirectoryExists($directory);
        $backup = $directory.DIRECTORY_SEPARATOR.'database-before-workflow-reset-'.now()->format('Ymd-His').'.sqlite';
        $databaseRoot = rtrim(str_replace('\\', '/', realpath(database_path()) ?: database_path()), '/').'/';
        $resolvedTarget = str_replace('\\', '/', $backup);
        if (! str_starts_with($resolvedTarget, $databaseRoot)) {
            throw new RuntimeException('Refusing to create a backup outside the database directory.');
        }

        DB::statement("VACUUM INTO '".str_replace("'", "''", $backup)."'");

        return $backup;
    }

    private function counts(): array
    {
        return collect([
            'requests', 'request_items', 'approvals', 'requisition_issuance_slips',
            'requisition_issuance_items', 'dispatch_plans', 'dispatch_plan_items',
            'dispatch_delivery_updates', 'distribution_plans', 'dromic_reports',
            'lgu_dromic_requested_items', 'lgu_dromic_review_comments',
            'lgu_signed_document_versions', 'epirma_signed_documents',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
