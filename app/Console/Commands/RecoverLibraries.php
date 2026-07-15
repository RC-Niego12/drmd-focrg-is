<?php

namespace App\Console\Commands;

use App\Services\LguDirectorySyncService;
use App\Services\LibraryRecoveryService;
use App\Services\PsgcSyncService;
use App\Services\OfflineCaragaPsgcRecoveryService;
use Illuminate\Console\Command;

class RecoverLibraries extends Command
{
    protected $signature='libraries:recover {--with-psgc : Synchronize the complete PSGC and LGU directory after local recovery}';
    protected $description='Idempotently restore normalized libraries from cached WIT files and existing system data';

    public function handle(LibraryRecoveryService $recovery,PsgcSyncService $psgc,LguDirectorySyncService $lgu,OfflineCaragaPsgcRecoveryService $offlinePsgc): int
    {
        $summary=$recovery->recoverLocal();
        $this->info('Local libraries: '.json_encode($summary['before']).' -> '.json_encode($summary['after']));
        if($this->option('with-psgc')){
            $this->info('Synchronizing complete PSGC reference data...');
            config(['services.psgc.allow_api_fallback'=>true]);
            try{$this->line(json_encode($psgc->sync(true)));}
            catch(\Throwable $e){$this->warn('Authoritative PSGC endpoints unavailable: '.$e->getMessage());$this->warn('Applying version-labelled offline Caraga recovery data.');$this->line(json_encode($offlinePsgc->recover()));}
            $this->info('Synchronizing LGU directory...');
            $this->line(json_encode($lgu->sync()));
        }
        return self::SUCCESS;
    }
}
