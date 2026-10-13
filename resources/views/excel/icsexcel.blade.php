<table>
    <thead>
        <tr>
            <th><b>#</b></th>
            <th><b>ICS No.</b></th>
            <th><b>Recipient</b></th>
            <th><b>Fund Cluster</b></th>
            <th><b>Remarks</b></th>
            <th><b>Total Value</b></th>
            <th><b>Date Issued</b></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($records as $index => $ics)
            @php
                $emp = $ics->receivedByEmployee;
                $recipient = $emp ? $emp->fullname . ($emp->dtrno ? '(' . $emp->dtrno . ')' : '') : '';
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $ics->ics_no }}</td>
                <td>{{ $recipient }}</td>
                <td>{{ $ics->fund_cluster }}</td>
                <td>{{ $ics->remarks }}</td>
                <td>{{ number_format($ics->total_amount, 2) }}</td>
                <td>{{ $ics->date_issued ? \Carbon\Carbon::parse($ics->date_issued)->format('F d, Y') : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>