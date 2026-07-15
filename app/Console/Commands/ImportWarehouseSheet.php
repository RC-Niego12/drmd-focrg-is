<?php

namespace App\Console\Commands;

use App\Services\WarehouseSheetImportService;
use Illuminate\Console\Command;

class ImportWarehouseSheet extends Command
{
    protected $signature = 'inventory:import-google-sheet
        {url? : Public Google Sheet URL}
        {--worksheet= : Worksheet name for audit/reference only}';

    protected $description = 'Import the FO CARAGA warehouse inventory Google Sheet into the normalized inventory tables.';

    public function handle(WarehouseSheetImportService $importer): int
    {
        $url = $this->argument('url') ?: config('services.google_sheets.url');

        if (! $url) {
            $this->error('Provide a Google Sheet URL or set GOOGLE_SHEETS_URL in .env.');

            return self::FAILURE;
        }

        $summary = $importer->import($url, $this->option('worksheet') ?: config('services.google_sheets.worksheet'));

        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $key) => [$key, $value])->all());

        return self::SUCCESS;
    }
}
