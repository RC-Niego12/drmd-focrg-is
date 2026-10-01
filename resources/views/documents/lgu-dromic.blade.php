@php
    $payload = $request->lgu_dromic_payload ?? [];
    $na = collect(data_get($payload, 'not_applicable_sections', []));
    $value = fn ($item) => filled($item) || $item === 0 || $item === '0' ? $item : '-';
    $number = fn ($item) => is_numeric($item) ? number_format((float) $item, floor((float) $item) == (float) $item ? 0 : 2) : $value($item);
    $date = function ($item) use ($value) {
        if (blank($item)) return '-';
        try { return \Illuminate\Support\Carbon::parse($item)->format('d F Y'); } catch (\Throwable) { return $value($item); }
    };
    $dateTime = function ($item) use ($value) {
        if (blank($item)) return '-';
        try { return \Illuminate\Support\Carbon::parse($item)->timezone(config('app.timezone'))->format('d F Y, h:i A'); } catch (\Throwable) { return $value($item); }
    };
    $affectedRows = collect(data_get($payload, 'area_rows', []));
    $ecRows = collect(data_get($payload, 'evacuation_center_rows', []));
    $outsideEcRows = $affectedRows->where('outside_ec_included', true)->values();
    $damagedHouseRows = $affectedRows->where('damaged_houses_included', true)->values();
    $assistanceRows = collect(data_get($payload, 'assistance_rows', []));
    $responseRows = collect(data_get($payload, 'response_action_rows', []));
    $requestedFniRows = collect(data_get($payload, 'requested_fni_items', []));
    $gapRows = collect(data_get($payload, 'cluster_gap_rows', []));
    $photoRows = collect(data_get($payload, 'photo_documentation_rows', []));
    $documentedPhotos = $photoRows->filter(fn ($row) => filled(data_get($row, 'data_url')))->values();
    $photoCollages = collect(data_get($payload, 'photo_collage_rows', []))
        ->filter(fn ($row) => filled(data_get($row, 'data_url')))
        ->values();
    $hasPhotoEvidence = ! $na->contains('photo_documentation')
        && ($photoCollages->isNotEmpty() || $documentedPhotos->isNotEmpty());
    $advisoryScreenshots = collect(data_get($payload, 'official_advisory_rows', []))->filter(fn ($row) => filled(data_get($row, 'screenshot_data_url')));
    $preAssistanceSections = [
        ['related_incidents', 'Related Incidents', 'related_incident_rows'],
        ['casualties', 'Casualties', 'casualty_rows'],
        ['infrastructure_damage', 'Damage to Infrastructure', 'infrastructure_damage_rows'],
        ['agriculture_damage', 'Damage and Losses to Agriculture', 'agriculture_damage_rows'],
    ];
    $postAssistanceSections = [
        ['class_suspension', 'Class Suspension', 'class_suspension_rows'],
        ['work_suspension', 'Work Suspension', 'work_suspension_rows'],
        ['roads_bridges', 'Status of Roads and Bridges', 'road_bridge_rows'],
        ['power_lifelines', 'Status of Power Supply', 'power_lifeline_rows'],
        ['water_lifelines', 'Status of Water Supply', 'water_lifeline_rows'],
        ['communication_lifelines', 'Status of Communication Lines', 'communication_lifeline_rows'],
        ['seaports', 'Status of Seaports', 'seaport_rows'],
        ['airports', 'Status of Airports', 'airport_rows'],
        ['land_transport_terminals', 'Status of Land Transportation Terminals', 'land_transport_terminal_rows'],
        ['stranded_transport', 'Stranded Passengers and Transport', 'stranded_transport_rows'],
        ['calamity_declaration', 'Declaration of State of Calamity', 'calamity_declaration_rows'],
        ['preemptive_evacuation', 'Pre-emptive Evacuation', 'preemptive_evacuation_rows'],
    ];
    $readableLabel = fn ($key) => str($key)->replace('_', ' ')->headline();
    $sumRows = fn ($rows, $field) => collect($rows)->sum(fn ($row) => is_numeric(data_get($row, $field)) ? (float) data_get($row, $field) : 0);
    $ageSexRows = [
        ['infant', 'Infant', '0-6 months old', true],
        ['toddler', 'Toddler', '7 months-2 years old', true],
        ['pre_school', 'Pre-School', '3-5 years old', true],
        ['school_age', 'School Age', '6-12 years old', true],
        ['teenage', 'Teenage', '13-17 years old', true],
        ['adult', 'Adult', '18-59 years old', true],
        ['elderly', 'Elderly', '60 years old and above', true],
    ];
    $sectoralRows = [
        ['pwds', 'Persons with Disabilities (PWDs)', '', true],
        ['child_headed_family', 'Child-Headed Family', '', true],
        ['single_headed_family', 'Single-Headed Family', '', true],
        ['solo_parent', 'Solo Parent', '', true],
        ['pregnant_women', 'Pregnant Women', '', false],
        ['lactating_mothers', 'Lactating Mothers', '', false],
        ['four_ps', '4Ps Beneficiaries (4Ps)', '', true],
        ['indigenous_people', 'Indigenous People (IP)', '', true],
    ];
    $disaggregationTotal = function ($row, string $group, string $field) {
        return collect(data_get($row, "disaggregation.{$group}", []))
            ->sum(fn ($entry) => is_numeric(data_get($entry, $field)) ? (float) data_get($entry, $field) : 0);
    };
    $completedEcRows = $ecRows->filter(fn ($row) => (bool) data_get($row, 'disaggregation_completed'));
    $aggregateDisaggregation = ['age_sex' => [], 'sectoral' => []];
    foreach (['age_sex' => $ageSexRows, 'sectoral' => $sectoralRows] as $group => $definitions) {
        foreach ($definitions as [$key]) {
            foreach (['male_cum', 'male_now', 'female_cum', 'female_now'] as $field) {
                $aggregateDisaggregation[$group][$key][$field] = $completedEcRows->sum(
                    fn ($row) => is_numeric(data_get($row, "disaggregation.{$group}.{$key}.{$field}"))
                        ? (float) data_get($row, "disaggregation.{$group}.{$key}.{$field}")
                        : 0,
                );
            }
        }
    }
    $aggregateRow = ['disaggregation' => $aggregateDisaggregation];
    $hasAggregateAgeSexData = collect($aggregateDisaggregation['age_sex'])->flatten()->contains(fn ($value) => (float) $value > 0);
    $hasAggregateSectoralData = collect($aggregateDisaggregation['sectoral'])->flatten()->contains(fn ($value) => (float) $value > 0);
    $situationOverview = $request->lgu_dromic_narrative
        ?: $request->assessment_summary
        ?: data_get($payload, 'narrative');
    $isScreenPreview = (bool) ($screenPreview ?? false);
    $situationOverviewParagraphs = collect(preg_split('/(?:\r\n|\r|\n){2,}/', trim((string) $situationOverview)) ?: [])
        ->map(fn ($paragraph) => preg_replace('/\s+/u', ' ', trim((string) $paragraph)))
        ->filter()
        ->values();
    $numericColumns = function ($rows, $columns) {
        $rows = collect($rows);

        return collect($columns)->filter(function ($column) use ($rows) {
            $populated = $rows->map(fn ($row) => data_get($row, $column))
                ->filter(fn ($cell) => filled($cell) || $cell === 0 || $cell === '0');

            return $populated->isNotEmpty() && $populated->every(fn ($cell) => is_numeric($cell));
        });
    };
    $pdfOrientation = $orientation ?? 'portrait';
    $sectionPaginationClass = function ($rows, int $columnCount = 1, float $baseUnits = 3) use ($pdfOrientation): string {
        $rows = collect($rows);
        $contentCharacters = $rows->sum(function ($row): int {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return strlen($encoded === false ? '' : $encoded);
        });
        $estimatedUnits = $baseUnits
            + ($rows->count() * 1.35)
            + ($contentCharacters / max(90, $columnCount * 34));
        $pageCapacity = $pdfOrientation === 'landscape' ? 14 : 20;

        return $rows->count() <= 5 && $estimatedUnits <= $pageCapacity
            ? 'keep-together'
            : 'section-splittable';
    };
    $reportNumber = $request->lgu_dromic_report_number ?: data_get($payload, 'report_number', 1);
    $reportClassification = $request->lgu_dromic_report_classification ?: data_get($payload, 'report_classification', 'regular');
    $reportLabel = match ($reportClassification) {
        'first_and_final' => 'First and Final DROMIC / Situational Report',
        'terminal' => 'Terminal DROMIC / Situational Report',
        default => "DROMIC / Situational Report No. {$reportNumber}",
    };
    $affectedBarangayNames = collect(data_get($payload, 'affected_barangays', []))
        ->map(fn ($name) => trim((string) $name))
        ->filter()
        ->unique()
        ->values();
    $incidentTypeTitle = trim((string) data_get($payload, 'incident_type', $request->incident?->name ?? 'Disaster Incident'));
    $locationParts = collect();
    if ($affectedBarangayNames->count() <= 2) {
        $locationParts->push($affectedBarangayNames
            ->map(fn ($name) => 'Brgy. '.preg_replace('/^(?:brgy\.?|barangay)\s+/i', '', $name))
            ->implode(' and '));
    }
    $provinceName = trim((string) ($request->province ?: data_get($payload, 'province')));
    $municipalityName = trim((string) ($request->municipality ?: data_get($payload, 'municipality')));
    if ($provinceName !== '') {
        $municipalityName = trim((string) preg_replace(
            '/,\s*'.preg_quote($provinceName, '/').'\s*$/i',
            '',
            $municipalityName,
        ));
    }
    $locationParts->push($municipalityName);
    $locationParts->push($provinceName);
    $incidentTitle = $incidentTypeTitle.' in '.$locationParts->filter()->implode(', ');
    $logos = $reportProfile['logos'] ?? [];
    $signatories = $reportProfile['signatories'] ?? [];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $request->reference_number }}</title>
    <style>
        @page { size: A4 {{ $orientation ?? 'portrait' }}; margin: 80px 26px 38px 38px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 9px; line-height: 1.35; }
        h1, h2, h3, p { margin: 0; }
        .brand-header { position: fixed; top: -68px; left: 0; right: 0; height: 50px; }
        .brand-header table, .brand-header td { border: 0; padding: 0; }
        .brand-header td { width: 33.333%; height: 44px; vertical-align: middle; }
        .brand-header td:nth-child(2) { text-align: center; }
        .brand-header td:nth-child(3) { text-align: right; }
        .brand-logo { display: inline-block; height: 42px; max-width: 150px; object-fit: contain; }
        .brand-placeholder { display: inline-block; box-sizing: border-box; width: 126px; height: 42px; padding-top: 14px; border: 1px solid #94a3b8; color: #64748b; font-size: 8px; font-weight: 700; text-align: center; }
        .header { text-align: center; margin-bottom: 14px; }
        .agency { color: #1d4ed8; font-weight: 700; font-size: 10px; }
        h1 { font-size: 15px; line-height: 1.25; margin: 4px 30px; }
        .as-of { font-size: 10px; }
        .section { margin-top: 12px; page-break-inside: auto; }
        .section.keep-together { page-break-inside: avoid; }
        .section.section-splittable { page-break-inside: auto; }
        .section h2 { color: #1d4ed8; font-size: 11px; margin-bottom: 5px; page-break-after: avoid; }
        .subhead { margin: 6px 0 3px; font-size: 9px; font-weight: 700; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; page-break-inside: avoid; page-break-after: avoid; }
        thead tr { page-break-inside: avoid; page-break-after: avoid; }
        tbody tr:first-child { page-break-before: avoid; }
        tfoot { display: table-row-group; page-break-inside: avoid; }
        th, td { border: 1px solid #64748b; padding: 4px; vertical-align: top; overflow-wrap: anywhere; }
        .numbered-table th:first-child, .numbered-table td:first-child { width: 5%; text-align: center; }
        .affected-table th:nth-child(2), .affected-table td:nth-child(2) { width: 25%; }
        .affected-table th:nth-child(3), .affected-table td:nth-child(3) { width: 20%; }
        .disaggregation-block { margin-top: 9px; page-break-inside: auto; }
        .disaggregation-block + .disaggregation-block { margin-top: 13px; padding-top: 7px; border-top: 1px solid #cbd5e1; }
        .disaggregation-heading { margin: 0 0 4px; font-size: 9px; font-weight: 700; page-break-after: avoid; }
        .disaggregation-table { margin-bottom: 7px; }
        .disaggregation-table th:first-child, .disaggregation-table td:first-child { width: 4%; text-align: center; }
        .disaggregation-table th:nth-child(2), .disaggregation-table td:nth-child(2) { width: 19%; }
        .disaggregation-table th:nth-child(3), .disaggregation-table td:nth-child(3) { width: 18%; }
        .disaggregation-table th, .disaggregation-table td { padding: 3px; }
        .disaggregation-table .not-applicable { text-align: center; color: #64748b; }
        .gaps-table th:nth-child(1) { width: 18%; }
        .gaps-table th:nth-child(2), .gaps-table th:nth-child(3) { width: 27%; }
        .gaps-table th:nth-child(4) { width: 28%; }
        th { background: #dbeafe; font-size: 7px; text-transform: uppercase; text-align: center; }
        tfoot td { background: #cffafe; font-weight: 700; text-align: center; vertical-align: middle; }
        .grand-total { text-transform: uppercase; }
        .label { width: 25%; background: #eff6ff; font-weight: 700; }
        .narrative { font-size: 10px; line-height: 1.5; }
        .narrative-paragraph { margin: 0 0 8px; text-align: justify; text-align-last: left; word-spacing: normal; }
        .narrative-paragraph:last-child { margin-bottom: 0; }
        .evidence { page-break-inside: avoid; margin-top: 7px; border: 1px solid #94a3b8; padding: 6px; }
        .evidence img, .photo img { display: block; max-width: 100%; max-height: 390px; margin: 5px auto; object-fit: contain; }
        .photo { display: inline-block; width: 47%; margin: 1%; vertical-align: top; page-break-inside: avoid; }
        .caption { font-size: 8px; text-align: center; }
        .collage { width: 100%; margin: 10px 0 14px; page-break-inside: avoid; }
        .collage img { display: block; width: 100%; max-height: 500px; margin: 0 auto; object-fit: contain; }
        .signature { margin-top: 28px; width: 100%; page-break-inside: avoid; }
        .signature-companion { page-break-inside: avoid; }
        .signature-companion .companion-photo img { max-height: 330px; }
        .signature td { width: 33.333%; border: 0; text-align: center; padding: 0 18px; vertical-align: top; }
        .signature-heading { margin-bottom: 34px; text-align: left; font-weight: 700; }
        .signature-person + .signature-person { margin-top: 34px; }
        .line { border-top: 1px solid #111827; padding-top: 3px; font-weight: 700; }
        .role { font-weight: 700; }
        @if ($isScreenPreview)
        html, body { background: #fff; }
        body { margin: 0; padding: 8px 10px 28px; }
        .brand-header { position: static; top: auto; height: auto; margin: 0 0 12px; }
        .brand-header td { height: 48px; }
        @endif
    </style>
</head>
<body>
    <div class="brand-header">
        <table>
            <tr>
                <td>
                    @if (filled(data_get($logos, 'lgu')))
                        <img class="brand-logo" src="{{ data_get($logos, 'lgu') }}" alt="LGU logo">
                    @else
                        <span class="brand-placeholder">LGU LOGO</span>
                    @endif
                </td>
                <td>
                    @if (filled(data_get($logos, 'dromic')))
                        <img class="brand-logo" src="{{ data_get($logos, 'dromic') }}" alt="DROMIC logo">
                    @else
                        <span class="brand-placeholder">DROMIC LOGO</span>
                    @endif
                </td>
                <td>
                    @if (filled(data_get($logos, 'ldrrmc')))
                        <img class="brand-logo" src="{{ data_get($logos, 'ldrrmc') }}" alt="LDRRMC logo">
                    @else
                        <span class="brand-placeholder">LDRRMC LOGO</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>
    <header class="header">
        <h1>LGU {{ $reportLabel }} on the {{ $incidentTitle }}</h1>
        @if ($request->lgu_submitted_to_dswd_at)
            <p class="as-of">As of {{ $dateTime($request->lgu_submitted_to_dswd_at) }}</p>
        @else
            <p class="as-of">Not yet submitted to DSWD</p>
        @endif
    </header>

    <section class="section {{ $advisoryScreenshots->isEmpty() ? $sectionPaginationClass([['narrative' => $request->lgu_dromic_narrative ?: $request->assessment_summary]], 1, 2) : 'section-splittable' }}">
        <h2>Situation Overview</h2>
        <div class="narrative">
            @forelse ($situationOverviewParagraphs as $paragraph)
                <p class="narrative-paragraph">{{ $paragraph }}</p>
            @empty
                <p class="narrative-paragraph">-</p>
            @endforelse
        </div>
        @foreach ($advisoryScreenshots as $advisory)
            <div class="evidence">
                <strong>{{ $value(data_get($advisory, 'agency')) }} — {{ $value(data_get($advisory, 'advisory_title')) }}</strong>
                <img src="{{ data_get($advisory, 'screenshot_data_url') }}" alt="Official advisory screenshot">
            </div>
        @endforeach
    </section>

    <section class="section {{ $sectionPaginationClass($affectedRows, 5) }}">
        <h2>Status of Affected Population</h2>
        <table class="numbered-table affected-table">
            <thead>
                <tr><th rowspan="2">No.</th><th rowspan="2">Barangay</th><th rowspan="2">PSA 2024 Population</th><th colspan="2">Number of Affected</th></tr>
                <tr><th>Families</th><th>Persons</th></tr>
            </thead>
            <tbody>
                @forelse ($affectedRows as $index => $row)
                    <tr><td>{{ $index + 1 }}</td><td>{{ $value(data_get($row, 'area')) }}</td><td>{{ $number(data_get($row, 'psa_2024')) }}</td><td>{{ $number(data_get($row, 'affected_families')) }}</td><td>{{ $number(data_get($row, 'affected_persons')) }}</td></tr>
                @empty
                    <tr><td colspan="5" style="text-align:center">-</td></tr>
                @endforelse
            </tbody>
            @if ($affectedRows->isNotEmpty())
                <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($affectedRows->pluck('area')->filter()->unique()->count()) }} barangay(s)</td><td>{{ $number($sumRows($affectedRows, 'psa_2024')) }}</td><td>{{ $number($sumRows($affectedRows, 'affected_families')) }}</td><td>{{ $number($sumRows($affectedRows, 'affected_persons')) }}</td></tr></tfoot>
            @endif
        </table>
    </section>

    @if (! $na->contains('inside_ec') || ! $na->contains('outside_ec'))
    <section class="section {{ $sectionPaginationClass($ecRows->concat($outsideEcRows), 10, 6) }}">
        <h2>Status of Displaced Population</h2>
        @if (! $na->contains('inside_ec'))
        <p class="subhead">A. Inside Evacuation Centers</p>
            <table class="numbered-table">
                <thead>
                    <tr><th rowspan="2">No.</th><th rowspan="2">Barangay Address of EC</th><th rowspan="2">Evacuation Center</th><th colspan="2">Families</th><th colspan="2">Persons</th><th rowspan="2">Barangay of Origin of IDPs</th><th rowspan="2">No. of Classrooms Used</th><th rowspan="2">Disaggregated Data</th></tr>
                    <tr><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th></tr>
                </thead>
                <tbody>
                    @forelse ($ecRows as $index => $row)
                        <tr><td>{{ $index + 1 }}</td><td>{{ $value(data_get($row, 'barangay_address')) }}</td><td>{{ $value(data_get($row, 'evacuation_center')) }}</td><td>{{ $number(data_get($row, 'families_cum')) }}</td><td>{{ $number(data_get($row, 'families_now')) }}</td><td>{{ $number(data_get($row, 'persons_cum')) }}</td><td>{{ $number(data_get($row, 'persons_now')) }}</td><td>{{ $value(data_get($row, 'barangay_origin')) }}</td><td>{{ $number(data_get($row, 'classrooms_used')) }}</td><td>{{ data_get($row, 'disaggregation_completed') ? 'Completed' : '-' }}</td></tr>
                    @empty <tr><td colspan="10" style="text-align:center">-</td></tr> @endforelse
                </tbody>
                @if ($ecRows->isNotEmpty())
                    <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($ecRows->pluck('barangay_address')->filter()->unique()->count()) }}</td><td>{{ $number($ecRows->pluck('evacuation_center')->filter()->unique()->count()) }}</td><td>{{ $number($sumRows($ecRows, 'families_cum')) }}</td><td>{{ $number($sumRows($ecRows, 'families_now')) }}</td><td>{{ $number($sumRows($ecRows, 'persons_cum')) }}</td><td>{{ $number($sumRows($ecRows, 'persons_now')) }}</td><td>All EC rows</td><td>{{ $number($sumRows($ecRows, 'classrooms_used')) }}</td><td>-</td></tr></tfoot>
                @endif
            </table>

            @if ($hasAggregateAgeSexData || $hasAggregateSectoralData)
                <div class="disaggregation-block">
                    <p class="disaggregation-heading">
                        Consolidated Sex, Age and Sectoral Disaggregated Data — All Listed Evacuation Centers
                    </p>

                    @if ($hasAggregateAgeSexData)
                        <table class="disaggregation-table">
                            <thead>
                                <tr><th rowspan="2">No.</th><th rowspan="2">Sex and Age Disaggregation</th><th rowspan="2">Age Range</th><th colspan="2">Male</th><th colspan="2">Female</th><th colspan="2">Total</th></tr>
                                <tr><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($ageSexRows as [$key, $label, $description])
                                    @php
                                        $maleCum = data_get($aggregateRow, "disaggregation.age_sex.{$key}.male_cum", 0);
                                        $maleNow = data_get($aggregateRow, "disaggregation.age_sex.{$key}.male_now", 0);
                                        $femaleCum = data_get($aggregateRow, "disaggregation.age_sex.{$key}.female_cum", 0);
                                        $femaleNow = data_get($aggregateRow, "disaggregation.age_sex.{$key}.female_now", 0);
                                    @endphp
                                    <tr><td>{{ $loop->iteration }}</td><td>{{ $label }}</td><td>{{ $description }}</td><td>{{ $number($maleCum) }}</td><td>{{ $number($maleNow) }}</td><td>{{ $number($femaleCum) }}</td><td>{{ $number($femaleNow) }}</td><td>{{ $number((float) $maleCum + (float) $femaleCum) }}</td><td>{{ $number((float) $maleNow + (float) $femaleNow) }}</td></tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td class="grand-total">Total</td><td colspan="2">All age groups</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'male_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'male_now')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'female_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'female_now')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'male_cum') + $disaggregationTotal($aggregateRow, 'age_sex', 'female_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'age_sex', 'male_now') + $disaggregationTotal($aggregateRow, 'age_sex', 'female_now')) }}</td></tr>
                            </tfoot>
                        </table>
                    @endif

                    @if ($hasAggregateSectoralData)
                        <table class="disaggregation-table">
                            <thead>
                                <tr><th rowspan="2">No.</th><th rowspan="2" colspan="2">Sectoral Group</th><th colspan="2">Male</th><th colspan="2">Female</th><th colspan="2">Total</th></tr>
                                <tr><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($sectoralRows as [$key, $label, $description, $hasMale])
                                    @php
                                        $maleCum = $hasMale ? data_get($aggregateRow, "disaggregation.sectoral.{$key}.male_cum", 0) : 0;
                                        $maleNow = $hasMale ? data_get($aggregateRow, "disaggregation.sectoral.{$key}.male_now", 0) : 0;
                                        $femaleCum = data_get($aggregateRow, "disaggregation.sectoral.{$key}.female_cum", 0);
                                        $femaleNow = data_get($aggregateRow, "disaggregation.sectoral.{$key}.female_now", 0);
                                    @endphp
                                    <tr><td>{{ $loop->iteration }}</td><td colspan="2">{{ $label }}</td>@if ($hasMale)<td>{{ $number($maleCum) }}</td><td>{{ $number($maleNow) }}</td>@else<td colspan="2" class="not-applicable">N/A</td>@endif<td>{{ $number($femaleCum) }}</td><td>{{ $number($femaleNow) }}</td><td>{{ $number((float) $maleCum + (float) $femaleCum) }}</td><td>{{ $number((float) $maleNow + (float) $femaleNow) }}</td></tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td class="grand-total">Total</td><td colspan="2">All sectoral groups</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'male_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'male_now')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'female_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'female_now')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'male_cum') + $disaggregationTotal($aggregateRow, 'sectoral', 'female_cum')) }}</td><td>{{ $number($disaggregationTotal($aggregateRow, 'sectoral', 'male_now') + $disaggregationTotal($aggregateRow, 'sectoral', 'female_now')) }}</td></tr>
                            </tfoot>
                        </table>
                    @endif
                </div>
            @endif
        @endif
        @if (! $na->contains('outside_ec'))
        <p class="subhead">B. Outside Evacuation Centers</p>
            <table class="numbered-table">
                <thead>
                    <tr><th rowspan="2">No.</th><th colspan="2">Families</th><th colspan="2">Persons</th><th rowspan="2">Barangay of Origin of IDPs</th></tr>
                    <tr><th>CUM</th><th>NOW</th><th>CUM</th><th>NOW</th></tr>
                </thead>
                <tbody>
                    @forelse ($outsideEcRows as $index => $row)
                        <tr><td>{{ $index + 1 }}</td><td>{{ $number(data_get($row, 'outside_ec_families_cum')) }}</td><td>{{ $number(data_get($row, 'outside_ec_families_now')) }}</td><td>{{ $number(data_get($row, 'outside_ec_persons_cum')) }}</td><td>{{ $number(data_get($row, 'outside_ec_persons_now')) }}</td><td>{{ $value(data_get($row, 'area')) }}</td></tr>
                    @empty <tr><td colspan="6" style="text-align:center">-</td></tr> @endforelse
                </tbody>
                @if ($outsideEcRows->isNotEmpty())
                    <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($sumRows($outsideEcRows, 'outside_ec_families_cum')) }}</td><td>{{ $number($sumRows($outsideEcRows, 'outside_ec_families_now')) }}</td><td>{{ $number($sumRows($outsideEcRows, 'outside_ec_persons_cum')) }}</td><td>{{ $number($sumRows($outsideEcRows, 'outside_ec_persons_now')) }}</td><td>{{ $number($outsideEcRows->pluck('area')->filter()->unique()->count()) }} barangay(s)</td></tr></tfoot>
                @endif
            </table>
        @endif
    </section>
    @endif

    @if (! $na->contains('damaged_houses'))
    <section class="section {{ $sectionPaginationClass($damagedHouseRows, 7) }}">
        <h2>Damaged Houses</h2>
            <table class="numbered-table">
                <thead>
                    <tr><th rowspan="2">No.</th><th rowspan="2">Barangay</th><th colspan="4">Damaged Houses</th><th rowspan="2">Affected Families</th></tr>
                    <tr><th>Totally</th><th>Partially</th><th>Total</th><th>Estimated Cost</th></tr>
                </thead>
                <tbody>
                    @forelse ($damagedHouseRows as $index => $row)
                        <tr><td>{{ $index + 1 }}</td><td>{{ $value(data_get($row, 'area')) }}</td><td>{{ $number(data_get($row, 'damaged_houses_totally')) }}</td><td>{{ $number(data_get($row, 'damaged_houses_partially')) }}</td><td>{{ $number((float) data_get($row, 'damaged_houses_totally', 0) + (float) data_get($row, 'damaged_houses_partially', 0)) }}</td><td>PHP {{ $number(data_get($row, 'damaged_houses_estimated_cost')) }}</td><td>{{ $number(data_get($row, 'affected_families')) }}</td></tr>
                    @empty <tr><td colspan="7" style="text-align:center">-</td></tr> @endforelse
                </tbody>
                @if ($damagedHouseRows->isNotEmpty())
                    <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($damagedHouseRows->pluck('area')->filter()->unique()->count()) }} barangay(s)</td><td>{{ $number($sumRows($damagedHouseRows, 'damaged_houses_totally')) }}</td><td>{{ $number($sumRows($damagedHouseRows, 'damaged_houses_partially')) }}</td><td>{{ $number($sumRows($damagedHouseRows, 'damaged_houses_totally') + $sumRows($damagedHouseRows, 'damaged_houses_partially')) }}</td><td>PHP {{ $number($sumRows($damagedHouseRows, 'damaged_houses_estimated_cost')) }}</td><td>{{ $number($sumRows($damagedHouseRows, 'affected_families')) }}</td></tr></tfoot>
                @endif
            </table>
    </section>
    @endif

    @foreach ($preAssistanceSections as [$key, $title, $field])
        @php $rows = collect(data_get($payload, $field, [])); @endphp
        @if (! $na->contains($key))
        <section class="section {{ $sectionPaginationClass($rows, max(1, count(array_keys($rows->first() ?? [])))) }}">
            <h2>{{ $title }}</h2>
            @if ($rows->isEmpty())
                <p>-</p>
            @else
                @php
                    $columns = array_keys($rows->first());
                    $totalColumns = $numericColumns($rows, $columns);
                @endphp
                <table>
                    <thead><tr>@foreach ($columns as $column)<th>{{ $readableLabel($column) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>@foreach ($row as $cell)<td>{{ is_array($cell) ? json_encode($cell, JSON_UNESCAPED_UNICODE) : $value($cell) }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr>
                        @foreach ($columns as $column)
                            <td class="{{ $loop->first ? 'grand-total' : '' }}">
                                @if ($loop->first)
                                    Total
                                @elseif ($totalColumns->contains($column))
                                    {{ $number($sumRows($rows, $column)) }}
                                @elseif ($loop->index === 1)
                                    {{ $number($rows->count()) }} record(s)
                                @else
                                    -
                                @endif
                            </td>
                        @endforeach
                    </tr></tfoot>
                </table>
            @endif
        </section>
        @endif
    @endforeach

    @if (! $na->contains('assistance'))
    <section class="section {{ $sectionPaginationClass($assistanceRows, 11) }}">
        <h2>Status of Assistance Provided</h2>
            <table class="numbered-table">
                <thead><tr><th>No.</th><th>Barangay</th><th>Source</th><th>Specify Source if Applicable</th><th>Quantity</th><th>Unit of Measurement</th><th>Type of Item</th><th>Particular</th><th>Cost per Unit</th><th>Total Amount</th><th>No. of Families Served</th></tr></thead>
                <tbody>
                    @forelse ($assistanceRows as $index => $row)
                        @php $rowTotal = (float) data_get($row, 'quantity', 0) * (float) data_get($row, 'cost_per_unit', 0); @endphp
                        <tr><td>{{ $index + 1 }}</td><td>{{ $value(data_get($row, 'barangay')) }}</td><td>{{ $value(data_get($row, 'source')) }}</td><td>{{ $value(data_get($row, 'source_details')) }}</td><td>{{ $number(data_get($row, 'quantity')) }}</td><td>{{ $value(data_get($row, 'unit')) }}</td><td>{{ $value(data_get($row, 'item_type')) }}</td><td>{{ $value(data_get($row, 'particular')) }}</td><td>PHP {{ $number(data_get($row, 'cost_per_unit')) }}</td><td>PHP {{ $number($rowTotal) }}</td><td>{{ $number(data_get($row, 'families_served')) }}</td></tr>
                    @empty <tr><td colspan="11" style="text-align:center">-</td></tr> @endforelse
                </tbody>
                @if ($assistanceRows->isNotEmpty())
                    <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($assistanceRows->pluck('barangay')->filter()->unique()->count()) }} barangay(s)</td><td>-</td><td>-</td><td>{{ $number($sumRows($assistanceRows, 'quantity')) }}</td><td>-</td><td>-</td><td>-</td><td>-</td><td>PHP {{ $number($assistanceRows->sum(fn ($row) => (float) data_get($row, 'quantity', 0) * (float) data_get($row, 'cost_per_unit', 0))) }}</td><td>{{ $number($sumRows($assistanceRows, 'families_served')) }}</td></tr></tfoot>
                @endif
            </table>
    </section>
    @endif

    @foreach ($postAssistanceSections as [$key, $title, $field])
        @php $rows = collect(data_get($payload, $field, [])); @endphp
        @if (! $na->contains($key))
        <section class="section {{ $sectionPaginationClass($rows, max(1, count(array_keys($rows->first() ?? [])))) }}">
            <h2>{{ $title }}</h2>
            @if ($rows->isEmpty())
                <p>-</p>
            @else
                @php
                    $columns = array_keys($rows->first());
                    $totalColumns = $numericColumns($rows, $columns);
                @endphp
                <table>
                    <thead><tr>@foreach ($columns as $column)<th>{{ $readableLabel($column) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>@foreach ($row as $cell)<td>{{ is_array($cell) ? json_encode($cell, JSON_UNESCAPED_UNICODE) : $value($cell) }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr>
                        @foreach ($columns as $column)
                            <td class="{{ $loop->first ? 'grand-total' : '' }}">
                                @if ($loop->first)
                                    Total
                                @elseif ($totalColumns->contains($column))
                                    {{ $number($sumRows($rows, $column)) }}
                                @elseif ($loop->index === 1)
                                    {{ $number($rows->count()) }} record(s)
                                @else
                                    -
                                @endif
                            </td>
                        @endforeach
                    </tr></tfoot>
                </table>
            @endif
        </section>
        @endif
    @endforeach

    @if (! $na->contains('cluster_gaps'))
    <section class="section {{ $sectionPaginationClass($gapRows, 4) }}">
        <h2>Gaps/Challenges and Status/Actions Undertaken (Cluster Leads)</h2>
            <table class="gaps-table">
                <thead><tr><th>Cluster</th><th>Areas of Concern / Gaps</th><th>Actions Undertaken</th><th>Status / Remarks</th></tr></thead>
                <tbody>
                    @forelse ($gapRows as $row)
                        <tr><td>{{ $value(data_get($row, 'cluster') ?: data_get($row, 'cluster_other')) }}</td><td>{{ $value(data_get($row, 'areas_of_concern')) }}</td><td>{{ $value(data_get($row, 'actions_undertaken')) }}</td><td>{{ $value(data_get($row, 'status_remarks')) }}</td></tr>
                    @empty <tr><td colspan="4" style="text-align:center">-</td></tr> @endforelse
                </tbody>
                @if ($gapRows->isNotEmpty())
                    <tfoot><tr><td class="grand-total">Total</td><td>{{ $number($gapRows->count()) }} record(s)</td><td>-</td><td>-</td></tr></tfoot>
                @endif
            </table>
    </section>
    @endif

    @if (! $hasPhotoEvidence)
    <div class="signature-companion">
    @endif

    <section class="section {{ $sectionPaginationClass($responseRows, 2) }}">
        <h2>Response Actions and Interventions</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:7%">No.</th>
                    <th style="width:28%">Acted by</th>
                    <th>Response Action / Intervention</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($responseRows as $index => $row)<tr><th style="width:7%">{{ $index + 1 }}</th><td style="width:28%">{{ $value(data_get($row, 'acted_by_office') === 'Others' ? data_get($row, 'acted_by_office_other') : data_get($row, 'acted_by_office')) }}</td><td>{{ $value(data_get($row, 'action_intervention')) }}</td></tr>
            @empty <tr><td colspan="3">-</td></tr> @endforelse
        </tbody>
        @if ($responseRows->isNotEmpty())
            <tfoot><tr><td class="grand-total">Total</td><td colspan="2">{{ $number($responseRows->count()) }} response action(s)</td></tr></tfoot>
        @endif
        </table>
    </section>

    @if ($hasPhotoEvidence)
    <section class="section section-splittable">
        <h2>Photo Documentation</h2>
        @if ($photoCollages->isNotEmpty())
            @foreach ($photoCollages->slice(0, -1) as $collage)
                <div class="collage"><img src="{{ data_get($collage, 'data_url') }}" alt="{{ $value(data_get($collage, 'title')) }}"></div>
            @endforeach
        @else
            @foreach ($documentedPhotos->slice(0, -1) as $photo)
                <div class="photo"><img src="{{ data_get($photo, 'data_url') }}" alt="Photo documentation"><p class="caption">{{ $value(data_get($photo, 'caption')) }}</p></div>
            @endforeach
        @endif
    </section>

    <div class="signature-companion">
        <section class="section keep-together companion-photo">
            @if (($photoCollages->isNotEmpty() ? $photoCollages : $documentedPhotos)->count() > 1)
                <p class="subhead">Photo Documentation (continued)</p>
            @endif
            @if ($photoCollages->isNotEmpty())
                @php $lastCollage = $photoCollages->last(); @endphp
                <div class="collage"><img src="{{ data_get($lastCollage, 'data_url') }}" alt="{{ $value(data_get($lastCollage, 'title')) }}"></div>
            @else
                @php $lastPhoto = $documentedPhotos->last(); @endphp
                <div class="photo"><img src="{{ data_get($lastPhoto, 'data_url') }}" alt="Photo documentation"><p class="caption">{{ $value(data_get($lastPhoto, 'caption')) }}</p></div>
            @endif
        </section>
    @elseif (! $na->contains('photo_documentation'))
        <section class="section keep-together">
            <h2>Photo Documentation</h2>
            <p>-</p>
        </section>
    @endif

    <table class="signature">
        <tr>
            <td>
                <div class="signature-heading">Prepared by:</div>
                <div class="signature-person">
                    <div class="line">{{ $value(data_get($signatories, 'lswdo.name')) }}</div>
                    <div class="role">{{ $value(data_get($signatories, 'lswdo.position') ?: 'LSWDO') }}</div>
                </div>
                <div class="signature-person">
                    <div class="line">{{ $value(data_get($signatories, 'ldrrmo.name')) }}</div>
                    <div class="role">{{ $value(data_get($signatories, 'ldrrmo.position') ?: 'LDRRMO') }}</div>
                </div>
            </td>
            <td>&nbsp;</td>
            <td>
                <div class="signature-heading">Approved by:</div>
                <div class="signature-person">
                    <div class="line">{{ $value(data_get($signatories, 'lce.name')) }}</div>
                    <div class="role">{{ $value(data_get($signatories, 'lce.position') ?: 'LCE') }}</div>
                </div>
            </td>
        </tr>
    </table>
    </div>
</body>
</html>
