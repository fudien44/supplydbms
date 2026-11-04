<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Shared stock rules for medical equipment issued from Laravel (ICS, PAR).
// ITR, ICS, PAR, PTR and RIS all deduct from the same ledger views, so one balance covers all of them.
class MedicalLedger
{
    // Qty requested per lot must not exceed balance_checkall (approved + draft issuances).
    // Call AFTER the document's old items are deleted, so their qty is already returned.
    public static function stockError(Collection $items): ?string
    {
        $requested = $items->filter(fn ($i) => !empty($i['inventory_id']))
            ->groupBy('inventory_id')
            ->map(fn ($rows) => $rows->sum('qty'));

        if ($requested->isEmpty()) {
            return null;
        }

        $available = DB::table('vw_geninventory')
            ->whereIn('gid', $requested->keys())
            ->get(['gid', 'itemdesc', 'balance_checkall'])
            ->keyBy('gid');

        foreach ($requested as $id => $qty) {
            $row = $available->get($id);
            $bal = $row ? (float) $row->balance_checkall : 0;

            if ($qty > $bal) {
                $name = $row ? strtok((string) $row->itemdesc, "\n") : "Item #{$id}";
                return "{$name}: only " . number_format($bal) . ' available.';
            }
        }

        return null;
    }

    // Next running-balance sequence for a lot (same rule the C# PTR uses)
    public static function nextSeqno(int $stockId): int
    {
        return (int) DB::table('vw_gen_inventorytadjissued_checkall')
            ->where('stockid', $stockId)
            ->max('seqno') + 1;
    }

    // CHD12-{TYPE}-YYYY-MM-xxxx; xxxx restarts every month
    public static function nextDocNo(string $table, string $column, string $type, ?string $date = null): string
    {
        $d = $date ? \Carbon\Carbon::parse($date) : now();
        $prefix = sprintf('CHD12-%s-%s-%s-', $type, $d->format('Y'), $d->format('m'));

        $last = DB::table($table)
            ->where($column, 'LIKE', $prefix . '%')
            ->pluck($column)
            ->map(fn ($no) => (int) substr($no, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public static function programs()
    {
        return DB::table('ref_programs')
            ->select('prog_id', 'prog_desc')
            ->where('prog_void', 0)
            ->orderBy('prog_desc')
            ->get();
    }
}