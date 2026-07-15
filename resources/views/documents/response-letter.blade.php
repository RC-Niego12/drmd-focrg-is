<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { size: A4 portrait; margin: 54pt 72pt 54pt; }
body { margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; line-height: 1.55; }
.header-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 18pt; }
.header-table td { border: 0; padding: 0; vertical-align: middle; }
.header-cell { height: 44pt; padding: 1pt 7pt; border: 0; }
.dswd-logo { width: 92pt; max-height: 35pt; vertical-align: middle; }
.bp-logo { width: 30pt; max-height: 35pt; margin-left: 6pt; vertical-align: middle; }
.division { white-space: normal; font-family: DejaVu Serif, serif; font-size: 8.7pt; font-weight: 700; text-align: center; line-height: 1.1; }
.center { text-align: center; }
.meta-block { display: inline-block; margin-top: 2pt; text-align: left; }
.form-code { white-space: nowrap; font-family: DejaVu Serif, serif; font-size: 6.7pt; font-style: italic; font-weight: 700; }
.drn { white-space: nowrap; margin-top: 5pt; font-size: 7.5pt; }
.code { text-align: right; font-family: DejaVu Serif, serif; font-size: 6.5pt; font-style: italic; font-weight: 700; white-space: nowrap; }
.draft { margin: 0 0 15pt; border: .75pt solid #9f1239; padding: 4pt; color: #9f1239; font-size: 8.5pt; font-weight: 700; text-align: center; }
.date, .recipient { margin: 0 0 15pt; }
p { margin: 0 0 11pt; text-align: justify; }
.items { width: 100%; margin: 12pt 0 15pt; border-collapse: collapse; }
.items th, .items td { border: .75pt solid #333; padding: 5pt 7pt; }
.items th { background: #e8e8e8; text-align: center; }
.qty { width: 22%; text-align: right; white-space: nowrap; }
.unit { width: 18%; white-space: nowrap; }
.signature { margin-top: 42pt; min-height: 78pt; }
.signature-name { margin-top: 33pt; font-weight: 700; text-transform: uppercase; }
.footer { position: fixed; bottom: -30pt; left: 0; right: 0; border-top: .5pt solid #777; padding-top: 4pt; color: #555; font-size: 6.5pt; text-align: center; }
</style>
</head>
<body>
@php
    $meta = $request->assessment_form_data ?? [];
    $areas = collect($meta['affected_areas'] ?? [])->filter();
    $areaText = $areas->isEmpty() ? '' : ($areas->count() <= 5 ? ' affecting '.$areas->implode(', ') : ' affecting '.$areas->count().' identified areas');
    $approvedStatus = in_array($request->status, ['approved', 'partially_approved']);
@endphp
<table class="form">
    <tr>
        <td colspan="12" class="header-cell">
            <table class="header-table">
                <tr>
                    <td style="width:42%; vertical-align:middle">
                    </td>
                    <td style="width:58%; vertical-align:middle">
                        <div class="division">DISASTER RESPONSE MANAGEMENT DIVISION</div>
                        <div class="center"><div class="meta-block"><div class="form-code">DSWD-DRMG-GF-010 | REV 00 | 12 OCT 2021</div><div class="drn"><b>DRN:</b> {{ $request->response_drn }}</div></div></div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@unless($approvedStatus)<div class="draft">DRAFT — FOR REVIEW AND APPROVAL</div>@endunless
<div class="date">{{ now()->format('F j, Y') }}<br>DRN: {{ $request->response_drn ?: '' }}</div>
<div class="recipient"><b>{{ $request->requester ?: $request->requesting_agency }}</b><br>{{ $request->requester_position ?: $request->office_agency_details }}<br>{{ $request->requester_address ?: collect([$request->municipality, $request->province])->filter()->implode(', ') }}</div>
<p><b>ATTENTION:</b>&nbsp;&nbsp;&nbsp;{{ $request->office_agency_details ?: $request->requesting_agency }}</p>
<p>Dear Sir/Madam:</p>
<p>Greetings of service excellence and resilience!</p>
<p>This is in reference to your request for Food and Non-Food Items intended for <b>{{ number_format((int) ($request->affected_families ?? 0)) }}</b> disaster-affected families due to <b>{{ $request->incident?->name ?: 'the reported incident' }}</b>{{ $request->incident?->incident_date ? ', which occurred on '.$request->incident->incident_date->format('F j, Y') : '' }}{{ $areaText }}.</p>
<p>After assessment and validation of the submitted information, the requested augmentation is documented as follows:</p>
<table class="items">
    <tr><th>Food and Non-Food Item</th><th class="qty">Quantity</th><th class="unit">Unit</th></tr>
    @foreach($request->items as $item)
        @php($quantity = $approvedStatus ? ($item->approved_quantity ?: $item->requested_quantity) : $item->requested_quantity)
        @if((float) $quantity > 0)<tr><td>{{ $item->item_name }}</td><td class="qty">{{ number_format((float) $quantity, floor((float) $quantity) == (float) $quantity ? 0 : 2) }}</td><td class="unit">{{ $item->unit }}</td></tr>@endif
    @endforeach
</table>
<p>{{ $approvedStatus ? 'The Regional Resource Operations Section (RROS) will prepare the necessary Requisition and Issuance Slip and coordinate with the requesting party once the documents are complete and the goods are ready for delivery or pick-up.' : 'The requested assistance remains subject to final review, approval, and the completion of the required issuance documents.' }}</p>
<p>For your information. Thank you.</p>
<div class="signature">Very truly yours,<div class="signature-name">MARI-FLOR A. DOLLAGA-LIBANG</div><div>Regional Director</div></div>
<div class="footer">DSWD Field Office Caraga · Disaster Response Management Division</div>
</body>
</html>
