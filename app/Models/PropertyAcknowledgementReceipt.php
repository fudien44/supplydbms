<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyAcknowledgementReceipt extends Model
{
    protected $table = 'property_acknowledgement_receipts';

    protected $fillable = [
        'par_no',
        'entity_name',
        'fund_cluster',
        'date_issued',
        'issued_by',
        'received_by',
        'recipient_pos',
        'remarks',
        'total_amount',
    ];

    public function items()
    {
        return $this->hasMany(PropertyAcknowledgementReceiptItem::class, 'par_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(PropertyAcknowledgementAttachment::class, 'par_id', 'id');
    }

    public function issuedBySignatory()
    {
        return $this->belongsTo(Signatory::class, 'issued_by', 'sign_id');
    }

    public function receivedByEmployee()
    {
        return $this->belongsTo(EmployeeList::class, 'received_by', 'empid');
    }
}