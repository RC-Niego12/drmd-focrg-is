<?php

namespace App\Console\Commands;

use App\Services\EpirmaDocumentStatusService;
use Illuminate\Console\Command;

class SyncEpirmaDocumentStatus extends Command
{
    protected $signature = 'epirma:sync-document-status
        {--limit=100 : Max open documents to sync per run}
        {--cache-signed : Also download/cache signed PDFs missing a local signed cache}';

    protected $description = 'Poll e-PIRMA latest-document-base-path for open routed/partial documents and persist status (including cancel via repeated Document not found)';

    public function handle(EpirmaDocumentStatusService $statusService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Syncing up to {$limit} open e-PIRMA documents...");

        $result = $statusService->syncOpenDocuments($limit);

        $this->line('synced='.$result['synced']);
        $this->line('signed='.$result['signed']);
        $this->line('failed='.$result['failed']);

        if ($this->option('cache-signed')) {
            $this->newLine();
            $this->info("Caching missing signed PDFs (limit {$limit})...");
            $cache = $statusService->cacheMissingSignedPdfs($limit);
            $this->line('cached='.$cache['cached']);
            $this->line('failed='.$cache['failed']);
            $this->line('skipped='.$cache['skipped']);
        }

        return self::SUCCESS;
    }
}
