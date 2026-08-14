<?php

namespace App\Console\Commands;

use App\Services\DispatchContactSheetSyncService;
use Illuminate\Console\Command;

class SyncDispatchContacts extends Command
{
    protected $signature = 'dispatch:sync-contacts';

    protected $description = 'Import Driver (Transported By) and Received By libraries from RIS tracking sheet columns AU/AV/AX.';

    public function handle(DispatchContactSheetSyncService $sync): int
    {
        try {
            $result = $sync->sync();
            $this->info(sprintf(
                'Dispatch contacts synced (rows %d): drivers +%d/~%d, received-by +%d/~%d; removed duplicate rows drivers=%d received-by=%d.',
                $result['rows_seen'],
                $result['drivers_created'],
                $result['drivers_updated'],
                $result['received_by_created'],
                $result['received_by_updated'],
                $result['drivers_duplicates_removed'] ?? 0,
                $result['received_by_duplicates_removed'] ?? 0,
            ));
            $this->line('Source: spreadsheet '.config('services.google_sheets.ris_tracking_spreadsheet_id')
                .' gid '.config('services.google_sheets.ris_tracking_gid', '1905199506'));
            $this->line('Mapped: AU Name of Driver, AV Contact Number of Driver, AX Received By (AW plate skipped).');
            $this->line('Uniqueness: trim + case-insensitive name; first non-empty contact/position/office wins on merge.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
