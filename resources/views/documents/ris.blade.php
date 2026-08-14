@php
    /** @var \App\Services\RisDrDocumentPdfService $pdf */
    $pdf = app(\App\Services\RisDrDocumentPdfService::class);
    $items = $items ?? [];
    $form = $form ?? [];
    $tracking = $tracking ?? [];
    $signatories = $signatories ?? [];
    // Match OfficialRisCopy: pad each copy's items table to half-page fill (dual-copy A4).
    $risMinItemRows = 9;
    $warehouseNote = $pdf->withdrawalRemarks($items);
    $requestedBy = $signatories['requested_by'] ?? ['name' => '', 'designation' => '', 'position' => ''];
    $approvedBy = $signatories['approved_by'] ?? ['name' => '', 'designation' => '', 'position' => ''];
    $issuedBy = $signatories['issued_by'] ?? ['name' => '', 'designation' => '', 'position' => ''];
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
/*
 * Layout source of truth: resources/js/Components/RrosOfficialDocuments.jsx
 * (RROS_OFFICIAL_PRINT_CSS + OfficialRisCopy / PrintableRisDr dual-copy).
 * Column % and section order match React.
 */
/*
 * Dual-copy equal halves — official sheet @page 0.25in all sides:
 * Usable height = 297mm − 12.7mm = 284.3mm.
 * Cut band = 3.6mm → each half = 140.35mm.
 * DomPDF: absolute half boxes (table %/mm row heights either spill to page 2
 * or pack by content and leave the cut above center). Cut line at top:142.15mm
 * = content midpoint = page midpoint with equal T/B margins.
 * React preview uses the same 140.35mm halves (RrosOfficialDocuments.jsx).
 */
