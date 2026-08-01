<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectorySyncRun;
use App\Models\PsgcAddress;
use App\Models\RequestParty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LguDirectorySyncService
{
    public const SHEETS=['ADN','ADS','SDN','SDS','PDI'];
    private const PROVINCES=['ADN'=>'Agusan del Norte','ADS'=>'Agusan del Sur','SDN'=>'Surigao del Norte','SDS'=>'Surigao del Sur','PDI'=>'Dinagat Islands'];
    private const PROVINCE_PSGC=['ADN'=>'1600200000','ADS'=>'1600300000','SDN'=>'1606700000','SDS'=>'1606800000','PDI'=>'1608500000'];
    private const REGIONAL_DIRECTORY_GIDS=[
        'ADN'=>'1016747780',
        'ADS'=>'1865679132',
        'SDN'=>'367028460',
        'SDS'=>'380529277',
        'PDI'=>'1997432914',
    ];

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
            $remote=$this->fetchRemote(); $ldrrmoRemote=$this->fetchLdrrmoRemote(); $summary=$this->previewFromRemote($remote);
            $ldrrmoSummary=DB::transaction(function()use($remote,$ldrrmoRemote):array{
                $seen=[];
                foreach($remote as $record){$entry=$this->applyRecord($record);$seen[]=$entry->id;}
                LguDirectoryEntry::query()->whereIn('source_sheet',self::SHEETS)->whereNotIn('id',$seen)->update(['is_active'=>false,'missing_from_source'=>true]);
                $ldrrmoSummary=$this->syncLdrrmoRecords($ldrrmoRemote);
                $this->linkRequestParties();
                return $ldrrmoSummary;
            });
            $summary['ldrrmo']=$ldrrmoSummary;
            $summary['unmatched']=$ldrrmoSummary['unmatched'] ?? [];
            $run->update(['status'=>'completed','summary'=>$summary,'finished_at'=>now()]);
            return $summary;
        }catch(\Throwable $e){$run->update(['status'=>'failed','error_message'=>$e->getMessage(),'finished_at'=>now()]);throw $e;}
    }

    private function applyRecord(array $record): LguDirectoryEntry
    {
        $entry=LguDirectoryEntry::query()->where('source_sheet',$record['source_sheet'])
            ->when($record['psgc_code'],fn($q,$code)=>$q->where('psgc_code',$code),fn($q)=>$q->where('lgu_name',$record['lgu_name']))->first()
            ?? LguDirectoryEntry::firstOrNew(['source_sheet'=>$record['source_sheet'],'lgu_name'=>$record['lgu_name']]);
        $entry->fill(collect($record)->only(['source_sheet','lgu_name','psgc_code','lgu_level','managed_district_code','managed_district_name','congressional_district','office_address','lswd_email','lswd_contact_number','lswd_alternate_email','lswd_facebook','lswd_alternate_name','lswd_alternate_position','lswd_alternate_contact_number','source_updated_label','source_hash'])->all()+['is_active'=>true,'missing_from_source'=>false,'source_seen_at'=>now()])->save();
        foreach($record['officials'] as $role=>$official){
            $row=$entry->officials()->where('role',$role)->first();
            if(!$official['name']){if($row&&!$row->override_name)$row->delete();continue;}
            $values=['name'=>$official['name'],'position_designation'=>$official['position']];
            if($row?->override_name&&$this->samePersonIdentity($row->override_name,$official['name'])){
                $values['override_name']=null;
            }
            $entry->officials()->updateOrCreate(['role'=>$role],$values);
        }
        foreach($record['contacts'] as $key=>$value){[$owner,$type]=explode('|',$key,2);$entry->contacts()->updateOrCreate(['owner_role'=>$owner,'contact_type'=>$type],['value'=>$value]);}
        $this->syncLswdoAlternateRows($entry,$record['lswdo_alternates']??[]);
        return $entry;
    }

    private function fetchRemote(): array
    {
        $spreadsheetId=(string)config('services.google_sheets.regional_directory_spreadsheet_id');
        if($spreadsheetId==='')throw new \RuntimeException('The live regional directory spreadsheet ID is not configured.');

        $sources=[];
        foreach(self::REGIONAL_DIRECTORY_GIDS as $sheet=>$gid){
            $url="https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";
            $sources[$sheet]=Http::retry(3,700,throw:false)->timeout(45)->get($url)->throw()->body();
        }

        return $this->recordsFromRegionalCsvSources($sources);
    }

    private function recordsFromRegionalCsvSources(array $sources): array
    {
        $records=[];
        foreach(self::REGIONAL_DIRECTORY_GIDS as $sheet=>$gid){
            $rows=$this->parseCsv((string)($sources[$sheet]??''));
            $headerIndex=collect($rows)->search(function(array $row):bool{
                $headers=array_map(fn($header)=>$this->normalizeHeader($header),$row);
                return in_array('lgu',$headers,true)&&in_array('lswd_officer',$headers,true);
            });
            if($headerIndex===false){
                throw new \RuntimeException("The live regional directory tab {$sheet} returned unexpected headers.");
            }

            $headers=array_map(fn($header)=>$this->normalizeHeader($header),$rows[$headerIndex]);
            foreach(array_slice($rows,$headerIndex+1) as $row){
                $source=[];
                foreach($headers as $index=>$header){
                    if($header==='')continue;
                    $source[$header]=trim((string)($row[$index]??''));
                }

                $sourceLgu=$this->sourceValueFor($source,['lgu']);
                if(!$sourceLgu)continue;

                $lcePosition=$this->sourceValueFor($source,['lce','position','lce_designation']);
                $lgu=$this->canonicalLguName($sheet,$sourceLgu,$lcePosition);
                $psgcCode=$this->resolvePsgc($sheet,$lgu);
                $lgu=$this->correctSourcePlaceTypo($lgu,$psgcCode);
                $managedDistrict=$this->managedDistrictFor($psgcCode);
                $alternates=$this->parseLswdAlternates($this->sourceValueFor($source,['alternate']));
                $alternate=$alternates[0]??['name'=>null,'position'=>null,'contact'=>null];
                $lceName=$this->sourceValueFor($source,['name_of_receiver_mayor','name_of_lce']);
                $lceEmail=$this->sourceValueFor($source,['lgu_lce_office_email_address']);
                $lswdoName=$this->sourceValueFor($source,['lswd_officer']);
                $lswdoPosition=$this->sourceValueFor($source,['position_designation','lswdo_position','lswd_position']);
                $lswdoEmail=$this->sourceValueFor($source,['lswd_office_email_address']);
                $alternateEmail=$this->sourceValueFor($source,['alternate_email_copy_furnish']);
                $contactNumber=$this->sourceValueFor($source,['contact_number_and_hotline','contact_number']);
                $facebook=$this->sourceValueFor($source,['facebook_account_link_of_lswdo','facebook_account_link']);

                $record=[
                    'source_sheet'=>$sheet,
                    'lgu_name'=>$lgu,
                    'psgc_code'=>$psgcCode,
                    'lgu_level'=>$this->lguLevelFor($lcePosition,$psgcCode),
                    'managed_district_code'=>$managedDistrict['code'],
                    'managed_district_name'=>$managedDistrict['name'],
                    'congressional_district'=>$this->sourceValueFor($source,['congressional_district','district']),
                    'office_address'=>$this->sourceValueFor($source,['lswd_office_complete_address','office_address']),
                    'lswd_email'=>$lswdoEmail,
                    'lswd_alternate_email'=>$alternateEmail,
                    'lswd_contact_number'=>$contactNumber,
                    'lswd_facebook'=>$facebook,
                    'lswd_alternate_name'=>$alternate['name'],
                    'lswd_alternate_position'=>$alternate['position'],
                    'lswd_alternate_contact_number'=>$alternate['contact'],
                    'source_updated_label'=>$this->sourceValueFor($source,['updated','last_update']),
                    'officials'=>[
                        'lce'=>['name'=>$lceName,'position'=>$lcePosition],
                        'lswd_officer'=>['name'=>$lswdoName,'position'=>$lswdoPosition],
                        'lswd_officer_alternate'=>['name'=>$alternate['name'],'position'=>$alternate['position']],
                    ],
                    'contacts'=>[
                        'lce|email'=>$lceEmail,
                        'lswd_officer|email'=>$lswdoEmail,
                        'lswd_officer|alternate_email'=>$alternateEmail,
                        'lswd_officer|phone'=>$contactNumber,
                        'lswd_officer|facebook'=>$facebook,
                        'lswd_officer_alternate|phone'=>$alternate['contact'],
                    ],
                    'lswdo_alternates'=>$alternates,
                ];
                $record['source_hash']=hash('sha256',json_encode(collect($record)->except('source_hash')->all(),JSON_UNESCAPED_UNICODE));
                $records[$sheet.'|'.$this->normalizedPlace($lgu)]=$record;
            }
        }

        if(count($records)<70){
            throw new \RuntimeException('The live regional directory returned fewer LGU rows than expected; synchronization was stopped.');
        }

        return $records;
    }

    private function fetchLdrrmoRemote(): array
    {
        $url=(string)config('services.google_sheets.ldrrmo_directory_url');
        $body=Http::retry(2,700,throw:false)->timeout(30)->get($url)->throw()->body();
        $rows=$this->parseCsv($body);
        if(count($rows)<2)return [];

        $headers=array_map(fn($header)=>$this->normalizeHeader($header),array_shift($rows));
        $records=[];
        foreach($rows as $row){
            $record=[];
            foreach($headers as $index=>$header){
                if($header==='')continue;
                $record[$header]=trim((string)($row[$index]??''));
            }
            if(collect($record)->filter(fn($value)=>filled($value))->isEmpty())continue;
            $records[]=[
                'raw'=>$record,
                'lgu_name'=>$this->valueFor($record,['lgu','lgu_name','city_municipality','city_municipality_barangay','municipality_city','municipality','city','name_of_lgu','office']),
                'province'=>$this->valueFor($record,['province','province_name']),
                'district'=>$this->valueFor($record,['district','managed_district','district_name']),
                'office'=>$this->valueFor($record,['office']),
                'psgc_code'=>$this->digitsOnly($this->valueFor($record,['psgc','psgc_code','code'])),
                'name'=>$this->valueFor($record,['local_drrm_officer','local_drrmo_officer','ldrrmo','ldrrmo_name','ldrrmo_officer','ldrrm_officer','name','head_of_office','officer','focal_person']),
                'position'=>$this->valueFor($record,['designation','position','ldrrmo_position','ldrrmo_designation']),
                'mobile_number'=>$this->valueFor($record,['mobile_number','mobile','contact_number','contact','cp_number','phone']),
                'hotline_number'=>$this->valueFor($record,['hotline_number','hotline']),
                'landline_number'=>$this->valueFor($record,['landline_number','landline','telephone']),
                'email_address'=>$this->valueFor($record,['email_address','email','official_email']),
                'alternate_email_address'=>$this->valueFor($record,['alternate_email_address','alternate_email']),
                'alternate_name'=>$this->valueFor($record,['alternate_officer','alternate_ldrrmo','alternate_ldrrmo_name','alternate_name']),
                'alternate_position'=>$this->valueFor($record,['alternate_designation','alternate_position','alternate_ldrrmo_position']),
                'alternate_contact'=>$this->compactContact([
                    $this->valueFor($record,['alternate_mobile_number','alternate_mobile','alternate_contact_number','alternate_contact']),
                    $this->valueFor($record,['alternate_hotline_number','alternate_hotline']),
                    $this->valueFor($record,['alternate_landline_number','alternate_landline']),
                ]),
                'alternate_email'=>$this->valueFor($record,['alternate_email_address','alternate_email','alternate_ldrrmo_email']),
                'facebook'=>$this->valueFor($record,['facebook','facebook_account','facebook_link']),
                'vhf_radio_frequency'=>$this->valueFor($record,['vhf_radio_frequency','vhf','radio_frequency']),
            ];
        }
        return $records;
    }

    private function syncLdrrmoRecords(array $records): array
    {
        $summary=['total'=>count($records),'matched'=>0,'updated'=>0,'skipped'=>0,'unmatched'=>[]];
        foreach($records as $record){
            if(!$record['psgc_code']&&!$record['lgu_name']){$summary['skipped']++;continue;}
            $entry=$this->findDirectoryEntry($record['psgc_code'],$record['lgu_name'],$record['province'] ?? null);
            if(!$entry && $this->isProvinceLdrrmoRecord($record)){
                $entry=$this->ensureProvinceDirectoryEntryFromLdrrmo($record);
            }
            if(!$entry){
                $entry=$this->ensureLocalDirectoryEntryFromLdrrmo($record);
            }
            if(!$entry){
                $summary['skipped']++;
                $summary['unmatched'][]=[
                    'lgu'=>$record['lgu_name'] ?: 'No LGU name',
                    'province'=>$record['province'] ?? null,
                    'psgc'=>$record['psgc_code'],
                    'name'=>$record['name'],
                ];
                continue;
            }

            $summary['matched']++;
            $previousValues=(array)data_get($entry->ldrrmo_payload,'source_values',[]);
            $sourceValues=$this->mergeLdrrmoSourceValues($previousValues,$record);
            $primaryRecord=$this->pickPrimaryLdrrmoRecord($sourceValues);
            $this->syncLdrrmoOfficerRows($entry, $sourceValues, $primaryRecord);
            $updates=['ldrrmo_payload'=>[
                'source_values'=>$sourceValues,
                'source_rows'=>$this->appendLdrrmoSourceRow((array)data_get($entry->ldrrmo_payload,'source_rows',[]),$record['raw']),
                'synced_at'=>now()->toISOString(),
            ]];

            $legacyPrimary = [
                'name' => $primaryRecord['name'] ?? null,
                'position' => $primaryRecord['designation'] ?? null,
                'contact' => $this->compactContact([
                    $primaryRecord['mobile_number'] ?? null,
                    $primaryRecord['hotline_number'] ?? null,
                    $primaryRecord['landline_number'] ?? null,
                ]),
                'email' => $primaryRecord['email_address'] ?? null,
            ];

            $effectivePrimary = $entry->ldrrmoOfficers()->where('is_primary', true)->first();
            $updates['ldrrmo_name'] = $effectivePrimary?->name ?? $legacyPrimary['name'];
            $updates['ldrrmo_position'] = $effectivePrimary?->designation ?? $legacyPrimary['position'];
            $updates['ldrrmo_contact'] = $effectivePrimary
                ? $this->compactContact([$effectivePrimary->mobile_number, $effectivePrimary->hotline_number, $effectivePrimary->landline_number])
                : $legacyPrimary['contact'];
            $updates['ldrrmo_email'] = $effectivePrimary?->email_address ?? $legacyPrimary['email'];

            $entry->forceFill($updates)->save();
            $summary['updated']++;
        }
        return $summary;
    }

    private function mergeLdrrmoSourceValues(array $existingValues, array $record): array
    {
        $merged=collect($existingValues)
            ->map(fn($value)=>is_array($value)?$this->normalizeLdrrmoSourceRecord($value):null)
            ->filter()
            ->values()
            ->all();

        $incoming=$this->normalizeLdrrmoSourceRecord($record);
        $signature=$this->ldrrmoSourceSignature($incoming);
        $alreadyPresent=collect($merged)->contains(fn($value)=>$this->ldrrmoSourceSignature($value)===$signature);

        if(!$alreadyPresent){
            $merged[]=$incoming;
        }

        return array_values($merged);
    }

    private function appendLdrrmoSourceRow(array $existingRows, array $rawRow): array
    {
        $rows=collect($existingRows)
            ->filter(fn($value)=>is_array($value))
            ->map(fn($value)=>array_filter($value, fn($item)=>filled($item)))
            ->values()
            ->all();

        $incoming=array_filter($rawRow, fn($value)=>filled($value));
        $signature=md5(json_encode($incoming,JSON_UNESCAPED_UNICODE));
        $alreadyPresent=collect($rows)->contains(fn($row)=>md5(json_encode(array_filter($row, fn($value)=>filled($value)),JSON_UNESCAPED_UNICODE))===$signature);

        if(!$alreadyPresent){
            $rows[]=$incoming;
        }

        return array_values($rows);
    }

    private function normalizeLdrrmoSourceRecord(array $record): array
    {
        $mobile = $record['mobile_number'] ?? $record['contact'] ?? $record['ldrrmo_contact'] ?? null;
        $hotline = $record['hotline_number'] ?? null;
        $landline = $record['landline_number'] ?? null;
        $designation = $record['designation'] ?? $record['position'] ?? $record['ldrrmo_position'] ?? null;
        $email = $record['email_address'] ?? $record['email'] ?? $record['ldrrmo_email'] ?? null;

        return array_filter([
            'office'=>$record['office'] ?? null,
            'name'=>$record['name'] ?? $record['ldrrmo_name'] ?? null,
            'designation'=>$designation,
            'mobile_number'=>$mobile,
            'hotline_number'=>$hotline,
            'landline_number'=>$landline,
            'email_address'=>$email,
            'alternate_email_address'=>$record['alternate_email_address'] ?? $record['alternate_email'] ?? null,
            // Retain legacy payload aliases for older reports while normalized columns remain authoritative.
            'position'=>$designation,
            'contact'=>$this->compactContact([$mobile, $hotline, $landline]),
            'email'=>$email,
            'alternate_name'=>$record['alternate_name'] ?? null,
            'alternate_position'=>$record['alternate_position'] ?? null,
            'alternate_contact'=>$record['alternate_contact'] ?? null,
            'alternate_email'=>$record['alternate_email'] ?? null,
            'facebook'=>$record['facebook'] ?? null,
            'vhf_radio_frequency'=>$record['vhf_radio_frequency'] ?? $record['vhf'] ?? null,
        ], fn($value)=>filled($value));
    }

    private function pickPrimaryLdrrmoRecord(array $sourceValues): array
    {
        $records=collect($sourceValues)
            ->map(fn($value)=>$this->normalizeLdrrmoSourceRecord($value))
            ->filter(fn($value)=>!empty($value))
            ->values();

        if($records->isEmpty())return [];

        $headOfficer=$records->first(fn($record)=>preg_match('/\b(?:P|C|M)DRRMO(?:\s*-\s*OIC)?\b/i',(string)($record['designation'] ?? ''))===1);
        if($headOfficer)return $headOfficer;

        $rosario=$records->first(fn($record)=>preg_match('/\brosario\b.*\balon\b/i',(string)($record['name'] ?? ''))===1);
        if($rosario)return $rosario;

        $roleMatch=$records->first(fn($record)=>str_contains(strtolower((string)($record['designation'] ?? '')),'ldrrmo')
            || str_contains(strtolower((string)($record['name'] ?? '')),'ldrrmo'));

        return (array) ($roleMatch ?: $records->first());
    }

    private function ldrrmoSourceSignature(array $record): string
    {
        return md5(json_encode([
            'name'=>$record['name'] ?? null,
            'designation'=>$record['designation'] ?? null,
            'mobile_number'=>$record['mobile_number'] ?? null,
            'hotline_number'=>$record['hotline_number'] ?? null,
            'landline_number'=>$record['landline_number'] ?? null,
            'email_address'=>$record['email_address'] ?? null,
            'alternate_email_address'=>$record['alternate_email_address'] ?? null,
            'alternate_name'=>$record['alternate_name'] ?? null,
            'alternate_position'=>$record['alternate_position'] ?? null,
            'alternate_contact'=>$record['alternate_contact'] ?? null,
            'alternate_email'=>$record['alternate_email'] ?? null,
            'facebook'=>$record['facebook'] ?? null,
            'vhf_radio_frequency'=>$record['vhf_radio_frequency'] ?? null,
        ],JSON_UNESCAPED_UNICODE));
    }

    private function syncLdrrmoOfficerRows(LguDirectoryEntry $entry, array $sourceValues, array $primaryRecord): void
    {
        $primarySignature = $this->ldrrmoSourceSignature($primaryRecord);

        foreach (array_values($sourceValues) as $index => $sourceValue) {
            $officer = $this->normalizeLdrrmoSourceRecord((array) $sourceValue);
            $signature = $this->ldrrmoSourceSignature($officer);
            $row = $entry->ldrrmoOfficers()->where('source_signature', $signature)->first()
                ?? $entry->ldrrmoOfficers()->where('sort_order', $index)->first();

            if ($row?->is_locally_updated) {
                continue;
            }

            $values = [
                'sort_order' => $index,
                'is_primary' => $signature === $primarySignature,
                'office' => $officer['office'] ?? null,
                'name' => $officer['name'] ?? null,
                'designation' => $officer['designation'] ?? null,
                'mobile_number' => $officer['mobile_number'] ?? null,
                'hotline_number' => $officer['hotline_number'] ?? null,
                'landline_number' => $officer['landline_number'] ?? null,
                'email_address' => $officer['email_address'] ?? null,
                'alternate_email_address' => $officer['alternate_email_address'] ?? null,
                'vhf_radio_frequency' => $officer['vhf_radio_frequency'] ?? null,
                'facebook' => $officer['facebook'] ?? $row?->facebook,
                'source_signature' => $signature,
            ];

            $row ? $row->update($values) : $entry->ldrrmoOfficers()->create($values);
        }
    }

    private function parseLswdAlternates(mixed $value): array
    {
        $lines=collect(preg_split('/\R+/u',trim((string)$value)) ?: [])
            ->map(fn($line)=>$this->clean($line))
            ->filter()
            ->values();

        if($lines->isEmpty())return [];

        if($lines->count()>3 && $lines->count()%2===0 && !$this->looksLikePhone($lines->last())){
            return $lines->chunk(2)->map(fn($pair)=>[
                'name'=>$pair->values()->get(0),
                'position'=>$pair->values()->get(1),
                'contact'=>null,
            ])->values()->all();
        }

        if($lines->count()===3){
            return [['name'=>$lines->get(0),'position'=>$lines->get(1),'contact'=>$lines->get(2)]];
        }

        if($lines->count()===2 && !$this->looksLikePhone($lines->get(1))){
            return [['name'=>$lines->get(0),'position'=>$lines->get(1),'contact'=>null]];
        }

        $contact=$lines->count()>1 && $this->looksLikePhone($lines->last())?$lines->pop():null;
        $combined=$lines->implode(' ');
        if($contact===null && preg_match('/(?:\+?63|0)\d[\d\s\-()]{7,}\d$/u',$combined,$match,PREG_OFFSET_CAPTURE)){
            $contact=$this->clean($match[0][0]);
            $combined=trim(substr($combined,0,$match[0][1]));
        }
        [$name,$position]=$this->splitAlternateNameAndPosition($combined);

        return [['name'=>$name,'position'=>$position,'contact'=>$contact]];
    }

    private function splitAlternateNameAndPosition(string $value): array
    {
        $pattern='/\b(SWO\s*[IVX]+|CAO\s*[IVX]+|(?:Provincial|City|Municipal)\s+Government\b|Assistant\s+P?SWD\b|P?SWD\s+Officer\b|Social\s+Welfare\b)/iu';
        if(preg_match($pattern,$value,$match,PREG_OFFSET_CAPTURE)){
            $offset=$match[0][1];
            return [$this->clean(substr($value,0,$offset)),$this->clean(substr($value,$offset))];
        }
        return [$this->clean($value),null];
    }

    private function looksLikePhone(?string $value): bool
    {
        return preg_match('/\d[\d\s\-()\/]{6,}\d/u',(string)$value)===1;
    }

    private function syncLswdoAlternateRows(LguDirectoryEntry $entry,array $alternates): void
    {
        foreach(array_values($alternates) as $index=>$alternate){
            $row=$entry->lswdoAlternates()->where('sort_order',$index)->first();
            if($row?->is_locally_updated)continue;
            $values=[
                'sort_order'=>$index,
                'name'=>$alternate['name']??null,
                'position'=>$alternate['position']??null,
                'contact_number'=>$alternate['contact']??null,
            ];
            $row?$row->update($values):$entry->lswdoAlternates()->create($values);
        }
        $entry->lswdoAlternates()->where('is_locally_updated',false)->where('sort_order','>=',count($alternates))->delete();
    }

    private function parseCsv(string $csv): array
    {
        $handle=fopen('php://temp','r+');fwrite($handle,$csv);rewind($handle);
        $rows=[];
        while(($row=fgetcsv($handle,null,',','"',''))!==false){
            if(collect($row)->filter(fn($value)=>filled($value))->isNotEmpty())$rows[]=$row;
        }
        fclose($handle);
        return $rows;
    }

    private function normalizeHeader(?string $header): string
    {
        return Str::of((string)$header)->lower()->replaceMatches('/[^a-z0-9]+/','_')->trim('_')->toString();
    }

    private function valueFor(array $record,array $keys): ?string
    {
        foreach($keys as $key){
            $normalized=$this->normalizeHeader($key);
            if(isset($record[$normalized])&&filled($record[$normalized]))return trim((string)$record[$normalized]);
        }
        return null;
    }

    private function sourceValueFor(array $record,array $keys): ?string
    {
        $value=$this->valueFor($record,$keys);
        if($value===null||preg_match('/^[-–—]+$/u',trim($value))===1)return null;

        return trim(str_replace(["\r\n","\r"],"\n",$value));
    }

    private function compactContact(array $values): ?string
    {
        $contacts=collect($values)
            ->filter(fn($value)=>filled($value))
            ->map(fn($value)=>trim((string)$value))
            ->unique()
            ->values();

        return $contacts->isEmpty()?null:$contacts->implode(' / ');
    }

    private function digitsOnly(?string $value): ?string
    {
        if(!$value)return null;
        $digits=preg_replace('/\D+/','',$value);
        return $digits!==''?$digits:null;
    }

    private function findDirectoryEntry(?string $psgc, ?string $lguName, ?string $provinceName=null): ?LguDirectoryEntry
    {
        if($psgc){
            $entry=LguDirectoryEntry::query()->where('psgc_code',$psgc)->first();
            if($entry)return $entry;
        }
        if(!$lguName)return null;
        $needle=$this->normalizedDirectoryPlace($lguName);
        $provinceSheet=$this->provinceSheetFor($provinceName);
        $query=LguDirectoryEntry::query();
        if($provinceSheet)$query->where('source_sheet',$provinceSheet);
        $entries=$query->get();

        $match=$entries->first(fn($entry)=>in_array($needle,[
            $this->normalizedDirectoryPlace($entry->override_lgu_name),
            $this->normalizedDirectoryPlace($entry->lgu_name),
        ],true));

        if($match)return $match;

        $provinceNeedle=$this->normalizedDirectoryPlace($provinceName);
        if($provinceNeedle && $needle===$provinceNeedle){
            return $entries->first(fn($entry)=>$entry->lgu_level==='PLGU'||$this->normalizedDirectoryPlace($entry->lgu_name)===$provinceNeedle);
        }

        return null;
    }

    private function isProvinceLdrrmoRecord(array $record): bool
    {
        $office=strtolower((string)($record['office'] ?? ''));
        $lgu=$this->normalizedDirectoryPlace($record['lgu_name'] ?? null);
        $province=$this->normalizedDirectoryPlace($record['province'] ?? null);

        return str_contains($office,'pdrrmo') || ($lgu!=='' && $province!=='' && $lgu===$province);
    }

    private function ensureProvinceDirectoryEntryFromLdrrmo(array $record): ?LguDirectoryEntry
    {
        $sheet=$this->provinceSheetFor($record['province'] ?? $record['lgu_name'] ?? null);
        if(!$sheet)return null;

        $provinceName=self::PROVINCES[$sheet];
        $managedDistrict=$this->managedDistrictFor(self::PROVINCE_PSGC[$sheet] ?? null);
        $entry=LguDirectoryEntry::query()
            ->where('source_sheet',$sheet)
            ->where(fn($query)=>$query
                ->where('lgu_level','PLGU')
                ->orWhere('psgc_code',self::PROVINCE_PSGC[$sheet] ?? null)
                ->orWhere('lgu_name',$provinceName))
            ->first() ?? LguDirectoryEntry::firstOrNew(['source_sheet'=>$sheet,'lgu_name'=>$provinceName]);

        $payload=[
            'source_sheet'=>$sheet,
            'lgu_name'=>$provinceName,
            'psgc_code'=>self::PROVINCE_PSGC[$sheet] ?? null,
            'lgu_level'=>'PLGU',
            'managed_district_code'=>$managedDistrict['code'],
            'managed_district_name'=>$managedDistrict['name'] ?: 'Provincial',
            'congressional_district'=>'N/A',
            'office_address'=>null,
            'source_updated_label'=>null,
        ];

        $entry->fill($payload + [
            'is_active'=>true,
            'missing_from_source'=>false,
            'source_seen_at'=>now(),
            'source_hash'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE)),
        ])->save();

        return $entry;
    }

    private function ensureLocalDirectoryEntryFromLdrrmo(array $record): ?LguDirectoryEntry
    {
        $sheet=$this->provinceSheetFor($record['province'] ?? null);
        $lguName=$this->clean($record['lgu_name'] ?? null);
        if(!$sheet||!$lguName)return null;

        $psgcCode=$record['psgc_code'] ?: $this->resolvePsgc($sheet,$lguName);
        if(!$psgcCode)return null;

        $managedDistrict=$this->managedDistrictFor($psgcCode);
        $entry=LguDirectoryEntry::query()
            ->where('source_sheet',$sheet)
            ->where(fn($query)=>$query
                ->where('psgc_code',$psgcCode)
                ->orWhere('lgu_name',$lguName))
            ->first() ?? LguDirectoryEntry::firstOrNew(['source_sheet'=>$sheet,'lgu_name'=>$lguName]);

        $payload=[
            'source_sheet'=>$sheet,
            'lgu_name'=>$lguName,
            'psgc_code'=>$psgcCode,
            'lgu_level'=>$this->lguLevelFor(null,$psgcCode),
            'managed_district_code'=>$managedDistrict['code'],
            'managed_district_name'=>$managedDistrict['name'],
            'congressional_district'=>$this->clean($record['district'] ?? null),
            'office_address'=>null,
            'source_updated_label'=>null,
        ];

        $entry->fill($payload + [
            'is_active'=>true,
            'missing_from_source'=>false,
            'source_seen_at'=>now(),
            'source_hash'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE)),
        ])->save();

        return $entry;
    }

    private function provinceSheetFor(?string $provinceName): ?string
    {
        $needle=$this->normalizedDirectoryPlace($provinceName);
        if(!$needle)return null;

        foreach(self::PROVINCES as $sheet=>$province){
            if($needle===$this->normalizedDirectoryPlace($province))return $sheet;
        }

        return match($needle){
            'dinagat','dinagat islands','province dinagat islands'=>'PDI',
            default=>null,
        };
    }

    private function normalizedDirectoryPlace(?string $value): string
    {
        $normalized = Str::of((string)$value)
            ->lower()
            ->replaceMatches('/\([^)]*\)/', ' ')
            ->replaceMatches('/\brtr\b/', ' remedios t romualdez ')
            ->replaceMatches('/\bsta\b\.?/', ' santa ')
            ->replaceMatches('/\b(city|municipality|province|of|the|lgu|plgu|clgu|mlgu)\b/',' ')
            ->replaceMatches('/[^a-z0-9]+/',' ')
            ->squish()
            ->toString();

        return match ($normalized) {
            'remedios romualdez' => 'remedios t romualdez',
            default => $normalized,
        };
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
            $party->update(['lgu_directory_entry_id'=>$match?->id,'lgu_level'=>$match?->lgu_level ?: $party->lgu_level]);
        });
    }
    private function clean(mixed $value):?string{$value=trim(preg_replace('/\s+/u',' ',(string)$value));return $value===''||$value==='-'?null:$value;}
    private function samePersonIdentity(string $left,string $right): bool
    {
        $normalize=static function(string $value): string{
            $value=strtoupper($value);
            $value=preg_replace('/^(?:HON(?:ORABLE)?|MS|MRS|MR|DR|ATTY|MAYOR|GOVERNOR)\.?\s+/u','',$value);
            return preg_replace('/[^A-Z0-9]+/','',$value);
        };
        $left=$normalize($left);
        $right=$normalize($right);
        return min(strlen($left),strlen($right))>=8
            && (str_starts_with($left,$right)||str_starts_with($right,$left));
    }
    private function canonicalLguName(string $sheet,string $lgu,?string $lcePosition): string
    {
        $needle=$this->normalizedPlace($lgu);
        $isProvince=str_contains(strtolower((string)$lcePosition),'governor')
            || $needle===$this->normalizedPlace($sheet)
            || $needle==='PDI';
        return $isProvince?self::PROVINCES[$sheet]:$lgu;
    }
    private function resolvePsgc(string $sheet,string $name):?string
    {
        $province=PsgcAddress::query()->where('level','province')->whereRaw('lower(name) like ?',['%'.strtolower(self::PROVINCES[$sheet]).'%'])->first();$needle=$this->normalizedPlace($name);
        if($province&&$needle===$this->normalizedPlace($province->name))return $province->code;
        $candidates=PsgcAddress::query()->whereIn('level',['city','municipality','city_municipality'])->when($province,fn($q)=>$q->where(fn($n)=>$n->where('parent_code',$province->code)->orWhere('code','1630400000')))->get(['code','name']);
        $exact=$candidates->first(fn($row)=>$this->normalizedPlace($row->name)===$needle);if($exact)return $exact->code;
        $close=$candidates->map(fn($row)=>['row'=>$row,'distance'=>levenshtein($needle,$this->normalizedPlace($row->name))])->sortBy('distance')->first();return($close&&$close['distance']<=2)?$close['row']->code:null;
    }
    private function correctSourcePlaceTypo(string $sourceName,?string $psgcCode): string
    {
        if(!$psgcCode||str_contains($sourceName,'('))return $sourceName;
        $officialName=PsgcAddress::query()->where('code',$psgcCode)->value('name');
        if(!$officialName)return $sourceName;
        $sourceKey=$this->normalizedPlace($sourceName);
        $officialKey=$this->normalizedPlace($officialName);

        return $sourceKey!==$officialKey&&levenshtein($sourceKey,$officialKey)<=2
            ? (ctype_upper(str_replace([' ','-','.'],'',$sourceName))?strtoupper($officialName):$officialName)
            : $sourceName;
    }
    private function lguLevelFor(?string $position, ?string $psgcCode): ?string
    {
        $position = strtolower((string) $position);
        if (str_contains($position, 'municipal mayor')) return 'MLGU';
        if (str_contains($position, 'city mayor')) return 'CLGU';
        if (str_contains($position, 'governor')) return 'PLGU';

        $address = $psgcCode ? PsgcAddress::query()->where('code', $psgcCode)->first(['level','type']) : null;
        $level = strtolower((string) $address?->level);
        $type = strtolower((string) $address?->type);

        return match (true) {
            $level === 'province' => 'PLGU',
            str_contains($type, 'municipality') || $level === 'municipality' => 'MLGU',
            str_contains($type, 'city') || $level === 'city' || $level === 'city_municipality' => 'CLGU',
            default => null,
        };
    }
    private function managedDistrictFor(?string $psgcCode): array
    {
        $address = $psgcCode ? PsgcAddress::query()->where('code', $psgcCode)->first(['district','district_code']) : null;
        if (! $address) return ['code'=>null,'name'=>null];

        $district = filled($address->district_code)
            ? PsgcAddress::query()->where('level','district')->where('code',$address->district_code)->first(['code','name','short_name'])
            : null;

        return [
            'code' => $district?->code ?: $address->district_code,
            'name' => $district?->short_name ?: $district?->name ?: $address->district,
        ];
    }
    private function normalizedPlace(string $name):string{$name=preg_replace('/\([^)]*\)/u','',$name);$name=strtoupper(trim($name));$name=preg_replace('/^PROVINCE\s+OF\s+/','',$name);$name=preg_replace('/^CITY\s+OF\s+/','',$name);$name=preg_replace('/\s+CITY$/','',$name);return preg_replace('/[^A-Z0-9]+/','',$name);}
}
