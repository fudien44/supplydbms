<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class TransferType extends Model
{
    protected $table = 'tbl_transfertype';
    protected $primaryKey = 'transid';
    public $incrementing = true;
    protected $keyType = 'int';  
    protected $guarded = array();
}
