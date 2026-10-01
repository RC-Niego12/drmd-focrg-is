<?php

namespace App\Console\Commands;

use App\Services\StfSheetSyncService;
use Illuminate\Console\Command;

class SyncStfData extends Command
{
    protected $signature = 'stf:sync {--trigger=automatic}';

    protected $description = 'Synchronize STF transaction rows from the configured tracking Google Sheet.';

    public function handle(StfSheetSyncService $sync): int
    {
        try {
            $result = $sync->run((string) $this->option('trigger'));
            $this->info("STF sync complete: {$result['rows_seen']} row(s) reviewed from {$result['source']}.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
