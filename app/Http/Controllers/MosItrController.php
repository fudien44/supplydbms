<?php

namespace App\Http\Controllers;

use App\Models\Mrpar;
use App\Models\TransferType;
use App\Models\Signatory;
use App\Models\EmployeeList;

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
        $top20 = $request->input('top20', '');
        $qry = $request->input('qry', '');
        $search = $request->input('search');
        $category = $request->input('category');

        // change to 50
        //     $sql = "
        //     SELECT {$top20} *, '0' as ptr_ID
        //     FROM vw_mrrunningbalance
        //     WHERE runningbalance > 0
        //     AND u_cost >= 15000
        //     AND ristype = 0
        // ";

        $sql = "
            SELECT {$top20}
                ID,
                date_acquired,
                ref_no,
                po_no,
                com_desc,
                Category_Name,
                description,
                u_cost,
                qty
                -- , '0' as ptr_ID  /* restore: SELECT {$top20} *, '0' as ptr_ID */
            FROM vw_mrrunningbalance
            WHERE runningbalance > 0
            AND u_cost >= 15000
            AND ristype = 0
        ";

        $bindings = [];

        if (!empty($search)) {
            $sql .= ' AND (com_desc LIKE ? OR ref_no LIKE ?)';
            $bindings[] = "%{$search}%";
            $bindings[] = "%{$search}%";
        }

        if (!empty($category)) {
            $sql .= ' AND Category_Name = ?';
            $bindings[] = $category;
        }

        if (!empty($qry)) {
            $sql .= " {$qry}";
        }

        $sql .= ' ORDER BY ID DESC';

        $data = DB::select($sql, $bindings);

        // return response()->json($data);
        return $data;
    }
}
