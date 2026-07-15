<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receiver extends Model
{
    use SoftDeletes;

    protected $fillable = ['agency', 'contact_person', 'contact_number', 'province', 'municipality', 'address'];
}