/* DomPDF applies @page margins on the html/-dompdf-page frame — never set html { margin: 0 }. */
@page { margin: 0.25in; }
* { box-sizing: border-box; }
html { color: #111; font-family: DejaVu Sans, sans-serif; font-size: 6pt; }
body { position: relative; height: 284.3mm; margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 6pt; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: 0.55pt solid #111; padding: 0.7pt 1.8pt; vertical-align: middle; font-size: 6pt; line-height: 1.08; word-wrap: break-word; overflow: visible; }
.no-border, .no-border td, .no-border th { border: 0 !important; }
.doc-title { position: relative; text-align: center; font-family: DejaVu Serif, serif; font-size: 9pt; font-weight: 700; padding: 1.5pt; letter-spacing: 0.1pt; }
.section-title { background: #d9e2ef; text-align: center; font-weight: 700; font-size: 6.5pt; letter-spacing: 0.15pt; }
.label { font-weight: 700; white-space: nowrap; text-align: left; }
.center { text-align: center; }
.right { text-align: right; }
.left { text-align: left; }
.top { vertical-align: top; }
.tiny { font-size: 5.2pt; }
.remarks-head { white-space: nowrap; font-size: 6pt; letter-spacing: 0.02em; }
.remarks-cell { white-space: pre-line; vertical-align: top; word-wrap: break-word; overflow: visible; font-size: 5pt; line-height: 1.1; }
.item-name-cell { overflow: visible; white-space: normal; word-wrap: break-word; }
.sig-name-cell { overflow: visible; white-space: normal; word-wrap: break-word; }
.signature-space td { height: 28pt; min-height: 28pt; }
.footer { text-align: center; font-family: DejaVu Serif, serif; font-size: 4.8pt; line-height: 1.1; padding: 1pt 2pt 0; border: 0; margin: 0; }
/* Match Assessment letterhead scale (committed assessment.blade.php). */
.official-logo { width: 88pt; max-height: 32pt; height: auto; vertical-align: middle; }
.official-logo.bp { width: 28pt; max-height: 32pt; height: auto; margin-left: 5pt; vertical-align: middle; }
.header-division { font-family: DejaVu Serif, serif; font-size: 7pt; font-weight: 700; text-align: center; line-height: 1.05; }
.drn-cell { font-size: 6pt; font-weight: 700; text-align: right; padding: 1pt 4pt; vertical-align: middle; }
.appendix { position: absolute; top: 1pt; right: 4pt; font-size: 4.8pt; font-style: italic; font-weight: 600; white-space: nowrap; line-height: 1.1; }
.ris-number-box { background: #fde047; }
/* Open top/sides on logo band (both dual copies); keep bottom to separate from DRN. */
.ris-brand-header {
  padding: 1.5pt 3pt;
  border-top: 0 !important;
  border-left: 0 !important;
  border-right: 0 !important;
  border-bottom: 0.55pt solid #111 !important;
}
.item-row td { height: 5.5pt; }
.purpose-cell { font-size: 5.5pt; line-height: 1.05; vertical-align: top; }
.check { font-size: 7.5pt; font-weight: 700; }
/* Absolute equal halves — blank rows fill inside each box and cannot shift the cut. */
.ris-copy {
  position: absolute;
  left: 0;
  right: 0;
  height: 140.35mm;
  overflow: hidden;
  margin: 0;
  padding-top: 4pt;
}
.ris-copy-top { top: 0; }
.ris-copy-bottom { top: 143.95mm; }
/* Content midpoint (142.15mm) + 0.25in top margin = page vertical center. */
.copy-divider {
  position: absolute;
  top: 142.15mm;
  left: 0;
  right: 0;
  height: 0;
  margin: 0;
  padding: 0;
  border: 0;
  border-top: 0.55pt dashed #111;
  font-size: 0;
  line-height: 0;
}
.ris-meta-pair .label { margin-right: 2pt; }
/* DomPDF drops <col>; fixed layout uses row 0 only — zero-height colspec carries % widths */
.ris-colspec td { height: 0; max-height: 0; padding: 0; border: 0; font-size: 0; line-height: 0; overflow: hidden; }
</style>
</head>
<body>
@php
    // Delivery meta labels colspan 2 = 14% (fits "Contact Number:" + pad); values colspan 3 = 36%.
    // Visible: Stock6 Unit8 Desc36 Qty7 Yes4 No4 Qty7 Remarks28 (Desc = 12+12+12)
    $risColWidths = [6, 8, 12, 12, 12, 7, 4, 4, 7, 28];
    // Signatory grid: label 13% (Printed Name: one line), remaining split equally across 4 roles
    $risSigColWidths = [13, 21.75, 21.75, 21.75, 21.75];
@endphp
@foreach ([1, 2] as $copyIndex)
@php
    // Blank rows fill within the fixed 140.35mm half; cut-line Y is absolute.
    $blankRows = max(0, $risMinItemRows - count($items));
    $rows = array_merge($items, array_fill(0, $blankRows, null));
    $copyClass = $copyIndex === 1 ? 'ris-copy ris-copy-top' : 'ris-copy ris-copy-bottom';
@endphp
<div class="{{ $copyClass }}">
<table>
    {{-- STOCK6 | UNIT8 | DESC12+12+12=36 | QTY7 | Yes4 | No4 | QTY7 | REMARKS28 | delivery labels 14% --}}
    <colgroup>
        @foreach ($risColWidths as $w)<col style="width:{{ $w }}%">@endforeach
    </colgroup>
    <tbody>
        <tr class="ris-colspec">
            @foreach ($risColWidths as $w)<td style="width:{{ $w }}%">&nbsp;</td>@endforeach
        </tr>
        <tr>
            <td class="ris-brand-header" colspan="10">
                <table class="no-border">
                    <tr>
                        <td style="width:42%; border:0; vertical-align:middle;">
                            <img class="official-logo" src="{{ public_path('images/dswd_logo_3.png') }}">
                            <img class="official-logo bp" src="{{ public_path('images/Bagong_PilipinasTransparent.png') }}">
                        </td>
                        <td class="header-division" style="border:0; vertical-align:middle;">
                            DISASTER RESPONSE MANAGEMENT DIVISION<br>FIELD OFFICE CARAGA
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="drn-cell" colspan="10"><b>DRN:</b> {{ $tracking['ris_drn'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="doc-title" colspan="10">
                REQUISITION AND ISSUANCE SLIP (RIS)
                <span class="appendix">Appendix 63</span>
            </td>
        </tr>
        <tr>
            <td class="ris-meta-pair" colspan="6"><span class="label">Office / Bureau:</span> <b>DSWD Caraga</b></td>
            <td class="ris-meta-pair" colspan="4"><span class="label">Fund Cluster:</span> <b>101</b></td>
        </tr>
        <tr>
            <td class="ris-meta-pair" colspan="6"><span class="label">Division:</span> DRMD</td>
            <td class="ris-meta-pair" colspan="4"><span class="label">Responsibility Center Code:</span></td>
        </tr>
        <tr>
            <td class="ris-meta-pair" colspan="6"><span class="label">Address:</span> R. Palma Street, Butuan City</td>
            <td class="ris-meta-pair ris-number-box" colspan="4"><span class="label">RIS No.:</span> <b>{{ $form['ris_number'] ?? '' }}</b></td>
        </tr>
        <tr>
            <th class="section-title" colspan="6"><i>Requisition</i></th>
            <th class="section-title" colspan="4"><i>Stocks Available?</i></th>
        </tr>
        <tr>
            <th class="center">Stock No.</th>
            <th class="center">Unit</th>
            <th class="center" colspan="3">Description</th>
            <th class="center">Quantity</th>
            <th class="center">Yes</th>
            <th class="center">No</th>
            <th class="center">Quantity</th>
            <th class="center remarks-head">REMARKS</th>
        </tr>
        @foreach ($rows as $index => $item)
            @php
                $stock = ($is_google_sheet_transaction ?? false)
                    ? ['yes' => '', 'no' => '', 'quantity' => '']
                    : $pdf->stockAvailableMark($item);
            @endphp
            <tr class="item-row">
                <td class="center">{{ $item ? $index + 1 : '' }}</td>
                <td class="center">{{ $item['unit'] ?? '' }}</td>
                <td class="left item-name-cell" colspan="3">{{ $item['item_name'] ?? '' }}</td>
                <td class="center">{{ $item ? $pdf->formatQuantity($item['quantity'] ?? 0) : '' }}</td>
                <td class="center check">{{ $stock['yes'] }}</td>
                <td class="center check">{{ $stock['no'] }}</td>
                <td class="center">{{ $stock['quantity'] }}</td>
                <td class="tiny left remarks-cell">{{ $item && !($is_google_sheet_transaction ?? false) ? $pdf->documentRisItemRemarks($item['remarks'] ?? null) : '' }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="label" colspan="2">Delivery Site:</td>
            <td colspan="3">{{ $form['delivery_site'] ?? '' }}</td>
            <th class="center" colspan="5">Returned / Cancelled Items:</th>
        </tr>
        <tr>
            <td class="label" colspan="2">Contact Person:</td>
            <td colspan="3">{{ $form['receiving_representative'] ?? '' }}</td>
            <th class="center">Date</th>
            <th class="center" colspan="2">Particular</th>
            <th class="center">Quantity</th>
            <th class="center">Certified by:</th>
        </tr>
        <tr>
            <td class="label" colspan="2">Contact Number:</td>
            <td colspan="3">{{ $form['contact_number'] ?? '' }}</td>
            <td class="center tiny">{{ $pdf->readableDate($tracking['returned_at'] ?? null) }}</td>
            <td class="tiny" colspan="2">{{ $tracking['returned_particulars'] ?? '' }}</td>
            <td class="center">{{ isset($tracking['returned_quantity']) && $tracking['returned_quantity'] !== '' && $tracking['returned_quantity'] !== null ? $pdf->formatQuantity($tracking['returned_quantity']) : '' }}</td>
            <td class="tiny center">{{ $tracking['returned_certified_by'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label" colspan="2">Delivery Date:</td>
            <td colspan="3">{{ $pdf->readableDate($tracking['delivered_at'] ?? ($form['ris_date'] ?? null)) }}</td>
            <td class="label" colspan="5">Remarks: {{ $tracking['returned_reason'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label top" colspan="2">Purpose:</td>
            <td class="purpose-cell" colspan="3">{{ $form['purpose_of_release'] ?? '' }}</td>
            <td class="purpose-cell tiny remarks-cell" colspan="5">{{ $warehouseNote !== '' ? $warehouseNote : ($form['remarks'] ?? '') }}</td>
        </tr>
        <tr>
            <td colspan="10" style="padding:0; border:0;">
                <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
                    <colgroup>
                        @foreach ($risSigColWidths as $w)<col style="width:{{ $w }}%">@endforeach
                    </colgroup>
                    <tbody>
                        {{-- DomPDF fixed-layout reads only row 0 for column % (colgroup is dropped). --}}
                        <tr class="ris-colspec">
                            @foreach ($risSigColWidths as $w)<td style="width:{{ $w }}%">&nbsp;</td>@endforeach
                        </tr>
                        <tr>
                            <th class="label center">Signature:</th>
                            <th class="center">Requested by:</th>
                            <th class="center">Approved by:</th>
                            <th class="center">Issued by:</th>
                            <th class="center">Received by:</th>
                        </tr>
                        <tr class="signature-space"><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr>
                            <td class="label">Printed Name:</td>
                            <td class="center sig-name-cell"><b>{{ $requestedBy['name'] ?? '' }}</b></td>
                            <td class="center sig-name-cell"><b>{{ $approvedBy['name'] ?? '' }}</b></td>
                            <td class="center sig-name-cell"><b>{{ $issuedBy['name'] ?? '' }}</b></td>
                            <td class="center"></td>
                        </tr>
                        <tr>
                            <td class="label">Designation:</td>
                            <td class="center tiny sig-name-cell">{{ $requestedBy['designation'] ?: ($requestedBy['position'] ?? '') }}</td>
                            <td class="center tiny sig-name-cell">{{ $approvedBy['designation'] ?: ($approvedBy['position'] ?? '') }}</td>
                            <td class="center tiny sig-name-cell">{{ $issuedBy['designation'] ?: ($issuedBy['position'] ?? '') }}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="label">Date:</td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </tbody>
</table>
<p class="footer">
    PAGE 1 of 1<br>
    DSWD Field Office Caraga, R. Palma Street, Butuan City, Philippines 8600<br>
    Website: http://www.caraga.dswd.gov.ph Tel Nos.: (085) 303-8620 local 238
</p>
</div>
@if ($copyIndex === 1)
<div class="copy-divider"></div>
@endif
@endforeach
</body>
</html>
