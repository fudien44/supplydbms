<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class FacilityAgencyList extends Model
{
    protected $table = 'ref_facilities_agency';
    protected $primaryKey = 'faid';
    public $incrementing = true;
    protected $keyType = 'int';  
    protected $guarded = array();
}
