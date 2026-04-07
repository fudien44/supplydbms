<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ITR Report</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #000;
            margin: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            table-layout: fixed;
        }

        table,
        th,
        td {
            border: 1px solid #000;
        }

        th,
        td {
            padding: 6px;
            text-align: left;
        }

        .td1 {
            font-size: 11px;
            font-weight: bold;
        }

        .td2 {
            font-size: 11px;
            font-weight: bold;
            text-decoration: underline;
            border: none;
            text-align: left;
        }

        .td4 {
            font-size: 11px;
            text-align: left;
        }

        .td5 {
            font-size: 10px;
            font-weight: bold;
        }

        .td5-2 {
            font-size: 10px;
            font-weight: bold;
            text-align: center;
        }

        .td6 {
            font-size: 11px;
            text-align: center;
            border-left: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-top: none !important;
            border-bottom: none !important;
            padding-bottom: 0px;
            margin-bottom: 0px;
        }

        .td6-1 {
            font-size: 10px;
            text-align: left;
            border-left: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-top: none !important;
            border-bottom: none !important;
        }

        .td6-2 {
            font-size: 8px;
            text-align: left;
            border-left: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-top: none !important;
            border-bottom: none !important;
        }

        .td6-3 {
            font-size: 11px;
            text-align: center;
            vertical-align: top;
            border-left: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-top: none !important;
            border-bottom: none !important;
            padding-top: 0px;
            margin-top: 0px;
            padding-bottom: 0px;
            margin-bottom: 0px;
        }

        .td7 {
            font-size: 10px;
            text-align: center;
            vertical-align: middle;
            border-left: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-top: 1px solid #000 !important;
            border-bottom: no 1px solid #000ne !important;
        }


        colgroup col {
            width: 8.33%;
        }

        .table-16 {
            table-layout: fixed;
            width: 100%;
            border-collapse: collapse;
        }

        .table-16 colgroup col {
            width: 6.25%;
        }

        @page {
            size: Legal;
            margin: 5mm;
        }

        body {
            margin: 0;
            padding: 10px;
        }

        .checkbox-symbol {
            font-family: "DejaVu Sans", "Arial Unicode MS", Arial, sans-serif;
            font-size: 11pt;
        }

        .peso {
            font-family: "DejaVu Sans", "Arial Unicode MS", Arial, sans-serif;
            font-size: 7pt;
            /* match your td5-2 */
        }
    </style>
</head>

