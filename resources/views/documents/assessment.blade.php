<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { size: A4 portrait; margin: {{ $pageMargin ?? 18 }}pt; }
* { box-sizing: border-box; }
body { margin: 0; color: #000; font-family: DejaVu Sans, sans-serif; font-size: 6.7pt; line-height: 1; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { border: .75pt solid #000; padding: .7pt 1.5pt; vertical-align: middle; overflow-wrap: normal; word-break: normal; }
th, .label { white-space: nowrap; }
.center { text-align: center; }
.right { text-align: right; }
.bold { font-weight: 700; }
.section { height: 11.5pt; background: #e4e4e4; text-align: center; font-size: 8pt; font-weight: 700; letter-spacing: .05pt; }
.title { height: 17pt; font-family: DejaVu Serif, serif; font-size: 11.5pt; font-weight: 700; text-align: center; }
.header-cell { height: 44pt; padding: 1pt 7pt; border: 0; }
.header-table td { border: 0; padding: 0; }
.dswd-logo { width: 92pt; max-height: 35pt; vertical-align: middle; }
.bp-logo { width: 30pt; max-height: 35pt; margin-left: 6pt; vertical-align: middle; }
.division { white-space: normal; font-family: DejaVu Serif, serif; font-size: 8.7pt; font-weight: 700; text-align: center; line-height: 1.1; }
.meta-block { display: inline-block; margin-top: 2pt; text-align: left; }
.form-code { white-space: nowrap; font-family: DejaVu Serif, serif; font-size: 6.7pt; font-style: italic; font-weight: 700; }
.drn { white-space: nowrap; margin-top: 5pt; font-size: 7.5pt; }
.title { white-space: nowrap; border-top: 0; border-right: 0; border-left: 0; }
.label { font-weight: 700; }
.request-row { height: 10pt; }
.item-row { height: 10pt; }
.validation-row { height: 10pt; }
.blank-data { height: 11pt; }
.batch-row { height: 11pt; }
.delivery-instruction { height: 18pt; font-size: 6.5pt; line-height: 1.08; text-align: center; }
.delivery-choice { height: 10pt; text-align: center; font-weight: 700; }
.small { font-size: 6.7pt; }
.mark { text-align: center; font-family: DejaVu Sans, sans-serif; font-size: 10pt; font-weight: 700; }
.recommendation-body { height: 115pt; vertical-align: top; padding: 0; }
.recommendation-items { height: auto; }
.recommendation-items td { height: 14pt; }
.narrative { height: 115pt; padding: 3pt; vertical-align: top; font-size: 6.7pt; line-height: 1; text-align: justify; }
.signature-cell { height: 58pt; vertical-align: top; padding: 2pt; }
.signature { padding-top: 21pt; text-align: center; line-height: 1.05; }
.other-remarks { height: 12pt; vertical-align: top; }
.approved { height: 88pt; text-align: center; vertical-align: top; padding-top: 2pt; }
.embedded-grid { padding: 0; }
.assessment-columns col.unit { width: 11.11%; }
.assessment-columns col.description { width: 19.45%; }
.assessment-columns col.quantity { width: 7.22%; }
.assessment-columns col.remarks { width: 62.22%; }
.assessment-columns td, .assessment-columns th { overflow-wrap: break-word; word-break: normal; }
.recommendation-layout { table-layout: fixed; }
.recommendation-layout td { vertical-align: top; }
.recommendation-detail { width: 38%; padding: 0; }
.recommendation-narrative { width: 62%; }
.recommendation-detail table { table-layout: fixed; }
.recommendation-detail-header { width: 38%; padding: 0; }
.recommendation-detail-header table { table-layout: fixed; }
.recommendation-layout tr { page-break-inside: avoid; }
</style>
</head>
<body>
@php
    $meta = $request->assessment_form_data ?? [];
    $previousRows = array_pad(array_slice($meta['previous_augmentations'] ?? [], 0, 3), 3, ['unit' => '', 'description' => '', 'quantity' => '', 'remarks' => '']);
    $deliveryRows = array_pad(array_slice($meta['delivery_batches'] ?? [], 0, 5), 5, ['quantity' => '', 'date' => '', 'available' => '', 'details' => '']);
    $isDisaster = ($meta['request_type'] ?? '') === 'Disaster';
    $hasPrevious = ($meta['has_previous_augmentation'] ?? false) === true;
    $provideAugmentation = ($meta['provide_augmentation'] ?? false) === true;
    $reviewed = explode('|', $meta['reviewed_by'] ?? '', 2);
    $approved = explode('|', $meta['approved_by'] ?? '', 2);
    $assessmentNarrative = \App\Support\AssessmentNarrative::sanitize($request->recommendations);
    $affectedFamilies = max(0, (int) ($request->affected_families ?? 0));
    $affectedPersons = max(0, (int) ($meta['affected_persons'] ?? 0));
    $familyWord = $affectedFamilies === 1 ? 'family' : 'families';
    $personWord = $affectedPersons === 1 ? 'person' : 'persons';
    $narrativeWordCount = str_word_count(strip_tags((string) $assessmentNarrative));
    $marginPenalty = max(0, ((int) ($pageMargin ?? 18)) - 18) * 0.45;
    $recommendationHeight = max(112, min(168, 174 - ($narrativeWordCount * 0.22) - $marginPenalty));
    $narrativeFontSize = $narrativeWordCount > 230 ? 6.1 : ($narrativeWordCount > 180 ? 6.35 : 6.7);
@endphp
<table class="form">
    <tr>
        <td colspan="12" class="header-cell">
            <table class="header-table">
                <tr>
                    <td style="width:42%; vertical-align:middle">
                        <img class="dswd-logo" src="{{ public_path('images/dswd_logo_3.png') }}">
                        <img class="bp-logo" src="{{ public_path('images/Bagong_PilipinasTransparent.png') }}">
                    </td>
                    <td style="width:58%; vertical-align:middle">
                        <div class="division">DISASTER RESPONSE MANAGEMENT DIVISION</div>
                        <div class="center"><div class="meta-block"><div class="form-code">DSWD-DRMG-GF-001 | REV 00 | 21 MAR 2022</div><div class="drn"><b>DRN:</b> {{ $request->assessment_drn }}</div></div></div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr><th colspan="12" class="title">FNI ASSESSMENT AND DELIVERY FORM</th></tr>
    <tr class="request-row"><td colspan="8"><b>RIS Number:</b></td><td colspan="4"><b>Assessment Date:</b> {{ filled($meta['assessment_date'] ?? null) ? date('F j, Y', strtotime($meta['assessment_date'])) : now()->format('F j, Y') }}</td></tr>
    <tr class="request-row"><td colspan="2" class="label">Requesting Party</td><td colspan="10">{{ $request->requesting_agency }}</td></tr>
    <tr class="request-row">
        <td colspan="2" class="label">Purpose</td>
        <td>Disaster</td><td class="mark">{!! $isDisaster ? '&#10003;' : '' !!}</td>
        <td colspan="2" class="label">Type of Disaster</td><td colspan="3">{{ $isDisaster ? $request->incident?->name : '' }}</td>
        <td colspan="3">{{ $meta['response_purpose'] ?? $request->purpose }}</td>
    </tr>
    <tr class="request-row"><td colspan="2" class="label">Date of Request</td><td colspan="10">{{ optional($request->date_requested)->format('F j, Y') }}</td></tr>

    <tr><th colspan="5" class="section">DETAILS OF REQUEST</th><th colspan="7" class="section">AVAILABILITY OF STOCKPILE</th></tr>
    <tr class="item-row"><th colspan="4">Description</th><th>Quantity</th><th colspan="2">Procured</th><th colspan="2">Donated</th><th colspan="3">Quantity</th></tr>
    @foreach($request->items as $item)
        <tr class="item-row"><td colspan="4">{{ $item->item_name }}</td><td class="center">{{ number_format((float) $item->requested_quantity, 0) }}</td><td colspan="2" class="center">—</td><td colspan="2" class="center">—</td><td colspan="3" class="center">{{ $item->available_quantity !== null ? number_format((float) $item->available_quantity, floor((float) $item->available_quantity) == (float) $item->available_quantity ? 0 : 2) : '—' }}</td></tr>
    @endforeach

    <tr><th colspan="12" class="section">ASSESSMENT AND VALIDATION</th></tr>
    <tr class="validation-row"><td colspan="12">Date of Disaster Occurrence: {{ optional($request->incident?->incident_date)->format('F j, Y') }}</td></tr>
    <tr class="validation-row"><td colspan="6">Actual Affected Families: {{ number_format($affectedFamilies) }} {{ $familyWord }} ({{ number_format($affectedPersons) }} {{ $personWord }})</td><td colspan="6">No. of Families Served: {{ is_numeric($meta['families_served'] ?? null) ? number_format((float) $meta['families_served']) : ($meta['families_served'] ?? '') }}</td></tr>
    <tr class="validation-row"><td colspan="6">Source of Information: {{ $meta['information_source'] ?? '' }}</td><td colspan="6">Date of Information: {{ filled($meta['information_date'] ?? null) ? date('F j, Y', strtotime($meta['information_date'])) : '' }}</td></tr>

    <tr><th colspan="12" class="section">PREVIOUS AUGMENTATION</th></tr>
    <tr class="validation-row"><td colspan="4">With previous augmentation?</td><td>YES</td><td class="mark">{!! $hasPrevious ? '&#10003;' : '' !!}</td><td>NO</td><td class="mark">{!! ! $hasPrevious ? '&#10003;' : '' !!}</td><td colspan="4"></td></tr>
    <tr class="validation-row"><td colspan="12">Details of previous augmentation (Indicate Month and Year), if any:</td></tr>
    <tr><td colspan="12" class="embedded-grid">
        <table class="assessment-columns">
            <colgroup><col class="unit"><col class="description"><col class="quantity"><col class="remarks"></colgroup>
            <tr><th>UNIT</th><th>DESCRIPTION</th><th>QUANTITY</th><th>REMARKS</th></tr>
            @foreach($previousRows as $row)
                <tr class="blank-data">
                    <td>{!! filled($row['unit'] ?? null) ? e($row['unit']) : '&nbsp;' !!}</td>
                    <td>{!! filled($row['description'] ?? null) ? e($row['description']) : '&nbsp;' !!}</td>
                    <td class="center">{!! is_numeric($row['quantity'] ?? null) ? e(number_format((float) $row['quantity'])) : '&nbsp;' !!}</td>
                    <td>{!! filled($row['remarks'] ?? null) ? e($row['remarks']) : '&nbsp;' !!}</td>
                </tr>
            @endforeach
        </table>
    </td></tr>

    <tr><th colspan="12" class="section">DELIVERY / HAULING DETAILS<br><span class="small"><i>(use separate sheet if necessary)</i></span></th></tr>
    <tr>
        <th rowspan="2">Batch</th>
        <th rowspan="2" colspan="2">Quantity</th>
        <th rowspan="2" colspan="2">Date</th>
        <th colspan="7" class="delivery-instruction">If YES, indicate which vehicle will be used for hauling/pickup;<br>If NO, put projected date of transportation asset availability</th>
    </tr>
    <tr class="delivery-choice"><th>YES</th><th>NO</th><th colspan="5"></th></tr>
    @foreach($deliveryRows as $index => $row)
        <tr class="batch-row">
            <td>Batch {{ $index + 1 }}</td>
            <td colspan="2" class="center">{!! is_numeric($row['quantity'] ?? null) ? e(number_format((float) $row['quantity'])) : '&nbsp;' !!}</td>
            <td colspan="2" class="center">{!! filled($row['date'] ?? null) ? e(date('F j, Y', strtotime($row['date']))) : '&nbsp;' !!}</td>
            <td class="mark">{!! ($row['available'] ?? '') === 'YES' ? '&#10003;' : '' !!}</td>
            <td class="mark">{!! ($row['available'] ?? '') === 'NO' ? '&#10003;' : '' !!}</td>
            <td colspan="5">{!! filled($row['details'] ?? null) ? e($row['details']) : '&nbsp;' !!}</td>
        </tr>
    @endforeach

    <tr><th colspan="12" class="section">RECOMMENDATION</th></tr>
    <tr class="validation-row"><td colspan="4">Provide Augmentation?</td><td>YES</td><td class="mark">{!! $provideAugmentation ? '&#10003;' : '' !!}</td><td>NO</td><td class="mark">{!! ! $provideAugmentation ? '&#10003;' : '' !!}</td><td colspan="4"></td></tr>
    <tr><td colspan="12" class="embedded-grid">
        <table class="recommendation-layout">
            <colgroup><col style="width:38%"><col style="width:62%"></colgroup>
            <tr>
                <th class="recommendation-detail-header">
                    <table>
                        <colgroup><col style="width:29%"><col style="width:53%"><col style="width:18%"></colgroup>
                        <tr><th>UNIT</th><th>DESCRIPTION</th><th>QUANTITY</th></tr>
                    </table>
                </th>
                <th>REMARKS / ASSESSMENT</th>
            </tr>
            <tr>
                <td class="recommendation-body recommendation-detail" style="height:{{ $recommendationHeight }}pt">
                    <table class="recommendation-items">
                        <colgroup><col style="width:29%"><col style="width:53%"><col style="width:18%"></colgroup>
                        @foreach($request->items as $item)
                            <tr><td class="center bold">{{ strtoupper($item->unit) }}</td><td class="center">{{ $item->item_name }}</td><td class="center">{{ number_format((float) $item->requested_quantity, 0) }}</td></tr>
                        @endforeach
                    </table>
                </td>
                <td class="narrative recommendation-narrative" style="height:{{ $recommendationHeight }}pt; font-size:{{ $narrativeFontSize }}pt">{!! nl2br(e($assessmentNarrative)) !!}</td>
            </tr>
        </table>
    </td></tr>
    <tr>
        <td colspan="6" class="signature-cell">Prepared by:<div class="signature"><b>{{ \Illuminate\Support\Str::upper(trim((string) ($meta['prepared_by'] ?? $request->assigned_social_worker ?? ''))) }}</b><br>{{ collect([$meta['prepared_by_position'] ?? null, $meta['prepared_by_designation'] ?? null])->filter()->unique()->implode(' / ') }}<br>Date / Time: {{ filled($meta['prepared_at'] ?? null) ? date('F j, Y, g:i A', strtotime($meta['prepared_at'])) : '' }}</div></td>
        <td colspan="6" class="signature-cell">Reviewed by:<div class="signature"><b>{{ $reviewed[0] ?? '' }}</b><br>{{ $reviewed[1] ?? '' }}<br>Date / Time: ____________________</div></td>
    </tr>
    <tr><td colspan="12" class="other-remarks"><b>Other Remarks:</b><br>{{ $request->remarks }}</td></tr>
    <tr><td colspan="12" class="approved"><b>Approved by:</b><div class="signature" style="padding-top:42pt"><b>{{ $approved[0] ?? '' }}</b><br>{{ $approved[1] ?? '' }}<br>Date / Time: ____________________</div></td></tr>
</table>
</body>
</html>
