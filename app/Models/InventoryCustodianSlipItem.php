<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCustodianSlipItem extends Model
{
    protected $table = 'inventory_custodian_slip_items';

    protected $fillable = [
        'ics_id',
        'inventory_id',
        'date_acquired',
        'qty',
        'unit',
        'unit_cost',
        'amount',
        'description',
        'reference',
        'inventory_no',
        'est_useful_life',
    ];

    public function slip()
    {
        return $this->belongsTo(InventoryCustodianSlip::class, 'ics_id', 'id');
    }
}