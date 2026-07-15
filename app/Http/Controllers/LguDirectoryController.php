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
        $summary=$service->sync(request()->user()?->id);
        return back()->with('success', "LGU sync applied: {$summary['new']} added, {$summary['changed']} updated, {$summary['missing']} deactivated.");
    }
    public function preview(LguDirectorySyncService $service): RedirectResponse { return back()->with('lgu_sync_preview',$service->preview()); }
    public function update(Request $request,LguDirectoryEntry $lguDirectoryEntry): RedirectResponse
    {
        $data=$request->validate([
            'lgu_name'=>['required','string','max:255'],'congressional_district'=>['nullable','string','max:255'],'office_address'=>['nullable','string'],
            'lce_name'=>['nullable','string','max:255'],'lce_position'=>['nullable','string','max:255'],'lce_email'=>['nullable','string','max:500'],
            'lswd_name'=>['nullable','string','max:255'],'lswd_position'=>['nullable','string','max:255'],'lswd_email'=>['nullable','string','max:500'],'lswd_phone'=>['nullable','string','max:500'],
        ]);
        $lguDirectoryEntry->update([
            'override_lgu_name'=>$data['lgu_name']===$lguDirectoryEntry->lgu_name?null:$data['lgu_name'],
            'override_congressional_district'=>($data['congressional_district']??null)===$lguDirectoryEntry->congressional_district?null:($data['congressional_district']??null),
            'override_office_address'=>($data['office_address']??null)===$lguDirectoryEntry->office_address?null:($data['office_address']??null),
        ]);
        $this->official($lguDirectoryEntry,'lce',$data['lce_name']??null,$data['lce_position']??null);
        $this->official($lguDirectoryEntry,'lswd_officer',$data['lswd_name']??null,$data['lswd_position']??null);
        foreach ([['lce','email','lce_email'],['lswd_officer','email','lswd_email'],['lswd_officer','phone','lswd_phone']] as [$owner,$type,$key]) {
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
}
