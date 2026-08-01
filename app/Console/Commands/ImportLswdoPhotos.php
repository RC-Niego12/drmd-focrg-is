<?php

namespace App\Console\Commands;

use App\Services\LswdoPhotoImportService;
use Illuminate\Console\Command;

class ImportLswdoPhotos extends Command
{
    protected $signature = 'lgu:import-lswdo-photos
        {--url= : Override the canonical workbook export URL}
        {--file= : Import an already downloaded XLSX workbook}';

    protected $description = 'Extract row-anchored LSWDO photos from the regional directory workbook.';

    public function handle(LswdoPhotoImportService $service): int
    {
        $this->info('Downloading and matching LSWDO photos by province, LGU, row, and officer name...');
        $summary = $this->option('file')
            ? $service->importFile((string) $this->option('file'))
            : $service->import($this->option('url') ?: null);

        $this->info("Imported: {$summary['imported']}; unchanged: {$summary['unchanged']}; placeholders skipped: {$summary['placeholder_skipped']}; stale photos cleared: {$summary['stale_cleared']}.");
        $this->line('LGUs unmatched: '.count($summary['lgu_unmatched']));
        $this->line('Names mismatched: '.count($summary['name_mismatched']));
        $this->line('Invalid images: '.count($summary['invalid_images']));

        foreach (array_slice($summary['name_mismatched'], 0, 20) as $row) {
            $this->warn("{$row['sheet']} row {$row['row']} {$row['lgu']}: workbook '{$row['name']}' does not match directory '".($row['directory_name'] ?: '-')."'.");
        }
        foreach (array_slice($summary['lgu_unmatched'], 0, 20) as $row) {
            $this->warn("{$row['sheet']} row {$row['row']}: LGU '{$row['lgu']}' was not matched.");
        }

        return self::SUCCESS;
    }
}
