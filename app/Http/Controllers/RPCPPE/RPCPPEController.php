<?php

namespace App\Http\Controllers\RPCPPE;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class RPCPPEController extends Controller
{
    /**
     * Masterlist for the Add Items picker.
     *
     * Produces the same shape as vw_MainInventory but computes the "latest
     * ICS/PAR issuance per inventory" once into PHP maps (small, fast tables),
     * then joins the main inventory directly. This avoids the expensive view
     * (whose CTEs get re-evaluated per row — ~8 min for a full fetch). The
     * vw_MainInventory view itself is left untouched for the old system.
     */
    public function masterlist(Request $request)
    {
        try {
            $conn = DB::connection('sqlsrv');

            $search = trim((string) $request->input('search', ''));

            // Categories arrive as composite values "W2:<id>" / "W1:<id>" (plain
            // numbers are treated as World 2 for backward compatibility).
            $rawCats = $request->input('categories', []);
            $rawCats = is_array($rawCats) ? $rawCats : [$rawCats];
            $w2Cats  = [];
            $w1Cats  = [];
            foreach ($rawCats as $c) {
                $c = (string) $c;
                if (str_starts_with($c, 'W1:')) $w1Cats[] = (int) substr($c, 3);
                elseif (str_starts_with($c, 'W2:')) $w2Cats[] = (int) substr($c, 3);
                elseif (is_numeric($c)) $w2Cats[] = (int) $c;
            }
            $w2Cats = array_values(array_filter($w2Cats));
            $w1Cats = array_values(array_filter($w1Cats));
            $anyCategory = !empty($w2Cats) || !empty($w1Cats);

            $valueMin   = $request->input('value_min');
            $valueMin   = is_numeric($valueMin) ? (float) $valueMin : null;
            $valueMax   = $request->input('value_max');
            $valueMax   = is_numeric($valueMax) ? (float) $valueMax : null;
            $perPage    = (int) $request->input('per_page', 20);
            $perPage  = ($perPage > 0 && $perPage <= 100) ? $perPage : 20;
            $page     = max(1, (int) $request->input('page', 1));
            $offset   = ($page - 1) * $perPage;

            $like = '%' . $search . '%';

            // Resolve description matches on the small commodity tables up front
            // (avoids an OR predicate spanning a join on the large text column).
            $w2Com = [];
            $w1Com = [];
            if ($search !== '') {
                $w2Com = $conn->table('dbo.tbl_mrcommodity')
                    ->where('com_desc', 'like', $like)->pluck('OS_code')
                    ->map(fn ($v) => (int) $v)->filter()->values()->all();
                $w1Com = $conn->table('dbo.ref_commoditymasterlist')
                    ->where('com_desc', 'like', $like)->pluck('com_code')
                    ->map(fn ($v) => (int) $v)->filter()->values()->all();
            }

            // The picker counts ONE classification at a time (keeps the list small):
            //   'ppe'  → property categories, unit cost >= capitalization threshold
            //   'semi' → property categories, unit cost <  capitalization threshold
            //   'rpci' → consumable/inventory categories (no threshold)
            // Default (empty) behaves like property (ppe + semi, no threshold split).
            $classification = strtolower(trim((string) $request->input('classification', '')));
            $threshold = $this->capitalizationThreshold();

            // Base category whitelist for the chosen classification. User-selected
            // categories are intersected with it (they can never widen the set).
            $base = $classification === 'rpci'
                ? $this->rpciCategoryCodes()
                : $this->ppeEligibleCategoryCodes();
            $w2Allowed = !empty($w2Cats) ? array_values(array_intersect($w2Cats, $base['W2'])) : $base['W2'];
            $w1Allowed = !empty($w1Cats) ? array_values(array_intersect($w1Cats, $base['W1'])) : $base['W1'];

            // World 2 (MR / office supplies / property) base query.
            $buildW2 = function () use ($conn, $w2Allowed, $valueMin, $valueMax, $search, $like, $w2Com, $classification, $threshold) {
                $q = $conn->table('dbo.tbl_mr_Maininventory as mi')
                    ->where(function ($v) { $v->whereNull('mi.void')->orWhere('mi.void', 0); });
                $q->whereIn('mi.category_ID', $w2Allowed); // empty => no rows
                if ($classification === 'ppe') $q->where('mi.u_cost', '>=', $threshold);
                elseif ($classification === 'semi') $q->where('mi.u_cost', '<', $threshold);
                if ($valueMin !== null) $q->where('mi.u_cost', '>=', $valueMin);
                if ($valueMax !== null) $q->where('mi.u_cost', '<=', $valueMax);
                if ($search !== '') {
                    $q->where(function ($s) use ($like, $w2Com) {
                        $s->where('mi.stock_no', 'like', $like)->orWhere('mi.po_no', 'like', $like)
                          ->orWhere('mi.source', 'like', $like)->orWhere('mi.remarks', 'like', $like);
                        if (!empty($w2Com)) $s->orWhereIn('mi.commodity_ID', $w2Com);
                    });
                }
                return $q;
            };

            // World 1 (consumables / general supply) base query.
            $buildW1 = function () use ($conn, $w1Allowed, $valueMin, $valueMax, $search, $like, $w1Com, $classification, $threshold) {
                $q = $conn->table('dbo.trans_gen_inventory as g')
                    ->where(function ($v) { $v->whereNull('g.void')->orWhere('g.void', 0); });
                $q->whereIn('g.typeofcomid', $w1Allowed); // empty => no rows
                if ($classification === 'ppe') $q->where('g.unitcost', '>=', $threshold);
                elseif ($classification === 'semi') $q->where('g.unitcost', '<', $threshold);
                if ($valueMin !== null) $q->where('g.unitcost', '>=', $valueMin);
                if ($valueMax !== null) $q->where('g.unitcost', '<=', $valueMax);
                if ($search !== '') {
                    $q->where(function ($s) use ($like, $w1Com) {
                        $s->where('g.stockno', 'like', $like)->orWhere('g.poptrno', 'like', $like)
                          ->orWhere('g.source', 'like', $like);
                        if (!empty($w1Com)) $s->orWhereIn('g.commodityid', $w1Com);
                    });
                }
                return $q;
            };

            // Decide which worlds to include based on which categories are selected.
            // No category filter → both worlds. W2 cats only → W2. W1 cats only → W1.
            $includeW2 = !$anyCategory || !empty($w2Cats);
            $includeW1 = !$anyCategory || !empty($w1Cats);

            $total = 0;
            if ($includeW2) $total += $buildW2()->count();
            if ($includeW1) $total += $buildW1()->count();

            // Page: union base columns (+ uom join, which is cheap) WITHOUT the
            // heavy com_desc text — that's resolved per page below.
            $w2Sel = $buildW2()
                ->leftJoin('dbo.ref_uom as uom', 'mi.unitofmeasure', '=', 'uom.uomid')
                ->selectRaw("'W2' as world, mi.ID as item_id, mi.commodity_ID as commodity_id, mi.category_ID as category_id, mi.qty as qty, mi.u_cost as u_cost, mi.amt as amt, mi.date_acquired as date_acquired, mi.po_no as po_no, mi.stock_no as stock_no, COALESCE(NULLIF(LTRIM(RTRIM(uom.uom_name)), ''), CAST(mi.unitofmeasure AS VARCHAR(100)), '') as unitofmeasure");

            $w1Sel = $buildW1()
                ->leftJoin('dbo.ref_commoditymasterlist as cm', 'g.commodityid', '=', 'cm.com_code')
                ->leftJoin('dbo.ref_uom as uom', 'cm.com_uomid', '=', 'uom.uomid')
                ->selectRaw("'W1' as world, g.gid as item_id, g.commodityid as commodity_id, g.typeofcomid as category_id, g.origqty as qty, g.unitcost as u_cost, (g.origqty * g.unitcost) as amt, g.dateaquired as date_acquired, g.poptrno as po_no, g.stockno as stock_no, COALESCE(NULLIF(LTRIM(RTRIM(uom.uom_name)), ''), '') as unitofmeasure");

            if ($includeW2 && $includeW1) $union = $w2Sel->unionAll($w1Sel);
            elseif ($includeW1) $union = $w1Sel;
            else $union = $w2Sel;

            $pageRows = $conn->query()->fromSub($union, 'x')
                ->orderByRaw('x.world DESC, x.item_id DESC')
                ->offset($offset)->limit($perPage)
                ->get();

            // Resolve com_desc for just this page, per world.
            $w2ComIds = []; $w1ComIds = []; $w2Ids = []; $w1Ids = [];
            foreach ($pageRows as $r) {
                if ($r->world === 'W2') { $w2ComIds[] = (int) $r->commodity_id; $w2Ids[] = (int) $r->item_id; }
                else { $w1ComIds[] = (int) $r->commodity_id; $w1Ids[] = (int) $r->item_id; }
            }

            $w2Desc = [];
            if (count($w2ComIds)) {
                foreach ($conn->table('dbo.tbl_mrcommodity')
                    ->whereIn('OS_code', array_values(array_unique($w2ComIds)))
                    ->get(['OS_code', DB::raw('CAST(com_desc AS VARCHAR(1000)) as com_desc')]) as $c) {
                    $w2Desc[(int) $c->OS_code] = $c->com_desc;
                }
            }
            $w1Desc = [];
            if (count($w1ComIds)) {
                // trans_gen_inventory.commodityid maps to ref_commoditymasterlist.com_code
                // (NOT comid) — mirrors vw_geninventory's join.
                foreach ($conn->table('dbo.ref_commoditymasterlist')
                    ->whereIn('com_code', array_values(array_unique($w1ComIds)))
                    ->get(['com_code', DB::raw('CAST(com_desc AS VARCHAR(1000)) as com_desc')]) as $c) {
                    $w1Desc[(int) $c->com_code] = $c->com_desc;
                }
            }

            // Latest ICS/PAR issuance for the World 2 items on this page. This only
            // supplies the "Issued To" custody info (who holds an item) — it is NOT
            // used to classify PPE vs semi-expendable (that is unit value vs threshold).
            $icsMap = [];
            $parMap = [];
            if (count($w2Ids)) {
                $ph = implode(',', array_fill(0, count($w2Ids), '?'));
                foreach ($conn->select("
                    SELECT inventory_ID, emp_no, issuance_type, issuance_id FROM (
                        SELECT il.inventory_ID, LTRIM(RTRIM(ics.emp_no)) AS emp_no, LTRIM(RTRIM(ics.ICS_No)) AS issuance_type, ics.ID AS issuance_id,
                               ROW_NUMBER() OVER (PARTITION BY il.inventory_ID ORDER BY il.ID DESC) AS rn
                        FROM dbo.tbl_mricslist il INNER JOIN dbo.tbl_mrics ics ON il.ics_ID = ics.ID
                        WHERE il.inventory_ID IN ($ph) AND (il.void IS NULL OR il.void = 0) AND (ics.void IS NULL OR ics.void = 0)
                    ) x WHERE x.rn = 1
                ", $w2Ids) as $r) { $icsMap[(int) $r->inventory_ID] = $r; }

                foreach ($conn->select("
                    SELECT inventory_ID, emp_no, issuance_type, issuance_id FROM (
                        SELECT pl.inventory_ID, LTRIM(RTRIM(par.emp_no)) AS emp_no, LTRIM(RTRIM(par.PAR_No)) AS issuance_type, par.ID AS issuance_id,
                               ROW_NUMBER() OVER (PARTITION BY pl.inventory_ID ORDER BY pl.ID DESC) AS rn
                        FROM dbo.tbl_mrparlist pl INNER JOIN dbo.tbl_mrpar par ON pl.par_ID = par.ID
                        WHERE pl.inventory_ID IN ($ph) AND (pl.void IS NULL OR pl.void = 0) AND (par.void IS NULL OR par.void = 0)
                    ) x WHERE x.rn = 1
                ", $w2Ids) as $r) { $parMap[(int) $r->inventory_ID] = $r; }
            }

            // Balance per card = REMAINING stock, not the original received qty.
            //   World 2: qty − issued(ICS+PAR) + returns
            //   World 1: latest running balance (vw_gen_runningbalance), else origqty
            $w2Issued = []; $w2Returned = []; $w1Balance = [];
            if (count($w2Ids)) {
                $ph = implode(',', array_fill(0, count($w2Ids), '?'));
                foreach ($conn->select("
                    SELECT inventory_ID, SUM(qty) AS q FROM dbo.tbl_mricslist
                    WHERE inventory_ID IN ($ph) AND (void IS NULL OR void = 0) GROUP BY inventory_ID
                ", $w2Ids) as $r) { $w2Issued[(int) $r->inventory_ID] = (float) $r->q; }
                foreach ($conn->select("
                    SELECT inventory_ID, SUM(qty) AS q FROM dbo.tbl_mrparlist
                    WHERE inventory_ID IN ($ph) AND (void IS NULL OR void = 0) GROUP BY inventory_ID
                ", $w2Ids) as $r) { $w2Issued[(int) $r->inventory_ID] = ($w2Issued[(int) $r->inventory_ID] ?? 0) + (float) $r->q; }
                foreach ($conn->select("
                    SELECT rl.inventory_ID, SUM(rl.qty) AS q
                    FROM dbo.tbl_mr_returnlist rl INNER JOIN dbo.tbl_mr_return r ON rl.return_ID = r.return_ID
                    WHERE rl.inventory_ID IN ($ph) AND (r.void IS NULL OR r.void = 0) GROUP BY rl.inventory_ID
                ", $w2Ids) as $r) { $w2Returned[(int) $r->inventory_ID] = (float) $r->q; }
            }
            if (count($w1Ids)) {
                $ph = implode(',', array_fill(0, count($w1Ids), '?'));
                foreach ($conn->select("
                    SELECT stockid, balance FROM (
                        SELECT stockid, balance, ROW_NUMBER() OVER (PARTITION BY stockid ORDER BY seqno DESC) AS rn
                        FROM dbo.vw_gen_runningbalance WHERE stockid IN ($ph)
                    ) x WHERE x.rn = 1
                ", $w1Ids) as $r) { $w1Balance[(int) $r->stockid] = (float) $r->balance; }
            }

            $data = collect($pageRows)->map(function ($r) use ($w2Desc, $w1Desc, $icsMap, $parMap, $w2Issued, $w2Returned, $w1Balance) {
                $r->com_desc     = $r->world === 'W2'
                    ? ($w2Desc[(int) $r->commodity_id] ?? '')
                    : ($w1Desc[(int) $r->commodity_id] ?? '');
                $r->commodity_ID = $r->commodity_id;
                $r->category_ID  = $r->category_id;
                $r->remarks      = '';
                $r->source       = $r->world === 'W2' ? 'Office' : 'General';

                // Replace the original qty with the true remaining balance.
                if ($r->world === 'W2') {
                    $r->qty = (float) $r->qty
                        - ($w2Issued[(int) $r->item_id] ?? 0)
                        + ($w2Returned[(int) $r->item_id] ?? 0);
                } else {
                    $r->qty = $w1Balance[(int) $r->item_id] ?? (float) $r->qty;
                }

                $iss = $r->world === 'W2' ? ($icsMap[(int) $r->item_id] ?? $parMap[(int) $r->item_id] ?? null) : null;
                $r->emp_no        = $iss->emp_no ?? '';
                $r->issuance_type = $iss->issuance_type ?? '';
                $r->issuance_id   = $iss->issuance_id ?? 0;

                return $r;
            });

            return response()->json([
                'status'   => 'success',
                'data'     => $data,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
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
                ->table('dbo.rpcppe as r')
                ->leftJoin('dbo.articles as a', 'a.id', '=', 'r.article_id')
                ->select(
                    'r.ID',
                    'r.item_id',
                    'r.article_id',
                    'a.article_name',
                    'r.category_id',
                    'r.source_world',
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

            // Tag each row with its nature: RPCI (inventory/consumable) vs PROPERTY
            // (PPE/Semi, split later by threshold). Lets the dashboard count RPCI
            // separately instead of lumping consumables into Semi-Expendable.
            $rpci = $this->rpciCategoryCodes();
            $data->transform(function ($r) use ($rpci) {
                $world = $r->source_world ?: 'W2';
                $code  = (int) $r->category_id;
                $r->report_type = in_array($code, $rpci[$world] ?? [], true) ? 'RPCI' : 'PROPERTY';

                return $r;
            });

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
                    ->table('dbo.articles')
                    ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [trim($item['article'] ?? '')])
                    ->value('id');

                if (!$articleId) {
                    throw new \Exception('Selected article was not found in articles table: ' . ($item['article'] ?? ''));
                }

                $itemId = $item['item_id'] ?? null;

                if (!$itemId) {
                    throw new \Exception('Main inventory item_id is required.');
                }

                $world = in_array(($item['world'] ?? 'W2'), ['W1', 'W2'], true) ? $item['world'] : 'W2';

                // Dedup within the same world (W1 gid and W2 ID are separate spaces).
                $alreadyExists = DB::connection('sqlsrv')
                    ->table('dbo.rpcppe')
                    ->where('item_id', $itemId)
                    ->where('source_world', $world)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $rows[] = [
                    'item_id'        => $itemId,
                    'source_world'   => $world,
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
                    ->table('dbo.rpcppe')
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

    /**
     * Add a single manually-entered item (not from the masterlist).
     */
    public function storeManualItem(Request $request)
    {
        $validated = $request->validate([
            'article'        => 'required|string',
            'description'    => 'nullable|string',
            'serialNo'       => 'nullable|string',
            'unit'           => 'nullable|string',
            'unitValue'      => 'nullable|numeric',
            'balancePerCard' => 'nullable|numeric',
            'dateAcquired'   => 'nullable|date',
            'remarks'        => 'nullable|string',
            'category_id'    => 'nullable|integer',
        ]);

        try {
            $articleId = DB::connection('sqlsrv')
                ->table('dbo.articles')
                ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [trim($validated['article'])])
                ->value('id');

            if (!$articleId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Selected article was not found: ' . $validated['article'],
                ], 422);
            }

            $qty    = is_numeric($validated['balancePerCard'] ?? null) ? (float) $validated['balancePerCard'] : 0;
            $uCost  = is_numeric($validated['unitValue'] ?? null) ? (float) $validated['unitValue'] : 0;

            $id = DB::connection('sqlsrv')
                ->table('dbo.rpcppe')
                ->insertGetId([
                    'item_id'       => 0,
                    'article_id'    => $articleId,
                    'category_id'   => $validated['category_id'] ?? null,
                    'com_desc'      => $validated['description'] ?? null,
                    'unitofmeasure' => $validated['unit'] ?? null,
                    'po_no'         => $validated['serialNo'] ?? null,
                    'date_acquired' => !empty($validated['dateAcquired']) ? $validated['dateAcquired'] : null,
                    'u_cost'        => $uCost,
                    'qty'           => $qty,
                    'amt'           => $qty * $uCost,
                    'stock_no'      => null,
                    'source'        => 'Manual Entry',
                    'remarks'       => isset($validated['remarks']) && $validated['remarks'] !== '' ? $validated['remarks'] : null,
                    'emp_no'        => '',
                    'issuance_type' => '',
                    'issuance_id'   => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ], 'ID');

            return response()->json([
                'status'  => 'success',
                'message' => 'Item added successfully.',
                'id'      => $id,
            ], 200);
        } catch (\Throwable $e) {
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
            ->table('dbo.rpcppe')
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
                    ->table('dbo.articles')
                    ->whereRaw('LTRIM(RTRIM(article_name)) = ?', [$article])
                    ->value('id');

                if (!$articleId) {
                    throw new \Exception('Selected article was not found in articles table: ' . $article);
                }

                DB::connection('sqlsrv')
                    ->table('dbo.rpcppe')
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

    /**
     * Update only the remarks of a single rpcppe row (by its ID).
     * Independent of the article lookup, so remarks can be set or cleared freely.
     */
    public function updateRemarks(Request $request)
    {
        $validated = $request->validate([
            'id'      => 'required|integer',
            'remarks' => 'nullable|string',
        ]);

        try {
            $remarks = trim((string) ($validated['remarks'] ?? ''));

            $affected = DB::connection('sqlsrv')
                ->table('dbo.rpcppe')
                ->where('ID', $validated['id'])
                ->update([
                    'remarks'    => $remarks !== '' ? $remarks : null,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Remarks updated successfully.',
                'updated' => $affected,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Re-pull the current REMAINING balance from the live source inventory for the
     * given rpcppe rows (Balance per Card goes stale as stock moves):
     *   World 2 → tbl_mr_Maininventory.qty − issued(ICS+PAR) + returns
     *   World 1 → latest running balance (vw_gen_runningbalance), else origqty
     * The amount is recomputed as qty × unit cost. Manual rows (item_id 0) skip.
     */
    public function refreshBalances(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        try {
            $conn = DB::connection('sqlsrv');

            $rows = $conn->table('dbo.rpcppe')
                ->whereIn('ID', $validated['ids'])
                ->get(['ID', 'item_id', 'source_world', 'u_cost', 'qty']);

            $updated   = [];
            $unchanged = 0;

            foreach ($rows as $r) {
                $itemId = (int) $r->item_id;
                if ($itemId <= 0) continue; // manual entry — no source to refresh from

                $world = $r->source_world ?: 'W2';
                $qty   = null;

                if ($world === 'W1') {
                    $qty = $conn->table('dbo.vw_gen_runningbalance')
                        ->where('stockid', $itemId)
                        ->orderByDesc('seqno')
                        ->value('balance');

                    if ($qty === null) {
                        $qty = $conn->table('dbo.trans_gen_inventory')
                            ->where('gid', $itemId)
                            ->value('origqty');
                    }
                } else {
                    $orig = $conn->table('dbo.tbl_mr_Maininventory')
                        ->where('ID', $itemId)
                        ->value('qty');

                    if ($orig !== null) {
                        $ics = (float) $conn->table('dbo.tbl_mricslist')
                            ->where('inventory_ID', $itemId)
                            ->where(function ($q) { $q->whereNull('void')->orWhere('void', 0); })
                            ->sum('qty');
                        $par = (float) $conn->table('dbo.tbl_mrparlist')
                            ->where('inventory_ID', $itemId)
                            ->where(function ($q) { $q->whereNull('void')->orWhere('void', 0); })
                            ->sum('qty');
                        $ret = (float) $conn->table('dbo.tbl_mr_returnlist as rl')
                            ->join('dbo.tbl_mr_return as r', 'rl.return_ID', '=', 'r.return_ID')
                            ->where('rl.inventory_ID', $itemId)
                            ->where(function ($q) { $q->whereNull('r.void')->orWhere('r.void', 0); })
                            ->sum('rl.qty');

                        $qty = (float) $orig - $ics - $par + $ret;
                    }
                }

                if ($qty === null) continue; // source row gone — leave as-is

                $qty = (float) $qty;

                // Only touch the row if the masterlist balance actually changed;
                // otherwise leave the physical-count item exactly as it is.
                if (abs($qty - (float) $r->qty) < 0.0001) {
                    $unchanged++;
                    continue;
                }

                $amt = $qty * (float) $r->u_cost;

                $conn->table('dbo.rpcppe')
                    ->where('ID', $r->ID)
                    ->update(['qty' => $qty, 'amt' => $amt, 'updated_at' => now()]);

                $updated[(int) $r->ID] = ['qty' => $qty, 'amt' => $amt];
            }

            return response()->json([
                'status'    => 'success',
                'message'   => count($updated) . ' balance(s) changed, ' . $unchanged . ' unchanged.',
                'updated'   => $updated,
                'count'     => count($updated),
                'unchanged' => $unchanged,
            ], 200);
        } catch (\Throwable $e) {
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

    /**
     * All persons in the office (full employee roster), used to label the RPCPPE
     * report with an accountable person. Unlike accountableOfficers(), this is not
     * limited to people who already have ICS/PAR issuances.
     */
    public function officePersonnel()
    {
        try {
            $data = DB::connection('sqlsrv')->select("
                SELECT DISTINCT
                    LTRIM(RTRIM(e.dtrno)) as emp_no,
                    e.fullname
                FROM dbo.vw_employeelist e
                WHERE e.fullname IS NOT NULL
                  AND LTRIM(RTRIM(ISNULL(e.fullname, ''))) <> ''
                  AND e.dtrno IS NOT NULL
                  AND LTRIM(RTRIM(ISNULL(e.dtrno, ''))) <> ''
                ORDER BY e.fullname ASC
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
            $conn = DB::connection('sqlsrv');
            $out  = [];

            // Match the picker's classification: 'rpci' shows the consumable
            // categories; anything else shows the PPE-eligible (property) ones.
            $classification = strtolower(trim((string) $request->input('classification', '')));
            $rpciMode  = $classification === 'rpci';
            $allowed   = $rpciMode ? $this->rpciCategoryCodes() : null;
            $excluded  = $rpciMode ? null : $this->excludedCategoryCodes();

            $keep = function (string $world, int $code) use ($rpciMode, $allowed, $excluded): bool {
                return $rpciMode
                    ? in_array($code, $allowed[$world], true)
                    : !in_array($code, $excluded[$world], true);
            };

            // World 2 — tbl_mrcategory
            foreach ($conn->table('dbo.tbl_mrcategory')->get() as $c) {
                $name = trim((string) ($c->Category_Name ?? ''));
                if ($name === '' || empty($c->Category_ID)) continue;
                if (!$keep('W2', (int) $c->Category_ID)) continue;
                $out[] = [
                    'value' => 'W2:' . (int) $c->Category_ID,
                    'title' => $name,
                    'world' => 'W2',
                ];
            }

            // World 1 — ref_commoditycategory. NOTE: trans_gen_inventory.typeofcomid
            // stores the category *code* (categ_code, e.g. 10402030), NOT the comcategid
            // PK. So the filter identifier must be categ_code to match the stock rows
            // (and the W1 row's resolved category_id, which is typeofcomid = categ_code).
            foreach ($conn->table('dbo.ref_commoditycategory')
                ->where(function ($q) { $q->whereNull('categ_void')->orWhere('categ_void', 0); })
                ->get() as $c) {
                $name = trim((string) ($c->categ_name ?? ''));
                $code = (int) ($c->categ_code ?? 0);
                if ($name === '' || $code === 0) continue;
                if (!$keep('W1', $code)) continue;
                $out[] = [
                    'value' => 'W1:' . $code,
                    'title' => $name,
                    'world' => 'W1',
                ];
            }

            return response()->json([
                'status' => 'success',
                'data'   => $out,
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
                ->table('dbo.articles as a')
                ->leftJoin('dbo.ppe_types as pt', 'pt.id', '=', 'a.ppe_type_id')
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
                ->table('dbo.articles')
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
                ->table('dbo.articles')
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
                ->table('dbo.articles')
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
                ->table('dbo.ppe_types')
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
                ->table('dbo.ppe_types')
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
                ->table('dbo.ppe_types')
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
                ->table('dbo.articles')
                ->where('ppe_type_id', $id)
                ->count();

            if ($articleCount > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot delete PPE type because it is already used by one or more articles.',
                ], 422);
            }

            DB::connection('sqlsrv')
                ->table('dbo.ppe_types')
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

    /**
     * Ensure every live category (both worlds) has a classification row. New
     * categories are seeded with a sensible default: World 1 categ_code starting
     * '104' = inventory (RPCI), '106' = PPE; World 2 defaults to PPE (property).
     * Idempotent and cheap — the category tables are tiny (~tens of rows).
     */
    private function ensureCategoryClassifications(): void
    {
        $conn = DB::connection('sqlsrv');
        $tbl  = 'dbo.category_classifications';

        $have = [];
        foreach ($conn->table($tbl)->get(['world', 'category_code']) as $r) {
            $have[$r->world . ':' . $r->category_code] = true;
        }

        $rows = [];

        // World 1 — ref_commoditycategory (keyed by categ_code).
        foreach ($conn->table('dbo.ref_commoditycategory')
            ->where(function ($q) { $q->whereNull('categ_void')->orWhere('categ_void', 0); })
            ->get() as $c) {
            $code = (int) ($c->categ_code ?? 0);
            $name = trim((string) ($c->categ_name ?? ''));
            if ($code === 0 || $name === '' || isset($have['W1:' . $code])) continue;
            $rows[] = [
                'world'         => 'W1',
                'category_code' => (string) $code,
                'category_name' => $name,
                'report_type'   => str_starts_with((string) $code, '104') ? 'RPCI' : 'PPE',
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        // World 2 — tbl_mrcategory (keyed by Category_ID). Default PPE (property).
        foreach ($conn->table('dbo.tbl_mrcategory')->get() as $c) {
            $id   = (int) ($c->Category_ID ?? 0);
            $name = trim((string) ($c->Category_Name ?? ''));
            if ($id === 0 || $name === '' || isset($have['W2:' . $id])) continue;
            $rows[] = [
                'world'         => 'W2',
                'category_code' => (string) $id,
                'category_name' => $name,
                'report_type'   => 'PPE',
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        if (!empty($rows)) {
            $conn->table($tbl)->insert($rows);
        }
    }

    /**
     * Category codes (per world) that are classified as RPCI (inventory) or
     * inactive — these must be excluded from the RPCPPE/RPCSP masterlist/picker.
     */
    private function excludedCategoryCodes(): array
    {
        $this->ensureCategoryClassifications();

        $out = ['W1' => [], 'W2' => []];
        foreach (DB::connection('sqlsrv')
            ->table('dbo.category_classifications')
            ->where(function ($q) { $q->where('report_type', 'RPCI')->orWhere('is_active', 0); })
            ->get(['world', 'category_code']) as $r) {
            if (isset($out[$r->world])) $out[$r->world][] = (int) $r->category_code;
        }
        return $out;
    }

    /**
     * Category codes (per world) that ARE eligible for RPCPPE/RPCSP — i.e. classified
     * PPE and active. The masterlist is a whitelist: only items whose category is in
     * this set appear; anything not PPE-eligible (RPCI, inactive, or unclassified) is
     * hidden.
     */
    private function ppeEligibleCategoryCodes(): array
    {
        $this->ensureCategoryClassifications();

        $out = ['W1' => [], 'W2' => []];
        foreach (DB::connection('sqlsrv')
            ->table('dbo.category_classifications')
            ->where('report_type', 'PPE')
            ->where('is_active', 1)
            ->get(['world', 'category_code']) as $r) {
            if (isset($out[$r->world])) $out[$r->world][] = (int) $r->category_code;
        }
        return $out;
    }

    /**
     * Category codes (per world) classified as RPCI (inventory/consumable) and
     * active. Used when the picker is counting RPCI items specifically.
     */
    private function rpciCategoryCodes(): array
    {
        $this->ensureCategoryClassifications();

        $out = ['W1' => [], 'W2' => []];
        foreach (DB::connection('sqlsrv')
            ->table('dbo.category_classifications')
            ->where('report_type', 'RPCI')
            ->where('is_active', 1)
            ->get(['world', 'category_code']) as $r) {
            if (isset($out[$r->world])) $out[$r->world][] = (int) $r->category_code;
        }
        return $out;
    }

    /**
     * List every category with its RPCI/PPE classification (for the References UI).
     */
    public function categoryClassifications()
    {
        try {
            $this->ensureCategoryClassifications();

            $data = DB::connection('sqlsrv')
                ->table('dbo.category_classifications')
                ->select('id', 'world', 'category_code', 'category_name', 'report_type', 'is_active')
                ->orderBy('world')
                ->orderBy('category_name')
                ->get();

            return response()->json(['status' => 'success', 'data' => $data], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a category's classification (RPCI vs PPE) and/or active flag.
     */
    public function updateCategoryClassification(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'report_type' => 'nullable|in:PPE,RPCI',
                'is_active'   => 'nullable|boolean',
            ]);

            $update = ['updated_at' => now()];
            if (array_key_exists('report_type', $validated) && $validated['report_type'] !== null) {
                $update['report_type'] = $validated['report_type'];
            }
            if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
                $update['is_active'] = $validated['is_active'] ? 1 : 0;
            }

            DB::connection('sqlsrv')
                ->table('dbo.category_classifications')
                ->where('id', $id)
                ->update($update);

            return response()->json(['success' => true, 'message' => 'Classification updated.'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve the PPE capitalization threshold: prefer the runtime value stored
     * in app_settings, falling back to the config/env default. COA has changed
     * this before, so it is configurable rather than hardcoded.
     */
    private function capitalizationThreshold(): float
    {
        $configDefault = (float) config('rpcppe.capitalization_threshold', 50000);

        try {
            $value = DB::connection('sqlsrv')
                ->table('dbo.app_settings')
                ->where('key', 'rpcppe_capitalization_threshold')
                ->value('value');

            return ($value !== null && is_numeric($value)) ? (float) $value : $configDefault;
        } catch (\Throwable $e) {
            // Table may not exist yet; fall back to the config default.
            return $configDefault;
        }
    }

    public function getSettings()
    {
        try {
            return response()->json([
                'status' => 'success',
                'data'   => [
                    'capitalization_threshold' => $this->capitalizationThreshold(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'capitalization_threshold' => 'required|numeric|min:0',
        ]);

        try {
            $threshold = (string) $validated['capitalization_threshold'];

            $exists = DB::connection('sqlsrv')
                ->table('dbo.app_settings')
                ->where('key', 'rpcppe_capitalization_threshold')
                ->exists();

            if ($exists) {
                DB::connection('sqlsrv')
                    ->table('dbo.app_settings')
                    ->where('key', 'rpcppe_capitalization_threshold')
                    ->update([
                        'value'      => $threshold,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::connection('sqlsrv')
                    ->table('dbo.app_settings')
                    ->insert([
                        'key'         => 'rpcppe_capitalization_threshold',
                        'value'       => $threshold,
                        'description' => 'PPE capitalization threshold (PHP). Items at or above this unit cost are PPE; below are semi-expendable.',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Capitalization threshold updated successfully.',
                'data'    => [
                    'capitalization_threshold' => (float) $threshold,
                ],
            ], 200);
        } catch (\Throwable $e) {
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
            'accountable_person' => 'required|string',
            'form_type' => 'nullable|string',
            'as_of_date' => 'nullable|date',
            'export_format' => 'nullable|in:pdf,excel',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'certified_name' => 'nullable|string',
            'approved_name' => 'nullable|string',
            'verified_name' => 'nullable|string',
        ]);
    }

    /**
     * Report signatory blocks (Certified / Approved / Verified) entered on the
     * Generate page. Each carries the printed name; the fixed COA role caption
     * is rendered by the view.
     */
    private function reportSignatories(Request $request): array
    {
        return [
            'certified' => ['name' => trim((string) $request->input('certified_name', ''))],
            'approved'  => ['name' => trim((string) $request->input('approved_name', ''))],
            'verified'  => ['name' => trim((string) $request->input('verified_name', ''))],
        ];
    }

    private function buildReportQuery(Request $request)
    {
        $itemStatus = $request->input('item_status');
        $propertyTypeId = $request->input('property_type_id');
        $accountableOfficerEmpNo = trim((string) $request->input('accountable_officer', ''));
        $formType = trim((string) $request->input('form_type', ''));
        $asOfDate = $request->input('as_of_date');

        $query = DB::connection('sqlsrv')
            ->table('dbo.rpcppe as r')
            ->leftJoin('dbo.articles as a', 'a.id', '=', 'r.article_id')
            ->leftJoin('dbo.ppe_types as pt', 'pt.id', '=', 'a.ppe_type_id')
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

        // When specific item IDs are supplied (generate from selected items),
        // return exactly those rows and skip the status/type/threshold filters.
        $selectedIds = $request->input('ids', []);

        if (is_array($selectedIds) && count($selectedIds)) {
            $query->whereIn('r.ID', array_map('intval', $selectedIds));

            return $query
                ->orderBy('a.article_name')
                ->orderBy('r.com_desc');
        }

        // Issued To / custody filter (who holds the item). This is independent of
        // PPE-vs-semi classification, which is driven by unit value vs threshold below.
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

        $capitalizationThreshold = $this->capitalizationThreshold();
        $rpciCodes = $this->rpciCategoryCodes();

        // Keep only rows whose (world, category) is in the per-world set.
        $inCategorySet = function ($q, array $set) {
            $q->where(function ($w) use ($set) {
                $w->whereRaw('1 = 0');
                if (!empty($set['W1'])) {
                    $w->orWhere(fn ($x) => $x->where('r.source_world', 'W1')->whereIn('r.category_id', $set['W1']));
                }
                if (!empty($set['W2'])) {
                    $w->orWhere(fn ($x) => $x->where('r.source_world', 'W2')->whereIn('r.category_id', $set['W2']));
                }
            });
        };

        // Drop rows whose (world, category) is in the per-world set (used to keep
        // RPCI consumables out of the PPE / Semi-Expendable property reports).
        $notInCategorySet = function ($q, array $set) {
            if (!empty($set['W1'])) {
                $q->where(fn ($x) => $x->where('r.source_world', '!=', 'W1')->orWhereNotIn('r.category_id', $set['W1']));
            }
            if (!empty($set['W2'])) {
                $q->where(fn ($x) => $x->where('r.source_world', '!=', 'W2')->orWhereNotIn('r.category_id', $set['W2']));
            }
        };

        if ($formType === 'RPCI') {
            // Inventories report: only consumable (RPCI) categories, any unit cost.
            $inCategorySet($query, $rpciCodes);
        } elseif ($formType === 'PPE') {
            // PPE report: property items at or above the capitalization threshold.
            $notInCategorySet($query, $rpciCodes);
            $query->where('r.u_cost', '>=', $capitalizationThreshold);
        } else {
            // Semi-Expendable: property items below the threshold (RPCI excluded).
            $notInCategorySet($query, $rpciCodes);
            $query->where('r.u_cost', '<', $capitalizationThreshold);
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
            ->table('dbo.vw_employeelist as e')
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
            ->table('dbo.ppe_types')
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

            // A selected accountable person labels the whole report (no filtering);
            // with none selected, fall back to grouping items by accountable officer.
            $accountableOfficer = $this->getAccountableOfficerDetails($validated['accountable_person'] ?? null);

            $groupedItems = [];
            $shouldGroupByOfficer = false;

            if ($accountableOfficer === null) {
                $groupedItems = $this->groupItemsByAccountableOfficer($items);
                $shouldGroupByOfficer = true;
            }

            $pdf = Pdf::loadView('rpcppe.preview', [
                'items' => $items,
                'groupedItems' => $groupedItems,
                'shouldGroupByOfficer' => $shouldGroupByOfficer,
                'total' => $total,
                'accountableOfficer' => $accountableOfficer,
                'signatories' => $this->reportSignatories($request),
                'filters' => [
                    'item_status' => $validated['item_status'] ?? null,
                    'property_type_id' => $validated['property_type_id'] ?? null,
                    'accountable_officer' => $validated['accountable_officer'] ?? null,
                    'accountable_person' => $validated['accountable_person'] ?? null,
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

            // A selected accountable person labels the whole report (no filtering);
            // with none selected, fall back to grouping items by accountable officer.
            $accountableOfficer = $this->getAccountableOfficerDetails($validated['accountable_person'] ?? null);

            $groupedItems = [];
            $shouldGroupByOfficer = false;

            if ($accountableOfficer === null) {
                $groupedItems = $this->groupItemsByAccountableOfficer($items);
                $shouldGroupByOfficer = true;
            }

            $pdf = Pdf::loadView('rpcppe.preview', [
                'items' => $items,
                'groupedItems' => $groupedItems,
                'shouldGroupByOfficer' => $shouldGroupByOfficer,
                'total' => $total,
                'accountableOfficer' => $accountableOfficer,
                'signatories' => $this->reportSignatories($request),
                'filters' => [
                    'item_status' => $validated['item_status'] ?? null,
                    'property_type_id' => $validated['property_type_id'] ?? null,
                    'accountable_officer' => $validated['accountable_officer'] ?? null,
                    'accountable_person' => $validated['accountable_person'] ?? null,
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

            $accountablePerson = $this->getAccountableOfficerDetails($request->input('accountable_person'));
            $accountablePersonName = $accountablePerson['name'] ?? '';

            $filename = 'rpcppe-report-' . now()->format('Ymd-His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            return response()->stream(function () use ($items, $request, $accountablePersonName) {
                $handle = fopen('php://output', 'w');

                fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

                $reportTitle = $request->input('form_type') === 'PPE'
                    ? 'REPORT ON THE PHYSICAL COUNT OF PROPERTY, PLANT AND EQUIPMENT'
                    : 'REPORT ON THE PHYSICAL COUNT OF SEMI-EXPENDABLE PROPERTY';

                fputcsv($handle, [$reportTitle]);
                fputcsv($handle, ['Form Type', $request->input('form_type', '')]);
                fputcsv($handle, ['As of Date', $request->input('as_of_date', '')]);
                fputcsv($handle, ['Accountable Person', $accountablePersonName]);
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