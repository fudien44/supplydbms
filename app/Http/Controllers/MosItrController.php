<?php

namespace App\Http\Controllers;

use App\Exports\ItrExcelExport;
use App\Models\EmployeeList;
use App\Models\FacilityAgencyList;
use App\Models\InventoryTransferReport;
use App\Models\InventoryTransferReportItem;
use App\Models\Signatory;
use App\Models\TransferType;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class MosItrController extends Controller
{
    private const MEDICAL_CATEGORY_ID = 11; // ref_commoditycategory.comcategid = Medical Equipment

    private function itrType(Request $request): string
    {
        return $request->boolean('medical') ? 'medical' : 'office';
    }

    private function medicalCategoryCodes()
    {
        return DB::table('ref_commoditycategory')
            ->select('categ_code')
            ->where('comcategid', self::MEDICAL_CATEGORY_ID);
    }

    // CHD12-ITR-YYYY-MM-xxxx; xxxx restarts every month (shared by medical and office ITRs)
    private function nextItrNo(?string $date = null): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = sprintf('CHD12-ITR-%s-%s-', $d->format('Y'), $d->format('m'));

        $last = InventoryTransferReport::where('itr_no', 'LIKE', $prefix . '%')
            ->pluck('itr_no')
            ->map(fn ($no) => (int) substr($no, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    // ── Lookups ──────────────────────────────────────────────
    public function index(Request $request)
    {
        return response()->json([
            'transferTypes' => TransferType::select('transid', 'transtype')
                ->where('transid', '!=', 5)->orderBy('transid')->get(),
            'signatories'   => [
                'approved_by' => Signatory::approvedBy()->get(),
                'issued_by'   => Signatory::issuedBy()->get(),
            ],
            'employeesList' => EmployeeList::select('empid', 'fullname')
                ->where('void', 0)->orderBy('firstname')->get(),
            // "From" — same list as the C# PTR page
            'supUnits'      => DB::table('ref_supunit')
                ->select('id', 'unitname')->where('void', 0)->orderBy('id')->get(),
            // Entity and Fund Cluster — same list (ref_programs)
            'programs'      => DB::table('ref_programs')
                ->select('prog_id', 'prog_desc')->where('prog_void', 0)->orderBy('prog_desc')->get(),
            'nextItrNo'     => $this->nextItrNo(),
        ]);
    }

    public function nextNo(Request $request)
    {
        return response()->json(['itr_no' => $this->nextItrNo($request->input('date'))]);
    }

    // ── Item pickers (only lots with stock left) ─────────────
    public function listInventory(Request $request) // office
    {
        $search = $request->input('search');

        $query = DB::table('vw_mrrunningbalance')
            ->select(
                'ID as id', 'stock_no as stockno', 'date_acquired as dateaquired',
                'po_no as poptrno', 'ref_no as reference', 'descwithunit as itemdesc',
                'Category_Name as categ_name', 'u_cost as unitcost', 'runningbalance as balance'
            )
            ->where('void', 0)
            ->where('ristype', 0)
            ->where('runningbalance', '>', 0)
            ->orderByDesc('date_acquired')
            ->orderByDesc('ID');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                foreach (['com_desc', 'stock_no', 'po_no', 'ref_no'] as $col) {
                    $q->orWhere($col, 'LIKE', "%{$search}%");
                }
            });
        }

        return $query->limit(300)->get();
    }

    public function listGenInventory(Request $request) // medical
    {
        $search = $request->input('search');

        $query = DB::table('vw_geninventory')
            ->select(
                'gid as id', 'stockno', 'dateaquired', 'poptrno', 'reference',
                'itemdesc', 'categ_name', 'unitcost', 'balance_checkall as balance'
            )
            ->where('void', 0)
            ->whereNotNull('commodityid')
            ->whereIn('typeofcomid', $this->medicalCategoryCodes())
            ->where('balance_checkall', '>', 0)
            ->orderByDesc('dateaquired')
            ->orderByDesc('gid');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                foreach (['com_desc', 'itemdesc', 'stockno', 'poptrno', 'reference'] as $col) {
                    $q->orWhere($col, 'LIKE', "%{$search}%");
                }
            });
        }

        return $query->limit(300)->get();
    }

    public function listFacilityAgency(Request $request)
    {
        $search = $request->input('search');

        $query = FacilityAgencyList::select('faid', 'facilitycode', 'facilityname')
            ->where('void', 0)->where('isactive', 1)->orderBy('facilityname');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('facilitycode', 'LIKE', "%{$search}%")->orWhere('facilityname', 'LIKE', "%{$search}%");
            });
        }

        return $query->limit(20)->get();
    }

    // Requested qty per lot must not exceed what is left. Call AFTER this ITR's old items are deleted,
    // so the views already exclude them (their qty is returned to the balance).
    private function stockError(string $itrType, Collection $items): ?string
    {
        $requested = $items->filter(fn ($i) => !empty($i['inventory_id']))
            ->groupBy('inventory_id')
            ->map(fn ($rows) => $rows->sum('qty'));

        if ($requested->isEmpty()) {
            return null;
        }

        $available = $itrType === 'medical'
            ? DB::table('vw_geninventory')->whereIn('gid', $requested->keys())->get(['gid as id', 'itemdesc as name', 'balance_checkall as bal'])
            : DB::table('vw_mrrunningbalance')->whereIn('ID', $requested->keys())->get(['ID as id', 'com_desc as name', 'runningbalance as bal']);

        $available = $available->keyBy('id');

        foreach ($requested as $id => $qty) {
            $row = $available->get($id);
            $bal = $row ? (float) $row->bal : 0;

            if ($qty > $bal) {
                $name = $row ? strtok((string) $row->name, "\n") : "Item #{$id}";
                return "{$name}: only " . number_format($bal) . " available.";
            }
        }

        return null;
    }

    // ── Save (create/update) — deducts stock through the ledger views ─────
    public function save(Request $request)
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $itrType = $this->itrType($request);

        $request->validate([
            'itr_no'               => 'required|string|max:50',
            'entity_name'          => 'required|string|max:255',
            'fund_cluster'         => 'required|string|max:255',
            'from_entity'          => 'required|integer',
            'to_entity'            => 'required|integer',
            'date_issued'          => 'required|date',
            'transfer_type_id'     => 'required|integer',
            'reason'               => 'required|string',
            'approved_by'          => 'required|integer',
            'issued_by'            => 'required|integer',
            'received_by'          => 'nullable|integer',
            'items'                => 'required|array|min:1',
            'items.*.inventory_id' => 'nullable|integer',
            'items.*.qty'          => 'required|integer|min:1',
            'items.*.unit_cost'    => 'required|numeric|min:0',
            'items.*.description'  => 'required|string',
        ], [
            'items.required' => 'Add at least one item.',
        ]);

        if ($id) {
            $existing = InventoryTransferReport::findOrFail($id);
            if ($existing->itr_type !== $itrType) {
                return response()->json(['success' => false, 'message' => 'This ITR belongs to the other module.'], 422);
            }
        }

        // ITR No. is editable but must be unique; suggest the next free number when taken
        $duplicate = InventoryTransferReport::where('itr_no', $request->itr_no)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success'   => false,
                'message'   => "ITR No. '{$request->itr_no}' already exists.",
                'suggested' => $this->nextItrNo($request->date_issued),
            ], 422);
        }

        $items = collect($request->items);

        // Medical: picked rows must be medical equipment lots
        if ($itrType === 'medical') {
            $unique = $items->pluck('inventory_id')->filter()->unique()->values();
            $valid = DB::table('vw_geninventory')
                ->where('void', 0)
                ->whereIn('gid', $unique->all())
                ->whereIn('typeofcomid', $this->medicalCategoryCodes())
                ->count();

            if ($items->pluck('inventory_id')->contains(null) || $valid !== $unique->count()) {
                return response()->json(['success' => false, 'message' => 'Only medical equipment from inventory can be added.'], 422);
            }
        }

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
            'received_by'      => $request->received_by ?: null,
            'status'           => $request->status ?? 'pending',
            'total_amount'     => $items->sum(fn ($i) => ($i['qty'] ?? 0) * ($i['unit_cost'] ?? 0)),
        ];

        try {
            $report = DB::transaction(function () use ($id, $data, $items, $itrType) {
                if ($id) {
                    $report = InventoryTransferReport::findOrFail($id);
                    $report->update($data);
                    $report->items()->delete(); // returns the old quantities to the balance
                } else {
                    $report = InventoryTransferReport::create($data + ['itr_type' => $itrType]);
                }

                if ($error = $this->stockError($itrType, $items)) {
                    throw new \RuntimeException($error);
                }

                // Insert one by one so each medical row gets the next ledger seqno for its lot
                foreach ($items as $item) {
                    $inventoryId = $item['inventory_id'] ?? null;
                    $seqno = null;

                    if ($itrType === 'medical' && $inventoryId) {
                        $seqno = (int) DB::table('vw_gen_inventorytadjissued_checkall')
                            ->where('stockid', $inventoryId)->max('seqno') + 1;
                    }

                    InventoryTransferReportItem::create([
                        'transfer_report_id' => $report->id,
                        'date_acquired'      => $item['date_acquired'] ?? null,
                        'reference'          => $item['reference'] ?? null,
                        'description'        => $item['description'] ?? null,
                        'qty'                => (int) $item['qty'],
                        'unit_cost'          => $item['unit_cost'] ?? 0,
                        'amount'             => ($item['qty'] ?? 0) * ($item['unit_cost'] ?? 0),
                        'item_condition'     => $item['item_condition'] ?? null,
                        'inventory_id'       => $inventoryId,
                        'seqno'              => $seqno,
                    ]);
                }

                return $report;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $id ? 'Updated successfully' : 'Created successfully',
            'data'    => $report,
        ]);
    }

    public function listItr(Request $request)
    {
        $perPage = min(100, (int) $request->input('per_page', 20));

        $query = InventoryTransferReport::query()
            ->with(['transferType', 'toEntity', 'employeeInfo', 'attachments'])
            ->where('itr_type', $this->itrType($request))
            ->orderByDesc('date_issued')
            ->orderByDesc('id');

        if ($from = $request->input('from_date')) {
            $query->whereDate('date_issued', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->whereDate('date_issued', '<=', $to);
        }
        if ($type = $request->input('transfer_type_id')) {
            $query->where('transfer_type_id', $type);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('itr_no', 'LIKE', "%{$search}%")
                    ->orWhere('entity_name', 'LIKE', "%{$search}%")
                    ->orWhere('reason', 'LIKE', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function showItr($id)
    {
        return response()->json(
            InventoryTransferReport::with(['items', 'transferType'])->findOrFail((int) $id)
        );
    }

    // Deleting the items removes them from the ledger views, so the stock comes back
    public function destroy($id)
    {
        $report = InventoryTransferReport::with('attachments')->findOrFail((int) $id);
        $files = $report->attachments->pluck('filepath')->all();

        DB::transaction(function () use ($report) {
            $report->items()->delete();
            $report->attachments()->delete();
            $report->delete();
        });

        Storage::disk('public')->delete($files);

        return response()->json(['success' => true, 'message' => 'Transfer record deleted successfully.']);
    }

    public function generatePdf($id)
    {
        $transfer = InventoryTransferReport::with([
            'items', 'transferType', 'fromEntity', 'toEntity',
            'approvedBySignatory', 'issuedBySignatory', 'receivedByEmployee',
        ])->findOrFail((int) $id);

        $ids = $transfer->items->pluck('inventory_id')->filter()->unique()->values()->all();

        $suppliers = $transfer->itr_type === 'medical'
            ? DB::table('vw_geninventory')->whereIn('gid', $ids)->pluck('supplier', 'gid')
            : DB::table('vw_mrrunningbalance')->whereIn('ID', $ids)->pluck('supplier', 'ID');

        $transfer->items->each(fn ($item) =>
            $item->setAttribute('source_supplier', $suppliers[$item->inventory_id] ?? null)
        );

        return Pdf::loadView('pdf.itr', ['transfer' => $transfer])
            ->setPaper('legal', 'portrait')
            ->stream("ITR-{$transfer->itr_no}.pdf");
    }

    public function export(Request $request)
    {
        return Excel::download(
            new ItrExcelExport(
                $request->from_date,
                $request->to_date,
                $request->transfer_type_id,
                $this->itrType($request)
            ),
            'itr_records.xlsx'
        );
    }

    // ── Attachments ──────────────────────────────────────────
    public function listAttachments($id)
    {
        return response()->json(InventoryTransferReport::findOrFail((int) $id)->attachments);
    }

    public function uploadAttachment(Request $request, $id)
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $itr = InventoryTransferReport::findOrFail((int) $id);
        $file = $request->file('file');

        $path = $file->storeAs('attachments', uniqid() . '_' . $file->getClientOriginalName(), 'public');

        if (!$path) {
            return response()->json(['message' => 'File failed to save.'], 500);
        }

        $attachment = $itr->attachments()->create([
            'filename' => $file->getClientOriginalName(),
            'filepath' => $path,
            'filetype' => $file->getClientOriginalExtension(),
            'filesize' => $file->getSize(),
        ]);

        return response()->json(['message' => 'Uploaded successfully', 'attachment' => $attachment]);
    }

    public function downloadAttachment($itrId, $attachmentId)
    {
        $attachment = InventoryTransferReport::findOrFail((int) $itrId)
            ->attachments()->findOrFail((int) $attachmentId);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        if (!$disk->exists($attachment->filepath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return $disk->download($attachment->filepath, $attachment->filename);
    }

    public function deleteAttachment($itrId, $attachmentId)
    {
        $attachment = InventoryTransferReport::findOrFail((int) $itrId)
            ->attachments()->findOrFail((int) $attachmentId);

        Storage::disk('public')->delete($attachment->filepath);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted successfully']);
    }
}