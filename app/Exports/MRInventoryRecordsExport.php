<?php

namespace App\Exports;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class MRInventoryRecordsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected Request $request;
    protected int $rowNumber = 0;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $search = $this->request->input('search');
        $categoryId = $this->request->input('category_id');

        return DB::table('vw_mrrunningbalance')
            ->selectRaw("*, (com_desc + ' (' + unitofmeasure + ')') as descwithunit")
            ->where('void', 0)
            ->when(!empty($search), function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('stock_no', 'like', '%' . $search . '%')
                      ->orWhere('com_desc', 'like', '%' . $search . '%')
                      ->orWhere('po_no', 'like', '%' . $search . '%');
                });
            })
            ->when(!empty($categoryId) && $categoryId !== 'Select Type', function ($query) use ($categoryId) {
                $query->where('category_ID', 'like', '%' . $categoryId . '%');
            })
            ->orderBy('ID', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            '#',
            'Stock No.',
            'P.O No.',
            'Commodity(Unit)',
            'Orig. Qty',
            'Unit Cost',
            'Amount',
            'Balance',
            'Return',
            'Reference No',
            'Date Acquired',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->stock_no ?? '',
            $row->po_no ?? '',
            $row->descwithunit ?? '',
            $row->qty ?? '',
            $row->u_cost ?? 0,
            $row->total_amount ?? 0,
            $row->runningbalance ?? '',
            $row->return_stock ?? '',
            $row->ref_no ?? '',
            !empty($row->date_acquired) ? date('F d, Y', strtotime($row->date_acquired)) : '',
        ];
    }
}