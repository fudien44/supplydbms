<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyAcknowledgementReceiptItem extends Model
{
    protected $table = 'property_acknowledgement_receipt_items';

    protected $fillable = [
        'par_id',
        'inventory_id',
        'date_acquired',
        'qty',
        'unit',
        'unit_cost',
        'amount',
        'description',
        'reference',
        'property_no',
    ];

    public function receipt()
    {
        return $this->belongsTo(PropertyAcknowledgementReceipt::class, 'par_id', 'id');
    }
}