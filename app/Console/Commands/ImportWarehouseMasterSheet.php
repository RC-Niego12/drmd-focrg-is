<?php

namespace App\Console\Commands;

use App\Services\WarehouseMasterSheetImportService;
use Illuminate\Console\Command;

class ImportWarehouseMasterSheet extends Command
{
    protected $signature = 'warehouses:import-google-sheet
        {url? : Public Google Sheet URL}
        {--worksheet= : Worksheet name for audit/reference only}';

    protected $description = 'Import the FO CARAGA managed warehouses Google Sheet into the warehouse master table.';

    public function handle(WarehouseMasterSheetImportService $importer): int
    {
        $url = $this->argument('url') ?: config('services.google_sheets.warehouse_master_url');

        if (! $url) {
            $this->error('Provide a Google Sheet URL or set GOOGLE_WAREHOUSE_MASTER_URL in .env.');

            return self::FAILURE;
        }

        $summary = $importer->import($url, $this->option('worksheet') ?: config('services.google_sheets.warehouse_master_worksheet'));

        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $key) => [$key, $value])->all());

        return self::SUCCESS;
    }
}
