<?php

namespace App\Http\Controllers;

use App\Exports\IcsExcelExport;
use App\Models\EmployeeList;
use App\Models\InventoryCustodianSlip;
use App\Models\Signatory;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class MosIcsController extends Controller
{
    private const MEDICAL_CATEGORY_ID = 11;   // ref_commoditycategory.comcategid = Medical Equipment (10605110)
    private const ICS_MAX_UNIT_COST   = 50000; // ICS: unit cost <= 50,000; higher goes to PAR
    private const DEFAULT_ENTITY      = 'Department of Health Center for Health and Development SOCCKSARGEN Region';

    private function icsType(Request $request): string
    {
        return $request->boolean('medical') ? 'medical' : 'office';
    }

    private function medicalCategoryCodes()
    {
        return DB::table('ref_commoditycategory')
            ->select('categ_code')
            ->where('comcategid', self::MEDICAL_CATEGORY_ID);
    }

    // Same pattern as the C# app: ICS{yyyy}-{MM}({dd})-{next id}
    private function nextIcsNo(?string $date = null): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $seq = ((int) InventoryCustodianSlip::max('id')) + 1;

        return sprintf('ICS%s-%s(%s)-%04d', $d->format('Y'), $d->format('m'), $d->format('d'), $seq);
    }

    // Dropdown data for the modal
    public function index()
    {
        return response()->json([
            'issuedBy'      => Signatory::issuedBy()->get(),
            'employees'     => EmployeeList::select('empid', 'fullname', 'pos_description')
                ->where('void', 0)
                ->orderBy('firstname')
                ->get(),
            'nextIcsNo'     => $this->nextIcsNo(),
            'defaultEntity' => self::DEFAULT_ENTITY,
        ]);
    }

    public function nextNo(Request $request)
    {
        return response()->json(['ics_no' => $this->nextIcsNo($request->input('date'))]);
    }

    // Item picker: lots with unit cost <= 50,000
    public function listInventory(Request $request)
    {
        $search = $request->input('search');

        if ($this->icsType($request) === 'medical') {
            $query = DB::table('vw_geninventory')
                ->select(
                    'gid as id', 'stockno', 'dateaquired', 'poptrno', 'reference',
                    'itemdesc', 'com_desc as description', 'uom_name as unit',
                    'categ_name', 'unitcost', 'balance'
                )
                ->where('void', 0)
                ->whereNotNull('commodityid')
                ->whereIn('typeofcomid', $this->medicalCategoryCodes())
                ->where('unitcost', '<=', self::ICS_MAX_UNIT_COST)
                ->orderByDesc('dateaquired')
                ->orderByDesc('gid');

            $searchCols = ['com_desc', 'itemdesc', 'stockno', 'poptrno', 'reference'];
        } else {
            $query = DB::table('vw_mrrunningbalance')
                ->select(
                    'ID as id', 'stock_no as stockno', 'date_acquired as dateaquired',
                    'po_no as poptrno', 'ref_no as reference', 'descwithunit as itemdesc',
                    'com_desc as description', 'unitofmeasure as unit',
                    'Category_Name as categ_name', 'u_cost as unitcost', 'runningbalance as balance'
                )
                ->where('void', 0)
                ->where('ristype', 0)
                ->where('runningbalance', '>', 0)
                ->where('u_cost', '<=', self::ICS_MAX_UNIT_COST)
                ->orderByDesc('date_acquired')
                ->orderByDesc('ID');

            $searchCols = ['com_desc', 'stock_no', 'po_no', 'ref_no'];
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search, $searchCols) {
                foreach ($searchCols as $col) {
                    $q->orWhere($col, 'LIKE', "%{$search}%");
                }
            });
        }

        return $query->limit(300)->get();
    }

    // Shared by the list and the Excel export so both show the same rows
    public static function listQuery(string $icsType, ?string $search, ?string $from, ?string $to)
    {
        $query = InventoryCustodianSlip::query()
            ->with('receivedByEmployee:empid,fullname,dtrno')
            ->where('ics_type', $icsType)
            ->orderByDesc('date_issued')
            ->orderByDesc('id');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ics_no', 'LIKE', "%{$search}%")
                    ->orWhere('entity_name', 'LIKE', "%{$search}%")
                    ->orWhere('fund_cluster', 'LIKE', "%{$search}%")
                    ->orWhere('remarks', 'LIKE', "%{$search}%")
                    ->orWhereHas('receivedByEmployee', fn ($e) => $e
                        ->where('fullname', 'LIKE', "%{$search}%")
                        ->orWhere('dtrno', 'LIKE', "%{$search}%"));
            });
        }

        if (!empty($from)) {
            $query->whereDate('date_issued', '>=', $from);
        }
        if (!empty($to)) {
            $query->whereDate('date_issued', '<=', $to);
        }

        return $query;
    }

    public function listIcs(Request $request)
    {
        $perPage = min(100, (int) $request->input('per_page', 10));

        $query = self::listQuery(
            $this->icsType($request),
            $request->input('search'),
            $request->input('from_date'),
            $request->input('to_date'),
        );

        return response()->json($query->paginate($perPage));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new IcsExcelExport(
                $this->icsType($request),
                $request->input('search'),
                $request->input('from_date'),
                $request->input('to_date'),
            ),
            'ICS Issuance List.xlsx'
        );
    }

    // ── Attachments ──────────────────────────────────────────
    public function listAttachments($id)
    {
        return response()->json(InventoryCustodianSlip::findOrFail((int) $id)->attachments);
    }

    public function uploadAttachment(Request $request, $id)
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $ics = InventoryCustodianSlip::findOrFail((int) $id);
        $file = $request->file('file');

        $path = $file->storeAs(
            'attachments/ics',
            uniqid() . '_' . $file->getClientOriginalName(),
            'public'
        );

        if (!$path) {
            return response()->json(['message' => 'File failed to save.'], 500);
        }

        $attachment = $ics->attachments()->create([
            'filename' => $file->getClientOriginalName(),
            'filepath' => $path,
            'filetype' => $file->getClientOriginalExtension(),
            'filesize' => $file->getSize(),
        ]);

        return response()->json(['message' => 'Uploaded successfully', 'attachment' => $attachment]);
    }

    public function downloadAttachment($id, $attachmentId)
    {
        $attachment = InventoryCustodianSlip::findOrFail((int) $id)
            ->attachments()->findOrFail((int) $attachmentId);

        if (!Storage::disk('public')->exists($attachment->filepath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return Storage::disk('public')->download($attachment->filepath, $attachment->filename);
    }

    public function deleteAttachment($id, $attachmentId)
    {
        $attachment = InventoryCustodianSlip::findOrFail((int) $id)
            ->attachments()->findOrFail((int) $attachmentId);

        Storage::disk('public')->delete($attachment->filepath);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted successfully.']);
    }

    public function show($id)
    {
        return response()->json(
            InventoryCustodianSlip::with('items')->findOrFail((int) $id)
        );
    }

    public function save(Request $request)
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $icsType = $this->icsType($request);

        $data = $request->validate([
            'ics_no'                  => ['required', 'string', 'max:50',
                Rule::unique('inventory_custodian_slips', 'ics_no')->ignore($id)],
            'entity_name'             => 'required|string|max:255',
            'fund_cluster'            => 'required|string|max:100',
            'date_issued'             => 'required|date',
            'issued_by'               => 'required|integer',
            'received_by'             => 'required|integer',
            'recipient_pos'           => 'nullable|string|max:150',
            'remarks'                 => 'nullable|string|max:500',
            'items'                   => 'required|array|min:1',
            'items.*.inventory_id'    => 'nullable|integer',
            'items.*.date_acquired'   => 'nullable|date',
            'items.*.qty'             => 'required|integer|min:1',
            'items.*.unit'            => 'nullable|string|max:50',
            'items.*.unit_cost'       => 'required|numeric|min:0|max:' . self::ICS_MAX_UNIT_COST,
            'items.*.description'     => 'required|string',
            'items.*.reference'       => 'nullable|string|max:255',
            'items.*.inventory_no'    => 'nullable|string|max:100',
            'items.*.est_useful_life' => 'nullable|string|max:50',
        ], [
            'ics_no.unique'         => 'ICS No. already exists.',
            'items.required'        => 'Add at least one item.',
            'items.*.unit_cost.max' => 'ICS items must cost ₱50,000 or less. Use PAR for higher-value items.',
        ]);

        if ($id) {
            $existing = InventoryCustodianSlip::findOrFail($id);
            if ($existing->ics_type !== $icsType) {
                return response()->json([
                    'success' => false,
                    'message' => 'This ICS belongs to the other module.',
                ], 422);
            }
        }

        // Medical: picked items must be medical equipment ≤ ₱50,000; manual rows allowed
       if ($icsType === 'medical') {
            $unique = collect($data['items'])->pluck('inventory_id')->filter()->unique()->values();

            if ($unique->isNotEmpty()) {
                $valid = DB::table('vw_geninventory')
                    ->where('void', 0)
                    ->whereIn('gid', $unique->all())
                    ->whereIn('typeofcomid', $this->medicalCategoryCodes())
                    ->where('unitcost', '<=', self::ICS_MAX_UNIT_COST)
                    ->count();

                if ($valid !== $unique->count()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Inventory items must be medical equipment at ₱50,000 and below.',
                    ], 422);
                }
            }
        }

        $items = collect($data['items'])->map(fn ($i) => [
            'inventory_id'    => $i['inventory_id'] ?? null,
            'date_acquired'   => $i['date_acquired'] ?? null,
            'qty'             => (int) $i['qty'],
            'unit'            => $i['unit'] ?? null,
            'unit_cost'       => $i['unit_cost'],
            'amount'          => round($i['qty'] * $i['unit_cost'], 2),
            'description'     => $i['description'],
            'reference'       => $i['reference'] ?? null,
            'inventory_no'    => $i['inventory_no'] ?? null,
            'est_useful_life' => $i['est_useful_life'] ?? null,
        ]);

        $header = collect($data)->except('items')->all();
        $header['total_amount'] = $items->sum('amount');

        $slip = DB::transaction(function () use ($id, $header, $items, $icsType) {
            if ($id) {
                $slip = InventoryCustodianSlip::findOrFail($id);
                $slip->update($header);
                $slip->items()->delete();
            } else {
                $slip = InventoryCustodianSlip::create($header + ['ics_type' => $icsType]);
            }

            $slip->items()->createMany($items->all());

            return $slip;
        });

        return response()->json([
            'success' => true,
            'message' => $id ? 'Updated successfully' : 'Created successfully',
            'data'    => $slip,
        ]);
    }

    public function destroy($id)
    {
        $slip = InventoryCustodianSlip::with('attachments')->findOrFail((int) $id);
        $files = $slip->attachments->pluck('filepath')->all();

        DB::transaction(function () use ($slip) {
            $slip->items()->delete();
            $slip->attachments()->delete();
            $slip->delete();
        });

        // Remove the files only after the database delete succeeded
        Storage::disk('public')->delete($files);

        return response()->json([
            'success' => true,
            'message' => 'ICS deleted successfully.',
        ]);
    }

    public function pdf($id)
    {
        $ics = InventoryCustodianSlip::with(['items', 'issuedBySignatory', 'receivedByEmployee'])
            ->findOrFail((int) $id);

        return Pdf::loadView('pdf.ics', ['ics' => $ics])
            ->setPaper('a4', 'portrait')
            ->stream("ICS-{$ics->ics_no}.pdf");
    }
}