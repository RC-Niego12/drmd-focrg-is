<?php
namespace App\Http\Controllers;
use App\Models\LguDirectoryEntry;
use App\Services\LguDirectorySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LguDirectoryController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('fni-library.index', ['library'=>'lgu_directory']);
    }
    public function sync(LguDirectorySyncService $service): RedirectResponse
    {
        abort_unless(request()->user()?->hasRole('Super Admin'), 403);

        $summary=$service->sync(request()->user()?->id);
        $ldrrmo=$summary['ldrrmo'] ?? [];
        $unmatched=$summary['unmatched'] ?? ($ldrrmo['unmatched'] ?? []);
        $unmatchedCount=count($unmatched);
        $matchedCount=$ldrrmo['matched'] ?? 0;
        $updatedCount=$ldrrmo['updated'] ?? 0;

        return back()
            ->with('success', "LGU directory sync completed from both Google Sheet sources: {$summary['new']} added, {$summary['changed']} updated, {$summary['missing']} deactivated. LDRRMO: {$matchedCount} matched, {$updatedCount} updated, {$unmatchedCount} unmatched.")
            ->with('lgu_sync_unmatched', $unmatched);
    }
    public function preview(LguDirectorySyncService $service): RedirectResponse
    {
        abort_unless(request()->user()?->hasRole('Super Admin'), 403);

        return back()->with('lgu_sync_preview',$service->preview());
    }
    public function update(Request $request,LguDirectoryEntry $lguDirectoryEntry): RedirectResponse
    {
        $data=$request->validate([
            'lgu_name'=>['required','string','max:255'],'office_address'=>['nullable','string'],
            'lce_name'=>['nullable','string','max:255'],'lce_position'=>['nullable','string','max:255'],'lce_email'=>['nullable','string','max:500'],'lce_phone'=>['nullable','string','max:500'],
            'lswd_name'=>['nullable','string','max:255'],'lswd_position'=>['nullable','string','max:255'],'lswd_email'=>['nullable','string','max:500'],'lswd_phone'=>['nullable','string','max:500'],'lswd_facebook'=>['nullable','string','max:500'],
            'lswd_alt_name'=>['nullable','string','max:255'],'lswd_alt_position'=>['nullable','string','max:255'],'lswd_alt_email'=>['nullable','string','max:500'],'lswd_alt_phone'=>['nullable','string','max:500'],'lswd_alt_facebook'=>['nullable','string','max:500'],
            'ldrrmo_name'=>['nullable','string','max:255'],'ldrrmo_position'=>['nullable','string','max:255'],'ldrrmo_contact'=>['nullable','string','max:500'],'ldrrmo_email'=>['nullable','string','max:500'],'ldrrmo_facebook'=>['nullable','string','max:500'],'ldrrmo_vhf'=>['nullable','string','max:500'],
            'ldrrmo_alt_name'=>['nullable','string','max:255'],'ldrrmo_alt_position'=>['nullable','string','max:255'],'ldrrmo_alt_contact'=>['nullable','string','max:500'],'ldrrmo_alt_email'=>['nullable','string','max:500'],'ldrrmo_alt_facebook'=>['nullable','string','max:500'],
            'ldrrmo_officers'=>['nullable','array','max:10'],
            'ldrrmo_officers.*.id'=>['nullable','integer'],
            'ldrrmo_officers.*.office'=>['nullable','string','max:255'],
            'ldrrmo_officers.*.name'=>['nullable','string','max:255'],
            'ldrrmo_officers.*.designation'=>['nullable','string','max:255'],
            'ldrrmo_officers.*.mobile_number'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.hotline_number'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.landline_number'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.email_address'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.alternate_email_address'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.vhf_radio_frequency'=>['nullable','string','max:500'],
            'ldrrmo_officers.*.facebook'=>['nullable','string','max:500'],
            'lswdo_alternates'=>['nullable','array','max:10'],
            'lswdo_alternates.*.id'=>['nullable','integer'],
            'lswdo_alternates.*.name'=>['nullable','string','max:255'],
            'lswdo_alternates.*.position'=>['nullable','string','max:255'],
            'lswdo_alternates.*.contact_number'=>['nullable','string','max:500'],
        ]);
        $lguDirectoryEntry->update([
            'override_lgu_name'=>$data['lgu_name']===$lguDirectoryEntry->lgu_name?null:$data['lgu_name'],
            'override_office_address'=>($data['office_address']??null)===$lguDirectoryEntry->office_address?null:($data['office_address']??null),
            'ldrrmo_name'=>$data['ldrrmo_name']??null,
            'ldrrmo_position'=>$data['ldrrmo_position']??null,
            'ldrrmo_contact'=>$data['ldrrmo_contact']??null,
            'ldrrmo_email'=>$data['ldrrmo_email']??null,
            'lswd_email'=>$data['lswd_email']??null,
            'lswd_contact_number'=>$data['lswd_phone']??null,
            'lswd_alternate_email'=>$data['lswd_alt_email']??null,
            'lswd_facebook'=>$data['lswd_facebook']??null,
            'lswd_alternate_name'=>$data['lswd_alt_name']??null,
            'lswd_alternate_position'=>$data['lswd_alt_position']??null,
            'lswd_alternate_contact_number'=>$data['lswd_alt_phone']??null,
        ]);
        $this->syncLdrrmoOfficers($lguDirectoryEntry,$data['ldrrmo_officers']??[]);
        $this->syncLswdoAlternates($lguDirectoryEntry,$data['lswdo_alternates']??[]);
        $this->official($lguDirectoryEntry,'lce',$data['lce_name']??null,$data['lce_position']??null);
        $this->official($lguDirectoryEntry,'lswd_officer',$data['lswd_name']??null,$data['lswd_position']??null);
        $this->official($lguDirectoryEntry,'lswd_officer_alternate',$data['lswd_alt_name']??null,$data['lswd_alt_position']??null);
        $this->official($lguDirectoryEntry,'ldrrmo_alternate',$data['ldrrmo_alt_name']??null,$data['ldrrmo_alt_position']??null);
        foreach ([['lce','email','lce_email'],['lce','phone','lce_phone'],['lswd_officer','email','lswd_email'],['lswd_officer','phone','lswd_phone'],['lswd_officer','facebook','lswd_facebook'],['lswd_officer_alternate','email','lswd_alt_email'],['lswd_officer_alternate','phone','lswd_alt_phone'],['lswd_officer_alternate','facebook','lswd_alt_facebook'],['ldrrmo','email','ldrrmo_email'],['ldrrmo','contact','ldrrmo_contact'],['ldrrmo','facebook','ldrrmo_facebook'],['ldrrmo','vhf','ldrrmo_vhf'],['ldrrmo_alternate','email','ldrrmo_alt_email'],['ldrrmo_alternate','contact','ldrrmo_alt_contact'],['ldrrmo_alternate','facebook','ldrrmo_alt_facebook']] as [$owner,$type,$key]) {
            $row=$lguDirectoryEntry->contacts()->firstOrCreate(['owner_role'=>$owner,'contact_type'=>$type],['value'=>null]);
            $row->update(['override_value'=>($data[$key]??null)===$row->value?null:($data[$key]??null)]);
        }
        return back()->with('success','LGU directory entry updated.');
    }
    private function official(LguDirectoryEntry $entry,string $role,?string $name,?string $position): void
    {
        $row=$entry->officials()->where('role',$role)->first();
        if(!$row&&filled($name)){$entry->officials()->create(['role'=>$role,'name'=>$name,'position_designation'=>$position]);return;}
        if($row)$row->update(['override_name'=>$name===$row->name?null:$name,'override_position_designation'=>$position===$row->position_designation?null:$position]);
    }
    private function syncLdrrmoOfficers(LguDirectoryEntry $entry,array $officers): void
    {
        foreach(array_values($officers) as $index=>$officer){
            $values=collect($officer)->only(['office','name','designation','mobile_number','hotline_number','landline_number','email_address','alternate_email_address','vhf_radio_frequency','facebook'])->all()+[
                'sort_order'=>$index,
                'is_primary'=>$index===0,
                'is_locally_updated'=>true,
            ];
            $row=filled($officer['id']??null)?$entry->ldrrmoOfficers()->whereKey($officer['id'])->first():null;
            $row?$row->update($values):$entry->ldrrmoOfficers()->create($values);
        }
        $primary=$officers[0]??null;
        if($primary){
            $contact=collect([$primary['mobile_number']??null,$primary['hotline_number']??null,$primary['landline_number']??null])->filter(fn($value)=>filled($value))->implode(' / ');
            $entry->forceFill([
                'ldrrmo_name'=>$primary['name']??null,
                'ldrrmo_position'=>$primary['designation']??null,
                'ldrrmo_contact'=>$contact?:null,
                'ldrrmo_email'=>$primary['email_address']??null,
            ])->save();
            foreach([['facebook',$primary['facebook']??null],['vhf',$primary['vhf_radio_frequency']??null]] as [$type,$value]){
                $contactRow=$entry->contacts()->firstOrCreate(['owner_role'=>'ldrrmo','contact_type'=>$type],['value'=>null]);
                $contactRow->update(['override_value'=>$value]);
            }
        }
    }
    private function syncLswdoAlternates(LguDirectoryEntry $entry,array $alternates): void
    {
        foreach(array_values($alternates) as $index=>$alternate){
            $values=collect($alternate)->only(['name','position','contact_number'])->all()+['sort_order'=>$index,'is_locally_updated'=>true];
            $row=filled($alternate['id']??null)?$entry->lswdoAlternates()->whereKey($alternate['id'])->first():null;
            $row?$row->update($values):$entry->lswdoAlternates()->create($values);
        }
        if($primary=$alternates[0]??null){
            $entry->forceFill([
                'lswd_alternate_name'=>$primary['name']??null,
                'lswd_alternate_position'=>$primary['position']??null,
                'lswd_alternate_contact_number'=>$primary['contact_number']??null,
            ])->save();
            $this->official($entry,'lswd_officer_alternate',$primary['name']??null,$primary['position']??null);
            $contactRow=$entry->contacts()->firstOrCreate(['owner_role'=>'lswd_officer_alternate','contact_type'=>'phone'],['value'=>null]);
            $contactRow->update(['override_value'=>$primary['contact_number']??null]);
        }
    }
}
