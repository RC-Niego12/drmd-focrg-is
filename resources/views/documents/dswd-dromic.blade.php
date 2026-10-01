<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
@page { size: A4 portrait; margin: 112px 48px 70px; } body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12pt; color: #111; line-height: 1.35; } p { margin:0 0 12pt; text-align:justify; } h1 { font-size: 16pt; text-align:center; line-height:1.2; margin:0 0 2pt; } h2 { font-size:14pt; margin:0 0 6pt; color:#0a2a66; page-break-after:avoid; } h3 { font-size:12pt; margin:0 0 5pt; page-break-after:avoid; } .center { text-align:center; } .muted { color:#6a6a6a; font-size:8pt; } .data { color:#0070c0; font-weight:bold; } .source { color:#0070c0; font-size:8pt; font-style:italic; text-align:right; margin:0 0 12pt; } .note { color:#555; font-size:8pt; font-style:italic; text-align:right; margin:-8pt 0 4pt; } table { width:100%; border-collapse:collapse; font-size:8pt; margin:0 0 12pt; page-break-inside:auto; } thead { display:table-header-group; } tfoot { display:table-footer-group; } th,td { border:1px solid #8ba1bf; padding:5px; } th { background:#0a2a66; color:#fff; text-align:center; vertical-align:middle; } td { text-align:right; } td:first-child { text-align:left; } .annex-wide { table-layout:fixed; font-size:4.1pt; } .annex-wide th,.annex-wide td { padding:2px 1px; line-height:1.1; word-wrap:break-word; white-space:normal; overflow:hidden; vertical-align:middle; } .fni-items { table-layout:fixed; font-size:12pt; page-break-inside:avoid; } .fni-items th,.fni-items td { padding:5px 4px; font-size:12pt; line-height:1.35; word-wrap:break-word; white-space:normal; overflow:hidden; text-align:center; } .fni-items tbody th { background:#eef3f9; color:#111; text-align:left; } .stockpile-summary { table-layout:fixed; font-size:8pt; page-break-inside:avoid; } .stockpile-summary th { text-align:center; vertical-align:middle; text-transform:uppercase; font-weight:bold; padding:5px 3px; font-size:8pt; line-height:1.2; } .stockpile-summary td { text-align:center; vertical-align:middle; font-weight:bold; padding:8px 3px; font-size:8pt; line-height:1.2; white-space:nowrap; } .stockpile-summary td:first-child { text-align:center; white-space:normal; } .region,.province { background:#e8f0fb; font-weight:bold; } .annex { margin:0 0 12pt; page-break-before:auto; page-break-inside:auto; } .annex.keep-together { page-break-inside:avoid; break-inside:avoid-page; } tr { page-break-inside:avoid; } .signatures { margin:12pt 0 0; page-break-inside:avoid; font-size:10pt; } .signatures td { width:33.33%; border:0; padding:0 10pt 0 0; text-align:left; vertical-align:top; } .signatures p { text-align:left; } .signature-space { height:34pt; } .signature-name { font-weight:bold; text-transform:uppercase; margin:0; } .signature-position { margin:0; }
.response-activities { page-break-inside:avoid; break-inside:avoid-page; margin-top:4pt; margin-bottom:7pt; font-size:12pt; line-height:1.35; } .response-activities>thead>tr>th { background:#c6e6ed; color:#111; font-size:12pt; } .response-activities>tbody>tr>td { padding:8px; font-size:12pt; vertical-align:top; } .response-activities ul { margin-top:0; } .response-activities li { text-align:left; vertical-align:top; } .annex table th { background:#c6e6ed; color:#111; border-color:#64748b; } .annex table td { border-color:#8ba1bf; }
</style><style>
header { position: fixed; top: -92px; left: 0; right: 0; height: 76px; border-bottom: 1px solid #666; text-align:left; }
header img { vertical-align:middle; width:auto; } header .dswd { height:58px; } header .dromic { height:40px; margin:0 12px; } header .bagong { height:54px; }
footer { position: fixed; bottom: -50px; left: 0; right: 0; height:36px; border-top: 1px solid #777; padding-top: 5px; color: #707070; font-size: 7.5px; text-align:right; line-height:1.2; }
</style></head><body>
<header><img class="dswd" src="{{ public_path('images/dromic-header-dswd.png') }}" alt="DSWD Field Office Caraga"><img class="dromic" src="{{ public_path('images/dromic-logo.png') }}" alt="DROMIC"><img class="bagong" src="{{ public_path('images/dromic-header-bagong.png') }}" alt="Bagong Pilipinas"></header>
@php
 $meta = $snapshot['metadata'];
 $value = fn ($v, $money = false) => $v === null ? '-' : number_format($v, $money ? 2 : 0);
 $countNoun = fn ($count, string $singular, ?string $plural = null) => (float) $count === 1.0 ? $singular : ($plural ?? $singular.'s');
 $countVerb = fn ($count) => (float) $count === 1.0 ? 'was' : 'were';
 $pluralItem = function (string $name, $quantity): string {
   if ((float) $quantity === 1.0) return $name;
   $words = preg_split('/\s+/', $name); $last = array_pop($words) ?: 'item';
   if (preg_match('/^(rice|water|food|milk|soap|equipment|clothing)$/i', $last)) return trim(implode(' ', [...$words, $last]));
   $plural = preg_match('/[^aeiou]y$/i', $last) ? substr($last, 0, -1).'ies' : (preg_match('/(s|x|z|ch|sh)$/i', $last) ? $last.'es' : (preg_match('/s$/i', $last) ? $last : $last.'s'));
   return trim(implode(' ', [...$words, $plural]));
 };
 $narrativeEdits = $meta['narrative_edits'] ?? [];
 $narrative = function (string $key, string $default, array $values = []) use ($narrativeEdits): string {
   $rendered = e($narrativeEdits[$key] ?? $default);
   foreach ($values as $name => $lockedValue) {
     $rendered = str_replace(e('{{'.$name.'}}'), '<span class="data">'.e($lockedValue).'</span>', $rendered);
   }
   return $rendered;
 };
 $totals = collect($snapshot['tables'])->map(fn ($t) => $t['rows'][0]['values']);
 $sourceLabel = collect($snapshot['sources'])->pluck('municipality')->filter()->unique()->implode('; ');
 $annexTitle = function ($title) {
   $minor = ['a','an','and','as','at','but','by','for','from','in','of','on','or','the','to','with'];
   $acronyms = ['idps'=>'IDPs','ecs'=>'ECs','dswd'=>'DSWD','fni'=>'FNI','fnis'=>'FNIs','lgu'=>'LGU','ngos'=>'NGOs','csos'=>'CSOs','php'=>'PHP'];
   $words = preg_split('/\s+/', trim((string) $title));
   return collect($words)->map(function ($word, $index) use ($words, $minor, $acronyms) {
     if (!preg_match('/[A-Za-z]+/', $word, $match)) return $word;
     $plain = $match[0]; $lower = strtolower($plain);
     $replacement = $acronyms[$lower] ?? (($index > 0 && $index < count($words) - 1 && in_array($lower, $minor, true)) ? $lower : ucfirst($lower));
     return preg_replace('/'.preg_quote($plain, '/').'/', $replacement, $word, 1);
   })->implode(' ');
 };
 $reportTitle = preg_match('/^the\b/i', trim($meta['title'] ?? '')) ? trim($meta['title']) : 'the '.trim($meta['title'] ?? 'Untitled incident');
 $overview = $meta['overview'] ?: 'Situation overview not yet provided.';
 $locationPattern = collect($snapshot['sources'])->map(fn ($source) => preg_quote(trim(($source['municipality'] ?? '').', '.($source['province'] ?? '')), '/'))->filter()->implode('|');
 if ($locationPattern !== '') { $overview = preg_replace('/^\s*(?:'.$locationPattern.')\s*\R+/iu', '', $overview); }
 $overview = preg_replace('/[^.?!]*\b(?:the\s+)?LGU reaffirms its commitment\b[^.?!]*[.?!]\s*/i', '', $overview);
 $sectionPhotos = $meta['section_photos'] ?? [];
 $asOf = \Illuminate\Support\Carbon::parse($meta['as_of'])->format('d F Y, h:i A');
 $fullTitle = 'DSWD Field Office Caraga '.$meta['type_label'].' on '.$reportTitle;
 $isOngoingReport = in_array($meta['report_type'] ?? null, ['initial', 'progress'], true);
 $stockpile = $snapshot['standby_stockpile_summary'] ?? [];
 $ffpBreakdown = collect($stockpile['ffp_breakdown'] ?? []);
 $otherBreakdown = collect($stockpile['other_breakdown'] ?? []);
 $ffpTotal = $ffpBreakdown->sum(fn ($row) => (float) ($row['current'] ?? 0));
 $regionalAndSatelliteFfps = $ffpBreakdown->filter(fn ($row) => preg_match('/regional|satellite/i', $row['warehouse_type'] ?? ''))->sum(fn ($row) => (float) ($row['current'] ?? 0));
 $prepositionedFfps = $ffpBreakdown->filter(fn ($row) => preg_match('/preposition/i', $row['warehouse_type'] ?? ''))->sum(fn ($row) => (float) ($row['current'] ?? 0));
 $reportFniGroups = collect($snapshot['dswd_assistance_releases'] ?? [])->groupBy(function ($release) {
   $category = mb_strtolower(str_replace(['-','_'], ' ', $release['category'] ?? ''));
   if (str_contains($category, 'family food') || trim($category) === 'food items' || trim($category) === 'food item') return 'Family Food Packs / Food Items';
   if (str_contains($category, 'other nfi') || str_contains($category, 'other non food')) return 'Other Non-Food Items';
   if (str_contains($category, 'raw material') || str_contains($category, 'indirect material')) return 'Raw Materials';
   if (str_contains($category, 'non food')) return 'Non-Food Items';
   return $release['category'] ?: 'Other Items';
 })->map(function ($releases) {
   return $releases->groupBy(fn ($release) => mb_strtolower(trim(($release['province'] ?? '').'|'.($release['municipality'] ?? '').'|'.($release['item'] ?? '').'|'.($release['unit'] ?? ''))))->map(function ($rows) {
     $first = $rows->first();
     return ['province' => $first['province'] ?? 'Province not reported', 'municipality' => $first['municipality'] ?? 'City / municipality not reported', 'item' => $first['item'] ?? 'Unspecified item', 'unit' => $first['unit'] ?? '', 'quantity' => $rows->sum('quantity'), 'cost' => $rows->sum('cost')];
   })->sortBy('item')->values();
 })->sortKeysUsing(function ($a, $b) { $order = ['Family Food Packs / Food Items', 'Non-Food Items', 'Other Non-Food Items', 'Raw Materials']; return (($ia = array_search($a, $order, true)) === false ? 99 : $ia) <=> (($ib = array_search($b, $order, true)) === false ? 99 : $ib) ?: strcmp($a, $b); });
 $affectedLguCount = collect($snapshot['sources'])->map(fn($source) => ($source['province'] ?? '').'|'.($source['municipality'] ?? ''))->unique()->count();
 $affectedAreaCount = collect($snapshot['sources'])->flatMap(fn($source) => count($source['barangays'] ?? []) ? collect($source['barangays'])->map(fn($barangay) => ($source['province'] ?? '').'|'.($source['municipality'] ?? '').'|'.$barangay) : [($source['province'] ?? '').'|'.($source['municipality'] ?? '')])->unique()->count();
 $fniActivitySentence = function (string $type) use ($affectedAreaCount, $affectedLguCount): string {
   $itemType = $type === 'Family Food Packs / Food Items' ? 'food items' : ($type === 'Non-Food Items' ? 'non-food items' : mb_strtolower($type));
   return 'Facilitated the delivery and distribution of the following '.$itemType.' to affected families in the affected '.($affectedAreaCount === 1 ? 'area' : 'areas').', in coordination with the concerned '.($affectedLguCount === 1 ? 'LGU' : 'LGUs').':';
 };
 $hasFoodItems = $reportFniGroups->has('Family Food Packs / Food Items');
 $hasNonFoodItems = $reportFniGroups->keys()->contains(fn ($type) => $type !== 'Family Food Packs / Food Items');
 $providedItemLabel = $hasFoodItems && $hasNonFoodItems ? 'food and non-food items' : ($hasFoodItems ? 'food items' : 'non-food items');
 $responseSections = $meta['response_sections'] ?? [];
 $activityDates = collect($snapshot['sources'])->map(fn($source) => $source['incident_date'] ?? $source['received_at'] ?? null)->filter()->map(fn($date) => \Illuminate\Support\Carbon::parse($date));
 $activityStart = $activityDates->isNotEmpty() ? $activityDates->sort()->first() : \Illuminate\Support\Carbon::parse($meta['as_of']);
 $activityEnd = \Illuminate\Support\Carbon::parse($meta['as_of']);
 $activityDateLabel = $activityStart->isSameDay($activityEnd) ? $activityEnd->format('d M Y') : $activityStart->format('d M Y').' – '.$activityEnd->format('d M Y');
 $fniReleaseDates = collect($snapshot['dswd_assistance_releases'] ?? []);
 $fniStart = $fniReleaseDates->pluck('response_letter_date')->filter()->sort()->first();
 $fniEnd = $fniReleaseDates->map(fn ($release) => $release['delivery_or_receipt_date'] ?? $release['date'] ?? null)->filter()->sort()->last();
 $fniActivityStart = $fniStart ? \Illuminate\Support\Carbon::parse($fniStart) : $activityStart;
 $fniActivityEnd = $fniEnd ? \Illuminate\Support\Carbon::parse($fniEnd) : $activityEnd;
 $fniActivityDateLabel = $fniActivityStart->isSameDay($fniActivityEnd) ? $fniActivityEnd->format('d M Y') : $fniActivityStart->format('d M Y').' – '.$fniActivityEnd->format('d M Y');
@endphp
<footer>{{ $fullTitle }}<br>As of {{ $asOf }}</footer>
<p style="text-align:right;font-size:8px;font-weight:bold;">DRN: {{ $report->report_number }}</p>
<h1>{{ $fullTitle }}</h1>
<p class="center" style="font-size:15px;margin-top:0;">As of {{ $asOf }}</p>
<h2>I. Situation Overview</h2>
@include('documents.partials.dromic-section-photo', ['photo' => $sectionPhotos['section_i'] ?? null, 'label' => 'Section I photo'])
@foreach (preg_split('/\R\R+/', $overview) as $paragraph)<p>{!! nl2br(e($paragraph)) !!}</p>@endforeach
<p class="source" style="clear:both">Source: {{ $sourceLabel }}</p>
{{-- LGU actions are summarized after damaged houses; the raw action list is not part of the narrative. --}}
@if (false && filled($meta['response_actions'] ?? null))
    @foreach (preg_split('/\R\R+/', $meta['response_actions']) as $action)<p>{!! nl2br(e($action)) !!}</p>@endforeach
@elseif (false)
@foreach ($snapshot['sources'] as $source)
 @foreach ($source['actions'] as $action)<p><strong>{{ $source['municipality'] }}, {{ $source['province'] }} — {{ $action['office'] }}:</strong> {{ $action['action'] }}</p>@endforeach
@endforeach
@endif
<h2>II. Status of Affected Areas and Population</h2>
@include('documents.partials.dromic-section-photo', ['photo' => $sectionPhotos['section_ii'] ?? null, 'label' => 'Section II photo'])
<p>{!! $narrative('section:affected', 'A total of {{families}} '.$countNoun($totals['affected']['families'], 'family', 'families').' '.$countVerb($totals['affected']['families']).' affected, comprising {{persons}} '.$countNoun($totals['affected']['persons'], 'individual').' across {{barangays}} '.$countNoun($totals['affected']['barangays'], 'barangay', 'barangays').' in Caraga Region (see Annex A).', ['families' => $value($totals['affected']['families']), 'persons' => $value($totals['affected']['persons']), 'barangays' => $value($totals['affected']['barangays'])]) !!}</p>
<p class="source" style="clear:both">Source: {{ $sourceLabel }}</p>
<h2>III. Status of Displaced Population</h2>
@foreach (['inside' => 'a. Inside evacuation centers', 'outside' => 'b. Outside evacuation centers', 'displaced' => 'c. Total displaced population'] as $key => $label)
@if (collect($totals[$key])->contains(fn ($value) => $value !== null))
<h3>{{ $label }}</h3>
@if ($key === 'inside')<p>{!! $narrative('section:inside', 'A total of {{families}} '.$countNoun($totals[$key]['families_cum'], 'family', 'families').' '.$countVerb($totals[$key]['families_cum']).' temporarily sheltered inside {{ecs}} '.$countNoun($totals[$key]['ecs_cum'], 'evacuation center').', comprising {{persons}} '.$countNoun($totals[$key]['persons_cum'], 'individual').' (see Annex B).', ['families' => $value($totals[$key]['families_cum']), 'persons' => $value($totals[$key]['persons_cum']), 'ecs' => $value($totals[$key]['ecs_cum'])]) !!}</p>@endif
@if ($key === 'outside')<p>{!! $narrative('section:outside', 'A total of {{families}} '.$countNoun($totals[$key]['families_cum'], 'family', 'families').', comprising {{persons}} '.$countNoun($totals[$key]['persons_cum'], 'individual').', temporarily stayed with relatives or friends (see Annex E).', ['families' => $value($totals[$key]['families_cum']), 'persons' => $value($totals[$key]['persons_cum'])]) !!}</p>@endif
@if ($key === 'displaced')<p>{!! $narrative('section:displaced', 'A total of {{families}} '.$countNoun($totals[$key]['families_cum'], 'family', 'families').' '.$countVerb($totals[$key]['families_cum']).' displaced in Caraga Region, comprising {{persons}} '.$countNoun($totals[$key]['persons_cum'], 'individual').' (see Annex F).', ['families' => $value($totals[$key]['families_cum']), 'persons' => $value($totals[$key]['persons_cum'])]) !!}</p>@endif
@endif
@endforeach
<p class="source">Source: {{ $sourceLabel }}</p>
<h2>IV. Damaged Houses</h2>
<p>{!! $narrative('section:houses', 'A total of {{total}} '.$countNoun($totals['houses']['total'], 'house', 'houses').' '.$countVerb($totals['houses']['total']).' damaged in Caraga Region, of which {{totally}} '.$countVerb($totals['houses']['totally']).' totally damaged and {{partially}} '.$countVerb($totals['houses']['partially']).' partially damaged (see Annex G).', ['total' => $value($totals['houses']['total']), 'totally' => $value($totals['houses']['totally']), 'partially' => $value($totals['houses']['partially'])]) !!}</p>
<p class="source">Source: {{ $sourceLabel }}</p>
<h2>V. Cost of Assistance Provided</h2>
<p>{!! $narrative('section:assistance', 'A total of PHP {{total}} worth of relief assistance was provided to affected families: DSWD, PHP {{dswd}}; LGUs, PHP {{lgu}}; NGOs / CSOs, PHP {{ngo}}; and others, PHP {{others}} (see Annex H).', ['total' => $value($totals['assistance']['total'], true), 'dswd' => $value($totals['assistance']['dswd'], true), 'lgu' => $value($totals['assistance']['lgu'], true), 'ngo' => $value($totals['assistance']['ngo'], true), 'others' => $value($totals['assistance']['others'], true)]) !!}</p>
<p class="source">Source: {{ $sourceLabel }}{{ count($snapshot['dswd_assistance_releases'] ?? []) ? '; DROMIS FNI Releases' : '' }}</p>
<h2>VI. Response Actions and Interventions</h2>
<h3>a. Standby Funds and Prepositioned Relief Stockpile</h3>
<table class="stockpile-summary"><colgroup><col style="width:19%"><col style="width:14.5%"><col style="width:15%"><col style="width:16%"><col style="width:17%"><col style="width:18.5%"></colgroup><thead>
<tr><th rowspan="3">Office</th><th rowspan="3">Standby<br>Funds</th><th colspan="3" style="font-size:11pt;">Stockpile</th><th rowspan="3">Total<br>Standby<br>Funds &amp;<br>Stockpile</th></tr>
<tr><th colspan="2">Family Food Packs</th><th rowspan="2">Other Food<br>and Non-Food<br>Items (FNIs)</th></tr>
<tr><th>Quantity</th><th>Total Cost</th></tr>
</thead><tbody><tr><td>{{ $stockpile['office'] ?? 'DSWD Field Office Caraga' }}</td><td>₱{{ $value($stockpile['standby_funds'] ?? 0, true) }}</td><td>{{ $value($stockpile['ffp_quantity'] ?? 0) }}</td><td>₱{{ $value($stockpile['ffp_cost'] ?? 0, true) }}</td><td>₱{{ $value($stockpile['other_food_non_food_cost'] ?? 0, true) }}</td><td>₱{{ $value($stockpile['total_standby_funds_stockpile'] ?? 0, true) }}</td></tr></tbody></table>
<ul style="margin:0 0 12pt;padding-left:18pt;"><li><strong>Prepositioned FFPs and Other Relief Items</strong>
<ul style="margin-top:7pt;padding-left:18pt;"><li style="margin-bottom:7pt;"><strong>{{ $value($ffpTotal) }} FFPs</strong> are available in the region; of which <strong>{{ $value($regionalAndSatelliteFfps) }} FFPs</strong> are at the DSWD Regional and Satellite Warehouses, and <strong>{{ $value($prepositionedFfps) }} FFPs</strong> are prepositioned at LGU warehouses.</li>
<li><strong>₱{{ $value($stockpile['other_food_non_food_cost'] ?? 0, true) }}</strong> available other food and non-food items, of which @foreach($otherBreakdown as $row){{ !$loop->first ? ', ' : '' }}<strong>₱{{ $value($row['cost'] ?? 0, true) }}</strong> for {{ strtolower($row['label'] ?? '') }}@endforeach.</li></ul>
</li></ul>
<h3>b. Food and Non-Food Items (FNIs)</h3>
<table class="response-activities"><thead><tr><th style="width:22%">Date</th><th>Activities</th></tr></thead><tbody><tr><td style="text-align:center;vertical-align:middle">{{ $fniActivityDateLabel }}</td><td style="text-align:left;vertical-align:top">
@if($affectedLguCount === 1 && $reportFniGroups->isNotEmpty())
<ul style="margin:4pt 0 7pt;padding-left:18pt"><li>Facilitated the delivery and distribution of {{ $providedItemLabel }} to the affected {{ (float) ($totals['affected']['families'] ?? 0) === 1.0 ? 'family' : 'families' }}, as follows:
<ul style="margin-top:4pt;list-style-type:circle">@foreach($reportFniGroups->flatten(1) as $item)<li>{{ $value($item['quantity']) }} {{ $pluralItem($item['item'], $item['quantity']) }}{{ filled($item['unit'] ?? null) ? ' ('.$item['unit'].')' : '' }}</li>@endforeach</ul>
</li></ul>
@else
@forelse($reportFniGroups as $type => $items)
<ul style="margin:4pt 0 7pt;padding-left:18pt"><li>{{ $fniActivitySentence($type) }}</li></ul>
@php
$columns = $items->unique(fn($item) => mb_strtolower(($item['item'] ?? '').'|'.($item['unit'] ?? '')))->values();
$areaRows = collect([['label' => 'CARAGA', 'level' => 'region', 'items' => $items]]);
foreach ($items->pluck('province')->unique()->sort() as $province) {
  $provinceItems = $items->where('province', $province);
  $areaRows->push(['label' => $province, 'level' => 'province', 'items' => $provinceItems]);
  foreach ($provinceItems->pluck('municipality')->unique()->sort() as $municipality) $areaRows->push(['label' => $municipality, 'level' => 'municipality', 'items' => $provinceItems->where('municipality', $municipality)]);
}
@endphp
<table class="fni-items"><thead><tr><th style="width:120px">PROVINCE/CITY/MUNICIPALITY</th>@foreach($columns as $column)<th>{{ $column['item'] }}<br><span style="font-weight:normal">({{ $column['unit'] ?: 'unit not specified' }})</span></th>@endforeach</tr></thead><tbody>@foreach($areaRows as $area)<tr class="area-{{ $area['level'] }}"><th style="text-align:left;{{ $area['level'] === 'municipality' ? 'padding-left:14px' : '' }}">{{ $area['label'] }}</th>@foreach($columns as $column)<td>{{ $value(collect($area['items'])->filter(fn($item) => strcasecmp($item['item'], $column['item']) === 0 && strcasecmp($item['unit'], $column['unit']) === 0)->sum('quantity')) }}</td>@endforeach</tr>@endforeach</tbody></table>
@empty<p>No DSWD FNI release was selected for this incident.</p>@endforelse
@endif
</td></tr></tbody></table>
@foreach ([
 ['idpp', 'c. Internally Displaced Person Protection (IDPP)', 'No DSWD IDPP activity was reported for the period.'],
 ['cccm', 'd. Camp Coordination and Camp Management (CCCM)', 'No DSWD camp coordination and camp management activity was reported for the period.'],
 ['ect', 'e. Emergency Cash Transfer (ECT)', 'No DSWD Emergency Cash Transfer activity was reported for the period.'],
 ['other', 'f. Other Activities', 'No other DSWD activity was reported for the period.'],
] as [$sectionKey, $sectionTitle, $emptyText])
<h3>{{ $sectionTitle }}</h3>
<table class="response-activities"><thead><tr><th style="width:22%">Date</th><th>Activities</th></tr></thead><tbody>
@forelse($responseSections[$sectionKey] ?? [] as $row)<tr><td style="text-align:center;vertical-align:top">@if(filled($row['date_from'] ?? null)){{ \Illuminate\Support\Carbon::parse($row['date_from'])->format('d M Y') }}@if(filled($row['date_to'] ?? null) && $row['date_to'] !== $row['date_from']) – {{ \Illuminate\Support\Carbon::parse($row['date_to'])->format('d M Y') }}@endif @else — @endif</td><td style="text-align:left;vertical-align:top"><ul style="margin:0;padding-left:18pt">@foreach($row['bullets'] ?? [] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul></td></tr>@empty<tr><td style="text-align:center;vertical-align:top">—</td><td style="text-align:left;vertical-align:top">{{ $emptyText }}</td></tr>@endforelse
</tbody></table>
@endforeach
<p class="source">Source: DROMIS{{ $reportFniGroups->isNotEmpty() ? ' Standby Stockpile and FNI Releases' : '' }} as of {{ $asOf }}</p>
@php($annexLetters = ['affected' => 'A', 'inside' => 'B', 'age_sex' => 'C', 'sectoral' => 'D', 'outside' => 'E', 'displaced' => 'F', 'houses' => 'G', 'assistance' => 'H'])
@php($annexOrder = array_keys($annexLetters))
@php($annexTables = $snapshot['annex_tables'] ?? $snapshot['tables'])
@php($ageGroups = [['INFANT','0–6 months old'],['TODDLERS','7 months–2 y/o'],['PRESCHOOLERS','3–5 y/o'],['SCHOOL AGE','6–12 y/o'],['TEENAGE','13–17 y/o'],['ADULT','18–59 years old'],['ELDERLY','60 years old and above']])
@php($sectorGroups = [['PREGNANT',2],['LACTATING MOTHERS',2],['CHILD-HEADED FAMILY',4],['SINGLE-HEADED FAMILY',4],['SOLO PARENT',4],['PERSON WITH DISABILITY (PWDs)',4],['INDIGENOUS PEOPLE (IPs)',4],['4Ps BENEFICIARY',4]])
<h2 class="center">Annexes</h2>
@foreach ($annexOrder as $key)
@continue(!isset($annexTables[$key]))
@php($table = $annexTables[$key])
<div class="annex {{ count($table['rows'] ?? []) <= 8 ? 'keep-together' : '' }}"><h2>Annex {{ $annexLetters[$key] ?? chr(65 + $loop->index) }}. {{ $annexTitle($table['title']) }}</h2>
<table class="{{ in_array($key, ['age_sex','sectoral'], true) ? 'annex-wide' : '' }}">@if(in_array($key, ['age_sex','sectoral'], true))<colgroup><col style="width:14%">@foreach($table['columns'] as $column)<col>@endforeach</colgroup>@endif<thead>
@if($key === 'affected')<tr><th rowspan="2">PROVINCE/CITY/MUNICIPALITY</th><th colspan="3">NUMBER OF AFFECTED</th></tr><tr><th>BRGYS.</th><th>FAMILIES</th><th>INDIVIDUALS</th></tr>
@elseif($key === 'inside')<tr><th rowspan="3">PROVINCE/CITY/MUNICIPALITY</th><th colspan="2" rowspan="2">NUMBER OF EVACUATION CENTER (ECs)</th><th colspan="4">NUMBER OF DISPLACED (INSIDE ECs)</th></tr><tr><th colspan="2">FAMILIES</th><th colspan="2">PERSONS</th></tr><tr>@foreach(['CUM','NOW','CUM','NOW','CUM','NOW'] as $label)<th>{{ $label }}</th>@endforeach</tr>
@elseif($key === 'age_sex')<tr><th rowspan="4">PROVINCE/CITY/MUNICIPALITY</th>@foreach($ageGroups as [$group,$age])<th colspan="4">{{ $group }}</th>@endforeach</tr><tr>@foreach($ageGroups as [$group,$age])<th colspan="4">{{ $age }}</th>@endforeach</tr><tr>@foreach($ageGroups as $group)<th colspan="2">MALE</th><th colspan="2">FEMALE</th>@endforeach</tr><tr>@foreach($ageGroups as $group)@foreach(['CUM','NOW','CUM','NOW'] as $label)<th>{{ $label }}</th>@endforeach @endforeach</tr>
@elseif($key === 'sectoral')<tr><th rowspan="3">PROVINCE/CITY/MUNICIPALITY</th>@foreach($sectorGroups as [$group,$span])<th colspan="{{ $span }}" @if($span === 2) rowspan="2" @endif>{{ $group }}</th>@endforeach</tr><tr>@foreach($sectorGroups as [$group,$span])@if($span === 4)<th colspan="2">MALE</th><th colspan="2">FEMALE</th>@endif @endforeach</tr><tr>@foreach($sectorGroups as [$group,$span])@for($i=0;$i<$span;$i++)<th>{{ $i % 2 ? 'NOW' : 'CUM' }}</th>@endfor @endforeach</tr>
@elseif(in_array($key,['outside','displaced']))<tr><th rowspan="3">PROVINCE/CITY/MUNICIPALITY</th><th colspan="4">{{ $key === 'outside' ? 'NUMBER OF DISPLACED (OUTSIDE ECs)' : 'NUMBER OF DISPLACED (INSIDE + OUTSIDE ECs)' }}</th></tr><tr><th colspan="2">FAMILIES</th><th colspan="2">PERSONS</th></tr><tr>@foreach(['CUM','NOW','CUM','NOW'] as $label)<th>{{ $label }}</th>@endforeach</tr>
@elseif($key === 'houses')<tr><th rowspan="2">PROVINCE/CITY/MUNICIPALITY</th><th colspan="3">NUMBER OF DAMAGED HOUSES</th></tr><tr><th>TOTAL</th><th>TOTALLY</th><th>PARTIALLY</th></tr>
@else<tr><th rowspan="2">PROVINCE/CITY/MUNICIPALITY</th><th colspan="{{ count($table['columns']) }}">{{ $key === 'assistance' ? 'TOTAL COST OF ASSISTANCE' : ($key === 'food_items' ? 'FOOD ITEMS PROVIDED' : 'NON-FOOD ITEMS PROVIDED') }}</th></tr><tr>@foreach($table['columns'] as $label)<th>{{ $key === 'assistance' && $label === 'Others' ? 'OTHER GOs' : $label }}</th>@endforeach</tr>
@endif
</thead><tbody>
@foreach ($table['rows'] as $row)@continue(($row['level'] ?? '') === 'barangay')<tr class="{{ $row['level'] }}"><td>{{ $row['label'] }}</td>@foreach ($row['values'] as $v)<td>{{ $key === 'assistance' && $v !== null ? '₱' : '' }}{{ $value($v, $key === 'assistance') }}</td>@endforeach</tr>@endforeach
</tbody></table>
@if ($isOngoingReport)<p class="note">Note: Ongoing assessment and validation</p>@endif
<p class="muted">CUM = cumulative; NOW = current. A dash (-) indicates incomplete source data.</p></div>
@endforeach
<div class="annex"><h2>Annex I. Photo Documentation</h2><p>Photo documentation is included when attached to the selected validated LGU reports.</p><p class="source">Source: {{ $sourceLabel }}</p></div>
<table class="signatures"><tr>
@foreach ([['Prepared by:', $meta['prepared_by'] ?? $signatories['prepared']], ['Recommended for Approval:', $signatories['recommended']], ['Approved by:', $signatories['approved']]] as [$label, $person])
<td><p>{{ $label }}</p><div class="signature-space"></div><p class="signature-name">{{ $person['name'] ?? '—' }}</p><p class="signature-position">{{ $person['position'] ?? '' }}</p></td>
@endforeach
</tr></table>
</body></html>
