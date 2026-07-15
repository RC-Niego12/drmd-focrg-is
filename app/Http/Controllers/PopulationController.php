<?php

namespace App\Http\Controllers;

use App\Models\BarangayPopulation;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\PopulationSheetImportService;
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
        $activeRegionCode = SystemSetting::getValue('default_region_code', '1600000000');
        $selectedRegion = PsgcAddress::query()
            ->where('level', 'region')
            ->where('code', $activeRegionCode)
            ->first(['code', 'name', 'short_name']);
        $provinces = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->where('parent_code', $activeRegionCode)
            ->orderBy('name')
            ->get(['code', 'name']);
        $provinceCodes = $provinces->pluck('code');
        $cities = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('parent_code', $provinceCodes)->orWhere('code', '1630400000'))
            ->orderBy('name')
            ->get(['code', 'parent_code', 'name']);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'province_code' => (string) $request->query('province_code', ''),
            'city_code' => (string) $request->query('city_code', ''),
        ];

        $base = $this->populationRowsQuery($activeRegionCode)
            ->when($filters['province_code'] !== '', fn ($query) => $query->where('province.code', $filters['province_code']))
            ->when($filters['city_code'] !== '', fn ($query) => $query->where('city.code', $filters['city_code']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = "%{$filters['search']}%";
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('barangay.name', 'like', $search)
                        ->orWhere('city.name', 'like', $search)
                        ->orWhere('province.name', 'like', $search)
                        ->orWhere('populations.barangay_psgc_code', 'like', $search);
                });
            });

        $rows = (clone $base)
            ->orderBy('province.name')
            ->orderBy('city.name')
            ->orderBy('barangay.name')
            ->limit(500)
            ->get();

        $provinceTotals = $this->populationJoinQuery($activeRegionCode)
            ->selectRaw('province.code, province.name, COUNT(DISTINCT city.code) as cities_count, COUNT(populations.id) as barangays_count, SUM(populations.population) as population, SUM(populations.estimated_families) as estimated_families')
            ->groupBy('province.code', 'province.name')
            ->orderByDesc('population')
            ->get();

        $provinceSummary = $this->populationJoinQuery($activeRegionCode)
            ->selectRaw('province.code, province.name, COUNT(DISTINCT COALESCE(city.district_code, city.district)) as districts_count, COUNT(DISTINCT city.code) as cities_count, COUNT(populations.id) as barangays_count, SUM(populations.population) as population, SUM(populations.estimated_families) as estimated_families')
            ->groupBy('province.code', 'province.name')
            ->orderByDesc('population')
            ->get();

        $cityTotals = $this->populationJoinQuery($activeRegionCode)
            ->selectRaw('city.code, city.name, province.name as province_name, SUM(populations.population) as population')
            ->groupBy('city.code', 'city.name', 'province.name')
            ->orderByDesc('population')
            ->limit(10)
            ->get();

        return Inertia::render('Population/Index', [
            'activeRegion' => $selectedRegion,
            'metrics' => [
                'total_population' => (int) $this->populationJoinQuery($activeRegionCode)->sum('populations.population'),
                'estimated_families' => (int) $this->populationJoinQuery($activeRegionCode)->sum('populations.estimated_families'),
                'provinces' => $provinceTotals->count(),
                'cities_municipalities' => $this->populationJoinQuery($activeRegionCode)->distinct('city.code')->count('city.code'),
                'barangays_with_population' => $this->populationJoinQuery($activeRegionCode)->count('populations.id'),
            ],
            'provinceTotals' => $provinceTotals,
            'provinceSummary' => $provinceSummary,
            'cityTotals' => $cityTotals,
            'distribution' => $provinceTotals,
            'records' => $rows,
            'provinces' => $provinces,
            'citiesMunicipalities' => $cities,
            'filters' => $filters,
            'sourceUrl' => config('services.google_sheets.population_url'),
        ]);
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
