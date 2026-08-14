<!doctype html>
<html>
<head>
<meta charset="utf-8">
@php
    $meta = $request->assessment_form_data ?? [];
    $hasPrevious = ($meta['has_previous_augmentation'] ?? false) === true;
    $previousFilled = collect($meta['previous_augmentations'] ?? [])
        ->filter(fn ($row) => collect((array) $row)->contains(fn ($value) => filled($value)))
        ->take(3)
        ->values()
        ->all();
    $previousTarget = $hasPrevious ? max(count($previousFilled), 1) : 1;
    $previousRows = array_pad($previousFilled, min(3, $previousTarget), ['unit' => '', 'description' => '', 'quantity' => '', 'remarks' => '']);

    $deliveryFilled = collect($meta['delivery_batches'] ?? [])
        ->filter(fn ($row) => collect((array) $row)->contains(fn ($value) => filled($value)))
        ->take(5)
        ->values()
        ->all();
    $deliveryTarget = min(5, max(count($deliveryFilled), 2));
    $deliveryRows = array_pad($deliveryFilled, $deliveryTarget, ['quantity' => '', 'date' => '', 'available' => '', 'details' => '']);

    $isDisaster = ($meta['request_type'] ?? '') === 'Disaster';
    $provideAugmentation = ($meta['provide_augmentation'] ?? false) === true;
    $reviewed = explode('|', $meta['reviewed_by'] ?? '', 2);
    $approved = explode('|', $meta['approved_by'] ?? '', 2);
    $assessmentNarrative = \App\Support\AssessmentNarrative::sanitize($request->recommendations);
    $affectedFamilies = max(0, (int) ($request->affected_families ?? 0));
    $affectedPersons = max(0, (int) $request->resolvedAffectedPersons());
    $incidentRows = collect($meta['incidents'] ?? [])->filter(fn ($row) => is_array($row) && filled($row['incident_type'] ?? null))->values();
    $incidentSummary = $incidentRows->map(function ($row, $index) {
        $date = filled($row['occurrence_at'] ?? null) ? date('M j, Y', strtotime($row['occurrence_at'])) : 'date not encoded';
        $place = collect([$row['barangay'] ?? null, $row['city_municipality'] ?? null])->filter()->implode(', ');
        $population = number_format((int) ($row['affected_families'] ?? 0)).' '.((int) ($row['affected_families'] ?? 0) === 1 ? 'family' : 'families');
        return ($index + 1).'. '.($row['incident_type'] ?? 'Incident').' — '.$date.($place !== '' ? ', '.$place : '').' ('.$population.')';
    })->implode('; ');
    $familyWord = $affectedFamilies === 1 ? 'family' : 'families';
    $personWord = $affectedPersons === 1 ? 'person' : 'persons';
    $narrativeWordCount = str_word_count(strip_tags((string) $assessmentNarrative));
    $itemCount = max(1, (int) $request->items->count());
    $compact = $itemCount >= 5;
    $veryCompact = $itemCount >= 8;
    $marginPenalty = max(0, ((int) ($pageMargin ?? 18)) - 18) * 0.45;

    $bodyFontSize = $veryCompact ? 6.2 : ($compact ? 6.4 : 6.7);
    $cellPaddingY = $veryCompact ? 0.35 : ($compact ? 0.5 : 0.7);
    $sectionHeight = $veryCompact ? 9.5 : ($compact ? 10.5 : 11.5);
    $sectionFontSize = $veryCompact ? 7.2 : ($compact ? 7.5 : 8);
    $titleHeight = $compact ? 14.5 : 17;
    $titleFontSize = $compact ? 10.5 : 11.5;
    $headerHeight = $compact ? 38 : 44;
    $metaRowHeight = $veryCompact ? 8.5 : ($compact ? 9 : 10);
    $detailItemRowHeight = $veryCompact ? 8 : ($compact ? 8.5 : 10);
    $blankRowHeight = $veryCompact ? 8.5 : ($compact ? 9.5 : 11);
    $deliveryInstructionHeight = $compact ? 14 : 18;
    $fniItemRowHeight = $veryCompact ? 8 : ($compact ? 9.5 : ($itemCount >= 3 ? 11.5 : 13.5));
    $fniItemPaddingY = $veryCompact ? 0.2 : 0.35;
    $fniItemFontSize = $veryCompact ? 5.9 : ($compact ? 6.15 : 6.5);
    $itemsNeeded = ($itemCount * $fniItemRowHeight) + 2;
    $narrativeFloor = max(68, min(128, 148 - ($narrativeWordCount * 0.24) - $marginPenalty - max(0, $itemCount - 3) * 3.2));
    $recommendationHeight = max($itemsNeeded, $narrativeFloor);
    if ($itemCount >= 6) {
        $recommendationHeight = min($recommendationHeight, max($itemsNeeded, 96));
    }
    $narrativeFontSize = $narrativeWordCount > 230 ? 5.85 : ($narrativeWordCount > 180 ? 6.1 : ($compact ? 6.25 : 6.55));
    $signatureHeight = $veryCompact ? 44 : ($compact ? 48 : 56);
    $signaturePadTop = $veryCompact ? 14 : ($compact ? 16 : 20);
    $otherRemarksHeight = $compact ? 10 : 12;
    $approvedHeight = $veryCompact ? 62 : ($compact ? 70 : 84);
    $approvedSignaturePad = max(28, $approvedHeight - 28);
