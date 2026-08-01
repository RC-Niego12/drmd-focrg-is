<?php

namespace App\Console\Commands;

use App\Services\LguDirectorySyncService;
use Illuminate\Console\Command;

class SyncLguLdrrmoDirectory extends Command
{
    protected $signature = 'lgu:sync-ldrrmo-directory
        {--url= : CSV export URL for the LDRRMO directory sheet}
        {--dry-run : Read and match rows without saving changes}';

    protected $description = 'Sync live regional LCE/LSWDO data and the dedicated LDRRMO directory into the local LGU directory.';

    public function handle(LguDirectorySyncService $service): int
    {
        if ($this->option('url')) {
            $this->warn('The directory now uses the configured live regional workbook; the --url option is retained only for command compatibility.');
        }

        if ($this->option('dry-run')) {
            $summary = $service->preview();
            $this->info('Dry run complete.');
            $this->line("LGUs: {$summary['total']}; changed: {$summary['changed']}; new: {$summary['new']}; missing: {$summary['missing']}");

            return self::SUCCESS;
        }

        $this->info('Synchronizing the live regional LGU/LSWDO directory and normalized LDRRMO directory...');
        $summary = $service->sync();
        $ldrrmo = $summary['ldrrmo'] ?? [];
        $this->info('Sync complete.');
        $this->line("LDRRMO rows matched: ".($ldrrmo['matched'] ?? 0));
        $this->line("LDRRMO rows updated: ".($ldrrmo['updated'] ?? 0));
        $this->line("LDRRMO rows unmatched: ".count($ldrrmo['unmatched'] ?? []));

        return self::SUCCESS;
    }
}
