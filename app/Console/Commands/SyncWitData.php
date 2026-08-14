<?php

namespace App\Console\Commands;

use App\Services\WitSyncService;
use Illuminate\Console\Command;

class SyncWitData extends Command
{
    protected $signature = 'wit:sync {--trigger=automatic}';

    protected $description = 'Synchronize WIT warehouses, inventory, and standby funds, then broadcast changes.';

    public function handle(WitSyncService $sync): int
    {
        try {
            $result = $sync->run((string) $this->option('trigger'));
            $this->info('WIT sync completed. Changed: '.($result['changed'] ? 'yes' : 'no'));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
