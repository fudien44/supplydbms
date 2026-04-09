<?php

namespace App\Http\Controllers\RPCPPE;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RPCPPEController extends Controller
{
    public function masterlist()
    {
        try {
            $data = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.tbl_mr_Maininventory as mi')
                ->leftJoin('EstockDbsv2_devdev.dbo.tbl_mrcommodity as mc', 'mi.commodity_ID', '=', 'mc.OS_code')
                ->leftJoin('EstockDbsv2_devdev.dbo.ref_uom as uom', 'mi.unitofmeasure', '=', 'uom.uomid')
                ->select(
                    'mi.ID as item_id',
                    'mi.commodity_ID',
                    'mi.category_ID',
                    'mi.unitofmeasure as uom_id',
                    'uom.uom_name as unitofmeasure',
                    'mi.qty',
                    'mi.u_cost',
                    'mi.amt',
                    'mi.remarks',
                    'mi.stock_no',
                    'mi.source',
                    'mc.com_desc as com_desc',
                    'mi.date_acquired',
                    'mi.po_no'
                )
                ->where('mi.void', 0)
                ->orderBy('mi.ID', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'total'  => $data->count(),
            ], 200);
        }
        catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getItems()
    {
        try {
            $data = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.rpcppe as r')
                ->leftJoin('EstockDbsv2_devdev.dbo.articles as a', 'a.id', '=', 'r.article_id')
                ->select(
                    'r.ID',
                    'r.item_id',
                    'r.article_id',
                    'a.article_name',
                    'r.category_id',
                    'r.com_desc',
                    'r.unitofmeasure',
                    'r.po_no',
                    'r.date_acquired',
                    'r.u_cost',
                    'r.qty',
                    'r.stock_no',
                    'r.source',
                    'r.emp_no',
                    'r.issuance_type',
                    'r.issuance_id',
                    'r.created_at',
                    'r.updated_at',
                    'r.amt'
                )
                ->orderBy('r.ID', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'total'  => $data->count(),
            ], 200);
        }
        catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeItems(Request $request)
    {
        $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.item_id'  => 'required|integer',
            'items.*.article'  => 'required|string',
        ]);

        DB::connection('sqlsrv')->beginTransaction();

        try {
            $rows = [];

            foreach ($request->items as $item) {
                $articleId = DB::connection('sqlsrv')
                    ->table('EstockDbsv2_devdev.dbo.articles')
                    ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [trim($item['article'] ?? '')])
                    ->value('id');

                if (!$articleId) {
                    throw new \Exception('Selected article was not found in articles table: ' . ($item['article'] ?? ''));
                }

                $itemId = $item['item_id'] ?? null;

                if (!$itemId) {
                    throw new \Exception('Main inventory item_id is required.');
                }

                $alreadyExists = DB::connection('sqlsrv')
                    ->table('EstockDbsv2_devdev.dbo.rpcppe')
                    ->where('item_id', $itemId)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $rows[] = [
                    'item_id'        => $itemId,
                    'article_id'     => $articleId,
                    'category_id'    => $item['category_id'] ?? null,
                    'com_desc'       => $item['description'] ?? null,
                    'unitofmeasure'  => $item['unit'] ?? null,
                    'po_no'          => $item['serialNo'] ?? null,
                    'date_acquired'  => !empty($item['dateAcquired']) ? $item['dateAcquired'] : null,
                    'u_cost'         => is_numeric($item['unitValue'] ?? null) ? $item['unitValue'] : 0,
                    'qty'            => is_numeric($item['balancePerCard'] ?? null) ? $item['balancePerCard'] : 0,
                    'stock_no'       => $item['stockNo'] ?? null,
                    'source'         => $item['source'] ?? null,
                    'emp_no'         => $item['emp_no'] ?? '',
                    'issuance_type'  => $item['issuance_type'] ?? '',
                    'issuance_id'    => $item['issuance_id'] ?? 0,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                    'amt'            => is_numeric($item['totalValue'] ?? null) ? $item['totalValue'] : 0,
                ];
            }

            if (!empty($rows)) {
                DB::connection('sqlsrv')
                    ->table('EstockDbsv2_devdev.dbo.rpcppe')
                    ->insert($rows);
            }

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'status'         => 'success',
                'message'        => 'RPCPPE items added successfully.',
                'inserted_count' => count($rows),
            ], 200);
        }
        catch (\Throwable $e) {
            DB::connection('sqlsrv')->rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteItems(Request $request)
    {
        $ids = $request->input('ids', []);

        DB::connection('sqlsrv')
            ->table('EstockDbsv2_devdev.dbo.rpcppe')
            ->whereIn('ID', $ids)
            ->delete();

        return response()->json([
            'status'        => 'success',
            'deleted_count' => count($ids),
        ]);
    }

    public function updateItems(Request $request)
    {
        try {
            $items = $request->input('items', []);

            if (!is_array($items) || !count($items)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No items provided for update.',
                ], 422);
            }

            DB::connection('sqlsrv')->beginTransaction();

            foreach ($items as $item) {
                $itemId  = $item['item_id'] ?? null;
                $article = trim($item['article'] ?? '');

                if (!$itemId || $article === '') {
                    continue;
                }

                $articleId = DB::connection('sqlsrv')
                    ->table('EstockDbsv2_devdev.dbo.articles')
                    ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [$article])
                    ->value('id');

                if (!$articleId) {
                    throw new \Exception('Selected article was not found in articles table: ' . $article);
                }

                DB::connection('sqlsrv')
                    ->table('EstockDbsv2_devdev.dbo.rpcppe')
                    ->where('item_id', $itemId)
                    ->update([
                        'article_id'    => $articleId,
                        'category_id'   => $item['category_id'] ?? null,
                        'com_desc'      => $item['description'] ?? null,
                        'unitofmeasure' => $item['unit'] ?? null,
                        'po_no'         => $item['serialNo'] ?? null,
                        'date_acquired' => !empty($item['dateAcquired']) ? $item['dateAcquired'] : null,
                        'u_cost'        => is_numeric($item['unitValue'] ?? null) ? $item['unitValue'] : 0,
                        'qty'           => is_numeric($item['balancePerCard'] ?? null) ? $item['balancePerCard'] : 0,
                        'amt'           => is_numeric($item['totalValue'] ?? null) ? $item['totalValue'] : 0,
                        'stock_no'      => $item['stockNo'] ?? null,
                        'source'        => $item['source'] ?? null,
                        'emp_no'        => $item['emp_no'] ?? null,
                        'issuance_type' => $item['issuance_type'] ?? null,
                        'issuance_id'   => $item['issuance_id'] ?? null,
                        'updated_at'    => now(),
                    ]);
            }

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'RPCPPE items updated successfully.',
            ], 200);
        }
        catch (\Throwable $e) {
            DB::connection('sqlsrv')->rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function accountableOfficers()
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT DISTINCT
                    x.emp_no,
                    x.fullname
                FROM (
                    SELECT
                        a.emp_no,
                        c.fullname
                    FROM tbl_mrics a
                    LEFT JOIN vw_employeelist c
                        ON a.emp_no = c.dtrno
                    WHERE a.emp_no IS NOT NULL
                    AND LTRIM(RTRIM(a.emp_no)) <> ''

                    UNION

                    SELECT
                        a.emp_no,
                        c.fullname
                    FROM tbl_mrpar a
                    LEFT JOIN vw_employeelist c
                        ON a.emp_no = c.dtrno
                    WHERE a.emp_no IS NOT NULL
                    AND LTRIM(RTRIM(a.emp_no)) <> ''
                ) x
                WHERE x.fullname IS NOT NULL
                AND LTRIM(RTRIM(x.fullname)) <> ''
                ORDER BY x.fullname ASC
            ");

            return response()->json($data, 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
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
                'data'   => $data,
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getArticles()
    {
        try {
            $data = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.articles as a')
                ->leftJoin('EstockDbsv2_devdev.dbo.ppe_types as pt', 'pt.id', '=', 'a.ppe_type_id')
                ->select(
                    'a.id',
                    'a.article_name',
                    'a.ppe_type_id',
                    'pt.ppe_name',
                    'a.is_active',
                    'a.created_at',
                    'a.updated_at'
                )
                ->orderBy('a.article_name')
                ->get();

            return response()->json($data, 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeArticle(Request $request)
    {
        try {
            $request->validate([
                'article_name' => 'required|string|max:255|unique:sqlsrv.articles,article_name',
                'ppe_type_id'  => 'required|integer|exists:sqlsrv.ppe_types,id',
            ]);

            $id = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.articles')
                ->insertGetId([
                    'article_name' => $request->article_name,
                    'ppe_type_id'  => $request->ppe_type_id,
                    'is_active'    => 1,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

            return response()->json([
                'success' => true,
                'id'      => $id,
                'message' => 'Article added successfully.',
            ], 200);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateArticle(Request $request, $id)
    {
        try {
            $request->validate([
                'article_name' => 'required|string|max:255|unique:sqlsrv.articles,article_name,' . $id,
                'ppe_type_id'  => 'required|integer|exists:sqlsrv.ppe_types,id',
            ]);

            DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.articles')
                ->where('id', $id)
                ->update([
                    'article_name' => $request->article_name,
                    'ppe_type_id'  => $request->ppe_type_id,
                    'updated_at'   => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Article updated successfully.',
            ], 200);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteArticle($id)
    {
        try {
            DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.articles')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Article deleted successfully.',
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPpeTypes()
    {
        try {
            $data = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.ppe_types')
                ->select(
                    'id',
                    'ppe_name',
                    'is_active',
                    'created_at',
                    'updated_at'
                )
                ->orderBy('ppe_name')
                ->get();

            return response()->json($data, 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function storePpeType(Request $request)
    {
        try {
            $request->validate([
                'ppe_name' => 'required|string|max:255|unique:sqlsrv.ppe_types,ppe_name',
            ]);

            $id = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.ppe_types')
                ->insertGetId([
                    'ppe_name'   => $request->ppe_name,
                    'is_active'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'id'      => $id,
                'message' => 'PPE type added successfully.',
            ], 200);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePpeType(Request $request, $id)
    {
        try {
            $request->validate([
                'ppe_name' => 'required|string|max:255|unique:sqlsrv.ppe_types,ppe_name,' . $id,
            ]);

            DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.ppe_types')
                ->where('id', $id)
                ->update([
                    'ppe_name'   => $request->ppe_name,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'PPE type updated successfully.',
            ], 200);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deletePpeType($id)
    {
        try {
            $articleCount = DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.articles')
                ->where('ppe_type_id', $id)
                ->count();

            if ($articleCount > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot delete PPE type because it is already used by one or more articles.',
                ], 422);
            }

            DB::connection('sqlsrv')
                ->table('EstockDbsv2_devdev.dbo.ppe_types')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'PPE type deleted successfully.',
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}