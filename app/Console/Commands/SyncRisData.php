<?php

namespace App\Console\Commands;

use App\Services\RisSheetSyncService;
use Illuminate\Console\Command;

class SyncRisData extends Command
{
    protected $signature = 'ris:sync {--trigger=automatic}';

    protected $description = 'Synchronize RIS/DR tracking and FNI allocation rows from Google Sheets.';

    public function handle(RisSheetSyncService $sync): int
    {
        try {
            $result = $sync->run((string) $this->option('trigger'));
            $this->info("RIS sync complete: {$result['records_created']} created, {$result['records_updated']} updated.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
