<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class EmployeeList extends Model
{
    protected $table = 'vw_employeelist';
    protected $primaryKey = 'empid';
    public $incrementing = false;
    protected $keyType = 'int';  
    protected $guarded = array();
}
