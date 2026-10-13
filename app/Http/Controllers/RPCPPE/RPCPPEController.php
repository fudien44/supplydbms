<?php

namespace App\Http\Controllers\RPCPPE;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class RPCPPEController extends Controller
{
    public function masterlist()
    {
        try {
            $data = DB::connection('sqlsrv')
                ->table('vw_MainInventory')
                ->orderBy('item_id', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'total'  => $data->count(),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getItems(Request $request)
    {
        try {
            $search = trim((string) $request->input('search', ''));

            $query = DB::connection('sqlsrv')
                ->table('EstockDbsv2.dbo.rpcppe as r')
                ->leftJoin('EstockDbsv2.dbo.articles as a', 'a.id', '=', 'r.article_id')
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
                    'r.remarks',
                    'r.emp_no',
                    'r.issuance_type',
                    'r.issuance_id',
                    'r.created_at',
                    'r.updated_at',
                    'r.amt'
                );

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $like = '%' . $search . '%';

                    $q->where('a.article_name', 'like', $like)
                        ->orWhere('r.com_desc', 'like', $like)
                        ->orWhere('r.po_no', 'like', $like)
                        ->orWhere('r.stock_no', 'like', $like)
                        ->orWhere('r.remarks', 'like', $like)
                        ->orWhere('r.source', 'like', $like)
                        ->orWhere('r.emp_no', 'like', $like)
                        ->orWhere('r.issuance_type', 'like', $like);
                });
            }

            $data = $query
                ->orderBy('r.ID', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'total'  => $data->count(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeItems(Request $request)
    {
        $request->validate([
            'items'           => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.article' => 'required|string',
        ]);

        DB::connection('sqlsrv')->beginTransaction();

        try {
            $rows = [];

            foreach ($request->items as $item) {
                $articleId = DB::connection('sqlsrv')
                    ->table('EstockDbsv2.dbo.articles')
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
                    ->table('EstockDbsv2.dbo.rpcppe')
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
                    'remarks'        => isset($item['remarks']) && $item['remarks'] !== '' ? $item['remarks'] : null,
                    'emp_no'         => trim((string) ($item['emp_no'] ?? '')),
                    'issuance_type'  => trim((string) ($item['issuance_type'] ?? '')),
                    'issuance_id'    => $item['issuance_id'] ?? 0,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                    'amt'            => is_numeric($item['totalValue'] ?? null) ? $item['totalValue'] : 0,
                ];
            }

            if (!empty($rows)) {
                DB::connection('sqlsrv')
                    ->table('EstockDbsv2.dbo.rpcppe')
                    ->insert($rows);
            }

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'status'         => 'success',
                'message'        => 'RPCPPE items added successfully.',
                'inserted_count' => count($rows),
            ], 200);
        } catch (\Throwable $e) {
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
            ->table('EstockDbsv2.dbo.rpcppe')
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
                    ->table('EstockDbsv2.dbo.articles')
                    ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [$article])
                    ->value('id');

                if (!$articleId) {
                    throw new \Exception('Selected article was not found in articles table: ' . $article);
                }

                DB::connection('sqlsrv')
                    ->table('EstockDbsv2.dbo.rpcppe')
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
                        'remarks'       => isset($item['remarks']) && $item['remarks'] !== '' ? $item['remarks'] : null,
                        'emp_no'        => trim((string) ($item['emp_no'] ?? '')),
                        'issuance_type' => trim((string) ($item['issuance_type'] ?? '')),
                        'issuance_id'   => $item['issuance_id'] ?? null,
                        'updated_at'    => now(),
                    ]);
            }

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'RPCPPE items updated successfully.',
            ], 200);
        } catch (\Throwable $e) {
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
                        LTRIM(RTRIM(a.emp_no)) as emp_no,
                        c.fullname
                    FROM tbl_mrics a
                    LEFT JOIN vw_employeelist c
                        ON LTRIM(RTRIM(a.emp_no)) = LTRIM(RTRIM(c.dtrno))
                    WHERE a.emp_no IS NOT NULL
                      AND LTRIM(RTRIM(a.emp_no)) <> ''

                    UNION

                    SELECT
                        LTRIM(RTRIM(a.emp_no)) as emp_no,
                        c.fullname
                    FROM tbl_mrpar a
                    LEFT JOIN vw_employeelist c
                        ON LTRIM(RTRIM(a.emp_no)) = LTRIM(RTRIM(c.dtrno))
                    WHERE a.emp_no IS NOT NULL
                      AND LTRIM(RTRIM(a.emp_no)) <> ''
                ) x
                WHERE x.fullname IS NOT NULL
                  AND LTRIM(RTRIM(x.fullname)) <> ''
                ORDER BY x.fullname ASC
            ");

            return response()->json($data, 200);
        } catch (\Exception $e) {
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
                FROM [EstockDbsv2].[dbo].[tbl_mrcategory]
            ");

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ], 200);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.articles as a')
                ->leftJoin('EstockDbsv2.dbo.ppe_types as pt', 'pt.id', '=', 'a.ppe_type_id')
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
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.articles')
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.articles')
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.articles')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Article deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.ppe_types')
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
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.ppe_types')
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.ppe_types')
                ->where('id', $id)
                ->update([
                    'ppe_name'   => $request->ppe_name,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'PPE type updated successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
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
                ->table('EstockDbsv2.dbo.articles')
                ->where('ppe_type_id', $id)
                ->count();

            if ($articleCount > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot delete PPE type because it is already used by one or more articles.',
                ], 422);
            }

            DB::connection('sqlsrv')
                ->table('EstockDbsv2.dbo.ppe_types')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'PPE type deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateReportRequest(Request $request): array
    {
        return $request->validate([
            'item_status' => 'required|in:issued,unissued,all',
            'property_type_id' => 'nullable|integer',
            'accountable_officer' => 'nullable|string',
            'form_type' => 'nullable|string',
            'as_of_date' => 'nullable|date',
            'export_format' => 'nullable|in:pdf,excel',
        ]);
    }

    private function buildReportQuery(Request $request)
    {
        $itemStatus = $request->input('item_status');
        $propertyTypeId = $request->input('property_type_id');
        $accountableOfficerEmpNo = trim((string) $request->input('accountable_officer', ''));
        $formType = trim((string) $request->input('form_type', ''));
        $asOfDate = $request->input('as_of_date');

        $query = DB::connection('sqlsrv')
            ->table('EstockDbsv2.dbo.rpcppe as r')
            ->leftJoin('EstockDbsv2.dbo.articles as a', 'a.id', '=', 'r.article_id')
            ->leftJoin('EstockDbsv2.dbo.ppe_types as pt', 'pt.id', '=', 'a.ppe_type_id')
            ->select(
                'r.ID',
                'r.item_id',
                'r.article_id',
                'r.category_id',
                'r.com_desc',
                'r.unitofmeasure',
                'r.po_no',
                'r.date_acquired',
                'r.u_cost',
                'r.qty',
                'r.stock_no',
                'r.source',
                'r.remarks',
                DB::raw("LTRIM(RTRIM(ISNULL(r.emp_no, ''))) as emp_no"),
                'r.issuance_type',
                'r.issuance_id',
                'r.created_at',
                'r.updated_at',
                'r.amt',
                'a.article_name',
                'a.ppe_type_id',
                'pt.ppe_name'
            );

        if ($itemStatus === 'issued') {
            $query->whereNotNull('r.issuance_id')
                ->where('r.issuance_id', '>', 0);

            if ($accountableOfficerEmpNo !== '') {
                $query->whereRaw("LTRIM(RTRIM(CAST(ISNULL(r.emp_no, '') as varchar(100)))) = ?", [$accountableOfficerEmpNo]);
            }
        } elseif ($itemStatus === 'unissued') {
            $query->where(function ($q) {
                $q->whereNull('r.issuance_id')
                  ->orWhere('r.issuance_id', '<=', 0);
            });
        } else {
            if ($accountableOfficerEmpNo !== '') {
                $query->whereRaw("LTRIM(RTRIM(CAST(ISNULL(r.emp_no, '') as varchar(100)))) = ?", [$accountableOfficerEmpNo]);
            }
        }

        if (!empty($propertyTypeId)) {
            $query->where('a.ppe_type_id', $propertyTypeId);
        }

        if (!empty($asOfDate)) {
            $query->whereNotNull('r.date_acquired')
                ->whereDate('r.date_acquired', '<=', $asOfDate);
        }

        if ($formType === 'Semi-Expendable Property') {
            $query->where('r.u_cost', '<', 50000);
        } elseif ($formType === 'Expendable Property') {
            $query->where('r.u_cost', '>=', 50000);
        }

        return $query
            ->orderBy('a.article_name')
            ->orderBy('r.com_desc');
    }

    private function getAccountableOfficerDetails(?string $empNo): ?array
    {
        $empNo = trim((string) $empNo);

        if ($empNo === '') {
            return null;
        }

        $officer = DB::connection('sqlsrv')
            ->table('EstockDbsv2.dbo.vw_employeelist as e')
            ->select([
                DB::raw('e.fullname as name'),
                DB::raw("
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(e.pos_description)), ''),
                        NULLIF(LTRIM(RTRIM(e.designation)), ''),
                        ''
                    ) as designation
                "),
                DB::raw("
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(e.sec_name)), ''),
                        ''
                    ) as office
                "),
                DB::raw('NULL as assumed_date'),
                DB::raw('LTRIM(RTRIM(e.dtrno)) as emp_no'),
            ])
            ->whereRaw("LTRIM(RTRIM(e.dtrno)) = ?", [$empNo])
            ->first();

        return $officer ? (array) $officer : null;
    }

    private function groupItemsByAccountableOfficer($items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $empNo = trim((string) ($item->emp_no ?? ''));
            $isAssigned = $empNo !== '';
            $groupKey = $isAssigned ? $empNo : 'UNASSIGNED';

            if (!isset($groups[$groupKey])) {
                $officer = $isAssigned ? $this->getAccountableOfficerDetails($empNo) : null;

                $groups[$groupKey] = [
                    'emp_no' => $empNo,
                    'officer' => $officer,
                    'items' => [],
                    'total' => 0,
                    'is_assigned' => $isAssigned,
                    'sort_name' => strtolower(trim($officer['name'] ?? '')),
                ];
            }

            $groups[$groupKey]['items'][] = $item;
            $groups[$groupKey]['total'] += (float) ($item->amt ?? 0);
        }

        $groups = array_values($groups);

        usort($groups, function ($a, $b) {
            if ($a['is_assigned'] && !$b['is_assigned']) {
                return -1;
            }

            if (!$a['is_assigned'] && $b['is_assigned']) {
                return 1;
            }

            return strcasecmp($a['sort_name'] ?? '', $b['sort_name'] ?? '');
        });

        return $groups;
    }

    private function resolvePpeName($items, ?int $propertyTypeId): string
    {
        if (empty($propertyTypeId)) {
            return 'All PPE Types';
        }

        $firstItemPpeName = trim((string) optional($items->first())->ppe_name);

        if ($firstItemPpeName !== '') {
            return $firstItemPpeName;
        }

        $ppeName = DB::connection('sqlsrv')
            ->table('EstockDbsv2.dbo.ppe_types')
            ->where('id', $propertyTypeId)
            ->value('ppe_name');

        return !empty($ppeName) ? trim($ppeName) : 'All PPE Types';
    }

    public function previewReport(Request $request)
    {
        try {
            $validated = $this->validateReportRequest($request);

            $items = $this->buildReportQuery($request)->get();

            $total = $items->sum(function ($item) {
                return (float) $item->amt;
            });

            $accountableOfficer = null;

            if (($validated['item_status'] ?? '') === 'issued' && !empty($validated['accountable_officer'])) {
                $accountableOfficer = $this->getAccountableOfficerDetails($validated['accountable_officer']);
            }

            $groupedItems = [];
            $shouldGroupByOfficer = false;

            if (
                (($validated['item_status'] ?? '') === 'issued' || ($validated['item_status'] ?? '') === 'all')
                && empty($validated['accountable_officer'])
            ) {
                $groupedItems = $this->groupItemsByAccountableOfficer($items);
                $shouldGroupByOfficer = true;
            }

            $pdf = Pdf::loadView('rpcppe.preview', [
                'items' => $items,
                'groupedItems' => $groupedItems,
                'shouldGroupByOfficer' => $shouldGroupByOfficer,
                'total' => $total,
                'accountableOfficer' => $accountableOfficer,
                'filters' => [
                    'item_status' => $validated['item_status'] ?? null,
                    'property_type_id' => $validated['property_type_id'] ?? null,
                    'accountable_officer' => $validated['accountable_officer'] ?? null,
                    'form_type' => $validated['form_type'] ?? null,
                    'as_of_date' => $validated['as_of_date'] ?? null,
                    'ppe_name' => $this->resolvePpeName(
                        $items,
                        isset($validated['property_type_id']) ? (int) $validated['property_type_id'] : null
                    ),
                ],
            ])
            ->setOptions(['isPhpEnabled' => true])
            ->setPaper([0, 0, 612, 936], 'landscape');

            return $pdf->stream('rpcppe-preview.pdf');
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        try {
            $validated = $this->validateReportRequest($request);

            $items = $this->buildReportQuery($request)->get();

            $total = $items->sum(function ($item) {
                return (float) $item->amt;
            });

            $accountableOfficer = null;

            if (($validated['item_status'] ?? '') === 'issued' && !empty($validated['accountable_officer'])) {
                $accountableOfficer = $this->getAccountableOfficerDetails($validated['accountable_officer']);
            }

            $groupedItems = [];
            $shouldGroupByOfficer = false;

            if (
                (($validated['item_status'] ?? '') === 'issued' || ($validated['item_status'] ?? '') === 'all')
                && empty($validated['accountable_officer'])
            ) {
                $groupedItems = $this->groupItemsByAccountableOfficer($items);
                $shouldGroupByOfficer = true;
            }

            $pdf = Pdf::loadView('rpcppe.preview', [
                'items' => $items,
                'groupedItems' => $groupedItems,
                'shouldGroupByOfficer' => $shouldGroupByOfficer,
                'total' => $total,
                'accountableOfficer' => $accountableOfficer,
                'filters' => [
                    'item_status' => $validated['item_status'] ?? null,
                    'property_type_id' => $validated['property_type_id'] ?? null,
                    'accountable_officer' => $validated['accountable_officer'] ?? null,
                    'form_type' => $validated['form_type'] ?? null,
                    'as_of_date' => $validated['as_of_date'] ?? null,
                    'ppe_name' => $this->resolvePpeName(
                        $items,
                        isset($validated['property_type_id']) ? (int) $validated['property_type_id'] : null
                    ),
                ],
            ])
            ->setOptions(['isPhpEnabled' => true])
            ->setPaper([0, 0, 612, 936], 'landscape');

            return $pdf->download('rpcppe-report.pdf');
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        try {
            $this->validateReportRequest($request);

            $items = $this->buildReportQuery($request)->get();

            $filename = 'rpcppe-report-' . now()->format('Ymd-His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            return response()->stream(function () use ($items, $request) {
                $handle = fopen('php://output', 'w');

                fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($handle, ['REPORT ON THE PHYSICAL COUNT OF PROPERTY, PLANT AND EQUIPMENT']);
                fputcsv($handle, ['Form Type', $request->input('form_type', '')]);
                fputcsv($handle, ['Item Status', $request->input('item_status', '')]);
                fputcsv($handle, ['As of Date', $request->input('as_of_date', '')]);
                fputcsv($handle, ['Accountable Officer', $request->input('accountable_officer', '')]);
                fputcsv($handle, []);

                fputcsv($handle, [
                    'ARTICLE',
                    'DESCRIPTION',
                    'DATE ACQUIRED',
                    'SOURCE / SERIAL NO.',
                    'UNIT OF MEASURE',
                    'UNIT VALUE',
                    'BALANCE PER CARD',
                    'ON HAND PER COUNT',
                    'TOTAL VALUE',
                    'SHORTAGE / OVERAGE QTY',
                    'SHORTAGE / OVERAGE VALUE',
                    'REMARKS',
                    'EMP NO',
                    'ISSUANCE TYPE',
                    'ISSUANCE ID',
                    'PPE TYPE',
                ]);

                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item->article_name,
                        $item->com_desc,
                        $item->date_acquired,
                        $item->stock_no,
                        $item->unitofmeasure,
                        $item->u_cost,
                        $item->qty,
                        '',
                        $item->amt,
                        '',
                        '',
                        $item->remarks,
                        $item->emp_no,
                        $item->issuance_type,
                        $item->issuance_id,
                        $item->ppe_name,
                    ]);
                }

                fclose($handle);
            }, 200, $headers);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}