<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectorySyncRun;
use App\Models\PsgcAddress;
use App\Models\RequestParty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LguDirectorySyncService
{
    public const SHEET_ID='1lab2oJ_wjFpkUVZHFM3yZ3FOi3SINh7c';
    public const SHEETS=['ADN','ADS','SDN','SDS','PDI'];
    private const PROVINCES=['ADN'=>'Agusan del Norte','ADS'=>'Agusan del Sur','SDN'=>'Surigao del Norte','SDS'=>'Surigao del Sur','PDI'=>'Dinagat Islands'];

    public function preview(): array
    {
        $remote=$this->fetchRemote();
        $local=LguDirectoryEntry::query()->get()->keyBy(fn($row)=>$row->source_sheet.'|'.$this->normalizedPlace($row->lgu_name));
        $summary=['total'=>count($remote),'new'=>0,'changed'=>0,'unchanged'=>0,'missing'=>0,'changes'=>[]];
        foreach($remote as $key=>$record){
            $row=$local->get($key);
            $type=!$row?'new':($row->source_hash!==$record['source_hash']?'changed':'unchanged');
            $summary[$type]++;
            if($type!=='unchanged'&&count($summary['changes'])<30)$summary['changes'][]=['type'=>$type,'sheet'=>$record['source_sheet'],'lgu'=>$record['lgu_name']];
        }
        foreach($local as $key=>$row)if(in_array($row->source_sheet,self::SHEETS,true)&&!isset($remote[$key])){$summary['missing']++;if(count($summary['changes'])<30)$summary['changes'][]=['type'=>'missing','sheet'=>$row->source_sheet,'lgu'=>$row->lgu_name];}
        return $summary;
    }

    public function sync(?int $userId=null): array
    {
        $run=LguDirectorySyncRun::create(['started_by'=>$userId,'status'=>'running','started_at'=>now()]);
        try{
            $remote=$this->fetchRemote(); $summary=$this->previewFromRemote($remote);
            DB::transaction(function()use($remote):void{
                $seen=[];
                foreach($remote as $record){$entry=$this->applyRecord($record);$seen[]=$entry->id;}
                LguDirectoryEntry::query()->whereIn('source_sheet',self::SHEETS)->whereNotIn('id',$seen)->update(['is_active'=>false,'missing_from_source'=>true]);
                $this->linkRequestParties();
            });
            $run->update(['status'=>'completed','summary'=>$summary,'finished_at'=>now()]);
            return $summary;
        }catch(\Throwable $e){$run->update(['status'=>'failed','error_message'=>$e->getMessage(),'finished_at'=>now()]);throw $e;}
    }

    private function applyRecord(array $record): LguDirectoryEntry
    {
        $entry=LguDirectoryEntry::query()->where('source_sheet',$record['source_sheet'])
            ->when($record['psgc_code'],fn($q,$code)=>$q->where('psgc_code',$code),fn($q)=>$q->where('lgu_name',$record['lgu_name']))->first()
            ?? LguDirectoryEntry::firstOrNew(['source_sheet'=>$record['source_sheet'],'lgu_name'=>$record['lgu_name']]);
        $entry->fill(collect($record)->only(['source_sheet','lgu_name','psgc_code','congressional_district','office_address','source_updated_label','source_hash'])->all()+['is_active'=>true,'missing_from_source'=>false,'source_seen_at'=>now()])->save();
        foreach($record['officials'] as $role=>$official){
            $row=$entry->officials()->where('role',$role)->first();
            if(!$official['name']){if($row&&!$row->override_name)$row->delete();continue;}
            $entry->officials()->updateOrCreate(['role'=>$role],['name'=>$official['name'],'position_designation'=>$official['position']]);
        }
        foreach($record['contacts'] as $key=>$value){[$owner,$type]=explode('|',$key,2);$entry->contacts()->updateOrCreate(['owner_role'=>$owner,'contact_type'=>$type],['value'=>$value]);}
        return $entry;
    }

    private function fetchRemote(): array
    {
        $records=[];
        foreach(self::SHEETS as $sheet){
            $url='https://docs.google.com/spreadsheets/d/'.self::SHEET_ID.'/gviz/tq?tqx=out:csv&sheet='.urlencode($sheet);
            $body=Http::retry(3,700,throw:false)->timeout(30)->get($url)->throw()->body();
            $stream=fopen('php://temp','r+');fwrite($stream,$body);rewind($stream);$header=fgetcsv($stream,null,',','"','');
            if(!$header||!str_contains(strtoupper((string)($header[0]??'')),'NAME OF RECEIVER'))throw new \RuntimeException("Unexpected {$sheet} directory headers.");
            while(($row=fgetcsv($stream,null,',','"',''))!==false){
                $lgu=$this->clean($row[3]??null);if(!$lgu)continue;
                $record=['source_sheet'=>$sheet,'lgu_name'=>$lgu,'psgc_code'=>$this->resolvePsgc($sheet,$lgu),'congressional_district'=>$this->clean($row[4]??null),'office_address'=>$this->clean($row[15]??null),'source_updated_label'=>$this->clean($row[12]??null),
                    'officials'=>['lce'=>['name'=>$this->clean($row[0]??null),'position'=>$this->clean($row[1]??null)],'lswd_officer'=>['name'=>$this->clean($row[5]??null),'position'=>$this->clean($row[6]??null)],'alternate'=>['name'=>$this->clean($row[11]??null),'position'=>null]],
                    'contacts'=>['lce|email'=>$this->clean($row[2]??null),'lswd_officer|email'=>$this->clean($row[8]??null),'lswd_officer|alternate_email'=>$this->clean($row[9]??null),'lswd_officer|phone'=>$this->clean($row[10]??null),'lswd_officer|facebook'=>$this->clean($row[14]??null)]];
                $record['source_hash']=hash('sha256',json_encode(collect($record)->except('source_hash')->all(),JSON_UNESCAPED_UNICODE));
                $records[$sheet.'|'.$this->normalizedPlace($lgu)]=$record;
            }fclose($stream);
        }return $records;
    }

    private function previewFromRemote(array $remote):array
    {
        $local=LguDirectoryEntry::all()->keyBy(fn($row)=>$row->source_sheet.'|'.$this->normalizedPlace($row->lgu_name));
        $summary=['total'=>count($remote),'new'=>0,'changed'=>0,'unchanged'=>0,'missing'=>0];
        foreach($remote as $key=>$record){$row=$local->get($key);$summary[!$row?'new':($row->source_hash!==$record['source_hash']?'changed':'unchanged')]++;}
        foreach($local as $key=>$row)if(in_array($row->source_sheet,self::SHEETS,true)&&!isset($remote[$key]))$summary['missing']++;
        return $summary;
    }

    private function linkRequestParties():void
    {
        $lgus=LguDirectoryEntry::query()->where('is_active',true)->get();
        RequestParty::query()->whereIn('lgu_level',['PLGU','CLGU','MLGU'])->get()->each(function($party)use($lgus):void{
            $label=trim(explode(',',(string)($party->office_agency_details?:preg_replace('/^[A-Z]+\s*-\s*/','',$party->requesting_party)))[0]);
            if(strtoupper($label)==='RTR')$label='Remedios T. Romualdez';
            $needle=$this->normalizedPlace($label);$match=$lgus->first(fn($lgu)=>$this->normalizedPlace($lgu->override_lgu_name?:$lgu->lgu_name)===$needle);
            $party->update(['lgu_directory_entry_id'=>$match?->id]);
        });
    }
    private function clean(mixed $value):?string{$value=trim(preg_replace('/\s+/u',' ',(string)$value));return $value===''||$value==='-'?null:$value;}
    private function resolvePsgc(string $sheet,string $name):?string
    {
        $province=PsgcAddress::query()->where('level','province')->whereRaw('lower(name) like ?',['%'.strtolower(self::PROVINCES[$sheet]).'%'])->first();$needle=$this->normalizedPlace($name);
        if($province&&$needle===$this->normalizedPlace($province->name))return $province->code;
        $candidates=PsgcAddress::query()->whereIn('level',['city','municipality','city_municipality'])->when($province,fn($q)=>$q->where(fn($n)=>$n->where('parent_code',$province->code)->orWhere('code','1630400000')))->get(['code','name']);
        $exact=$candidates->first(fn($row)=>$this->normalizedPlace($row->name)===$needle);if($exact)return $exact->code;
        $close=$candidates->map(fn($row)=>['row'=>$row,'distance'=>levenshtein($needle,$this->normalizedPlace($row->name))])->sortBy('distance')->first();return($close&&$close['distance']<=2)?$close['row']->code:null;
    }
    private function normalizedPlace(string $name):string{$name=preg_replace('/\([^)]*\)/u','',$name);$name=strtoupper(trim($name));$name=preg_replace('/^PROVINCE\s+OF\s+/','',$name);$name=preg_replace('/^CITY\s+OF\s+/','',$name);$name=preg_replace('/\s+CITY$/','',$name);return preg_replace('/[^A-Z0-9]+/','',$name);}
}
