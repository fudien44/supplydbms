<?php

namespace App\Exports;

use App\Http\Controllers\MosParController;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ParExcelExport implements FromView
{
    public function __construct(
        protected ?string $search = null,
        protected ?string $from = null,
        protected ?string $to = null,
    ) {
    }

    public function view(): View
    {
        return view('excel.parexcel', [
            'records' => MosParController::listQuery($this->search, $this->from, $this->to)->get(),
        ]);
    }
}