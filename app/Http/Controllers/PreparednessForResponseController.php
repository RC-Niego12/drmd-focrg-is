<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\DromicReport;
use App\Models\InventoryBatch;
use App\Models\PreparednessBriefingIntro;
use App\Models\PreparednessActionPage;
use App\Models\PreparednessQrtCoverageArea;
use App\Models\PreparednessQrtSpecialization;
use App\Models\PreparednessResponseAsset;
use App\Models\PreparednessReport;
use App\Models\RegionalAlert;
use App\Models\StandbyFund;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use App\Services\InventoryBalanceService;
use App\Services\EvacuationCenterInventoryService;
use App\Services\PreparednessEvacuationPhotos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PreparednessForResponseController extends Controller
{
    public function evacuationPhoto(string $center, EvacuationCenterInventoryService $inventory, PreparednessEvacuationPhotos $photos): \Illuminate\Http\Response
    {
        $record = collect($inventory->inventory()['centers'])->firstWhere('id', $center);
        abort_unless($record, 404);
        $image = $photos->image($record);
        return response($image['body'], 200, ['Content-Type' => $image['type'], 'Cache-Control' => 'private, max-age=86400']);
    }

    public function __invoke(PreparednessReport $report, InventoryBalanceService $balanceService): Response
    {
        // A finalized report is an historical record. Serve its captured data
        // directly and do not query or recalculate current inventory figures.
        if ($report->status === 'finalized' && filled($report->data_snapshot)) {
            return Inertia::render('PreparednessForResponse/Index', array_replace(
                $report->data_snapshot,
                $this->editableReportProps($report, false),
            ));
        }

        $warehouses = Warehouse::query()->where('status', 'active')->get();
        $activeWarehouseIds = $warehouses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $rows = $balanceService->balanceRows()
            ->filter(fn (array $row): bool => in_array((int) ($row['warehouse_id'] ?? 0), $activeWarehouseIds, true))
            ->values();
        $inventory = $balanceService->summary($rows);
        $standbyFund = StandbyFund::current();
        $activeAlerts = RegionalAlert::query()->effective()->latest('effective_at')->get();

        $itemRows = $rows->groupBy(fn (array $row): string => trim((string) ($row['item'] ?? '')) ?: 'Unspecified item')
            ->map(fn (Collection $items, string $item): array => [
                'item' => $item,
                'category' => trim((string) ($items->first()['category'] ?? '')) ?: 'Unspecified',
                'quantity' => $items->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                'value' => $items->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                'warehouses' => $items->pluck('warehouse_id')->filter()->unique()->count(),
            ])->values();

        // balanceRows omits entries whose quantity and cost are both zero. Merge
        // the imported WIT catalog so valid zero-stock items remain reportable.
        $witImports = WarehouseSheetImport::query()
            ->with(['item:id,name,category', 'warehouse:id,province'])
            ->whereHas('warehouse', fn ($query) => $query->where('status', 'active'))
            ->where('import_status', 'imported')
            ->whereNotNull('inventory_item_id')
            ->get();
        $witCatalogRows = $witImports
            ->pluck('item')
            ->filter()
            ->unique(fn ($item): string => mb_strtolower(trim((string) $item->name)))
            ->map(fn ($item): array => [
                'item' => trim((string) $item->name),
                'category' => trim((string) $item->category) ?: 'Unspecified',
                'quantity' => 0,
                'value' => 0,
                'warehouses' => 0,
            ]);
        $balancedItemNames = $itemRows
            ->pluck('item')
            ->map(fn (string $item): string => mb_strtolower(trim($item)))
            ->all();
        $itemRows = $itemRows
            ->concat($witCatalogRows->reject(
                fn (array $row): bool => in_array(mb_strtolower($row['item']), $balancedItemNames, true)
            ))
            ->sortByDesc('quantity')
            ->values();
        $bottledWaterVariants = $witImports
            ->filter(fn (WarehouseSheetImport $import): bool => mb_strtolower(trim((string) ($import->raw_payload['item'] ?? $import->item?->name))) === 'water')
            ->map(fn (WarehouseSheetImport $import): string => trim((string) ($import->raw_payload['brand_description'] ?? '')))
            ->filter(fn (string $brand): bool => str_starts_with(mb_strtolower($brand), 'bottled'))
            ->unique()
            ->values();

        $warehouseRows = $rows->groupBy('warehouse_id')->map(function (Collection $stockRows): array {
            $first = $stockRows->first();

            return [
                'warehouse' => $first['warehouse'] ?? 'Unspecified warehouse',
                'province' => $first['warehouse_province'] ?? 'Unspecified',
                'municipality' => $first['warehouse_municipality'] ?? 'Unspecified',
                'district' => $first['warehouse_district'] ?? 'Unspecified',
                'type' => $first['warehouse_type'] ?? 'Unspecified',
                'partnership' => $first['partnership'] ?? 'Unspecified',
                'ffp_capacity' => (float) ($first['warehouse_ffp_capacity'] ?? 0),
                'rtef_capacity' => (float) ($first['warehouse_rtef_capacity'] ?? 0),
                'quantity' => $stockRows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                'value' => $stockRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                'ffp' => $stockRows->filter(fn (array $row): bool => $this->matches($row['item'] ?? '', ['family food pack']))
                    ->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                'ffp_cost' => $stockRows->filter(fn (array $row): bool => $this->matches($row['item'] ?? '', ['family food pack']))
                    ->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                'rtef' => $stockRows->filter(fn (array $row): bool => $this->matches($row['item'] ?? '', ['ready to eat', 'rtef']))
                    ->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                'rtef_cost' => $stockRows->filter(fn (array $row): bool => $this->matches($row['item'] ?? '', ['ready to eat', 'rtef']))
                    ->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
            ];
        })->sortByDesc('quantity')->values();

        $stockedWarehouseIds = $rows->pluck('warehouse_id')->filter()->unique();
        $capacityOnlyWarehouses = $warehouses
            ->whereNotIn('id', $stockedWarehouseIds)
            ->filter(fn (Warehouse $warehouse): bool => (float) $warehouse->ffp_capacity > 0 || (float) $warehouse->rtef_capacity > 0)
            ->map(fn (Warehouse $warehouse): array => [
                'warehouse' => $warehouse->display_name ?: $warehouse->name,
                'province' => $warehouse->province ?: 'Unspecified',
                'municipality' => $warehouse->municipality ?: 'Unspecified',
                'district' => $warehouse->district ?: 'Unspecified',
                'type' => $warehouse->warehouse_type ?: 'Unspecified',
                'partnership' => $warehouse->partnership ?: 'Unspecified',
                'ffp_capacity' => (float) $warehouse->ffp_capacity,
                'rtef_capacity' => (float) $warehouse->rtef_capacity,
                'quantity' => 0,
                'value' => 0,
                'ffp' => 0,
                'ffp_cost' => 0,
                'rtef' => 0,
                'rtef_cost' => 0,
            ]);
        $warehouseRows = $warehouseRows->concat($capacityOnlyWarehouses)->sortByDesc('quantity')->values();

        $provinceRows = collect(['Agusan Del Norte', 'Agusan Del Sur', 'Dinagat Islands', 'Surigao Del Norte', 'Surigao Del Sur'])
            ->map(function (string $province) use ($warehouseRows, $warehouses): array {
                $stock = $warehouseRows->where('province', $province);
                $nodes = $warehouses->where('province', $province);

                return [
                    'province' => $province,
                    'warehouses' => $nodes->count(),
                    'active_warehouses' => $nodes->where('status', 'active')->count(),
                    // Match the RROS FFP Status rule: a warehouse is included
                    // when it has stock or encoded FFP capacity. Warehouses with
                    // both values at zero display "-" and are not counted.
                    'ffp_warehouses' => $stock->filter(fn (array $row): bool =>
                        (float) ($row['ffp'] ?? 0) > 0
                        || (float) ($row['ffp_capacity'] ?? 0) > 0
                    )->count(),
                    'ffp' => $stock->sum('ffp'),
                    'ffp_cost' => $stock->sum('ffp_cost'),
                    'capacity' => (float) $nodes->sum('ffp_capacity'),
                    'quantity' => $stock->sum('quantity'),
                    'value' => $stock->sum('value'),
                ];
            })->values();

        $ffpInventoryRows = $rows->filter(fn (array $row): bool => $this->matches($row['item'] ?? '', ['family food pack']));
        $ffpCost = $ffpInventoryRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0));
        $warehousesById = $warehouses->keyBy('id');
        $ffpProvinceRows = collect(['Agusan Del Norte', 'Agusan Del Sur', 'Dinagat Islands', 'Surigao Del Norte', 'Surigao Del Sur'])
            ->map(function (string $province) use ($ffpInventoryRows, $warehousesById, $warehouses): array {
                $provinceFfpRows = $ffpInventoryRows->filter(
                    fn (array $row): bool => $warehousesById->get($row['warehouse_id'] ?? null)?->province === $province
                );

                return [
                    'province' => $province,
                    'capacity' => (float) $warehouses->where('province', $province)->sum('ffp_capacity'),
                    'current' => $provinceFfpRows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                    'cost' => $provinceFfpRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                ];
            })->values();
        $ffpCallouts = $ffpInventoryRows
            ->groupBy(function (array $row) use ($warehousesById): string {
                $warehouse = $warehousesById->get($row['warehouse_id'] ?? null);
                $province = $warehouse?->province ?: 'Unspecified';
                $district = strtoupper(preg_replace('/\s+/', '', (string) $warehouse?->district));

                if ($province === 'Surigao Del Norte') {
                    return $district === 'SDN1' ? 'Surigao del Norte - Siargao' : 'Surigao del Norte - Mainland';
                }

                return match ($province) {
                    'Dinagat Islands' => 'Province of Dinagat Islands',
                    default => $province,
                };
            })
            ->map(fn (Collection $group, string $label): array => [
                'label' => $label,
                'current' => $group->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
            ])->values();
        $otherInventoryRows = $rows->reject(fn (array $row): bool => $this->matches($row['item'] ?? '', ['family food pack']));
        $otherCost = $otherInventoryRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0));

        $props = [
            'asOf' => ($report->reporting_as_of ?? $report->created_at ?? now())->toIso8601String(),
            'summary' => [
                'standby_funds' => (float) $standbyFund->amount,
                'stockpile_value' => (float) $inventory['cost'],
                'total_resources' => (float) $standbyFund->amount + (float) $inventory['cost'],
                'stockpile_quantity' => (float) $inventory['current_balance'],
                'active_warehouses' => $warehouses->count(),
                'total_warehouses' => $warehouses->count(),
                'active_alerts' => $activeAlerts->count(),
                'near_expiry' => InventoryBatch::query()
                    ->whereHas('warehouse', fn ($query) => $query->where('status', 'active'))
                    ->nearExpiry()
                    ->count(),
            ],
            'provinceRows' => $provinceRows,
            'warehouseRows' => $warehouseRows,
            'itemRows' => $itemRows,
            'bottledWaterVariants' => $bottledWaterVariants,
            ...$this->editableReportProps($report, $report->isDraft() && $report->canBeRevised() && (request()->user()?->hasRole('DRIMS') ?? false)),
            'itemScopeRows' => $rows->groupBy(fn (array $row): string => implode('|', [
                trim((string) ($row['item'] ?? '')) ?: 'Unspecified item',
                trim((string) ($row['category'] ?? '')) ?: 'Unspecified',
                trim((string) ($row['brand_description'] ?? '')) ?: 'Unspecified',
                trim((string) ($row['warehouse_province'] ?? '')) ?: 'Unspecified',
                trim((string) ($row['warehouse_district'] ?? '')) ?: 'Unspecified',
                $row['warehouse_id'] ?? 'none',
            ]))->map(function (Collection $scopeRows): array {
                $first = $scopeRows->first();

                return [
                    'item' => trim((string) ($first['item'] ?? '')) ?: 'Unspecified item',
                    'category' => trim((string) ($first['category'] ?? '')) ?: 'Unspecified',
                    'brand_description' => trim((string) ($first['brand_description'] ?? '')) ?: 'Unspecified',
                    'province' => trim((string) ($first['warehouse_province'] ?? '')) ?: 'Unspecified',
                    'district' => trim((string) ($first['warehouse_district'] ?? '')) ?: 'Unspecified',
                    'warehouse_id' => $first['warehouse_id'] ?? null,
                    'quantity' => $scopeRows->sum(fn (array $row): float => (float) ($row['current_balance'] ?? 0)),
                    'value' => $scopeRows->sum(fn (array $row): float => (float) ($row['cost'] ?? 0)),
                ];
            })->values(),
            'ffpRows' => $itemRows->filter(fn (array $row): bool => $this->matches($row['item'], ['family food pack']))->values(),
            'rtefRows' => $itemRows->filter(fn (array $row): bool => $this->matches($row['item'], ['ready to eat', 'rtef']))->values(),
            'nfiRows' => $itemRows->filter(fn (array $row): bool => $this->matches($row['category'], ['non food', 'nfi']))->values(),
            'shelterRows' => $itemRows->filter(fn (array $row): bool => $this->matches($row['item'], ['tent', 'tarpaulin', 'shelter', 'sleeping']))->values(),
            'specialRows' => $itemRows->filter(fn (array $row): bool => $this->matches($row['item'], ['mobile', 'command center', 'kitchen', 'child friendly', 'women friendly', 'cccm']))->values(),
            'alerts' => $activeAlerts->map(fn (RegionalAlert $alert): array => [
                'id' => $alert->id,
                'level' => $alert->alert_level,
                'incident' => $alert->incident_name,
                'coverage' => $alert->coverage,
                'reason' => $alert->reason,
                'effective_at' => $alert->effective_at?->toIso8601String(),
            ]),
            'operations' => [
                'dromic_reports' => DromicReport::query()->count(),
                'reports_this_month' => DromicReport::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'requests' => AssistanceRequest::query()->where('submission_type', '!=', 'lgu_dromic_relief_request')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'dispatches' => DispatchPlan::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
            'ffpSummary' => [
                'total_current' => $ffpProvinceRows->sum('current'),
                'total_capacity' => $ffpProvinceRows->sum('capacity'),
                'total_cost' => $ffpProvinceRows->sum('cost'),
                'province_rows' => $ffpProvinceRows,
                'callouts' => $ffpCallouts,
            ],
            'standbyStockpileSummary' => [
                'office' => $standbyFund->office,
                'standby_funds' => (float) $standbyFund->amount,
                'source' => $standbyFund->source,
                'synced_at' => $standbyFund->synced_at?->toIso8601String(),
                'ffp_quantity' => $provinceRows->sum('ffp'),
                'ffp_cost' => $ffpCost,
                'other_food_non_food_cost' => $otherCost,
                'total_standby_funds_stockpile' => (float) $standbyFund->amount + $ffpCost + $otherCost,
                'ffp_breakdown' => $ffpInventoryRows->groupBy(fn (array $row): string => trim((string) ($row['warehouse_type'] ?? '')) ?: 'Unspecified')
                    ->map(fn (Collection $group, string $warehouseType): array => [
                        'warehouse_type' => $warehouseType,
                        'current' => $group->sum('current_balance'),
                        'cost' => $group->sum('cost'),
                    ])->values(),
                'other_breakdown' => $otherInventoryRows->groupBy(fn (array $row): string => trim((string) ($row['category'] ?? '')) ?: 'Unspecified')
                    ->map(fn (Collection $group, string $label): array => ['label' => $label, 'cost' => $group->sum('cost')])
                    ->sortByDesc('cost')->values(),
            ],
        ];

        if ($report->status === 'finalized' && filled($report->data_snapshot)) {
            $props = array_replace($props, $report->data_snapshot);
        }

        return Inertia::render('PreparednessForResponse/Index', $props);
    }

    private function matches(?string $value, array $needles): bool
    {
        $value = strtolower((string) $value);

        return collect($needles)->contains(fn (string $needle): bool => str_contains($value, $needle));
    }

    private function editableReportProps(PreparednessReport $report, bool $canEdit): array
    {
        $populationLgus = DB::table('barangay_populations as populations')
            ->join('psgc_addresses as barangay', function ($join): void {
                $join->on('barangay.code', '=', 'populations.barangay_psgc_code')
                    ->where('barangay.level', 'barangay')
                    ->where('barangay.is_active', true);
            })
            ->join('psgc_addresses as city', function ($join): void {
                $join->on('city.code', '=', 'barangay.parent_code')
                    ->where('city.level', 'city_municipality')
                    ->where('city.is_active', true);
            })
            ->join('psgc_addresses as province', function ($join): void {
                $join->on('province.code', '=', DB::raw("CASE WHEN city.code = '1630400000' THEN '1600200000' ELSE city.parent_code END"))
                    ->where('province.level', 'province')
                    ->where('province.is_active', true);
            })
            ->where('province.parent_code', '1600000000')
            ->groupBy('province.code', 'province.name', 'city.code', 'city.name', 'city.district', 'city.district_code')
            ->orderBy('province.name')
            ->orderBy('city.name')
            ->get([
                'province.code as province_code',
                'province.name as province',
                'city.code',
                'city.name',
                'city.district',
                'city.district_code',
                DB::raw('SUM(COALESCE(populations.population, 0)) as population'),
            ]);

        $ecInventory = app(EvacuationCenterInventoryService::class)->inventory();
        $centers = collect($ecInventory['centers'] ?? []);
        $permanent = $centers->filter(fn (array $row): bool => strcasecmp((string) ($row['availability'] ?? ''), 'Permanent') === 0);
        $temporary = $centers->filter(fn (array $row): bool => strcasecmp((string) ($row['availability'] ?? ''), 'Temporary') === 0);
        $totalCenters = $centers->count();
        $percentage = fn (int $count): float => $totalCenters > 0 ? round($count * 100 / $totalCenters, 1) : 0.0;
        $yes = fn (array $row, string $key): bool => strcasecmp((string) data_get($row, "facilities.{$key}", ''), 'Yes') === 0;
        $evacuationCenterSummary = [
            'total_identified' => $totalCenters,
            'permanent_centers' => $permanent->count(),
            'permanent_percentage' => $percentage($permanent->count()),
            'temporary_centers' => $temporary->count(),
            'temporary_percentage' => $percentage($temporary->count()),
            'unclassified_centers' => $totalCenters - $permanent->count() - $temporary->count(),
            'selected_photos' => app(PreparednessEvacuationPhotos::class)->selected($centers),
            'camp_safety_audited' => null,
            'temporary_school_camps' => $centers->filter(fn (array $row): bool => strcasecmp((string) ($row['availability'] ?? ''), 'Temporary') === 0 && strcasecmp((string) ($row['ec_type'] ?? ''), 'School') === 0)->count(),
            'facilities' => [
                'child_friendly_space' => $permanent->filter(fn (array $row): bool => $yes($row, 'child_friendly_space'))->count(),
                'women_friendly_space' => $permanent->filter(fn (array $row): bool => $yes($row, 'women_friendly_space'))->count(),
                'shelter_accommodation' => $permanent->filter(fn (array $row): bool => (int) ($row['rooms'] ?? 0) > 0)->count(),
                'camp_management_desk' => $permanent->filter(fn (array $row): bool => $yes($row, 'help_desk') || filled($row['camp_manager'] ?? null))->count(),
                'community_kitchen' => $permanent->filter(fn (array $row): bool => $yes($row, 'community_kitchen'))->count(),
                'storage_area' => $permanent->filter(fn (array $row): bool => $yes($row, 'ffps_storage'))->count(),
                'toilets_bathing' => $permanent->filter(fn (array $row): bool => $yes($row, 'female_cr') || $yes($row, 'male_cr') || $yes($row, 'common_cr') || $yes($row, 'sealed_latrine'))->count(),
                'handwashing' => $permanent->filter(fn (array $row): bool => $yes($row, 'wash_facility'))->count(),
                'water_based' => $permanent->filter(fn (array $row): bool => $yes($row, 'potable_water') || $yes($row, 'non_potable_water'))->count(),
                'laundry_space' => $permanent->filter(fn (array $row): bool => $yes($row, 'laundry_space'))->count(),
                'health_facilities' => $permanent->filter(fn (array $row): bool => $yes($row, 'health_station'))->count(),
                'couples_room' => $permanent->filter(fn (array $row): bool => $yes($row, 'couples_room'))->count(),
                'prayer_rooms' => $permanent->filter(fn (array $row): bool => $yes($row, 'prayer_room'))->count(),
                'livestock_area' => $permanent->filter(fn (array $row): bool => $yes($row, 'animals_area'))->count(),
            ],
            'source' => $ecInventory['source'] ?? null,
            'generated_at' => $ecInventory['generated_at'] ?? null,
        ];

        return [
            'currentReport' => $report->load(['creator:id,name', 'finalizer:id,name']),
            'briefingIntro' => PreparednessBriefingIntro::query()->where('preparedness_report_id', $report->id)->first(),
            'actionPages' => PreparednessActionPage::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->orderBy('id')->get(),
            'responseAssets' => PreparednessResponseAsset::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->orderBy('id')->get(),
            'qrtCoverageAreas' => PreparednessQrtCoverageArea::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->orderBy('id')->get(),
            'qrtSpecializations' => PreparednessQrtSpecialization::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->orderBy('id')->get(),
            'populationLgus' => $populationLgus,
            'evacuationCenterSummary' => $evacuationCenterSummary,
            'canEditPreparednessData' => $canEdit,
            'canRevisePreparednessReport' => (request()->user()?->hasRole('DRIMS') ?? false) && $report->canBeRevised(),
        ];
    }
}
