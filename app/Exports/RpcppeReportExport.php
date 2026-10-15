<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * RPCPPE / RPCSP / RPCI report as a styled .xlsx — mirrors the PDF layout
 * (title block, bordered table, grouping/subtotals, total, signatories).
 */
class RpcppeReportExport implements FromView, WithColumnWidths, WithEvents
{
    public function __construct(private array $data) {}

    public function view(): View
    {
        return view('excel.rpcppe', $this->data);
    }

    /** Column widths (A–L) matching the 12 report columns. */
    public function columnWidths(): array
    {
        return [
            'A' => 22,  // Article
            'B' => 55,  // Description
            'C' => 14,  // Date Acquired
            'D' => 18,  // Source / Serial No.
            'E' => 12,  // Unit of Measure
            'F' => 14,  // Unit Value
            'G' => 12,  // Balance per Card
            'H' => 12,  // On Hand per Count
            'I' => 16,  // Total Value
            'J' => 12,  // Shortage/Overage Qty
            'K' => 12,  // Shortage/Overage Value
            'L' => 30,  // Remarks
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Wrap long text so rows auto-size their height.
                foreach (['A', 'B', 'L'] as $col) {
                    $sheet->getStyle($col . '1:' . $col . $sheet->getHighestRow())
                        ->getAlignment()->setWrapText(true)
                        ->setVertical(Alignment::VERTICAL_TOP);
                }
            },
        ];
    }
}
