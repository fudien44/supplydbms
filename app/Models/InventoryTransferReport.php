<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferReport extends Model
{
    use HasFactory;

    protected $table = 'inventory_transfer_reports';

    protected $fillable = [
        'itr_no',
        'entity_name',
        'fund_cluster',
        'from_entity',
        'to_entity',
        'date_issued',
        'transfer_type_id',
        'others_specify',
        'reason',
        'approved_by',
        'issued_by',
        'received_by',
        'status',
        'total_amount',
    ];

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

    public function employeeInfo()
    {
       return $this->belongsto(EmployeeList::class, 'received_by', 'empid');
    }
}