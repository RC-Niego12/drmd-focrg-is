<?php

namespace App\Http\Controllers;

use App\Models\BarangayPopulation;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\PopulationSheetImportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PopulationController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    /**
     * @param  array{province_code?:string,district_code?:string,city_code?:string}  $lockedFilters
     * @param  array<string, mixed>  $viewProps
     */
    public function renderIndex(Request $request, array $lockedFilters = [], array $viewProps = []): Response
    {
        $activeRegionCode = SystemSetting::getValue('default_region_code', '1600000000');
        $selectedRegion = PsgcAddress::query()
            ->where('level', 'region')
            ->where('code', $activeRegionCode)
            ->first(['code', 'name', 'short_name']);
        $provinces = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->where('parent_code', $activeRegionCode)
            ->when(
                filled($lockedFilters['province_code'] ?? null),
                fn ($query) => $query->where('code', $lockedFilters['province_code']),
            )
            ->orderBy('name')
            ->get(['code', 'name']);
        $provinceCodes = $provinces->pluck('code');
        $districts = PsgcAddress::query()
            ->where('level', 'district')
            ->where('is_active', true)
            ->whereIn('parent_code', $provinceCodes)
            ->when(
                filled($lockedFilters['district_code'] ?? null),
                fn ($query) => $query->where('code', $lockedFilters['district_code']),
            )
            ->orderBy('name')
            ->get(['code', 'parent_code', 'name', 'short_name']);
        $cities = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('parent_code', $provinceCodes)->orWhere('code', '1630400000'))
            ->when(
                filled($lockedFilters['city_code'] ?? null),
                fn ($query) => $query->where('code', $lockedFilters['city_code']),
            )
            ->when(
                filled($lockedFilters['province_code'] ?? null) && blank($lockedFilters['city_code'] ?? null),
                function ($query) use ($lockedFilters): void {
                    $provinceCode = (string) $lockedFilters['province_code'];
                    $query->where(function ($inner) use ($provinceCode): void {
                        $inner->where('parent_code', $provinceCode);
                        if ($provinceCode === '1600200000') {
                            $inner->orWhere('code', '1630400000');
                        }
                    });
                },
            )
            ->orderBy('name')
            ->get(['code', 'parent_code', 'name', 'district', 'district_code']);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'province_code' => (string) ($lockedFilters['province_code'] ?? $request->query('province_code', '')),
            'district_code' => (string) ($lockedFilters['district_code'] ?? $request->query('district_code', '')),
            'city_code' => (string) ($lockedFilters['city_code'] ?? $request->query('city_code', '')),
        ];

        // Locked scope always wins over query-string tampering.
        foreach (['province_code', 'district_code', 'city_code'] as $key) {
            if (filled($lockedFilters[$key] ?? null)) {
                $filters[$key] = (string) $lockedFilters[$key];
            }
        }

        $scoped = fn (): Builder => $this->applyPopulationFilters(
            $this->populationJoinQuery($activeRegionCode),
            $filters,
        );

        $rows = $this->applyPopulationFilters($this->populationRowsQuery($activeRegionCode), $filters)
            ->orderBy('province.name')
            ->orderBy('city.name')
            ->orderBy('barangay.name')
            ->limit(500)
            ->get();

        $provinceTotals = $scoped()
            ->selectRaw('province.code, province.name, COUNT(DISTINCT city.code) as cities_count, COUNT(populations.id) as barangays_count, SUM(populations.population) as population, SUM(populations.estimated_families) as estimated_families')
            ->groupBy('province.code', 'province.name')
            ->orderByDesc('population')
            ->get();

        $provinceSummary = $scoped()
            ->selectRaw('province.code, province.name, COUNT(DISTINCT COALESCE(city.district_code, city.district)) as districts_count, COUNT(DISTINCT city.code) as cities_count, COUNT(populations.id) as barangays_count, SUM(populations.population) as population, SUM(populations.estimated_families) as estimated_families')
            ->groupBy('province.code', 'province.name')
            ->orderByDesc('population')
            ->get();

        $cityTotals = $scoped()
            ->selectRaw('city.code, city.name, province.name as province_name, SUM(populations.population) as population')
            ->groupBy('city.code', 'city.name', 'province.name')
            ->orderByDesc('population')
            ->limit(10)
            ->get();

        $regionWide = fn (): Builder => $this->populationJoinQuery($activeRegionCode);
        $caragaProvinceSummary = $regionWide()
            ->selectRaw('province.code, province.name, COUNT(DISTINCT COALESCE(city.district_code, city.district)) as districts_count, COUNT(DISTINCT city.code) as cities_count, COUNT(populations.id) as barangays_count, SUM(populations.population) as population, SUM(populations.estimated_families) as estimated_families')
            ->groupBy('province.code', 'province.name')
            ->orderByDesc('population')
            ->get();
        $caragaMetrics = [
            'total_population' => (int) $regionWide()->sum('populations.population'),
            'estimated_families' => (int) $regionWide()->sum('populations.estimated_families'),
            'provinces' => $caragaProvinceSummary->count(),
            'cities_municipalities' => (int) $regionWide()->select('city.code')->distinct()->get()->count(),
            'barangays_with_population' => (int) $regionWide()->count('populations.id'),
        ];

        return Inertia::render($viewProps['page'] ?? 'Population/Index', array_merge([
            'activeRegion' => $selectedRegion,
            'metrics' => [
                'total_population' => (int) $scoped()->sum('populations.population'),
                'estimated_families' => (int) $scoped()->sum('populations.estimated_families'),
                'provinces' => $provinceTotals->count(),
                'cities_municipalities' => (int) $scoped()->select('city.code')->distinct()->get()->count(),
                'barangays_with_population' => (int) $scoped()->count('populations.id'),
            ],
            'provinceTotals' => $provinceTotals,
            'provinceSummary' => $provinceSummary,
            'caragaProvinceSummary' => $caragaProvinceSummary,
            'caragaMetrics' => $caragaMetrics,
            'lguMapPoints' => (
                filled($lockedFilters['city_code'] ?? null) || filled($lockedFilters['province_code'] ?? null)
            ) ? $this->buildLguBarangayMapPoints($rows) : [],
            'cityTotals' => $cityTotals,
            'distribution' => $provinceTotals,
            'records' => $rows,
            'provinces' => $provinces,
            'districts' => $districts,
            'citiesMunicipalities' => $cities,
            'filters' => $filters,
            'lockedFilters' => [
                'province_code' => (string) ($lockedFilters['province_code'] ?? ''),
                'district_code' => (string) ($lockedFilters['district_code'] ?? ''),
                'city_code' => (string) ($lockedFilters['city_code'] ?? ''),
            ],
            'sourceUrl' => config('services.google_sheets.population_url'),
            'workspace' => 'admin',
            'readOnly' => false,
            'filterBasePath' => '/population',
        ], $viewProps));
    }

    public function import(PopulationSheetImportService $importer, AuditLogger $audit): RedirectResponse
    {
        abort_unless(request()->user()?->can('manage users') || request()->user()?->can('manage population'), 403);

        try {
            $summary = $importer->import();
            $audit->log('population.imported', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Population import failed.');
        }

        return back()->with('success', "Population import complete: {$summary['imported']} imported, {$summary['updated']} updated, {$summary['skipped']} skipped.");
    }

    public function update(Request $request, BarangayPopulation $population, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('manage users') || $request->user()?->can('manage population'), 403);

        $validated = $request->validate([
            'population' => ['nullable', 'integer', 'min:0'],
            'estimated_families' => ['nullable', 'integer', 'min:0'],
            'census_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'source' => ['nullable', 'string', 'max:255'],
            'last_updated' => ['nullable', 'date'],
            'barangay_psgc_code' => [
                'required',
                'string',
                Rule::exists('psgc_addresses', 'code')->where(fn ($query) => $query->where('level', 'barangay')),
            ],
        ]);

        $old = $population->toArray();
        $population->update([
            ...$validated,
            'poor_families' => null,
            'poor_individuals' => null,
        ]);
        $audit->log('population.updated', $population, $old, $population->fresh()->toArray());

        return back()->with('success', 'Population record updated.');
    }

    /**
     * Map points for LGU barangays using real barangay centroids when available.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<array{code:string,name:string,city_name:?string,population:int,estimated_families:int,lat:float,lng:float}>
     */
    private function buildLguBarangayMapPoints($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        [$cityLat, $cityLng] = $this->resolveLguMapCenter($rows->first());
        $barangayCentroids = config('caraga_barangay_centroids', []);

        $sorted = $rows->sortBy('barangay_name')->values();
        $total = max(1, $sorted->count());
        $points = [];
        $fallbackIndex = 0;

        foreach ($sorted as $row) {
            $code = (string) ($row->barangay_psgc_code ?? '');
            $coords = null;

            if ($code !== '' && isset($barangayCentroids[$code]) && is_array($barangayCentroids[$code])) {
                $coords = [(float) $barangayCentroids[$code][0], (float) $barangayCentroids[$code][1]];
            }

            if ($coords === null) {
                // Approximate only when the barangay has no stored coordinates.
                $angle = (2 * M_PI * $fallbackIndex) / $total;
                $radius = 0.0035 + (min($fallbackIndex, 20) * 0.00025);
                $coords = [
                    $cityLat + cos($angle) * $radius,
                    $cityLng + sin($angle) * $radius,
                ];
                $fallbackIndex++;
            }

            $points[] = [
                'code' => $code,
                'name' => (string) ($row->barangay_name ?? 'Barangay'),
                'city_name' => $row->city_name ?? null,
                'population' => (int) ($row->population ?? 0),
                'estimated_families' => (int) ($row->estimated_families ?? 0),
                'lat' => round($coords[0], 6),
                'lng' => round($coords[1], 6),
            ];
        }

        return $points;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveLguMapCenter(object $sampleRow): array
    {
        $cityCode = (string) ($sampleRow->city_code ?? '');
        $centroids = config('caraga_lgu_centroids', []);

        if ($cityCode !== '' && isset($centroids[$cityCode]) && is_array($centroids[$cityCode])) {
            return [(float) $centroids[$cityCode][0], (float) $centroids[$cityCode][1]];
        }

        $cityName = strtoupper(trim((string) ($sampleRow->city_name ?? '')));
        $byName = [
            'TUBOD' => [9.5547, 125.5697],
            'SURIGAO CITY' => [9.7847, 125.4950],
            'CITY OF BUTUAN' => [8.9475, 125.5406],
            'BUTUAN CITY' => [8.9475, 125.5406],
            'MAINIT' => [9.5350, 125.5230],
            'TUBAY' => [9.1670, 125.5240],
        ];
        if (isset($byName[$cityName])) {
            return $byName[$cityName];
        }

        $provinceName = strtoupper(trim((string) ($sampleRow->province_name ?? '')));

        return match (true) {
            str_contains($provinceName, 'AGUSAN DEL NORTE') => [9.05, 125.55],
            str_contains($provinceName, 'AGUSAN DEL SUR') => [8.55, 125.75],
            str_contains($provinceName, 'DINAGAT') => [10.12, 125.62],
            str_contains($provinceName, 'SURIGAO DEL NORTE') => [9.70, 125.50],
            str_contains($provinceName, 'SURIGAO DEL SUR') => [8.95, 126.18],
            default => [9.2, 125.55],
        };
    }

    private function applyPopulationFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['province_code'] !== '', fn ($query) => $query->where('province.code', $filters['province_code']))
            ->when($filters['district_code'] !== '', fn ($query) => $query->where('city.district_code', $filters['district_code']))
            ->when($filters['city_code'] !== '', fn ($query) => $query->where('city.code', $filters['city_code']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = "%{$filters['search']}%";
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('barangay.name', 'like', $search)
                        ->orWhere('city.name', 'like', $search)
                        ->orWhere('city.district', 'like', $search)
                        ->orWhere('province.name', 'like', $search)
                        ->orWhere('populations.barangay_psgc_code', 'like', $search);
                });
            });
    }

    private function populationRowsQuery(string $activeRegionCode)
    {
        return $this->populationJoinQuery($activeRegionCode)
            ->select([
                'populations.id',
                'populations.barangay_psgc_code',
                'populations.population',
                'populations.estimated_families',
                'populations.census_year',
                'populations.source',
                'populations.last_updated',
                'province.code as province_code',
                'province.name as province_name',
                'city.code as city_code',
                'city.name as city_name',
                'city.district as city_district',
                'city.district_code as city_district_code',
                'barangay.name as barangay_name',
            ]);
    }

    private function populationJoinQuery(string $activeRegionCode)
    {
        return DB::table('barangay_populations as populations')
            ->join('psgc_addresses as barangay', function ($join): void {
                $join->on('barangay.code', '=', 'populations.barangay_psgc_code')
                    ->where('barangay.level', '=', 'barangay')
                    ->where('barangay.is_active', '=', true);
            })
            ->join('psgc_addresses as city', function ($join): void {
                $join->on('city.code', '=', 'barangay.parent_code')
                    ->where('city.level', '=', 'city_municipality')
                    ->where('city.is_active', '=', true);
            })
            ->join('psgc_addresses as province', function ($join): void {
                $join->on('province.code', '=', DB::raw("CASE WHEN city.code = '1630400000' THEN '1600200000' ELSE city.parent_code END"))
                    ->where('province.level', '=', 'province')
                    ->where('province.is_active', '=', true);
            })
            ->where('province.parent_code', $activeRegionCode);
    }
}
