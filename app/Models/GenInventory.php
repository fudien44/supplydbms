<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GenInventory extends Model
{
    protected $table = 'trans_gen_inventory';
    protected $primaryKey = 'gid';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;
    protected $guarded = [];

    public function commodity()
    {
        return $this->belongsTo(CommodityMasterList::class, 'commodityid', 'com_code');
    }
}