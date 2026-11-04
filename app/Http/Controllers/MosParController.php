<?php

namespace App\Http\Controllers;

use App\Exports\ParExcelExport;
use App\Models\EmployeeList;
use App\Models\PropertyAcknowledgementReceipt;
use App\Models\Signatory;
use App\Support\MedicalLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

// Medical equipment PAR: saving deducts stock (same ledger as PTR/RIS/ITR/ICS)
class MosParController extends Controller
{
    private const MEDICAL_CATEGORY_ID = 11;    // ref_commoditycategory.comcategid = Medical Equipment (10605110)
    private const PAR_MIN_UNIT_COST   = 50000; // PAR: unit cost >= 50,000 (same as the C# picker); lower goes to ICS
    private const DEFAULT_ENTITY      = 'Department of Health Center for Health and Development SOCCKSARGEN Region';

    private function medicalCategoryCodes()
    {
        return DB::table('ref_commoditycategory')
            ->select('categ_code')
            ->where('comcategid', self::MEDICAL_CATEGORY_ID);
    }

    // CHD12-PAR-YYYY-MM-xxxx (resets monthly)
    private function nextParNo(?string $date = null): string
    {
        return MedicalLedger::nextDocNo('property_acknowledgement_receipts', 'par_no', 'PAR', $date);
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
            'nextParNo'     => $this->nextParNo(),
            'programs'      => MedicalLedger::programs(), // Fund Cluster dropdown
            'defaultEntity' => self::DEFAULT_ENTITY,
        ]);
    }

    public function nextNo(Request $request)
    {
        return response()->json(['par_no' => $this->nextParNo($request->input('date'))]);
    }

    // Item picker: medical equipment lots with unit cost >= 50,000
    public function listInventory(Request $request)
    {
        $search = $request->input('search');

        $query = DB::table('vw_geninventory')
            ->select(
                'gid as id', 'stockno', 'dateaquired', 'poptrno', 'reference',
                'itemdesc', 'com_desc as description', 'uom_name as unit',
                'categ_name', 'unitcost', 'balance_checkall as balance'
            )
            ->where('void', 0)
            ->whereNotNull('commodityid')
            ->whereIn('typeofcomid', $this->medicalCategoryCodes())
            ->where('unitcost', '>=', self::PAR_MIN_UNIT_COST)
            ->where('balance_checkall', '>', 0) // only lots with stock left
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

    // Shared by the list and the Excel export so both show the same rows
    public static function listQuery(?string $search, ?string $from, ?string $to)
    {
        $query = PropertyAcknowledgementReceipt::query()
            ->with('receivedByEmployee:empid,fullname,dtrno')
            ->orderByDesc('date_issued')
            ->orderByDesc('id');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('par_no', 'LIKE', "%{$search}%")
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

    public function listPar(Request $request)
    {
        $perPage = min(100, (int) $request->input('per_page', 10));

        $query = self::listQuery(
            $request->input('search'),
            $request->input('from_date'),
            $request->input('to_date'),
        );

        return response()->json($query->paginate($perPage));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new ParExcelExport(
                $request->input('search'),
                $request->input('from_date'),
                $request->input('to_date'),
            ),
            'PAR List.xlsx'
        );
    }

    public function show($id)
    {
        return response()->json(
            PropertyAcknowledgementReceipt::with('items')->findOrFail((int) $id)
        );
    }

    public function save(Request $request)
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;

        $data = $request->validate([
            'par_no'                => 'required|string|max:50',
            'entity_name'           => 'required|string|max:255',
            'fund_cluster'          => 'required|string|max:255',
            'date_issued'           => 'required|date',
            'issued_by'             => 'required|integer',
            'received_by'           => 'required|integer',
            'recipient_pos'         => 'nullable|string|max:150',
            'remarks'               => 'nullable|string|max:500',
            'items'                 => 'required|array|min:1',
            'items.*.inventory_id'  => 'nullable|integer',
            'items.*.date_acquired' => 'nullable|date',
            'items.*.qty'           => 'required|integer|min:1',
            'items.*.unit'          => 'nullable|string|max:50',
            'items.*.unit_cost'     => 'required|numeric|min:' . self::PAR_MIN_UNIT_COST,
            'items.*.description'   => 'required|string',
            'items.*.reference'     => 'nullable|string|max:255',
            'items.*.property_no'   => 'nullable|string|max:100',
        ], [
            'items.required'        => 'Add at least one item.',
            'items.*.unit_cost.min' => 'PAR items must cost ₱50,000 or more. Use ICS for lower-value items.',
        ]);

        // PAR No. is editable but must be unique; suggest the next free number when taken
        $duplicate = PropertyAcknowledgementReceipt::where('par_no', $data['par_no'])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success'   => false,
                'message'   => "PAR No. '{$data['par_no']}' already exists.",
                'suggested' => $this->nextParNo($data['date_issued']),
            ], 422);
        }

        // Picked rows must be medical equipment lots (gid) at or above the PAR limit; manual rows are allowed
        $unique = collect($data['items'])->pluck('inventory_id')->filter()->unique()->values();

        if ($unique->isNotEmpty()) {
            $valid = DB::table('vw_geninventory')
                ->where('void', 0)
                ->whereIn('gid', $unique->all())
                ->whereIn('typeofcomid', $this->medicalCategoryCodes())
                ->where('unitcost', '>=', self::PAR_MIN_UNIT_COST)
                ->count();

            if ($valid !== $unique->count()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inventory items must be medical equipment at ₱50,000 and above.',
                ], 422);
            }
        }

        $items = collect($data['items'])->map(fn ($i) => [
            'inventory_id'  => $i['inventory_id'] ?? null,
            'date_acquired' => $i['date_acquired'] ?? null,
            'qty'           => (int) $i['qty'],
            'unit'          => $i['unit'] ?? null,
            'unit_cost'     => $i['unit_cost'],
            'amount'        => round($i['qty'] * $i['unit_cost'], 2),
            'description'   => $i['description'],
            'reference'     => $i['reference'] ?? null,
            'property_no'   => $i['property_no'] ?? null,
        ]);

        $header = collect($data)->except('items')->all();
        $header['total_amount'] = $items->sum('amount');

        // Saving deducts stock through the ledger views (edit re-checks, delete returns it)
        try {
            $par = DB::transaction(function () use ($id, $header, $items) {
                if ($id) {
                    $par = PropertyAcknowledgementReceipt::findOrFail($id);
                    $par->update($header);
                    $par->items()->delete(); // old quantities go back to the balance
                } else {
                    $par = PropertyAcknowledgementReceipt::create($header);
                }

                if ($error = MedicalLedger::stockError($items)) {
                    throw new \RuntimeException($error);
                }

                // One by one so each picked row gets the next ledger seqno for its lot
                foreach ($items as $item) {
                    $item['seqno'] = $item['inventory_id']
                        ? MedicalLedger::nextSeqno((int) $item['inventory_id'])
                        : null;
                    $par->items()->create($item);
                }

                return $par;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $id ? 'Updated successfully' : 'Created successfully',
            'data'    => $par,
        ]);
    }

    public function destroy($id)
    {
        $par = PropertyAcknowledgementReceipt::with('attachments')->findOrFail((int) $id);
        $files = $par->attachments->pluck('filepath')->all();

        DB::transaction(function () use ($par) {
            $par->items()->delete();
            $par->attachments()->delete();
            $par->delete();
        });

        // Remove the files only after the database delete succeeded
        Storage::disk('public')->delete($files);

        return response()->json([
            'success' => true,
            'message' => 'PAR deleted successfully.',
        ]);
    }

    public function pdf($id)
    {
        $par = PropertyAcknowledgementReceipt::with(['items', 'issuedBySignatory', 'receivedByEmployee'])
            ->findOrFail((int) $id);

        return Pdf::loadView('pdf.par', ['par' => $par])
            ->setPaper('a4', 'portrait')
            ->stream("PAR-{$par->par_no}.pdf");
    }

    // ── Attachments ──────────────────────────────────────────
    public function listAttachments($id)
    {
        return response()->json(PropertyAcknowledgementReceipt::findOrFail((int) $id)->attachments);
    }

    public function uploadAttachment(Request $request, $id)
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $par = PropertyAcknowledgementReceipt::findOrFail((int) $id);
        $file = $request->file('file');

        $path = $file->storeAs(
            'attachments/par',
            uniqid() . '_' . $file->getClientOriginalName(),
            'public'
        );

        if (!$path) {
            return response()->json(['message' => 'File failed to save.'], 500);
        }

        $attachment = $par->attachments()->create([
            'filename' => $file->getClientOriginalName(),
            'filepath' => $path,
            'filetype' => $file->getClientOriginalExtension(),
            'filesize' => $file->getSize(),
        ]);

        return response()->json(['message' => 'Uploaded successfully', 'attachment' => $attachment]);
    }

    public function downloadAttachment($id, $attachmentId)
    {
        $attachment = PropertyAcknowledgementReceipt::findOrFail((int) $id)
            ->attachments()->findOrFail((int) $attachmentId);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        if (!$disk->exists($attachment->filepath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return $disk->download($attachment->filepath, $attachment->filename);
    }

    public function deleteAttachment($id, $attachmentId)
    {
        $attachment = PropertyAcknowledgementReceipt::findOrFail((int) $id)
            ->attachments()->findOrFail((int) $attachmentId);

        Storage::disk('public')->delete($attachment->filepath);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted successfully.']);
    }
}