<body>

    <!-- HEADER -->
    <div class="header">
        <div class="title">INVENTORY TRANSFER REPORT</div>
    </div>

    <!-- BASIC INFO -->
    <div class="section">
        <table style="border: none;">
            <colgroup>
                <col span="16">
            </colgroup>
            <tr>
                <td colspan="2" style="border: none; text-align: left;">
                    <span class="td1">Entity Name:</span>
                </td>
                <td colspan="6" class="td2">{{ $transfer->entity_name }}</td>
                <td colspan="2" style="border: none; text-align: left;">
                    <span class="td1">Fund Cluster:</span>
                </td>
                <td colspan="6" class="td2">{{ $transfer->fund_cluster }}</td>
            </tr>
        </table>
    </div>

    <!-- TRANSFER DETAILS -->
    <div class="section">
        <table class="table-16">
            <colgroup>
                <col span="12">
            </colgroup>
            <tbody>
                <tr>
                    <td colspan="4" class="" style="text-align: left"><b>From</b> Accountable <br />
                        <span style="font-size: 10px;"> (Officer/Agency/Fund Cluster) </span>
                    </td>
                    <td colspan="6" class="td4">{{ $transfer->fromEntity?->facilityname }}
                    </td>
                    <td colspan="2" class="td1" style="text-align: right">ITR No.</td>
                    <td colspan="4" class="td4" style="text-align: center;">{{ $transfer->itr_no }}</td>
                </tr>
                <tr>

                    <td colspan="4" class="" style="text-align: left"><b>To</b> Accountable<br />
                        <span style="font-size: 10px"> (Officer/Agency/Fund Cluster) </span>
                    </td>
                    <td colspan="6" class="td4">{{ $transfer->toEntity?->facilityname }}
                    </td>
                    <td colspan="2" class="td1" style="text-align: right">Date</td>
                    <td colspan="4" class="td4" style="text-align: center">
                        {{ \Carbon\Carbon::parse($transfer->date_issued)->format('d-M-y') }}
                    </td>
                </tr>
                <tr>
                    <td colspan="16" style="border: none; text-align: left;">
                        <span class="td1">Transfer Type</span> (Check only one):
                    </td>
                </tr>
                <tr>
                    <td colspan="16" style="border: none; text-align: left;">
                        @php
                            $transferTypeOptions = \App\Models\TransferType::where('transid', '!=', 5)
                                ->orderBy('transid')
                                ->get();
                        @endphp
                        @foreach ($transferTypeOptions as $type)
                            <span style="margin-right: 12px;">
                                <span class="checkbox-symbol">
                                    {{ $transfer->transfer_type_id == $type->transid ? '☑' : '☐' }}
                                </span>
                                <span style="font-size: 9pt;">{{ $type->transtype }}</span>
                                @if ($transfer->transfer_type_id == $type->transid && $transfer->others_specify)
                                    <span style="font-size: 9pt; text-decoration: underline;">( {{ $transfer->others_specify }} )</span>
                                @endif
                            </span>
                        @endforeach
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- ITEMS TABLE -->
        <table class="table-16" style="margin-top: 0px;">
            <colgroup>
                <col span="16">
            </colgroup>
            <thead>
                <tr>
                    <td class="td5-2">#</td>
                    <td class="td5" colspan="2">Date Acquired</td>
                    <td class="td5" colspan="2">P.O # / ICS No.</td>
                    <td class="td5-2" colspan="3">Description</td>
                    <td class="td5-2">Qty</td>
                    <td class="td5-2" colspan="2">Unit Cost</td>
                    <td class="td5-2" colspan="2">Amount</td>
                    <td class="td5-2" colspan="3">Condition of Inventory</td>
                </tr>
            </thead>
            <tbody>
                @foreach ($transfer->items as $index => $item)
                    <tr>
                        <td class="td5-2">{{ $index + 1 }}</td>
                        <td colspan="2" class="td4">
                            {{ $item->date_acquired ? \Carbon\Carbon::parse($item->date_acquired)->format('m/d/Y') : '-' }}
                        </td>
                        <td colspan="2" class="td4">{{ $item->reference ?? '-' }}</td>
                        <td colspan="3" class="td4"><b>{{ $item->description ?? '-' }}</b></td>
                        <td class="td5-2">{{ $item->qty }}</td>
                        <td colspan="2" class="td5-2 peso" style="text-align: left">
                            ₱{{ number_format($item->unit_cost, 2) }}</td>
                        <td colspan="2" class="td5-2 peso" style="text-align: left">
                            ₱{{ number_format($item->qty * $item->unit_cost, 2) }}
                        </td>
                        <td colspan="3" class="td4" style="text-align: center">{{ $item->item_condition ?? '-' }}
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td colspan="2"></td>
                        <td colspan="2"></td>
                        <td colspan="3"><i>Source:</i> {{ $item->inventorydet->supplier ?? '-' }}</td>
                        <td></td>
                        <td colspan="2"></td>
                        <td colspan="2"></td>
                        <td colspan="3"></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="11" class="td5" style="text-align: right;">Total Amount:</td>
                    <td colspan="2" class="td5-2 peso" style="text-align: left">
                        ₱{{ number_format($transfer->total_amount, 2) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>

        <!-- REASON + SIGNATORIES -->
        <table class="table-16" style="margin-top: 0px;">
            <colgroup>
                <col span="16">
            </colgroup>
            <tr>
                <td class="td5" colspan="4" style="border-right: none;">
                    Reason for Transfer:
                </td>
                <td colspan="12" style="border-left: none;">
                    {{ $transfer->reason }}
                </td>
            </tr>
            <tr>
                <td colspan="2" class="td6-1">
                </td>

                <td colspan="5" class="td6-1">
                    Approved by:
                </td>

                <td colspan="5" class="td6-1">
                    Released/Issued by:
                </td>

                <td colspan="4" class="td6-1">
                    Received by:
                </td>
            </tr>
            <tr>
                <td colspan="2" class="td6-1">
                    Signature:
                </td>
                <td colspan="5" class="td6">
                </td>
                <td colspan="5" class="td6">

                </td>
                <td colspan="4" class="td6">

                </td>
            </tr>
            <tr>
                <td colspan="2" class="td6-1">
                    Printed Name:
                </td>
                <td colspan="5" class="td6">
                    <b><u>{{ $transfer->approvedBySignatory?->sign_name ?? '' }}</u></b>
                </td>
                <td colspan="5" class="td6">
                    <b><u>{{ $transfer->issuedBySignatory?->sign_name ?? '' }}</u></b>
                </td>
                <td colspan="4" class="td6">
                    <b> <u>{{ $transfer->receivedByEmployee?->fullname ?? '' }}</u></b>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="td6-1">
                    Designation:
                </td>
                <td colspan="5" class="td6-3">
                    {{ $transfer->approvedBySignatory?->sign_position ?? '' }}
                </td>
                <td colspan="5" class="td6-3">
                    {{ $transfer->issuedBySignatory?->sign_position ?? '' }}
                </td>
                <td colspan="4" class="td6-3">
                    {{ $transfer->receivedByEmployee?->pos_description ?? '' }}
                </td>
            </tr>
            <tr style="height: 5px;">
                <td colspan="2" class="td6-1"
                    style="height: 14px; line-height: 14px; padding-top: 0px; padding-bottom: 0px; margin-top: 0px; margin-bottom: 0px">
                    Date:
                </td>
                <td colspan="5" class="td7"
                    style="padding: 0px; margin: 0px; height: 14px; line-height: 14px;">
                    {{ now()->format('m/d/Y') }} </td>
                <td colspan="5" class="td7"
                    style="padding: 0px; margin: 0px; height: 14px; line-height: 14px;">
                    {{ now()->format('m/d/Y') }} </td>
                <td colspan="4" class="td7"
                    style="padding: 0px; margin: 0px; height: 14px; line-height: 14px;">

                </td>
            </tr>
            <tr style="height: 5px;">
                <td colspan="2" class="td6-1" style="padding: 0; height: 5px; line-height: 1px;"></td>
                <td colspan="5" class="td7" style="padding: 0; height: 5px; line-height: 1px;"></td>
                <td colspan="5" class="td7" style="padding: 0; height: 5px; line-height: 1px;"></td>
                <td colspan="4" class="td7" style="padding: 0; height: 5px; line-height: 1px;"></td>
            </tr>
        </table>
    </div>

</body>

</html>
