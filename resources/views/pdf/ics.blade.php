<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ICS {{ $ics->ics_no }}</title>
    <style>
        @page { size: A4; margin: 10mm; }

        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000; margin: 0; }

        .hdr { width: 100%; border-collapse: collapse; }
        .hdr td { border: none; vertical-align: middle; }
        .hdr .logo { width: 85px; }
        .hdr .logo img { width: 72px; height: 72px; }
        .agency { text-align: center; font-size: 12px; line-height: 1.35; }

        .printed { text-align: right; font-size: 8px; font-style: italic; margin-top: 2px; }
        .title { text-align: center; font-weight: bold; font-size: 14px; margin: 6px 0 14px; }

        .info { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 12px; }
        .info td { border: none; padding: 1px 0; }

        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #000; padding: 3px 4px; }
        .grid th { font-size: 10px; text-align: center; }
        .grid tbody td { border-top: none; border-bottom: none; vertical-align: top; font-size: 10px; }
        .grid tbody tr.filler td { height: 18px; }
        .grid tfoot td { font-size: 10px; }

        .right { text-align: right; }
        .center { text-align: center; }
        .ref { font-size: 9px; }

        table.sign { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .sign td { border: 1px solid #000; border-top: none; padding: 6px 8px; vertical-align: top; font-size: 10px; height: 115px; }
        .sign .name { margin-top: 26px; text-align: center; text-decoration: underline; }
        .sign .cap { text-align: center; }
        .sign .date { margin-top: 12px; text-align: center; text-decoration: underline; }
    </style>
</head>

<body>
    @php
        $seal = public_path('images/doh-seal.png');
        $regional = public_path('images/chd12-logo.png');
        $fillerRows = max(0, 20 - $ics->items->count() * 2);
        $issuedDate = $ics->date_issued ? \Carbon\Carbon::parse($ics->date_issued)->format('m/d/Y') : '';
    @endphp

    <!-- HEADER -->
    <table class="hdr">
        <tr>
            <td class="logo">
                @if (file_exists($seal))
                    <img src="{{ $seal }}" alt="">
                @endif
            </td>
            <td class="agency">
                Republic of the Philippines<br>
                Department of Health<br>
                Center for Health Development XII<br>
                SOCCSKSARGEN REGION
            </td>
            <td class="logo" style="text-align: right;">
                @if (file_exists($regional))
                    <img src="{{ $regional }}" alt="">
                @endif
            </td>
        </tr>
    </table>
    <div class="printed">Date printed: {{ now()->format('m/d/Y g:i:s A') }}</div>
    <div class="title">Inventory Custodian Slip</div>

    <!-- INFO -->
    <table class="info">
        <tr>
            <td colspan="2"><b>Entity Name :</b> {{ $ics->entity_name }}</td>
        </tr>
        <tr>
            <td><b>Fund Cluster :</b> {{ $ics->fund_cluster }}</td>
            <td class="right"><b>ICS No :</b> {{ $ics->ics_no }}</td>
        </tr>
    </table>

    <!-- ITEMS -->
    <table class="grid">
        <colgroup>
            <col style="width: 9%;">
            <col style="width: 9%;">
            <col style="width: 11%;">
            <col style="width: 11%;">
            <col style="width: 34%;">
            <col style="width: 13%;">
            <col style="width: 13%;">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">Quantity</th>
                <th rowspan="2">Unit</th>
                <th colspan="2">Amount</th>
                <th rowspan="2">Description</th>
                <th rowspan="2">Inventory Item No.</th>
                <th rowspan="2">Estimated Useful Life</th>
            </tr>
            <tr>
                <th>Unit Cost</th>
                <th>Total Cost</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ics->items as $item)
                <tr>
                    <td class="center">{{ number_format($item->qty) }}</td>
                    <td class="center">{{ $item->unit }}</td>
                    <td class="right">{{ number_format($item->unit_cost, 2) }}</td>
                    <td class="right">{{ number_format($item->amount, 2) }}</td>
                    <td>
                        {!! nl2br(e($item->description)) !!}
                        @if ($item->reference)
                            <br><span class="ref">{{ $item->reference }}</span>
                        @endif
                    </td>
                    <td class="center">{{ $item->inventory_no }}</td>
                    <td class="center">{{ $item->est_useful_life }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td><td></td><td></td><td></td>
                <td class="ref">xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</td>
                <td></td><td></td>
            </tr>
            @for ($i = 0; $i < $fillerRows; $i++)
                <tr class="filler">
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" style="border-right: none;"></td>
                <td colspan="2" class="right" style="border-left: none; border-right: none;">
                    <b>Grand Total: {{ number_format($ics->total_amount, 2) }}</b>
                </td>
                <td colspan="3" style="border-left: none;"></td>
            </tr>
        </tfoot>
    </table>

    <!-- SIGNATURES -->
    <table class="sign">
        <tr>
            <td>
                <b>Received from:</b>
                <div class="name">{{ $ics->issuedBySignatory?->sign_name }}</div>
                <div class="cap">Signature Over Printed Name</div>
                <div class="cap">{{ $ics->issuedBySignatory?->sign_position }}</div>
                <div class="date">{{ $issuedDate }}</div>
                <div class="cap">Date</div>
            </td>
            <td>
                <b>Received By:</b>
                <div class="name">{{ $ics->receivedByEmployee?->fullname }}</div>
                <div class="cap">Signature Over Printed Name</div>
                <div class="cap">{{ $ics->recipient_pos }}</div>
                <div class="date">{{ $issuedDate }}</div>
                <div class="cap">Date</div>
            </td>
        </tr>
    </table>
</body>

</html>