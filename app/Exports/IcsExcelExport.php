<?php

namespace App\Exports;

use App\Http\Controllers\MosIcsController;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class IcsExcelExport implements FromView
{
    public function __construct(
        protected string $icsType,
        protected ?string $search = null,
        protected ?string $from = null,
        protected ?string $to = null,
    ) {
    }

    public function view(): View
    {
        return view('excel.icsexcel', [
            'records' => MosIcsController::listQuery($this->icsType, $this->search, $this->from, $this->to)->get(),
        ]);
    }
}