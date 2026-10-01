<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\PsgcAddress;
use App\Models\User;
use Illuminate\Support\Collection;

class DswdDromicConsolidator
{
    public const CCCM_COLUMNS = ['child_friendly' => 'Child-friendly space: persons', 'women_friendly' => 'Women-friendly space: persons', 'pfa' => 'Psychological first aid: persons', 'referrals' => 'Psychologist / psychiatrist referrals', 'command_cum' => 'Command center CUM', 'command_now' => 'Command center NOW', 'kitchen_cum' => 'Mobile kitchen CUM', 'kitchen_now' => 'Mobile kitchen NOW', 'water_cum' => 'Water treatment CUM', 'water_now' => 'Water treatment NOW', 'tanker_cum' => 'Water tanker CUM', 'tanker_now' => 'Water tanker NOW'];

    public const TABLES = [
        'affected' => ['title' => 'Affected areas and population', 'columns' => ['barangays' => 'Barangays', 'families' => 'Families', 'persons' => 'Persons']],
        'inside' => ['title' => 'Displaced population inside evacuation centers', 'columns' => ['ecs_cum' => 'ECs CUM', 'ecs_now' => 'ECs NOW', 'families_cum' => 'Families CUM', 'families_now' => 'Families NOW', 'persons_cum' => 'Persons CUM', 'persons_now' => 'Persons NOW']],
        'outside' => ['title' => 'Displaced population outside evacuation centers', 'columns' => ['families_cum' => 'Families CUM', 'families_now' => 'Families NOW', 'persons_cum' => 'Persons CUM', 'persons_now' => 'Persons NOW']],
        'displaced' => ['title' => 'Total displaced population', 'columns' => ['families_cum' => 'Families CUM', 'families_now' => 'Families NOW', 'persons_cum' => 'Persons CUM', 'persons_now' => 'Persons NOW']],
        'houses' => ['title' => 'Damaged houses', 'columns' => ['total' => 'Total', 'totally' => 'Totally', 'partially' => 'Partially']],
        'assistance' => ['title' => 'Cost of assistance provided (PHP)', 'columns' => ['dswd' => 'DSWD', 'lgu' => 'LGU', 'ngo' => 'NGOs / CSOs', 'others' => 'Others', 'total' => 'Grand total']],
        'age_sex' => ['title' => 'Sex and Age Distribution of IDPs Inside ECs', 'columns' => ['male_cum' => 'Male CUM', 'male_now' => 'Male NOW', 'female_cum' => 'Female CUM', 'female_now' => 'Female NOW', 'total_cum' => 'Total CUM', 'total_now' => 'Total NOW']],
        'sectoral' => ['title' => 'Sectoral Distribution of IDPs Inside ECs', 'columns' => ['male_cum' => 'Male CUM', 'male_now' => 'Male NOW', 'female_cum' => 'Female CUM', 'female_now' => 'Female NOW', 'total_cum' => 'Total CUM', 'total_now' => 'Total NOW']],
    ];

    public const AGE_SEX_ROWS = ['infant' => 'Infant (0–6 months)', 'toddler' => 'Toddler (7 months–2 years)', 'pre_school' => 'Pre-School (3–5 years)', 'school_age' => 'School Age (6–12 years)', 'teenage' => 'Teenage (13–17 years)', 'adult' => 'Adult (18–59 years)', 'elderly' => 'Elderly (60 years and above)'];
    public const SECTORAL_ROWS = ['pwds' => 'Persons with Disabilities (PWDs)', 'child_headed_family' => 'Child-Headed Family', 'single_headed_family' => 'Single-Headed Family', 'solo_parent' => 'Solo Parent', 'pregnant_women' => 'Pregnant Women', 'lactating_mothers' => 'Lactating Mothers', 'four_ps' => '4Ps Beneficiaries (4Ps)', 'indigenous_people' => 'Indigenous People (IP)'];

