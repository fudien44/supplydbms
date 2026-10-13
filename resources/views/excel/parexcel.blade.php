<table>
    <thead>
        <tr>
            <th><b>#</b></th>
            <th><b>PAR No.</b></th>
            <th><b>Recipient</b></th>
            <th><b>Fund Cluster</b></th>
            <th><b>Remarks</b></th>
            <th><b>Total Cost</b></th>
            <th><b>Date Issued</b></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($records as $index => $par)
            @php
                $emp = $par->receivedByEmployee;
                $recipient = $emp ? $emp->fullname . ($emp->dtrno ? '(' . $emp->dtrno . ')' : '') : '';
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $par->par_no }}</td>
                <td>{{ $recipient }}</td>
                <td>{{ $par->fund_cluster }}</td>
                <td>{{ $par->remarks }}</td>
                <td>{{ number_format($par->total_amount, 2) }}</td>
                <td>{{ $par->date_issued ? \Carbon\Carbon::parse($par->date_issued)->format('F d, Y') : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>