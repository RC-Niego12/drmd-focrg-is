@php
    /** @var \App\Services\RisDrDocumentPdfService $pdf */
    $pdf = app(\App\Services\RisDrDocumentPdfService::class);
    $items = $items ?? [];
    $form = $form ?? [];
    $tracking = $tracking ?? [];
    $signatories = $signatories ?? [];
    // Match OfficialDr blank-row budget.
    $blankRows = max(0, 8 - count($items));
    $rows = array_merge($items, array_fill(0, $blankRows, null));
    $warehouseNote = $pdf->withdrawalRemarks($items);
    $issuanceApprovedBy = $signatories['issuance_approved_by'] ?? ['name' => '', 'designation' => '', 'position' => '', 'office' => ''];
    $releasedBy = $signatories['released_by'] ?? ['name' => '', 'designation' => '', 'position' => '', 'office' => ''];
    $modes = $tracking['mode_of_transportation'] ?? [];
    if (! is_array($modes)) {
        $modes = array_values(array_filter(array_map('trim', explode(',', (string) $modes))));
    }
    $drNumber = $tracking['dr_number'] ?? 'DR NUMBER PENDING';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
/*
 * Layout source of truth: resources/js/Components/RrosOfficialDocuments.jsx
 * (RROS_OFFICIAL_PRINT_CSS + OfficialDr). Column % and section order match React.
 */
