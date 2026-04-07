<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommodityMasterList extends Model
{
    protected $table = 'ref_commoditymasterlist';
    protected $primaryKey = 'comid';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;
    protected $guarded = [];

    public function inventory()
    {
        return $this->hasOne(GenInventory::class, 'commodityid', 'com_code');
    }

    public function category()
    {
        return $this->belongsTo(CommodityCategory::class, 'com_categcode', 'categ_code');
    }
}