    public function displayPlace(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value !== mb_strtoupper($value, 'UTF-8')) {
            return $value === '' ? null : $value;
        }
        $value = mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback('/\b(Del|De|Of|And)\b/u', fn ($match) => mb_strtolower($match[0], 'UTF-8'), $value);
    }

    public function eligible(User $user): Collection
    {
        $coverage = app(AorCoverageService::class);
        $codes = $coverage->normalizeUserAorCodes($user);
        $scoped = ! $user->hasRole('Super Admin') && ($codes['cities'] !== [] || $codes['districts'] !== [] || $codes['provinces'] !== []);

        return AssistanceRequest::with(['incident', 'lguSubmitter'])
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_submitted_to_dswd_at')
            ->whereIn('lgu_report_status', ['advance_submitted', 'submitted'])
            ->where(fn ($q) => $q->whereNull('lgu_correction_target')->orWhere('lgu_correction_target', 'report'))
            ->where(fn ($q) => $q->whereNull('lgu_dromic_payload->standalone_relief_request')->orWhere('lgu_dromic_payload->standalone_relief_request', false))
            ->orderByDesc('lgu_dromic_report_number')->orderByDesc('lgu_dromic_revision_number')
            ->orderByDesc('lgu_submitted_to_dswd_at')->orderByDesc('id')
            ->get()
            ->filter(fn ($r) => ! $scoped || $coverage->coversRequest($user, $r, null))
            // Resolve the current report BEFORE validation filtering. A newer report
            // requiring correction must not silently revive an older validated one.
            ->unique(fn ($r) => $this->series($r))
            ->reject(fn ($r) => in_array($r->lgu_dromic_validation_status, ['superseded', 'needs_lgu_action'], true))
            ->values();
    }

    public function series(AssistanceRequest $r): string
    {
        return ($r->lgu_psgc_code ?: $r->province.'|'.$r->municipality).'|'.($r->lgu_dromic_series_key ?: ($r->incident_id ? 'incident-'.$r->incident_id : 'request-'.$r->id));
    }

    public function canRead(User $user, DromicReport $report): bool
    {
        $coverage = app(AorCoverageService::class);
        $codes = $coverage->normalizeUserAorCodes($user);
        if ($user->hasRole('Super Admin') || ($codes['cities'] === [] && $codes['districts'] === [] && $codes['provinces'] === [])) {
            return true;
        }
        if (! $report->consolidation) {
            return $report->request && $coverage->coversRequest($user, $report->request);
        }

        return collect($report->consolidation['sources'])->every(fn ($s) => $coverage->coversLocation($user, null, $s['province'], $s['municipality']));
    }

    private function sum(array $values): ?float
    {
        return count($values) === 0 || in_array(null, $values, true) ? null : array_sum($values);
    }

    public function source(AssistanceRequest $r): array
    {
        $p = $r->lgu_dromic_payload ?? [];
        $city = $r->municipality ?: ($p['municipality'] ?? null);
        $province = $r->province ?: ($p['province'] ?? null);
        if ((! $city || ! $province) && ($code = $r->lgu_psgc_code ?: $r->lguSubmitter?->lgu_psgc_code)) {
            $address = PsgcAddress::where('code', $code)->first();
            if (! $city && in_array($address?->level, ['city', 'municipality', 'city_municipality'], true)) {
                $city = $address->name;
            }
            if (! $province) {
                $province = PsgcAddress::where('code', $address?->parent_code)->where('level', 'province')->value('name');
            }
        }
        $province = $this->displayPlace($province);
        $city = $this->displayPlace($city);
        $na = $p['not_applicable_sections'] ?? [];
        $areas = collect($p['area_rows'] ?? []);
        $ec = collect($p['evacuation_center_rows'] ?? []);
        $outside = $areas->where('outside_ec_included', true);
        $houses = $areas->where('damaged_houses_included', true);
        $sum = function (Collection $rows, string $key, string $section = '', ?string $fallback = null) use ($na): ?float {
            if (in_array($section, $na, true)) {
                return 0;
            }

            return $this->sum($rows->map(fn ($row) => is_numeric($v = ($row[$key] ?? ($fallback ? ($row[$fallback] ?? null) : null))) ? (float) $v : null)->all());
        };
        $metrics = [
            'affected' => ['barangays' => $areas->pluck('area')->filter()->unique()->count(), 'families' => $sum($areas, 'affected_families'), 'persons' => $sum($areas, 'affected_persons')],
            'inside' => [
                'ecs_cum' => in_array('inside_ec', $na) ? 0 : ($ec->isEmpty() ? null : $ec->pluck('evacuation_center')->filter()->unique()->count()),
                'ecs_now' => in_array('inside_ec', $na) ? 0 : ($ec->isEmpty() || $ec->contains(fn ($row) => ! is_numeric($row['persons_now'] ?? null)) ? null : $ec->filter(fn ($row) => ($row['persons_now'] ?? 0) > 0)->pluck('evacuation_center')->unique()->count()),
            ],
            'outside' => [], 'displaced' => [],
            'houses' => ['totally' => $sum($houses, 'damaged_houses_totally', 'damaged_houses'), 'partially' => $sum($houses, 'damaged_houses_partially', 'damaged_houses')],
        ];
        foreach (['families_cum', 'families_now', 'persons_cum', 'persons_now'] as $key) {
            $metrics['inside'][$key] = $sum($ec, $key, 'inside_ec');
            $metrics['outside'][$key] = $sum($outside, 'outside_ec_'.$key, 'outside_ec', str_ends_with($key, '_cum') ? 'outside_ec_'.str_replace('_cum', '', $key) : null);
            $metrics['displaced'][$key] = $this->sum([$metrics['inside'][$key], $metrics['outside'][$key]]);
        }
        $metrics['houses']['total'] = $this->sum(array_values($metrics['houses']));
        $completedEc = $ec->filter(fn ($row) => (bool) ($row['disaggregation_completed'] ?? false));
        foreach (['age_sex' => self::AGE_SEX_ROWS, 'sectoral' => self::SECTORAL_ROWS] as $group => $definitions) {
            $metrics[$group] = [];
            foreach ($definitions as $key => $label) {
                $metrics[$group][$key] = [];
                foreach (['male_cum', 'male_now', 'female_cum', 'female_now'] as $field) {
                    $values = $completedEc->map(fn ($row) => data_get($row, "disaggregation.{$group}.{$key}.{$field}"))->map(fn ($v) => is_numeric($v) ? (float) $v : null)->all();
                    $metrics[$group][$key][$field] = $this->sum($values);
                }
                $metrics[$group][$key]['total_cum'] = $this->sum([$metrics[$group][$key]['male_cum'], $metrics[$group][$key]['female_cum']]);
                $metrics[$group][$key]['total_now'] = $this->sum([$metrics[$group][$key]['male_now'], $metrics[$group][$key]['female_now']]);
            }
        }
        $assistance = collect(in_array('assistance', $na) ? [] : ($p['assistance_rows'] ?? []));
        $costs = ['dswd' => 0, 'lgu' => 0, 'ngo' => 0, 'others' => 0];
        foreach ($assistance as $row) {
            $source = strtolower($row['source'] ?? '');
            $key = str_contains($source, 'dswd') ? 'dswd' : (str_contains($source, 'lgu') ? 'lgu' : ((str_contains($source, 'ngo') || str_contains($source, 'cso')) ? 'ngo' : 'others'));
            $amount = is_numeric($row['quantity'] ?? null) && is_numeric($row['cost_per_unit'] ?? null) ? round($row['quantity'] * $row['cost_per_unit'], 2) : null;
            $costs[$key] = $this->sum([$costs[$key], $amount]);
        }
        if ($assistance->isEmpty() && ! in_array('assistance', $na)) {
            $costs = array_fill_keys(array_keys($costs), null);
        }
        $metrics['assistance'] = [...$costs, 'total' => $this->sum(array_values($costs))];

        $barangayNames = collect($areas)->pluck('area')
            ->merge($ec->pluck('barangay_origin'))
            ->merge($assistance->pluck('barangay'))
            ->filter()->map(fn ($name) => $this->displayPlace($name))->unique(fn ($name) => mb_strtolower($name))->values();
        $barangayMetrics = [];
        foreach ($barangayNames as $barangay) {
            $matches = fn ($value) => mb_strtolower(trim((string) $value)) === mb_strtolower(trim($barangay));
            $areaRows = $areas->filter(fn ($row) => $matches($row['area'] ?? null));
            $ecRows = $ec->filter(fn ($row) => $matches($row['barangay_origin'] ?? null));
            $outsideRows = $areaRows->where('outside_ec_included', true);
            $houseRows = $areaRows->where('damaged_houses_included', true);
            $assistanceRows = $assistance->filter(fn ($row) => $matches($row['barangay'] ?? null));
            $barangaySum = fn (Collection $rows, string $field, string $section = '', ?string $fallback = null) => $sum($rows, $field, $section, $fallback);
            $inside = [
                'ecs_cum' => in_array('inside_ec', $na) ? 0 : ($ecRows->isEmpty() ? null : $ecRows->pluck('evacuation_center')->filter()->unique()->count()),
                'ecs_now' => in_array('inside_ec', $na) ? 0 : ($ecRows->isEmpty() || $ecRows->contains(fn ($row) => ! is_numeric($row['persons_now'] ?? null)) ? null : $ecRows->filter(fn ($row) => ($row['persons_now'] ?? 0) > 0)->pluck('evacuation_center')->filter()->unique()->count()),
            ];
            $outsideMetric = [];
            $displaced = [];
            foreach (['families_cum', 'families_now', 'persons_cum', 'persons_now'] as $field) {
                $inside[$field] = $barangaySum($ecRows, $field, 'inside_ec');
                $outsideMetric[$field] = $barangaySum($outsideRows, 'outside_ec_'.$field, 'outside_ec', str_ends_with($field, '_cum') ? 'outside_ec_'.str_replace('_cum', '', $field) : null);
                $displaced[$field] = $this->sum([$inside[$field], $outsideMetric[$field]]);
            }
            $houseMetric = ['totally' => $barangaySum($houseRows, 'damaged_houses_totally', 'damaged_houses', 'damaged_houses'), 'partially' => $barangaySum($houseRows, 'damaged_houses_partially', 'damaged_houses', 'damaged_houses')];
            $houseMetric['total'] = $this->sum(array_values($houseMetric));
            $barangayCosts = ['dswd' => 0, 'lgu' => 0, 'ngo' => 0, 'others' => 0];
            foreach ($assistanceRows as $row) {
                $source = strtolower($row['source'] ?? '');
                $costKey = str_contains($source, 'dswd') ? 'dswd' : (str_contains($source, 'lgu') ? 'lgu' : ((str_contains($source, 'ngo') || str_contains($source, 'cso')) ? 'ngo' : 'others'));
                $amount = is_numeric($row['quantity'] ?? null) && is_numeric($row['cost_per_unit'] ?? null) ? round($row['quantity'] * $row['cost_per_unit'], 2) : null;
                $barangayCosts[$costKey] = $this->sum([$barangayCosts[$costKey], $amount]);
            }
            if ($assistanceRows->isEmpty() && ! in_array('assistance', $na)) $barangayCosts = array_fill_keys(array_keys($barangayCosts), null);
            $barangayMetrics[$barangay] = [
                'affected' => ['barangays' => 1, 'families' => $barangaySum($areaRows, 'affected_families'), 'persons' => $barangaySum($areaRows, 'affected_persons')],
                'inside' => $inside, 'outside' => $outsideMetric, 'displaced' => $displaced,
                'houses' => $houseMetric,
                'assistance' => [...$barangayCosts, 'total' => $this->sum(array_values($barangayCosts))],
            ];
        }

        return [
            'id' => $r->id, 'reference' => $r->reference_number, 'series' => $this->series($r),
            'version' => hash('sha256', json_encode([$p, $province, $city, $r->lgu_dromic_validation_status, $r->updated_at])),
            'incident_key' => $r->lgu_dromic_series_key ?: (string) $r->incident_id ?: 'request-'.$r->id,
            'incident_id' => $r->incident_id,
            'incident' => $p['incident_name'] ?? $r->incident?->name ?? 'Untitled incident',
            'incident_date' => substr((string) ($p['occurrence_started_at'] ?? $p['incident_date'] ?? $r->incident?->incident_date ?? $r->date_requested), 0, 10),
            'province' => $province, 'municipality' => $city,
            'report_number' => $r->lgu_dromic_report_number, 'revision' => $r->lgu_dromic_revision_number,
            'classification' => $r->lgu_dromic_report_classification,
            'received_at' => $r->lgu_submitted_to_dswd_at?->toIso8601String(),
            'validation' => $r->lgu_dromic_validation_status ?: 'pending_review',
            'narrative' => $p['narrative'] ?? '',
            'actions' => collect($p['response_action_rows'] ?? [])->map(fn ($a) => [
                'office' => ($a['acted_by_office'] ?? '') === 'Others' ? ($a['acted_by_office_other'] ?? '') : ($a['acted_by_office'] ?? ''),
                'action' => $a['action_intervention'] ?? '',
            ])->filter(fn ($a) => filled($a['action']))->values()->all(),
            'barangays' => $areas->pluck('area')->filter()->map(fn ($name) => $this->displayPlace($name))->unique()->values()->all(),
            'details' => [
                'areas' => $areas->map(fn ($area) => [...$area, 'area' => $this->displayPlace($area['area'] ?? null)])->values()->all(),
                'centers' => $ec->values()->all(), 'assistance' => $assistance->values()->all(),
            ],
            'metrics' => $metrics, 'barangay_metrics' => $barangayMetrics,
        ];
    }

    public function consolidate(Collection $sources): array
    {
        $tables = [];
        foreach (self::TABLES as $key => $definition) {
            if (in_array($key, ['age_sex', 'sectoral'], true)) {
                $tables[$key] = [...$definition, 'rows' => collect($key === 'age_sex' ? self::AGE_SEX_ROWS : self::SECTORAL_ROWS)->map(function ($label, $rowKey) use ($sources, $key) {
                    $values = [];
                    foreach (array_keys(self::TABLES[$key]['columns']) as $column) $values[$column] = $this->sum($sources->map(fn ($source) => $source['metrics'][$key][$rowKey][$column] ?? null)->all());
                    return ['label' => $label, 'level' => 'category', 'province' => '', 'values' => $values];
                })->values()->all()];
                continue;
            }
            $row = function (Collection $group, string $label, string $level, string $province = '') use ($key, $definition): array {
                $values = [];
                foreach ($definition['columns'] as $column => $title) {
                    $values[$column] = $column === 'barangays'
                        ? $group->flatMap(fn ($s) => array_map(fn ($b) => $s['province'].'|'.$s['municipality'].'|'.mb_strtolower(trim($b)), $s['barangays']))->unique()->count()
                        : $this->sum($group->map(fn ($s) => $s['metrics'][$key][$column])->all());
                }

                return ['label' => $label, 'level' => $level, 'province' => $province, 'values' => $values];
            };
            $rows = [$row($sources, 'CARAGA', 'region')];
            foreach ($sources->groupBy('province')->sortKeys() as $province => $group) {
                $rows[] = $row($group, $province ?: 'Province not reported', 'province', $province);
                foreach ($group->groupBy('municipality')->sortKeys() as $city => $local) {
                    $rows[] = $row($local, $city ?: 'City / municipality not reported', 'municipality', $province);
                    $barangays = $local->flatMap(fn ($source) => array_keys($source['barangay_metrics'] ?? []))->unique(fn ($name) => mb_strtolower($name))->sort()->values();
                    foreach ($barangays as $barangay) {
                        $values = [];
                        foreach (array_keys($definition['columns']) as $column) {
                            $barangayValues = $local->map(function ($source) use ($barangay, $key, $column) {
                                $matchedName = collect(array_keys($source['barangay_metrics'] ?? []))->first(fn ($name) => mb_strtolower($name) === mb_strtolower($barangay));

                                return $matchedName === null ? ['present' => false, 'value' => null] : ['present' => true, 'value' => $source['barangay_metrics'][$matchedName][$key][$column] ?? null];
                            })->filter(fn ($entry) => $entry['present'])->pluck('value')->all();
                            $values[$column] = $this->sum($barangayValues);
                        }
                        $rows[] = ['label' => $barangay, 'level' => 'barangay', 'province' => $province, 'municipality' => $city, 'values' => $values];
                    }
                }
            }
            $tables[$key] = [...$definition, 'rows' => $rows];
        }

        return [
            'sources' => $sources->values()->all(), 'tables' => $tables,
            'cccm' => ['title' => 'DSWD CCCM & IDPP interventions', 'columns' => self::CCCM_COLUMNS,
                'rows' => array_map(fn ($row) => [...$row, 'values' => array_fill_keys(array_keys(self::CCCM_COLUMNS), null)], $tables['affected']['rows'])],
        ];
    }

    public function annexTables(array $snapshot): array
    {
        $sources = collect($snapshot['sources'] ?? []);
        $tables = $snapshot['tables'] ?? [];
        $ageGroups = ['infant', 'toddler', 'pre_school', 'school_age', 'teenage', 'adult', 'elderly'];
        $sectorGroups = ['pregnant_women' => false, 'lactating_mothers' => false, 'child_headed_family' => true, 'single_headed_family' => true, 'solo_parent' => true, 'pwds' => true, 'indigenous_people' => true, 'four_ps' => true];
        $ageColumns = [];
        foreach ($ageGroups as $group) foreach (['male_cum', 'male_now', 'female_cum', 'female_now'] as $field) $ageColumns[$group.'_'.$field] = $field;
        $sectorColumns = [];
        foreach ($sectorGroups as $group => $bothSexes) foreach ($bothSexes ? ['male_cum', 'male_now', 'female_cum', 'female_now'] : ['female_cum', 'female_now'] as $field) $sectorColumns[$group.'_'.$field] = $field;
        $geography = function (array $columns, callable $value) use ($sources): array {
            $row = function (Collection $group, string $label, string $level, string $province = '') use ($columns, $value): array {
                return ['label' => $label, 'level' => $level, 'province' => $province, 'values' => collect(array_keys($columns))->mapWithKeys(fn ($column) => [$column => $this->sum($group->map(fn ($source) => $value($source, $column))->all())])->all()];
            };
            $rows = [$row($sources, 'CARAGA', 'region')];
            foreach ($sources->groupBy('province')->sortKeys() as $province => $provinceSources) {
                $rows[] = $row($provinceSources, $province, 'province', $province);
                foreach ($provinceSources->groupBy('municipality')->sortKeys() as $municipality => $local) $rows[] = $row($local, $municipality, 'municipality', $province);
            }
            return $rows;
        };
        return [
            'affected' => $tables['affected'], 'inside' => $tables['inside'],
            'age_sex' => ['title' => 'Sex and Age Distribution of IDPs Inside ECs', 'columns' => $ageColumns, 'rows' => $geography($ageColumns, function ($source, $column) { preg_match('/^(.*)_(male|female)_(cum|now)$/', $column, $match); return data_get($source, 'metrics.age_sex.'.($match[1] ?? '').'.'.($match[2] ?? '').'_'.($match[3] ?? '')); })],
            'sectoral' => ['title' => 'Sectoral Distribution of IDPs Inside ECs', 'columns' => $sectorColumns, 'rows' => $geography($sectorColumns, function ($source, $column) { preg_match('/^(.*)_(male|female)_(cum|now)$/', $column, $match); return data_get($source, 'metrics.sectoral.'.($match[1] ?? '').'.'.($match[2] ?? '').'_'.($match[3] ?? '')); })],
            'outside' => $tables['outside'], 'displaced' => $tables['displaced'], 'houses' => $tables['houses'], 'assistance' => $tables['assistance'],
        ];
    }
}
