<table>
    <thead>
        <tr>
            <th colspan="8" style="text-align: center; font-weight: bold;">
                INVENTORY TRANSFER RECORDS
            </th>
        </tr>

        <tr>
            <th style="width: 75px">#</th>
            <th style="width: 125px">ITR No.</th>
            {{-- <th  style="width: 125px">Fund Cluster</th> --}}
            <th  style="width: 125px">Reason for Transfer</th>
            <th  style="width: 125px">Transfer Type</th>
            <th  style="width: 225px">From Entity</th>
            <th  style="width: 225px">To Entity</th>
            <th  style="width: 200px">Approved By</th>
            <th  style="width: 200px">Issued By</th>
            <th  style="width: 200px">Received By</th>
            <th  style="width: 125px">Total Amount</th>
            <th  style="width: 125px">Date Issued</th>
        </tr>
    </thead>

    <tbody>
        @php $count = 1; @endphp

        @foreach ($records as $r)
            {{-- Main ITR row --}}
            <tr>
            <td>{{ $count++ }}</td>
            <td>{{ $r->itr_no }}</td>
            {{-- <td>{{ $r->fund_cluster }}</td> --}}
            <td>{{ $r->reason }}</td>
            <td>{{ $r->transferType->transtype ?? '-' }}</td>
            <td>{{ $r->fromEntity->facilityname ?? '-' }}</td>
            <td>{{ $r->toEntity->facilityname ?? '-' }}</td>
            <td>{{ $r->approvedBySignatory->sign_name ?? '-' }}</td>
            <td>{{ $r->issuedBySignatory->sign_name ?? '-' }}</td>
            <td>{{ $r->employeeInfo->fullname ?? '-' }}</td>
            <td>₱ {{ number_format($r->total_amount, 2) }}</td>
            <td>{{ \Carbon\Carbon::parse($r->date_issued)->format('Y-m-d') }}</td>
        </tr>

            {{-- Items Header --}}
            {{-- @if ($r->items && count($r->items))
                <tr>
                    <td></td>
                    <td colspan="7"><strong>Items:</strong></td>
                </tr> --}}

            {{-- Items list --}}
            {{-- @foreach ($r->items as $item)
                    <tr>
                        <td></td>
                        <td colspan="2">{{ $item->description }}</td>
                        <td>Qty: {{ $item->quantity }}</td>
                        <td></td>
                        <td></td>
                        <td>₱ {{ number_format($item->unit_cost, 2) }}</td>
                        <td></td>
                    </tr>
                @endforeach
            @endif --}}

            {{-- Spacer row --}}
            {{-- <tr>
                <td colspan="8"></td>
            </tr> --}}
        @endforeach
    </tbody>
</table>