@endphp
<style>
@page { size: A4 portrait; margin: {{ $pageMargin ?? 18 }}pt; }
* { box-sizing: border-box; }
body { margin: 0; color: #000; font-family: DejaVu Sans, sans-serif; font-size: {{ $bodyFontSize }}pt; line-height: 1; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { border: .75pt solid #000; padding: {{ $cellPaddingY }}pt 1.4pt; vertical-align: middle; overflow-wrap: normal; word-break: normal; }
th, .label { white-space: nowrap; }
.center { text-align: center; }
.right { text-align: right; }
.bold { font-weight: 700; }
.section { height: {{ $sectionHeight }}pt; background: #e4e4e4; text-align: center; font-size: {{ $sectionFontSize }}pt; font-weight: 700; letter-spacing: .05pt; }
.title { height: {{ $titleHeight }}pt; font-family: DejaVu Serif, serif; font-size: {{ $titleFontSize }}pt; font-weight: 700; text-align: center; white-space: nowrap; border-top: 0; border-right: 0; border-left: 0; }
.header-cell { height: {{ $headerHeight }}pt; padding: 1pt 6pt; border: 0; }
.header-table td { border: 0; padding: 0; }
.dswd-logo { width: 88pt; max-height: 32pt; vertical-align: middle; }
.bp-logo { width: 28pt; max-height: 32pt; margin-left: 5pt; vertical-align: middle; }
.division { white-space: normal; font-family: DejaVu Serif, serif; font-size: 8.2pt; font-weight: 700; text-align: center; line-height: 1.05; }
.meta-block { display: inline-block; margin-top: 1pt; text-align: left; }
.form-code { white-space: nowrap; font-family: DejaVu Serif, serif; font-size: 6.4pt; font-style: italic; font-weight: 700; }
.drn { white-space: nowrap; margin-top: 3pt; font-size: 7.1pt; }
.label { font-weight: 700; }
.request-row { height: {{ $metaRowHeight }}pt; }
.item-row { height: {{ $detailItemRowHeight }}pt; }
.validation-row { height: {{ $metaRowHeight }}pt; }
.blank-data { height: {{ $blankRowHeight }}pt; }
.batch-row { height: {{ $blankRowHeight }}pt; }
.delivery-instruction { height: {{ $deliveryInstructionHeight }}pt; font-size: 6.1pt; line-height: 1.05; text-align: center; }
.delivery-choice { height: {{ $metaRowHeight }}pt; text-align: center; font-weight: 700; }
.small { font-size: 6.3pt; }
.mark { text-align: center; font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; font-weight: 700; }
.recommendation-body { height: {{ $recommendationHeight }}pt; vertical-align: top; padding: 0; }
.recommendation-items { height: auto; }
.recommendation-items td { height: {{ $fniItemRowHeight }}pt; padding: {{ $fniItemPaddingY }}pt 1pt; font-size: {{ $fniItemFontSize }}pt; }
.narrative { height: {{ $recommendationHeight }}pt; padding: 2pt 2.5pt; vertical-align: top; font-size: {{ $narrativeFontSize }}pt; line-height: 1; text-align: justify; }
.signature-cell { height: {{ $signatureHeight }}pt; vertical-align: top; padding: 1.5pt; }
.signature { padding-top: {{ $signaturePadTop }}pt; text-align: center; line-height: 1.05; }
.other-remarks { height: {{ $otherRemarksHeight }}pt; vertical-align: top; }
.approved { height: {{ $approvedHeight }}pt; text-align: center; vertical-align: top; padding-top: 1.5pt; }
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
        <tr class="item-row"><td colspan="4">{{ $item->item_name }}</td><td class="center">{{ number_format((int) $item->requested_quantity, 0) }}</td><td colspan="2" class="center">—</td><td colspan="2" class="center">—</td><td colspan="3" class="center">{{ $item->available_quantity !== null ? number_format((int) $item->available_quantity, 0) : '—' }}</td></tr>
    @endforeach

    <tr><th colspan="12" class="section">ASSESSMENT AND VALIDATION</th></tr>
    <tr class="validation-row"><td colspan="12"><b>{{ $incidentRows->count() > 1 ? 'Separate Incidents Covered:' : 'Date of Disaster Occurrence:' }}</b> {{ $incidentRows->isNotEmpty() ? $incidentSummary : optional($request->incident?->incident_date)->format('F j, Y') }}</td></tr>
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
                    <td class="center">{!! is_numeric($row['quantity'] ?? null) ? e(number_format((int) $row['quantity'], 0)) : '&nbsp;' !!}</td>
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
            <td colspan="2" class="center">{!! is_numeric($row['quantity'] ?? null) ? e(number_format((int) $row['quantity'], 0)) : '&nbsp;' !!}</td>
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
                            <tr><td class="center bold">{{ strtoupper($item->unit) }}</td><td class="center">{{ $item->item_name }}</td><td class="center">{{ number_format((int) $item->requested_quantity, 0) }}</td></tr>
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
    <tr><td colspan="12" class="approved"><b>Approved by:</b><div class="signature" style="padding-top:{{ $approvedSignaturePad }}pt"><b>{{ $approved[0] ?? '' }}</b><br>{{ $approved[1] ?? '' }}<br>Date / Time: ____________________</div></td></tr>
</table>
</body>
</html>
