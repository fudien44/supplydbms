<?php

namespace App\Http\Controllers;

use App\Models\Mrpar;
use App\Models\TransferType;
use App\Models\Signatory;
use App\Models\EmployeeList;
use App\Models\InventoryTransferReport;
use App\Models\InventoryTransferReportItem;
use App\Models\FacilityAgencyList;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // Get only id and fullname
        $employees = EmployeeList::select('empid', 'fullname')->get();

        // ✅ Convert to array and return directly
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
                ->with(['transferType', 'toEntity', 'employeeInfo'])
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
}
