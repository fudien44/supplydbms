<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferReportItem extends Model
{
    use HasFactory;

    protected $table = 'inventory_transfer_report_items';

    protected $fillable = [
        'transfer_report_id',
        'date_acquired',
        'reference',
        'description',
        'qty',
        'unit_cost',
        'item_condition',
    ];



    public function inventorydet()
    {
        return $this->belongsTo(GenInventory::class, 'inventory_id', 'commodityid');
    }

    public function report()
    {
        return $this->belongsTo(InventoryTransferReport::class, 'transfer_report_id', 'id');
    }

    public function getAmountAttribute()
    {
        return ($this->qty ?? 0) * ($this->unit_cost ?? 0);
    }


}