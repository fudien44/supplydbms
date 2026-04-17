<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        margin: 20px 18px 32px 18px;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 10px;
    }

    .title {
        text-align: center;
        font-weight: bold;
        font-size: 13px;
        margin-bottom: 6px;
    }

    .subtitle,
    .meta-line {
        text-align: center;
        font-size: 10px;
        margin-bottom: 4px;
        line-height: 1.35;
    }

    .meta-line {
        margin-bottom: 10px;
    }

    .form-subtitle {
        text-align: center;
        font-size: 10px;
        margin-bottom: 8px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    thead {
        display: table-header-group;
    }

    tfoot {
        display: table-footer-group;
    }

    tr {
        page-break-inside: avoid;
    }

    th, td {
        border: 1px solid #000;
        vertical-align: middle;
    }

    th {
        font-size: 9px;
        text-align: center;
        font-weight: bold;
        padding: 6px 4px;
        line-height: 1.3;
        white-space: normal;
        word-wrap: break-word;
        word-break: break-word;
        text-transform: uppercase;
    }

    td {
        font-size: 9px;
        padding: 3px;
        white-space: normal;
        word-wrap: break-word;
        word-break: break-word;
        vertical-align: top;
    }

    .text-right { text-align: right; }
    .text-center { text-align: center; }

    .small-text {
        font-size: 7px;
        font-weight: normal;
        text-transform: none;
    }

    .uom-text {
        line-height: 1.1;
    }

    .article { width: 8%; }
    .desc { width: 20%; }
    .date { width: 8%; }
    .stock { width: 10%; }
    .uom { width: 6%; }
    .unit { width: 8%; }
    .balance { width: 8%; }
    .onhand { width: 8%; }
    .total { width: 8%; }
    .shortqty { width: 4%; }
    .shortval { width: 4%; }
    .remarks { width: 8%; }

    .shortqty,
    .shortval {
        text-align: center;
        padding: 1px;
        font-size: 7px;
        line-height: 1;
    }

    .group-header {
        font-weight: bold;
        background: #f2f2f2;
    }
</style>
</head>

<body>

<div class="title">
    REPORT ON THE PHYSICAL COUNT OF PROPERTY, PLANT AND EQUIPMENT (RPCPPE)
</div>

<div class="subtitle">
    <strong>{{ $filters['ppe_name'] ?? 'All PPE Types' }}</strong>
</div>

<div class="subtitle">
    As of
    {{ !empty($filters['as_of_date'])
        ? \Carbon\Carbon::parse($filters['as_of_date'])->format('F d, Y')
        : now()->format('F d, Y') }}
</div>

@if(($filters['item_status'] ?? '') === 'issued' && !empty($accountableOfficer))
    <div class="meta-line">
        for which
        <strong>{{ $accountableOfficer['name'] ?? '' }}</strong>
        @if(!empty($accountableOfficer['designation']))
            , <strong>{{ $accountableOfficer['designation'] }}</strong>
        @endif
        @if(!empty($accountableOfficer['office']))
            , <strong>{{ $accountableOfficer['office'] }}</strong>
        @endif
        is accountable after having to assume such accountability
    </div>
@endif

@if(!empty($filters['form_type']))
    <div class="form-subtitle">
        <small>{{ $filters['form_type'] }}</small>
    </div>
@endif

<table>
    <thead>
        <tr>
            <th rowspan="2" class="article">ARTICLE</th>
            <th rowspan="2" class="desc">DESCRIPTION</th>
            <th rowspan="2" class="date">DATE ACQUIRED</th>
            <th rowspan="2" class="stock">SOURCE / SERIAL NO.</th>

            <th rowspan="2" class="uom">
                <div class="uom-text">
                    UNIT<br>
                    OF<br>
                    MEASURE
                </div>
            </th>

            <th rowspan="2" class="unit">UNIT VALUE</th>

            <th rowspan="2" class="balance">
                BALANCE PER CARD<br>
                <span class="small-text">(QUANTITY)</span>
            </th>

            <th rowspan="2" class="onhand">
                ON HAND PER COUNT<br>
                <span class="small-text">(QUANTITY)</span>
            </th>

            <th rowspan="2" class="total">TOTAL VALUE</th>
            <th colspan="2">SHORTAGE / OVERAGE</th>
            <th rowspan="2" class="remarks">REMARKS</th>
        </tr>
        <tr>
            <th class="shortqty">QTY</th>
            <th class="shortval">VALUE</th>
        </tr>
    </thead>

    <tbody>
        @if(!empty($shouldGroupByOfficer) && count($groupedItems))
            @foreach($groupedItems as $group)
                <tr>
                    <td colspan="12" class="group-header">
                        ACCOUNTABLE OFFICER:
                        {{ $group['officer']['name'] ?? 'Unassigned / No Accountable Officer' }}
                        @if(!empty($group['emp_no']))
                            ({{ $group['emp_no'] }})
                        @endif
                        @if(!empty($group['officer']['designation']))
                            - {{ $group['officer']['designation'] }}
                        @endif
                        @if(!empty($group['officer']['office']))
                            - {{ $group['officer']['office'] }}
                        @endif
                    </td>
                </tr>

                @foreach($group['items'] as $item)
                    <tr>
                        <td>{{ $item->article_name }}</td>
                        <td>{{ $item->com_desc }}</td>
                        <td class="text-center">
                            {{ !empty($item->date_acquired) ? \Carbon\Carbon::parse($item->date_acquired)->format('m/d/Y') : '' }}
                        </td>
                        <td class="text-center">{{ $item->stock_no }}</td>
                        <td class="text-center">{{ $item->unitofmeasure }}</td>
                        <td class="text-right">{{ number_format((float)$item->u_cost, 2) }}</td>
                        <td class="text-center">{{ number_format((float)$item->qty, 0) }}</td>
                        <td></td>
                        <td class="text-right">{{ number_format((float)$item->amt, 2) }}</td>
                        <td></td>
                        <td></td>
                        <td>{{ $item->remarks ?? '' }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="8" class="text-right"><strong>SUBTOTAL</strong></td>
                    <td class="text-right"><strong>{{ number_format((float)$group['total'], 2) }}</strong></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endforeach
        @else
            @forelse($items as $item)
                <tr>
                    <td>{{ $item->article_name }}</td>
                    <td>{{ $item->com_desc }}</td>
                    <td class="text-center">
                        {{ !empty($item->date_acquired) ? \Carbon\Carbon::parse($item->date_acquired)->format('m/d/Y') : '' }}
                    </td>
                    <td class="text-center">{{ $item->stock_no }}</td>
                    <td class="text-center">{{ $item->unitofmeasure }}</td>
                    <td class="text-right">{{ number_format((float)$item->u_cost, 2) }}</td>
                    <td class="text-center">{{ number_format((float)$item->qty, 0) }}</td>
                    <td></td>
                    <td class="text-right">{{ number_format((float)$item->amt, 2) }}</td>
                    <td></td>
                    <td></td>
                    <td>{{ $item->remarks ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center">No records found.</td>
                </tr>
            @endforelse
        @endif
    </tbody>

    <tfoot>
        <tr>
            <td colspan="8" class="text-right"><strong>TOTAL</strong></td>
            <td class="text-right">
                <strong>{{ number_format((float)$total, 2) }}</strong>
            </td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
    </tfoot>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script('
            $text = "Page " . $PAGE_NUM . " of " . $PAGE_COUNT;
            $font = $fontMetrics->getFont("Helvetica", "normal");
            $size = 7;
            $color = array(0, 0, 0);
            $textWidth = $fontMetrics->getTextWidth($text, $font, $size);
            $x = ($pdf->get_width() - $textWidth) / 2;
            $y = $pdf->get_height() - 18;
            $pdf->text($x, $y, $text, $font, $size, $color);
        ');
    }
</script>

</body>
</html>