<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>PAR {{ $par->par_no }}</title>
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
        $fillerRows = max(0, 20 - $par->items->count() * 2);
        $issuedDate = $par->date_issued ? \Carbon\Carbon::parse($par->date_issued)->format('m/d/Y') : '';
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
    <div class="title">Property Acknowledgement Receipt</div>

    <!-- INFO -->
    <table class="info">
        <tr>
            <td colspan="2"><b>Entity Name :</b> {{ $par->entity_name }}</td>
        </tr>
        <tr>
            <td><b>Fund Cluster :</b> {{ $par->fund_cluster }}</td>
            <td class="right"><b>PAR No :</b> {{ $par->par_no }}</td>
        </tr>
    </table>

    <!-- ITEMS -->
    <table class="grid">
        <colgroup>
            <col style="width: 9%;">
            <col style="width: 9%;">
            <col style="width: 40%;">
            <col style="width: 14%;">
            <col style="width: 13%;">
            <col style="width: 15%;">
        </colgroup>
        <thead>
            <tr>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Description</th>
                <th>Property Number</th>
                <th>Date Acquired</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($par->items as $item)
                <tr>
                    <td class="center">{{ number_format($item->qty) }}</td>
                    <td class="center">{{ $item->unit }}</td>
                    <td>
                        {!! nl2br(e($item->description)) !!}
                        @if ($item->reference)
                            <br><span class="ref">{{ $item->reference }}</span>
                        @endif
                    </td>
                    <td class="center">{{ $item->property_no }}</td>
                    <td class="center">
                        {{ $item->date_acquired ? \Carbon\Carbon::parse($item->date_acquired)->format('m/d/Y') : '' }}
                    </td>
                    <td class="right">{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td><td></td>
                <td class="ref">xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</td>
                <td></td><td></td><td></td>
            </tr>
            @for ($i = 0; $i < $fillerRows; $i++)
                <tr class="filler">
                    <td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="right" style="border-right: none;"><b>Grand Total:</b></td>
                <td class="right" style="border-left: none;"><b>{{ number_format($par->total_amount, 2) }}</b></td>
            </tr>
        </tfoot>
    </table>

    <!-- SIGNATURES (standard PAR: Received by on the left, Issued by on the right) -->
    <table class="sign">
        <tr>
            <td>
                <b>Received by:</b>
                <div class="name">{{ $par->receivedByEmployee?->fullname }}</div>
                <div class="cap">Signature Over Printed Name of End User</div>
                <div class="cap">{{ $par->recipient_pos }}</div>
                <div class="date">{{ $issuedDate }}</div>
                <div class="cap">Date</div>
            </td>
            <td>
                <b>Issued by:</b>
                <div class="name">{{ $par->issuedBySignatory?->sign_name }}</div>
                <div class="cap">Signature Over Printed Name of Supply and/or Property Custodian</div>
                <div class="cap">{{ $par->issuedBySignatory?->sign_position }}</div>
                <div class="date">{{ $issuedDate }}</div>
                <div class="cap">Date</div>
            </td>
        </tr>
    </table>
</body>

</html>