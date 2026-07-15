<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LguDirectorySyncRun extends Model
{
    protected $fillable=['started_by','status','summary','error_message','started_at','finished_at'];
    protected function casts(): array { return ['summary'=>'array','started_at'=>'datetime','finished_at'=>'datetime']; }
}
