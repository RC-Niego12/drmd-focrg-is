<?php

namespace App\Services;

use App\Http\Controllers\FniLibraryController;
use App\Models\FniLibraryItem;
use App\Models\OperationalLibraryValue;
use App\Models\WarehouseLibraryValue;
use Illuminate\Support\Facades\DB;

class LibraryRecoveryService
{
    public function recoverLocal(): array
    {
        $before=$this->counts();
        WarehouseLibraryValue::populateDefaults();
        OperationalLibraryValue::populateDefaults();
        app(FniLibraryController::class)->recoverFromInventory();
        $this->restoreIncidentTypes();
        $this->restoreDrrsSignatories();
        $this->deduplicateOperationalValues();
        return ['before'=>$before,'after'=>$this->counts()];
    }

    public function counts(): array
    {
        return ['fni'=>FniLibraryItem::count(),'warehouse'=>WarehouseLibraryValue::count(),'operational'=>OperationalLibraryValue::count()];
    }

    private function restoreIncidentTypes(): void
    {
        $values=[
            'Effects of Strong Winds','Effects of Big Waves','Whirlwind Incident','Effects of Thunderstorms',
            'Effects of Southwest Monsoon (Habagat)','Effects of Northeast Monsoon (Amihan)','Effects of Easterlies','Effects of ITCZ',
            'Effects of Shear Line','Effects of Tail-End of Cold Front','Effects of Trough of Low Pressure','Effects of Tropical Cyclone',
            'Effects of Low-Pressure Area (LPA)','Effects of Tropical Depression','Effects of Tropical Storm','Effects of Severe Tropical Storm',
            'Effects of Typhoon','Effects of Super Typhoon','Social Disorganization/Displacement','Oil Spill Incident','Landslide Incident',
            'Mudslide Incident','Flooding Incident','Flashflood Incident','Fire Incident','Earthquake Incident','Dry Spell/Drought/El Niño',
            'La Niña','Armed Conflict','Volcanic Activity','Volcanic Eruption','Prolonged Power Outage','Planned Event','Tornado Incident',
            'Combined Effects of 2 or more Weather Disturbances','Displacement',
        ];
        foreach($values as $index=>$value) OperationalLibraryValue::updateOrCreate(
            ['library_type'=>'incident_type','value'=>$value,'context'=>'all'],
            ['metadata'=>['sort_order'=>$index+1],'is_active'=>true]
        );
    }

    private function restoreDrrsSignatories(): void
    {
        foreach([
            ['JHON CARLO B. ROXAS | Social Welfare Officer II','prepared_by'],
            ['ALDIE MAE A. ANDOY | OIC- DRMD Chief','reviewed_by'],
            ['JEAN PAUL S. PARAJES, RSW, MSSW | Assistant Regional Director for Operations','approved_by'],
        ] as [$value,$context]) OperationalLibraryValue::updateOrCreate(
            ['library_type'=>'drrs_signatory','value'=>$value,'context'=>$context],['is_active'=>true]
        );
    }

    private function deduplicateOperationalValues(): void
    {
        DB::table('operational_library_values')->where('library_type','!=','drrs_signatory')->orderBy('id')->get()
            ->groupBy(fn($row)=>mb_strtolower($row->library_type.'|'.$this->normalize($row->value)))
            ->each(function($rows):void{
                $keep=$rows->first();$remove=$rows->pluck('id')->reject(fn($id)=>$id===$keep->id);
                if($remove->isNotEmpty())DB::table('operational_library_values')->whereIn('id',$remove)->delete();
                DB::table('operational_library_values')->where('id',$keep->id)->update(['value'=>$this->normalize($keep->value),'context'=>'all']);
            });
    }
    private function normalize(mixed $value): string { return preg_replace('/\s+/u',' ',trim((string)$value))??''; }
}
