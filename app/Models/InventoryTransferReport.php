<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferReport extends Model
{
    use HasFactory;

    protected $table = 'inventory_transfer_reports';

    protected $fillable = ['itr_no', 'itr_type', 'entity_name', 'fund_cluster', 'from_entity', 'to_entity', 'date_issued', 'transfer_type_id', 'others_specify', 'reason', 'approved_by', 'issued_by', 'received_by', 'status', 'total_amount'];

    public function items()
    {
        return $this->hasMany(InventoryTransferReportItem::class, 'transfer_report_id', 'id');
    }

    public function transferType()
    {
        return $this->belongsTo(TransferType::class, 'transfer_type_id', 'transid');
    }

    public function toEntity()
    {
        return $this->belongsTo(FacilityAgencyList::class, 'to_entity', 'faid');
    }

    public function fromEntity()
    {
        return $this->belongsTo(FacilityAgencyList::class, 'from_entity', 'faid');
    }

    public function employeeInfo()
    {
        return $this->belongsTo(EmployeeList::class, 'received_by', 'empid');
    }

    public function approvedBySignatory()
    {
        return $this->belongsTo(Signatory::class, 'approved_by', 'sign_id');
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
        return $this->hasMany(InventoryTransferAttachment::class, 'transfer_report_id', 'id');
    }
}
