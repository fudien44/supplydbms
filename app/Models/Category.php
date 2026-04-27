<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class EmployeeList extends Model
{
    protected $table = 'tbl_mrcategory';
    protected $primaryKey = 'Category_ID';
    public $incrementing = true;
    protected $keyType = 'int';  
    protected $guarded = array();
}
