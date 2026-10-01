<?php

namespace App\Http\Controllers;

use App\Models\DromicReport;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DswdDromicConsolidator;
use App\Services\StandbyStockpileSummaryService;
use App\Services\DromicFniReleaseService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DswdDromicReportController extends Controller
{
    public function index(Request $request, DswdDromicConsolidator $service, StandbyStockpileSummaryService $stockpile, DromicFniReleaseService $releases)
    {
        return Inertia::render('Dashboard/Dromic', [
            'reports' => DromicReport::with('creator:id,name,position,designation')->whereIn('id', DromicReport::with('request')->get()->filter(fn ($r) => $service->canRead($request->user(), $r))->pluck('id'))->latest()->paginate(12)->withQueryString(),
            'eligibleReports' => $service->eligible($request->user())->map(fn ($r) => $service->source($r)),
            'tableDefinitions' => DswdDromicConsolidator::TABLES,
            'cccmColumns' => DswdDromicConsolidator::CCCM_COLUMNS,
            'canEditReports' => $request->user()->hasAnyRole(['DRIMS', 'Super Admin']),
            'signatories' => $this->signatories($request->user()),
            'standbyStockpileSummary' => $stockpile->current(),
            'fniReleases' => $releases->eligible(),
        ]);
    }

    private function signatories(User $preparedBy): array
    {
        $library = OperationalLibraryValue::query()
            ->where('library_type', 'drims_signatory')
            ->where('is_active', true)
            ->whereIn('context', ['recommended_by', 'approved_by'])
            ->get()
            ->keyBy('context');
        $recommended = User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->whereIn('name', ['DRMD Chief']))->first();
        $approved = User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->whereIn('name', ['Regional Director', 'RD']))->first();
        $person = fn (?User $user, string $fallbackName, string $fallbackPosition) => [
            'name' => $user?->name ?: $fallbackName,
            'position' => $user?->designation ?: $user?->position ?: $fallbackPosition,
        ];

        return [
            'prepared' => $person($preparedBy, 'DRIMS Monitoring Officer', 'DRIMS'),
            'recommended' => $library->has('recommended_by')
                ? ['name' => data_get($library['recommended_by']->metadata, 'employee_name') ?: trim(explode('|', $library['recommended_by']->value)[0]), 'position' => data_get($library['recommended_by']->metadata, 'designation') ?: trim(explode('|', $library['recommended_by']->value)[1] ?? '')]
                : $person($recommended, 'DRMD Chief', 'OIC-Chief, DRMD'),
            'approved' => $library->has('approved_by')
                ? ['name' => data_get($library['approved_by']->metadata, 'employee_name') ?: trim(explode('|', $library['approved_by']->value)[0]), 'position' => data_get($library['approved_by']->metadata, 'designation') ?: trim(explode('|', $library['approved_by']->value)[1] ?? '')]
                : $person($approved, 'Regional Director', 'Regional Director'),
        ];
    }

    private function input(Request $request, DswdDromicConsolidator $service, DromicFniReleaseService $releases, StandbyStockpileSummaryService $stockpile): array
    {
        $data = $request->validate([
            'source_ids' => ['required', 'array', 'min:1', 'max:300'],
            'source_ids.*' => ['required', 'integer', 'distinct'],
            'source_versions' => ['required', 'array'],
            'source_versions.*' => ['required', 'string', 'size:64'],
            'title' => ['required', 'string', 'max:255'],
            'report_type' => ['required', 'in:initial,progress,terminal,first_final'],
            'progress_number' => ['required_if:report_type,progress', 'nullable', 'integer', 'min:1'],
            'as_of' => ['required', 'date'],
            'relationship' => ['nullable', 'string', 'max:3000'],
            'overview' => ['required', 'string', 'max:30000'],
            'narrative_edits' => ['nullable', 'array', 'max:500'],
            'narrative_edits.*' => ['nullable', 'string', 'max:30000'],
            'section_photos' => ['nullable', 'array:section_i,section_ii'],
            'section_photos.*' => ['nullable', 'array:data_url,side,width,offset_y,aspect_ratio,alt'],
            'section_photos.*.data_url' => ['required_with:section_photos.*', 'string', 'max:5000000', 'starts_with:data:image/jpeg;base64,'],
            'section_photos.*.side' => ['nullable', 'in:left,right'],
            'section_photos.*.width' => ['nullable', 'numeric', 'between:25,75'],
            'section_photos.*.offset_y' => ['nullable', 'numeric', 'between:0,180'],
            'section_photos.*.aspect_ratio' => ['nullable', 'numeric', 'between:0.1,10'],
            'section_photos.*.alt' => ['nullable', 'string', 'max:255'],
            'response_sections' => ['nullable', 'array'],
            'response_sections.*' => ['nullable', 'array', 'max:100'],
            'response_sections.*.*.date_from' => ['nullable', 'date'],
            'response_sections.*.*.date_to' => ['nullable', 'date', 'after_or_equal:response_sections.*.*.date_from'],
            'response_sections.*.*.bullets' => ['required', 'array', 'min:1', 'max:30'],
            'response_sections.*.*.bullets.*' => ['required', 'string', 'max:3000'],
            'review_edits' => ['nullable', 'array'],
            'review_edits.*.reason' => ['required', 'string', 'max:3000'],
            'review_edits.*.rows' => ['required', 'array', 'max:500'],
            'review_edits.*.rows.*.index' => ['required', 'integer', 'min:0'],
            'review_edits.*.rows.*.values' => ['required', 'array'],
            'overlap_reviewed' => ['accepted'],
        ]);
        $models = $service->eligible($request->user())->whereIn('id', $data['source_ids']);
        if ($models->count() !== count($data['source_ids'])) {
            throw ValidationException::withMessages(['source_ids' => 'A selected LGU report has been superseded by a newer submitted report, is no longer eligible, or is outside your assigned coverage. Refresh the source list and select the latest report.']);
        }
        if ($models->unique(fn ($r) => $service->series($r))->count() !== $models->count()) {
            throw ValidationException::withMessages(['source_ids' => 'Select only one reporting period per LGU incident series.']);
        }
        $sources = $models->map(fn ($r) => $service->source($r))->values();
        if ($sources->contains(fn ($s) => ($data['source_versions'][$s['id']] ?? null) !== $s['version'])) {
            throw ValidationException::withMessages(['source_ids' => 'A source report changed after you opened this page. Refresh and review the updated figures before saving.']);
        }
        if ($sources->contains(fn ($s) => ! $s['province'] || ! $s['municipality'])) {
            throw ValidationException::withMessages(['source_ids' => 'Complete the province and city / municipality of the source LGU reports before consolidation.']);
        }
        if ($sources->contains(fn ($s) => Carbon::parse($s['received_at'])->gt(Carbon::parse($data['as_of'])))) {
            throw ValidationException::withMessages(['as_of' => 'The cutoff must be on or after receipt of every selected LGU report.']);
        }
        if ($sources->pluck('incident_key')->unique()->count() > 1 && blank($data['relationship'] ?? null)) {
            throw ValidationException::withMessages(['relationship' => 'Explain how the selected incidents are related.']);
        }
        $snapshot = $service->consolidate($sources);
        $this->applyReviewEdits($snapshot, $data['review_edits'] ?? []);
        $selectedReleases = $releases->confirmedForSources($sources, $data['as_of']);
        foreach ($snapshot['tables']['assistance']['rows'] as $index => $row) {
            if ($row['level'] !== 'municipality') continue;
            $dswd = $selectedReleases->where('province', $row['province'])->where('municipality', $row['label'])->sum('cost');
            $snapshot['tables']['assistance']['rows'][$index]['values']['dswd'] = $dswd;
            $components = collect($snapshot['tables']['assistance']['rows'][$index]['values'])->except('total');
            $snapshot['tables']['assistance']['rows'][$index]['values']['total'] = $components->containsStrict(null) ? null : $components->sum();
        }
        foreach ($snapshot['tables']['assistance']['rows'] as $index => $row) if (in_array($row['level'], ['region', 'province'], true)) foreach (array_keys($row['values']) as $column) {
            $municipalities = collect($snapshot['tables']['assistance']['rows'])->where('level', 'municipality');
            if ($row['level'] === 'province') $municipalities = $municipalities->where('province', $row['province']);
            $snapshot['tables']['assistance']['rows'][$index]['values'][$column] = $municipalities->pluck('values.'.$column)->containsStrict(null) ? null : $municipalities->sum('values.'.$column);
        }
        $snapshot['dswd_assistance_releases'] = $selectedReleases->values()->all();
        $snapshot['annex_tables'] = $service->annexTables($snapshot);
        $snapshot['metadata'] = $data;
        $snapshot['metadata']['as_of'] = Carbon::parse($data['as_of'])->toIso8601String();
        $snapshot['metadata']['overview'] = $data['overview'] ?? '';
        $snapshot['metadata']['type_label'] = match ($data['report_type']) {
            'initial' => 'Initial Report', 'progress' => 'Progress Report No. '.$data['progress_number'],
            'terminal' => 'Terminal Report', 'first_final' => 'First and Final Report',
        };
        $snapshot['metadata']['prepared_by'] = [
            'name' => $request->user()->name,
            'position' => $request->user()->designation ?: $request->user()->position ?: 'DRIMS',
        ];
        $snapshot['standby_stockpile_summary'] = $stockpile->current();

        return $snapshot;
    }

    private function applyReviewEdits(array &$snapshot, array $edits): void
    {
        foreach ($edits as $section => $edit) {
            $container = $section === 'cccm' ? 'cccm' : 'tables';
            if (! isset($snapshot[$container][$section]) && $container === 'tables') continue;
            if ($container === 'cccm') $table =& $snapshot['cccm'];
            else $table =& $snapshot['tables'][$section];
            foreach ($edit['rows'] ?? [] as $editRow) {
                $index = (int) ($editRow['index'] ?? -1);
                if (! isset($table['rows'][$index]) || ! in_array($table['rows'][$index]['level'] ?? '', ['municipality', 'category'], true)) continue;
                foreach ($editRow['values'] ?? [] as $column => $value) {
                    if (! array_key_exists($column, $table['columns'])) continue;
                    if ($section === 'assistance' && in_array($column, ['dswd', 'total'], true)) continue;
                    $table['rows'][$index]['values'][$column] = $value === null || $value === '' ? null : (float) $value;
                }
                if ($section === 'assistance') {
                    $components = collect($table['rows'][$index]['values'])->except('total');
                    $table['rows'][$index]['values']['total'] = $components->containsStrict(null) ? null : $components->sum();
                }
            }
            $municipalities = collect($table['rows'])->where('level', 'municipality');
            foreach ($table['rows'] as $index => $row) {
                if (! in_array($row['level'] ?? '', ['region', 'province'], true)) continue;
                $group = $row['level'] === 'region' ? $municipalities : $municipalities->where('province', $row['province'] ?? null);
                foreach (array_keys($row['values']) as $column) {
                    $values = $group->pluck('values.'.$column);
                    $table['rows'][$index]['values'][$column] = $values->containsStrict(null) ? null : $values->sum();
                }
            }
            unset($table);
        }
    }

    public function store(Request $request, DswdDromicConsolidator $service, DromicFniReleaseService $releases, StandbyStockpileSummaryService $stockpile, AuditLogger $audit)
    {
        $snapshot = $this->input($request, $service, $releases, $stockpile);
        $report = DromicReport::create([
            'report_number' => 'DROMIC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'request_id' => $snapshot['sources'][0]['id'],
            'affected_lgu' => Str::limit(collect($snapshot['sources'])->pluck('municipality')->unique()->implode(', '), 255, ''),
            'purpose' => $snapshot['metadata']['title'], 'assessment' => $snapshot['metadata']['overview'],
            'status' => 'draft', 'created_by' => $request->user()->id, 'consolidation' => $snapshot,
        ]);
        $audit->log('dswd_dromic.created', $report, [], ['source_ids' => $snapshot['metadata']['source_ids'], 'fni_release_ids' => collect($snapshot['dswd_assistance_releases'])->pluck('id')->all()]);

        return redirect('/dromic')->with('success', 'DSWD DROMIC draft saved with its source data snapshot.');
    }

    public function download(Request $request, DromicReport $report, string $format, DswdDromicConsolidator $service, StandbyStockpileSummaryService $stockpile)
    {
        abort_unless($service->canRead($request->user(), $report), 403);
        abort_unless($report->consolidation, 404, 'This legacy report has no consolidated snapshot.');
        $snapshot = $report->consolidation;
        $snapshot['annex_tables'] ??= $service->annexTables($snapshot);
        $snapshot['standby_stockpile_summary'] ??= $stockpile->current();
        if ($format === 'pdf') {
            $preparedUser = $report->creator ?: $request->user();
            $signatories = $this->signatories($preparedUser);
            $pdf = Pdf::loadView('documents.dswd-dromic', compact('snapshot', 'report', 'signatories'))->setPaper('a4');
            $pdf->render();
            $dompdf = $pdf->getDomPDF();
            $dompdf->getCanvas()->page_script(function (int $pageNumber, int $pageCount, $canvas, $fontMetrics): void {
                $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
                $text = "Page {$pageNumber} of {$pageCount}";
                $size = 7.5;
                $rightEdge = $canvas->get_width() - 48;
                $canvas->text($rightEdge - $fontMetrics->getTextWidth($text, $font, $size), 817, $text, $font, $size, [0.42, 0.42, 0.42]);
            });

            return $pdf->download($report->report_number.'.pdf');
        }
        abort_unless($format === 'xlsx', 404);

        return response()->streamDownload(function () use ($snapshot) {
            $book = new Spreadsheet;
            $book->removeSheetByIndex(0);
            foreach (array_merge($snapshot['tables'], isset($snapshot['cccm']) ? ['CCCM & IDPP' => $snapshot['cccm']] : []) as $key => $table) {
                $sheet = $book->createSheet()->setTitle(ucfirst($key));
                $sheet->fromArray([['DSWD Field Office Caraga'], [$snapshot['metadata']['type_label'].' — '.$snapshot['metadata']['title']], ['As of '.$snapshot['metadata']['as_of']], [$table['title']], ['Province / City / Municipality', ...array_values($table['columns'])]], null, 'A1');
                $line = 6;
                foreach ($table['rows'] as $row) {
                    if (($row['level'] ?? '') === 'barangay') continue;
                    $sheet->fromArray([($row['level'] === 'municipality' ? '    ' : '').$row['label'], ...array_map(fn ($v) => $v ?? '-', array_values($row['values']))], null, 'A'.$line, true);
                    if ($row['level'] !== 'municipality') {
                        $sheet->getStyle('A'.$line.':'.$sheet->getHighestColumn().$line)->getFont()->setBold(true);
                    }
                    $line++;
                }
                $sheet->getStyle('A5:'.$sheet->getHighestColumn().'5')->getFont()->setBold(true);
                $sheet->getColumnDimension('A')->setWidth(38);
                foreach (range('B', $sheet->getHighestColumn()) as $column) {
                    $sheet->getColumnDimension($column)->setWidth(22);
                }
                if ($key === 'assistance') {
                    $sheet->getStyle('B6:'.$sheet->getHighestColumn().$line)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $sheet->freezePane('B6');
                $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
            }
            $sheet = $book->createSheet()->setTitle('Sources');
            $sheet->fromArray([['Reference', 'Incident', 'Province', 'City / Municipality', 'Received', 'Validation']]);
            foreach ($snapshot['sources'] as $i => $s) {
                $sheet->fromArray([$s['reference'], $s['incident'], $s['province'], $s['municipality'], $s['received_at'], $s['validation']], null, 'A'.($i + 2));
            }
            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setWidth(30);
            }
            $detail = $book->createSheet()->setTitle('Barangay detail');
            $detail->fromArray([['Province', 'City / Municipality', 'Barangay', 'Affected families', 'Affected persons', 'Source report']]);
            $line = 2;
            foreach ($snapshot['sources'] as $s) {
                foreach ($s['details']['areas'] as $area) {
                    $detail->fromArray([$s['province'], $s['municipality'], $area['area'] ?? '-', $area['affected_families'] ?? '-', $area['affected_persons'] ?? '-', $s['reference']], null, 'A'.$line++, true);
                }
            }
            foreach (range('A', 'F') as $column) {
                $detail->getColumnDimension($column)->setWidth(28);
            }
            $detail->freezePane('D2');
            // All workbook content is data; preserve formula-looking source text literally.
            foreach ($book->getAllSheets() as $tab) {
                foreach ($tab->getCellCollection()->getCoordinates() as $coordinate) {
                    $cell = $tab->getCell($coordinate);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $cell->setValueExplicit($cell->getValue(), DataType::TYPE_STRING);
                    }
                }
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $report->report_number.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function show(Request $request, DromicReport $report, DswdDromicConsolidator $service)
    {
        abort_unless($service->canRead($request->user(), $report), 403);
        abort_unless($report->consolidation, 404);
        $report->load('creator:id,name,position,designation');

        return response()->json([
            'report' => $report,
        ]);
    }
}
