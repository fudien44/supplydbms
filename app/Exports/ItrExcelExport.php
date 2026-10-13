<?php

namespace App\Exports;

use App\Models\InventoryTransferReport;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ItrExcelExport implements FromView
{
    protected $from;
    protected $to;
    protected $transferType;

    protected $itrType;


   public function __construct($from = null, $to = null, $transferType = null, $itrType = null)
{
    $this->from = $from;
    $this->to = $to;
    $this->transferType = $transferType;
    $this->itrType = $itrType;
}

    public function view(): View
    {
        $query = InventoryTransferReport::with([
            'items',                 
            'transferType',         
            'toEntity',           
            'fromEntity',              
            'employeeInfo',          
            'approvedBySignatory',  
            'issuedBySignatory',   
            'receivedByEmployee', 
        ]);

        // Apply filters
        if ($this->from && $this->to) {
            $query->whereBetween('date_issued', [$this->from, $this->to]);
        }

        if ($this->transferType) {
            $query->where('transfer_type_id', $this->transferType);
        }

        if ($this->itrType) {
            $query->where('itr_type', $this->itrType);
        }

        return view('excel.itrexcel', [
            'records' => $query->get()
        ]);
    }
}