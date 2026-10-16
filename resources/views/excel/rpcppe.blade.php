@php
    $bd = 'border:1px solid #000000;';
    $hdr = $bd . 'font-weight:bold;text-align:center;vertical-align:middle;background-color:#f2f2f2;';

    $dataRow = function ($item) use ($bd) {
        $date = !empty($item->date_acquired) ? \Carbon\Carbon::parse($item->date_acquired)->format('m/d/Y') : '';
        return '<tr>'
            . '<td style="' . $bd . 'vertical-align:top;">' . e($item->article_name) . '</td>'
            . '<td style="' . $bd . 'vertical-align:top;">' . e($item->com_desc) . '</td>'
            . '<td style="' . $bd . 'text-align:center;">' . $date . '</td>'
            . '<td style="' . $bd . 'text-align:center;">' . e($item->po_no) . '</td>'
            . '<td style="' . $bd . 'text-align:center;">' . e($item->unitofmeasure) . '</td>'
            . '<td style="' . $bd . 'text-align:right;">' . number_format((float) $item->u_cost, 2) . '</td>'
            . '<td style="' . $bd . 'text-align:center;">' . number_format((float) $item->qty, 0) . '</td>'
            . '<td style="' . $bd . '"></td>'
            . '<td style="' . $bd . 'text-align:right;">' . number_format((float) $item->amt, 2) . '</td>'
            . '<td style="' . $bd . '"></td>'
            . '<td style="' . $bd . '"></td>'
            . '<td style="' . $bd . 'vertical-align:top;">' . e($item->remarks) . '</td>'
            . '</tr>';
    };
@endphp
<table>
    <tr><td colspan="12" style="text-align:center;font-weight:bold;font-size:14px;">{{ $title }}</td></tr>
    <tr><td colspan="12" style="text-align:center;font-weight:bold;">{{ $subtitle }}</td></tr>
    <tr><td colspan="12" style="text-align:center;">{{ $asOf }}</td></tr>
    @if(!empty($accountablePersonName))
        <tr><td colspan="12">for which {{ $accountablePersonName }} of DOH - CHD SOCCSKSARGEN Region is accountable after having to assume such accountability on {{ !empty($dateAssumed) ? \Carbon\Carbon::parse($dateAssumed)->format('F d, Y') : '' }}.</td></tr>
    @endif
    <tr><td colspan="12"></td></tr>

    <tr>
        <th style="{{ $hdr }}">ARTICLE</th>
        <th style="{{ $hdr }}">DESCRIPTION</th>
        <th style="{{ $hdr }}">DATE ACQUIRED</th>
        <th style="{{ $hdr }}">SOURCE / SERIAL NO.</th>
        <th style="{{ $hdr }}">UNIT OF MEASURE</th>
        <th style="{{ $hdr }}">UNIT VALUE</th>
        <th style="{{ $hdr }}">BALANCE PER CARD</th>
        <th style="{{ $hdr }}">ON HAND PER COUNT</th>
        <th style="{{ $hdr }}">TOTAL VALUE</th>
        <th style="{{ $hdr }}">SHORTAGE / OVERAGE QTY</th>
        <th style="{{ $hdr }}">SHORTAGE / OVERAGE VALUE</th>
        <th style="{{ $hdr }}">REMARKS (CONDITION/LOCATION)</th>
    </tr>

    @if($shouldGroupByOfficer)
        @foreach($groupedItems as $group)
            @php
                $officer = $group['officer']['name'] ?? 'Unassigned / No Accountable Officer';
                if (!empty($group['emp_no'])) $officer .= ' (' . $group['emp_no'] . ')';
                if (!empty($group['officer']['designation'])) $officer .= ' - ' . $group['officer']['designation'];
                if (!empty($group['officer']['office'])) $officer .= ' - ' . $group['officer']['office'];
            @endphp
            <tr><td colspan="12" style="{{ $bd }}font-weight:bold;background-color:#f2f2f2;">ACCOUNTABLE OFFICER: {{ $officer }}</td></tr>
            @foreach($group['items'] as $item)
                {!! $dataRow($item) !!}
            @endforeach
            <tr>
                <td colspan="8" style="{{ $bd }}text-align:right;font-weight:bold;">SUBTOTAL</td>
                <td style="{{ $bd }}text-align:right;font-weight:bold;">{{ number_format((float) $group['total'], 2) }}</td>
                <td style="{{ $bd }}"></td><td style="{{ $bd }}"></td><td style="{{ $bd }}"></td>
            </tr>
        @endforeach
    @else
        @forelse($items as $item)
            {!! $dataRow($item) !!}
        @empty
            <tr><td colspan="12" style="{{ $bd }}text-align:center;">No records found.</td></tr>
        @endforelse
    @endif

    <tr>
        <td colspan="8" style="{{ $bd }}text-align:right;font-weight:bold;">TOTAL</td>
        <td style="{{ $bd }}text-align:right;font-weight:bold;">{{ number_format((float) $grandTotal, 2) }}</td>
        <td style="{{ $bd }}"></td><td style="{{ $bd }}"></td><td style="{{ $bd }}"></td>
    </tr>

    <tr><td colspan="12"></td></tr>
    <tr><td colspan="12"></td></tr>

    <tr>
        <td colspan="4" style="text-align:center;">Certified Correct by:</td>
        <td colspan="4" style="text-align:center;">Approved by:</td>
        <td colspan="4" style="text-align:center;">Verified by:</td>
    </tr>
    <tr><td colspan="12"></td></tr>
    <tr>
        <td colspan="4" style="text-align:center;font-weight:bold;border-top:1px solid #000;">{{ strtoupper($signatories['certified']['name'] ?? '') }}</td>
        <td colspan="4" style="text-align:center;font-weight:bold;border-top:1px solid #000;">{{ strtoupper($signatories['approved']['name'] ?? '') }}</td>
        <td colspan="4" style="text-align:center;font-weight:bold;border-top:1px solid #000;">{{ strtoupper($signatories['verified']['name'] ?? '') }}</td>
    </tr>
    <tr>
        <td colspan="4" style="text-align:center;font-size:9px;">Signature over Printed Name of Inventory Committee Chair and Members</td>
        <td colspan="4" style="text-align:center;font-size:9px;">Signature over Printed Name of Head of Agency/Entity or Authorized Representative</td>
        <td colspan="4" style="text-align:center;font-size:9px;">Signature over Printed Name of COA Representative</td>
    </tr>
</table>