/* DomPDF applies @page margins on the html/-dompdf-page frame — never set html { margin: 0 }. */
@page { margin: 0.5in; }
* { box-sizing: border-box; }
html { color: #111; font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; }
/* Reserve space so fixed footer never overlaps REMARKS / recipient block. */
body { margin: 0; padding-bottom: 32pt; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: 0.65pt solid #111; padding: 2.5pt 3.5pt; vertical-align: middle; font-size: 7.5pt; line-height: 1.12; word-wrap: break-word; overflow: visible; }
.no-border, .no-border td, .no-border th { border: 0 !important; }
.doc-title { text-align: center; font-family: DejaVu Serif, serif; font-size: 14pt; font-weight: 700; padding: 5pt 7pt; letter-spacing: 0.3pt; }
.section-title { background: #d9e2ef; text-align: center; font-weight: 700; font-size: 8.5pt; padding: 3.5pt 4pt; letter-spacing: 0.2pt; }
.label { font-weight: 700; white-space: nowrap; }
.center { text-align: center; }
.right { text-align: right; }
.left { text-align: left; }
.top { vertical-align: top; }
.tiny { font-size: 7pt; }
.remarks-head { white-space: nowrap; font-size: 7.5pt; letter-spacing: 0.02em; }
.remarks-cell { white-space: pre-line; vertical-align: top; font-size: 7pt; word-wrap: break-word; overflow: visible; }
.unit-cost-cell { white-space: nowrap; text-align: right; word-wrap: normal; }
.item-name-cell { overflow: visible; white-space: normal; word-wrap: break-word; }
.signature-space td { height: 22pt; }
/* DomPDF: pin footer to bottom of page content area (@page 0.5in). */
.footer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  text-align: center;
  font-family: DejaVu Serif, serif;
  font-size: 6pt;
  line-height: 1.15;
  padding: 3pt 3pt 0;
  border: 0;
  margin: 0;
}
/* Match Assessment letterhead scale (committed assessment.blade.php). */
.official-logo { width: 88pt; max-height: 32pt; height: auto; vertical-align: middle; }
.official-logo.bp { width: 28pt; max-height: 32pt; height: auto; margin-left: 5pt; vertical-align: middle; }
.header-division { font-family: DejaVu Serif, serif; font-size: 9.5pt; font-weight: 700; text-align: center; line-height: 1.2; letter-spacing: 0.02em; padding: 2pt 4pt; }
.header-division-line { white-space: nowrap; display: inline-block; }
.ris-brand-header { padding: 6pt 8pt; }
.dr-number-box { background: #fde047; text-align: center; font-size: 16pt; font-weight: 800; letter-spacing: 0.02em; vertical-align: middle; padding: 4pt 3pt; white-space: nowrap; line-height: 1.15; }
.item-row td { height: 12pt; padding: 2.5pt 3.5pt; font-size: 7.5pt; vertical-align: middle; }
.item-row .remarks-cell { vertical-align: top; font-size: 6.5pt; }
.purpose-cell { font-size: 7.5pt; line-height: 1.15; vertical-align: top; padding: 3pt 4pt; }
.check { font-size: 10pt; font-weight: 700; }
.dr-meta-label { font-weight: 700; white-space: nowrap; text-align: right; vertical-align: middle; padding: 4pt 4pt; word-break: keep-all; overflow-wrap: normal; word-wrap: normal; }
.dr-meta-value { vertical-align: middle; padding: 4pt 5pt; }
.dr-qty-unit-head { text-align: center; white-space: nowrap; word-break: keep-all; overflow-wrap: normal; word-wrap: normal; padding: 3pt 2pt; font-size: 7.5pt; }
.transport-label { font-weight: 700; text-align: right; vertical-align: middle; line-height: 1.15; font-size: 7.5pt; padding: 4pt 4pt; white-space: normal; word-break: keep-all; overflow-wrap: normal; word-wrap: normal; }
.dr-lower-wrap { padding: 0 !important; border: 0 !important; vertical-align: top; background: #fff; }
.dr-lower { width: 100%; border-collapse: collapse; table-layout: fixed; background: #fff; }
.dr-lower th, .dr-lower td { border: 0.7pt solid #111; overflow: visible; background: #fff; padding: 4pt 6pt; vertical-align: middle; }
.dr-purpose-label { font-weight: 700; text-align: right; white-space: nowrap; vertical-align: middle; font-size: 8pt; }
.dr-purpose-value { font-size: 8pt; line-height: 1.2; vertical-align: top; text-align: left; }
.dr-sig-header-row > th { border-top: 2pt solid #111; }
.dr-sig-date-row > td { border-bottom: 2pt solid #111; }
.dr-sig-row-label { font-weight: 700; text-align: right; white-space: nowrap; vertical-align: middle; font-size: 7.5pt; padding: 4pt 5pt; }
.dr-sig-office-label { white-space: normal; line-height: 1.15; }
.dr-sig-role { text-align: center; font-weight: 700; font-size: 7.5pt; line-height: 1.15; text-transform: uppercase; white-space: normal; overflow: visible; word-wrap: break-word; padding: 4pt 5pt; }
.dr-sig-name { text-align: center; font-weight: 700; text-transform: uppercase; font-size: 8pt; line-height: 1.15; white-space: nowrap; overflow: visible; word-wrap: normal; padding: 4pt 6pt; }
.dr-sig-designation { text-align: center; font-style: italic; text-transform: uppercase; font-size: 7pt; line-height: 1.15; white-space: normal; overflow: visible; word-wrap: break-word; padding: 4pt 6pt; }
.dr-sig-office { text-align: center; font-style: normal; font-weight: 400; text-transform: uppercase; font-size: 7pt; line-height: 1.15; white-space: normal; overflow: visible; word-wrap: break-word; padding: 4pt 6pt; }
/* Beat .dr-lower td (0,1,1) + column inheritance from .dr-sig-role { text-align:center } */
td.dr-sig-transport {
  text-align: left !important;
  vertical-align: top !important;
  font-style: italic;
  font-size: 5.5pt;
  line-height: 1.15;
  overflow: visible;
  white-space: normal;
  padding: 1pt 2pt 1pt 2pt !important;
}
td.dr-sig-transport .dr-sig-transport-line {
  display: block;
  text-align: left !important;
  margin: 0;
  padding: 0;
}
td.dr-sig-transport .dr-sig-transport-label,
td.dr-sig-transport .dr-sig-transport-value {
  text-align: left !important;
}
td.dr-sig-transport .dr-sig-transport-label {
  font-style: italic;
  font-weight: 400;
  font-size: 5.5pt;
}
td.dr-sig-transport .dr-sig-transport-value {
  font-style: normal;
  font-weight: 700;
  text-transform: uppercase;
  margin-left: 2pt;
  font-size: 5.5pt;
  white-space: pre-line;
}
.dr-recipient-label { font-weight: 700; white-space: nowrap; vertical-align: middle; text-align: left; padding: 4pt 5pt; word-break: keep-all; overflow-wrap: normal; }
.dr-recipient-value { vertical-align: middle; padding: 4pt 6pt; text-align: left; }
/* DomPDF drops <col>; fixed layout uses row 0 only — zero-height colspec carries % widths */
.dr-colspec td, .dr-lower-colspec td { height: 0; max-height: 0; padding: 0; border: 0; font-size: 0; line-height: 0; overflow: hidden; }
</style>
</head>
<body>
@php
    // Qty5.5 Unit6.5 Desc25 Cost10 | Qty5.5 Unit6.5 Items25 Remarks16
    $drColWidths = [5.5, 6.5, 6.25, 6.25, 6.25, 6.25, 10, 5.5, 6.5, 5, 5, 5, 5, 5, 16];
    $drLowerColWidths = [15, 25, 25, 17.5, 17.5];
@endphp
<table>
    {{-- OfficialDr: Qty5.5 Unit6.5 Desc25(6.25×4) Cost10 | Qty5.5 Unit6.5 Items25(5×5) Remarks16 --}}
    <colgroup>
        @foreach ($drColWidths as $w)<col style="width:{{ $w }}%">@endforeach
    </colgroup>
    <tbody>
        <tr class="dr-colspec">
            @foreach ($drColWidths as $w)<td style="width:{{ $w }}%">&nbsp;</td>@endforeach
        </tr>
        <tr>
            <td class="ris-brand-header" colspan="15">
                <table class="no-border">
                    <tr>
                        <td style="width:38%; border:0; vertical-align:middle; padding:0;">
                            <img class="official-logo" src="{{ public_path('images/dswd_logo_3.png') }}">
                            <img class="official-logo bp" src="{{ public_path('images/Bagong_PilipinasTransparent.png') }}">
                        </td>
                        <td class="header-division" style="border:0; vertical-align:middle;">
                            <span class="header-division-line">DISASTER RESPONSE MANAGEMENT DIVISION</span><br>FIELD OFFICE CARAGA
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="doc-title" colspan="10">DELIVERY RECEIPT</td>
            <td class="dr-number-box" colspan="5">{{ $drNumber }}</td>
        </tr>
        <tr>
            <td class="dr-meta-label" colspan="2">DATE:</td>
            <td class="dr-meta-value" colspan="13"><b>{{ $pdf->readableDate($form['ris_date'] ?? null, true) }}</b></td>
        </tr>
        <tr>
            <td class="dr-meta-label" colspan="2">RECIPIENT:</td>
            <td class="dr-meta-value" colspan="13"><b>{{ $form['recipient'] ?? '' }}</b></td>
        </tr>
        <tr>
            <td class="dr-meta-label" colspan="2">ADDRESS:</td>
            <td class="dr-meta-value" colspan="13">{{ $form['delivery_site'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="dr-meta-label" colspan="2">RIS NO.:</td>
            <td class="dr-meta-value" colspan="13">{{ $form['ris_number'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="transport-label" rowspan="2" colspan="3">MODE OF<br>TRANSPORTATION:</td>
            <td colspan="6"><span class="check">{{ $pdf->transportMark($modes, 'DSWD-Owned') }}</span> DSWD-Owned</td>
            <td colspan="6"><span class="check">{{ $pdf->transportMark($modes, 'Government Asset') }}</span> Government Asset</td>
        </tr>
        <tr>
            <td colspan="6"><span class="check">{{ $pdf->transportMark($modes, 'Service Provider') }}</span> Service Provider</td>
            <td colspan="6"><span class="check">{{ $pdf->transportMark($modes, 'Partner') }}</span> Partner</td>
        </tr>
        <tr>
            <th class="section-title" colspan="7">ISSUED</th>
            <th class="section-title" colspan="8">RECEIVED</th>
        </tr>
        <tr>
            <th class="dr-qty-unit-head">QTY</th>
            <th class="dr-qty-unit-head">UNIT</th>
            <th class="center" colspan="4">ITEMS DESCRIPTION</th>
            <th class="center">UNIT COST</th>
            <th class="dr-qty-unit-head">QTY</th>
            <th class="dr-qty-unit-head">UNIT</th>
            <th class="center" colspan="5">ITEM(S)</th>
            <th class="center remarks-head">REMARKS</th>
        </tr>
        @foreach ($rows as $item)
            @php $unitCost = $item ? $pdf->documentDrUnitCost($item) : null; @endphp
            <tr class="item-row">
                <td class="center">{{ $item ? $pdf->formatQuantity($item['quantity'] ?? 0) : '' }}</td>
                <td class="center">{{ $item['unit'] ?? '' }}</td>
                <td class="left item-name-cell" colspan="4">{{ $item['item_name'] ?? '' }}</td>
                <td class="right unit-cost-cell">{{ !($is_google_sheet_transaction ?? false) && $unitCost !== null ? $pdf->formatPeso($unitCost) : '' }}</td>
                <td></td>
                <td></td>
                <td colspan="5"></td>
                <td class="tiny left remarks-cell">{{ $item ? $pdf->documentDrItemRemarks($item['remarks'] ?? null, $item['expiry'] ?? null) : '' }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="dr-lower-wrap" colspan="15">
                <table class="dr-lower">
                    {{-- Label15 | Issuance25 | Released25 | Transported17.5 | Received17.5 --}}
                    <colgroup>
                        @foreach ($drLowerColWidths as $w)<col style="width:{{ $w }}%">@endforeach
                    </colgroup>
                    <tbody>
                        <tr class="dr-lower-colspec">
                            @foreach ($drLowerColWidths as $w)<td style="width:{{ $w }}%">&nbsp;</td>@endforeach
                        </tr>
                        <tr>
                            <td class="dr-purpose-label">PURPOSE:</td>
                            <td class="dr-purpose-value" colspan="4">{{ $form['purpose_of_release'] ?? '' }}</td>
                        </tr>
                        <tr class="dr-sig-header-row">
                            <th class="dr-sig-row-label"></th>
                            <th class="dr-sig-role">ISSUANCE APPROVED BY:</th>
                            <th class="dr-sig-role">RELEASED BY:</th>
                            <th class="dr-sig-role">TRANSPORTED BY:</th>
                            <th class="dr-sig-role">RECEIVED BY:</th>
                        </tr>
                        <tr class="signature-space">
                            <td class="dr-sig-row-label">SIGNATURE:</td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                        <tr>
                            <td class="dr-sig-row-label">NAME:</td>
                            <td class="dr-sig-name">{{ $issuanceApprovedBy['name'] ?? '' }}</td>
                            <td class="dr-sig-name">{{ $releasedBy['name'] ?? '' }}</td>
                            <td class="dr-sig-transport">
                                <div class="dr-sig-transport-line">
                                    <span class="dr-sig-transport-label">DRIVER:</span>
                                    @if (! empty($tracking['driver_name']))
                                        <span class="dr-sig-transport-value">{!! nl2br(e(mb_strtoupper((string) $tracking['driver_name']))) !!}</span>
                                    @endif
                                    @if (! empty($tracking['escort_name']))
                                        <span class="dr-sig-transport-value"><br>ESCORT: {!! nl2br(e(mb_strtoupper((string) $tracking['escort_name']))) !!}</span>
                                    @endif
                                </div>
                            </td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="dr-sig-row-label">POSITION:</td>
                            <td class="dr-sig-designation">{{ $issuanceApprovedBy['designation'] ?: ($issuanceApprovedBy['position'] ?? '') }}</td>
                            <td class="dr-sig-designation">{{ $releasedBy['designation'] ?: ($releasedBy['position'] ?? '') }}</td>
                            <td class="dr-sig-transport">
                                <div class="dr-sig-transport-line">
                                    <span class="dr-sig-transport-label">CONTACT NO.:</span>
                                    @php $driverContact = $tracking['driver_contact'] ?? ($tracking['driver_contact_number'] ?? null); @endphp
                                    @if (! empty($driverContact))
                                        <span class="dr-sig-transport-value">{!! nl2br(e((string) $driverContact)) !!}</span>
                                    @endif
                                    @if (! empty($tracking['escort_contact_number']))
                                        <span class="dr-sig-transport-value"><br>ESCORT: {!! nl2br(e((string) $tracking['escort_contact_number'])) !!}</span>
                                    @endif
                                </div>
                            </td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="dr-sig-row-label dr-sig-office-label">OFFICE/ UNIT/<br>SECTION:</td>
                            <td class="dr-sig-office">{{ $issuanceApprovedBy['office'] ?? '' }}</td>
                            <td class="dr-sig-office">{{ $releasedBy['office'] ?? '' }}</td>
                            <td class="dr-sig-transport">
                                <div class="dr-sig-transport-line">
                                    <span class="dr-sig-transport-label">PLATE NO.:</span>
                                    @if (! empty($tracking['vehicle_plate_number']))
                                        <span class="dr-sig-transport-value">{!! nl2br(e(mb_strtoupper((string) $tracking['vehicle_plate_number']))) !!}</span>
                                    @endif
                                </div>
                            </td>
                            <td></td>
                        </tr>
                        <tr class="dr-sig-date-row">
                            <td class="dr-sig-row-label">DATE SIGNED:</td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        <tr>
            <th class="section-title" colspan="15">RECIPIENT'S INFORMATION</th>
        </tr>
        <tr>
            <td class="dr-recipient-label" colspan="5">NAME OF CONTACT PERSON:</td>
            <td class="dr-recipient-value" colspan="10">{{ $form['receiving_representative'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="dr-recipient-label" colspan="5">CONTACT NUMBER:</td>
            <td class="dr-recipient-value" colspan="10">{{ $form['contact_number'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label top" colspan="2">REMARKS:</td>
            <td class="purpose-cell remarks-cell" colspan="13">{{ $warehouseNote !== '' ? $warehouseNote : ($form['remarks'] ?? '') }}</td>
        </tr>
    </tbody>
</table>
<p class="footer">
    PAGE 1 of 1<br>
    DSWD Field Office Caraga, R. Palma Street, Butuan City, Philippines 8600<br>
    Website: http://www.caraga.dswd.gov.ph Tel Nos.: (085) 303-8620 local 238
</p>
</body>
</html>
