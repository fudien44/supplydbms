<?php

namespace App\Http\Controllers;

use App\Models\Mrpar;
use App\Models\TransferType;
use App\Models\Signatory;
use App\Models\EmployeeList;
use App\Models\InventoryTransferReport;
use App\Models\InventoryTransferReportItem;
use App\Models\FacilityAgencyList;
use App\Models\CommodityMasterList;
use App\Models\TransGenInventory;
use App\Models\CommodityCategory;
use App\Models\InventoryTransferAttachment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ItrExcelExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;


class MosItrController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'transferTypes' => $this->getTransferTypes(),
            'signatories' => $this->getsignatories(),
            'employeesList' => $this->employeesList(),
        ]);
    }

    public function getSignatories()
    {
        return [
            'approved_by' => Signatory::approvedBy()->get(),
            'issued_by' => Signatory::issuedBy()->get(),
        ];
    }

    private function getTransferTypes()
    {
        return TransferType::select('transid as transid', 'transtype as transtype')->where('transid', '!=', 5)->orderBy('transid', 'asc')->get();
    }

    public function employeesList()
    {
        $employees = EmployeeList::select('empid', 'fullname')
            ->where('void', '=', 0)
            ->orderBy('firstname', 'asc')
            ->get();

        return $employees->toArray();
    }

    public function listInventory(Request $request)
    {
        $search = $request->input('search');
        $category = $request->input('category');

        $baseWhere = 'WHERE runningbalance > 0 AND ristype = 0';
        $bindings = [];

        if (!empty($search)) {
            $baseWhere .= ' AND (com_desc LIKE ? OR ref_no LIKE ?)';
            $bindings[] = "%{$search}%";
            $bindings[] = "%{$search}%";
        }

        if (!empty($category)) {
            $baseWhere .= ' AND Category_Name = ?';
            $bindings[] = $category;
        }

        return DB::select(
            "
            SELECT TOP 20
                ID, date_acquired, ref_no, po_no,
                com_desc, Category_Name, description, u_cost, qty
            FROM vw_mrrunningbalance
            {$baseWhere}
            ORDER BY ID DESC
        ",
            $bindings,
        );
    }

    public function listGenInventory(Request $request)
    {
        $search = $request->input('search');
        $category = $request->input('category'); // from VSelect

        $query = CommodityMasterList::with(['inventory', 'category'])
            ->orderBy('comid', 'desc');

        // Text search (only on com_desc and inventory.poptrno)
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('com_desc', 'LIKE', "%{$search}%")
                ->orWhereHas('inventory', function ($q2) use ($search) {
                    $q2->where('poptrno', 'LIKE', "%{$search}%");
                });
            });
        }

        // Category filter (only if selected)
        if (!empty($category)) {
            $query->whereHas('category', function ($q) use ($category) {
                $q->where('comcategid', $category);
            });
        }

        return $query->limit(20)->get();
    }

    public function listFacilityAgency(Request $request)
    {
        $search = $request->input('search');

        $query = FacilityAgencyList::select('faid', 'facilitycode', 'facilityname')->where('void', 0)->where('isactive', 1)->orderBy('facilityname', 'asc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('facilitycode', 'LIKE', "%{$search}%")->orWhere('facilityname', 'LIKE', "%{$search}%");
            });
        }

        return $query->limit(20)->get();
    }

    public function save(Request $request)
    {
        $id = $request->input('id');

        if (!$id) {
            $exists = InventoryTransferReport::where('itr_no', $request->itr_no)->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => "ITR No. '{$request->itr_no}' already exists."
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $totalAmount = collect($request->items)->sum(function ($item) {
                return ($item['qty'] ?? 0) * ($item['unit_cost'] ?? 0);
            });

            $data = [
                'itr_no'           => $request->itr_no,
                'entity_name'      => $request->entity_name,
                'fund_cluster'     => $request->fund_cluster,
                'from_entity'      => $request->from_entity,
                'to_entity'        => $request->to_entity,
                'date_issued'      => $request->date_issued,
                'transfer_type_id' => $request->transfer_type_id,
                'others_specify'   => $request->others_specify,
                'reason'           => $request->reason,
                'approved_by'      => $request->approved_by,
                'issued_by'        => $request->issued_by,
                'received_by'      => $request->received_by,
                'status'           => $request->status ?? 'pending',
                'total_amount'     => $totalAmount,
            ];

            if ($id) {
                $report = InventoryTransferReport::findOrFail((int) $id);
                $report->update($data);
                $report->items()->delete();
            } else {
                $report = InventoryTransferReport::create($data);
            }

            $items = collect($request->items)->map(function ($item) use ($report) {
                return [
                    'transfer_report_id' => $report->id,
                    'date_acquired'      => $item['date_acquired'] ?? null,
                    'reference'          => $item['reference'] ?? null,
                    'description'        => $item['description'] ?? null,
                    'qty'                => $item['qty'] ?? 0,
                    'unit_cost'          => $item['unit_cost'] ?? 0,
                    'amount'             => ($item['qty'] ?? 0) * ($item['unit_cost'] ?? 0),
                    'item_condition'     => $item['item_condition'] ?? null,
                    'inventory_id'       => $item['inventory_id'] ?? null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ];
            });

            InventoryTransferReportItem::insert($items->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $id ? 'Updated successfully' : 'Created successfully',
                'data'    => $report,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function listItr(Request $request)
    {
        try {
            $search = $request->input('search');
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');
            $transferType = $request->input('transfer_type_id');
            $perPage = min(100, (int) $request->input('per_page', 20));

            $query = InventoryTransferReport::query()
                ->with(['transferType', 'toEntity', 'employeeInfo', 'attachments'])
                ->orderBy('date_issued', 'desc')
                ->limit(20);

            if ($fromDate && $toDate) {
                $query->whereBetween('date_issued', [$fromDate, $toDate]);
            } else {
                if ($fromDate) {
                    $query->whereDate('date_issued', '>=', $fromDate);
                }
                if ($toDate) {
                    $query->whereDate('date_issued', '<=', $toDate);
                }
            }

            if ($transferType) {
                $query->where('transfer_type_id', $transferType);
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('itr_no', 'LIKE', "%{$search}%")
                        ->orWhere('entity_name', 'LIKE', "%{$search}%")
                        ->orWhere('reason', 'LIKE', "%{$search}%");
                });
            }

            return response()->json($query->paginate($perPage));
        } catch (\Exception $e) {
            return response()->json(
                [
                    'message' => 'Error fetching reports',
                    'error' => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function showItr($id)
    {
        $transfer = InventoryTransferReport::with(['items', 'transferType'])
            ->findOrFail($id);

        return response()->json($transfer);
    }

    public function destroy($id)
    {
        try {
            $report = InventoryTransferReport::findOrFail((int) $id);
            $report->items()->delete();
            $report->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transfer record deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generatePdf($id)
    {
        $transfer = InventoryTransferReport::with([
            'items.inventorydet', 
            'transferType',
            'fromEntity',
            'toEntity',
            'approvedBySignatory',
            'issuedBySignatory',
            'receivedByEmployee',
        ])->findOrFail((int) $id);

        $pdf = Pdf::loadView('pdf.itr', [
            'transfer' => $transfer,
        ])->setPaper('legal', 'portrait');

        return $pdf->stream("ITR-{$transfer->itr_no}.pdf");
    }

    public function export(Request $request)
    {
        return Excel::download(
            new ItrExcelExport(
                $request->from_date,
                $request->to_date,
                $request->transfer_type_id
            ),
            'itr_records.xlsx'
        );
    }

    public function listAttachments($id)
    {
        $itr = InventoryTransferReport::findOrFail($id);
        return response()->json($itr->attachments);
    }

    public function uploadAttachment(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $itr = InventoryTransferReport::findOrFail($id);
        $file = $request->file('file');

        // Ensure directory exists
        Storage::disk('public')->makeDirectory('attachments');

        $path = $file->storeAs(
            'attachments',
            uniqid() . '_' . $file->getClientOriginalName(),
            'public'
        );

        if (!$path) {
            return response()->json(['error' => 'File failed to save'], 500);
        }

        \Log::info('Saved to: ' . $path);

        $attachment = $itr->attachments()->create([
            'filename' => $file->getClientOriginalName(),
            'filepath' => $path,
            'filetype' => $file->getClientOriginalExtension(),
            'filesize' => $file->getSize(),
        ]);

        return response()->json([
            'message' => 'Uploaded successfully',
            'attachment' => $attachment,
        ]);
    }

    public function downloadAttachment($itrId, $attachmentId)
    {
        $itr = InventoryTransferReport::findOrFail($itrId);
        $attachment = $itr->attachments()->findOrFail($attachmentId);

        $path = $attachment->filepath;

        if (!Storage::disk('public')->exists($path)) {
            return response()->json([
                'error' => 'File not found',
                'path' => $path,
            ], 404);
        }

        return Storage::disk('public')->download($path, $attachment->filename);
    }

    public function deleteAttachment($itrId, $attachmentId)
    {
        $itr = InventoryTransferReport::findOrFail($itrId);
        $attachment = $itr->attachments()->findOrFail($attachmentId);

        // Delete physical file
        if (Storage::disk('public')->exists($attachment->filepath)) {
            Storage::disk('public')->delete($attachment->filepath);
        }

        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted successfully']);
    }
}
