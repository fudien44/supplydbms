<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCustodianSlip extends Model
{
    protected $table = 'inventory_custodian_slips';

    protected $fillable = [
        'ics_no',
        'ics_type',
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
        return $this->hasMany(InventoryCustodianSlipItem::class, 'ics_id', 'id');
    }

    public function issuedBySignatory()
    {
        return $this->belongsTo(Signatory::class, 'issued_by', 'sign_id');
    }

    public function receivedByEmployee()
    {
        return $this->belongsTo(EmployeeList::class, 'received_by', 'empid');
    }

    public function attachments()
    {
        return $this->hasMany(InventoryCustodianAttachment::class, 'ics_id', 'id');
    }
}