<?php

namespace App\Http\Controllers;

use App\Exports\MRInventoryRecordsExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class MRInventoryRecordsController extends Controller
{
    protected string $pageName = 'MR_inventoryrecords';

    public function index(Request $request)
    {
        try {
            $categories = $this->getCategories();
            $inventory = $this->getInventoryQuery($request)->paginate(20);

            return response()->json([
                'status' => 'success',
                'filters' => [
                    'search' => $request->search,
                    'category_id' => $request->category_id,
                ],
                'categories' => $categories,
                'inventory' => $inventory,
            ]);
        } catch (\Throwable $e) {
            $this->writeErrorLog($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Sorry some error occurred. Please contact the system administrator.',
            ], 500);
        }
    }

    public function categories()
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->getCategories(),
        ]);
    }

    public function inventoryList(Request $request)
    {
        try {
            $rows = $this->getInventoryQuery($request)
                ->orderBy('ID', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'count' => $rows->count(),
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->writeErrorLog($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to load inventory records.',
            ], 500);
        }
    }

    public function recordList(Request $request, $inventoryId)
    {
        try {
            $search = $request->input('search');

            $query = DB::table('vw_mrissuedlist as a')
                ->select(
                    'b.dtrno',
                    'b.fullname',
                    'a.inventory_ID',
                    'a.description',
                    'a.qty',
                    'a.date_issued',
                    'a.source'
                )
                ->leftJoin('vw_employeelist as b', 'a.emp_no', '=', 'b.dtrno')
                ->where('a.void', 0)
                ->where('a.inventory_ID', $inventoryId)
                ->whereNotNull('a.emp_no')
                ->whereRaw('LEN(a.emp_no) > 0');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('b.fullname', 'like', '%' . $search . '%')
                      ->orWhere('b.dtrno', 'like', '%' . $search . '%');
                });
            }

            $rows = $query->orderBy('a.emp_no', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'count' => $rows->count(),
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->writeErrorLog($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to load issued records.',
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $exists = DB::table('vw_mrissuedlist')
                ->where('inventory_ID', $id)
                ->where('void', 0)
                ->count();

            if ($exists > 0) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'Unable to delete record. Inventory already has issued records.',
                ], 422);
            }

            $deleted = DB::table('tbl_mr_Maininventory')
                ->where('ID', $id)
                ->delete();

            if ($deleted > 0) {
                $this->auditTrail('Delete', 'Inventory deleted: ' . $id);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Successfully removed!',
                ]);
            }

            return response()->json([
                'status' => 'warning',
                'message' => 'No record was deleted.',
            ], 404);
        } catch (\Throwable $e) {
            $this->writeErrorLog($e);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        $fileName = 'Office Inventory Supply ' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(new MRInventoryRecordsExport($request), $fileName);
    }

    protected function getCategories()
    {
        return DB::table('tbl_mrcategory')
            ->select('Category_ID', 'Category_Name')
            ->orderBy('Category_Name', 'asc')
            ->get();
    }

    protected function getInventoryQuery(Request $request)
    {
        $search = $request->input('search');
        $categoryId = $request->input('category_id');

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
            });
    }

    protected function writeErrorLog(\Throwable $e): void
    {
        Log::error('MR_inventoryrecords error', [
            'page' => $this->pageName,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    protected function auditTrail(string $action, string $description): void
    {
        try {
            DB::table('audit_trails')->insert([
                'user_id' => Auth::id(),
                'page_name' => $this->pageName,
                'action' => $action,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit trail insert failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}