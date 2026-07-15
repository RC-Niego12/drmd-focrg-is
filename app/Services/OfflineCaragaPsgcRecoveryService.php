<?php

namespace App\Services;

use App\Models\PsgcAddress;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OfflineCaragaPsgcRecoveryService
{
    public function recover(): array
    {
        $process=new Process(['node',base_path('scripts/export_caraga_psgc.mjs')],base_path());
        $process->setTimeout(120);$process->mustRun();
        $rows=json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);$batch=(string)Str::uuid();$counts=[];
        foreach($rows as $row){
            PsgcAddress::updateOrCreate(['code'=>$row['code']],$row+['source'=>'offline-psa-derived-package','source_version'=>'@aivangogh/ph-address 2025.4.4','sync_batch'=>$batch,'synced_at'=>now(),'is_active'=>true,'raw_payload'=>['recovery_note'=>'Temporary offline recovery; replace on next authoritative PSA sync']]);
            $counts[$row['level']]=($counts[$row['level']]??0)+1;
        }
        return ['batch'=>$batch,'source'=>'offline PSA-derived package','counts'=>$counts,'total'=>count($rows)];
    }
}
