<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommodityCategory extends Model
{
    protected $table = 'ref_commoditycategory';
    protected $primaryKey = 'comcategid';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;
    protected $guarded = [];
}