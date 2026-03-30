<?php

namespace App\Http\Controllers\RPCPPE;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RPCPPEController extends Controller
{
    // public function masterlist(Request $request)
    // {
    //     $query = DB::connection('sqlsrv')
    //         ->table('inventory_items')
    //         ->select(
    //             'id',
    //             'property_no',
    //             'item_name',
    //             'description',
    //             'unit',
    //             'unit_value',
    //             'quantity'
    //         );

    //     if ($request->filled('search')) {
    //         $search = $request->search;

    //         $query->where(function ($q) use ($search) {
    //             $q->where('property_no', 'like', '%' . $search . '%')
    //               ->orWhere('item_name', 'like', '%' . $search . '%')
    //               ->orWhere('description', 'like', '%' . $search . '%');
    //         });
    //     }

    //     $items = $query->orderBy('item_name')->get();

    //     return response()->json([
    //         'success' => true,
    //         'data' => $items,
    //     ]);
    // }

    public function masterlist(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => 1,
                    'article' => '',
                    'description' => 'Dell Latitude Laptop',
                    'propertyNo' => 'PPE-001',
                    'serialNo' => '',
                    'unitValue' => 45000,
                    'autoGenerate' => false,
                ],
                [
                    'id' => 2,
                    'article' => '',
                    'description' => 'Office Chair Ergonomic',
                    'propertyNo' => 'PPE-002',
                    'serialNo' => '',
                    'unitValue' => 6500,
                    'autoGenerate' => false,
                ],
                [
                    'id' => 3,
                    'article' => '',
                    'description' => 'Epson Ink Tank Printer',
                    'propertyNo' => 'PPE-003',
                    'serialNo' => '',
                    'unitValue' => 12000,
                    'autoGenerate' => false,
                ],
            ],
        ]);
    }

    public function accOfficer(Request $request)
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT *
                FROM [EstockDbsv2_devdev].[dbo].[trans_employee]
                WHERE isactive = 1
                ORDER BY lastname ASC
            ");
            return response()->json([
                'status' => 'success',
                'data'   => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function categories(Request $request)
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT *
                FROM [EstockDbsv2_devdev].[dbo].[tbl_mrcategory]
            ");
            return response()->json([
                'status' => 'success',
                'data'   => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function fund_cluster(Request $request)
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT *
                FROM [EstockDbsv2_devdev].[dbo].[ref_programs]
            ");
            return response()->json([
                'status' => 'success',
                'data'   => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function entity(Request $request)
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT *
                FROM [EstockDbsv2_devdev].[dbo].[ref_entity]
            ");
            return response()->json([
                'status' => 'success',
                'data'   => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


}
