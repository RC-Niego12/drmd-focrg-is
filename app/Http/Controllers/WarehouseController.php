<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseRequest;
use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLibraryValue;
use App\Services\AuditLogger;
use App\Services\WarehouseIdentityService;
use App\Services\WarehouseMasterSheetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        $request = request();
        $filterKeys = [
            'warehouse_name',
            'warehouse_type',
            'partnership',
            'category',
            'wh_focal',
            'storekeeper',
            'province',
            'district',
            'municipality',
            'status',
        ];
        $filters = collect($filterKeys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->filterArray($request, $key)])
            ->all();
        $filters['search'] = (string) $request->input('search', '');

        $allWarehouses = Warehouse::query()
            ->with('population')
            ->get();

        $filterColumns = [
            'warehouse_name' => 'name',
            'warehouse_type' => 'warehouse_type',
            'partnership' => 'partnership',
            'category' => 'category',
            'wh_focal' => 'contact_person',
            'storekeeper' => 'designated_storekeepers',
            'province' => 'province',
            'district' => 'district',
            'municipality' => 'municipality',
            'status' => 'status',
        ];

        $filterRows = function ($rows, ?string $except = null) use ($filters, $filterColumns) {
            foreach ($filterColumns as $filterKey => $column) {
                $value = $filters[$filterKey] ?? [];

                if ($filterKey === $except || $value === []) {
                    continue;
                }

                $rows = $rows->filter(fn (Warehouse $warehouse): bool => in_array((string) data_get($warehouse, $column), $value, true));
            }

            if ($except !== 'search' && filled($filters['search'] ?? null)) {
                $needle = strtolower((string) $filters['search']);

                $rows = $rows->filter(function (Warehouse $warehouse) use ($needle): bool {
                    $haystack = strtolower(implode(' ', [
                        $warehouse->external_warehouse_id,
                        $warehouse->name,
                        $warehouse->warehouse_number,
                        $warehouse->office,
                        $warehouse->province,
                        $warehouse->district,
                        $warehouse->municipality,
                        $warehouse->barangay_name,
                        $warehouse->barangay_code,
                        $warehouse->distribution_network,
                        $warehouse->warehouse_type,
                        $warehouse->category,
                        $warehouse->ownership,
                        $warehouse->partnership,
                        $warehouse->contact_person,
                        $warehouse->contact_number,
                        $warehouse->email,
                        $warehouse->designated_storekeepers,
                        $warehouse->storekeeper_contact_number,
                        $warehouse->status,
                    ]));

                    return str_contains($haystack, $needle);
                });
            }

            return $rows;
        };

        $optionValues = fn (string $except, string $column) => $filterRows($allWarehouses, $except)
            ->pluck($column)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $warehouses = $filterRows($allWarehouses)
            ->sortBy(['province', 'municipality', 'name'])
            ->values();

        return Inertia::render('Warehouses/Index', [
            'warehouses' => $warehouses,
            'metrics' => [
                'warehouses' => $warehouses->count(),
                'active' => $warehouses->where('status', 'active')->count(),
                'inactive' => $warehouses->where('status', 'inactive')->count(),
                'distribution_networks' => $this->summaryBy($warehouses, 'distribution_network'),
                'warehouse_types' => $this->summaryBy($warehouses, 'warehouse_type'),
                'categories' => $this->summaryBy($warehouses, 'category'),
                'partnerships' => $this->summaryBy($warehouses, 'partnership'),
            ],
            'filters' => $filters,
            'filterOptions' => [
                'warehouse_names' => $optionValues('warehouse_name', 'name'),
                'warehouse_types' => $optionValues('warehouse_type', 'warehouse_type'),
                'partnerships' => $optionValues('partnership', 'partnership'),
                'categories' => $optionValues('category', 'category'),
                'wh_focals' => $optionValues('wh_focal', 'contact_person'),
                'storekeepers' => $optionValues('storekeeper', 'designated_storekeepers'),
                'provinces' => $optionValues('province', 'province'),
                'districts' => $optionValues('district', 'district'),
                'municipalities' => $optionValues('municipality', 'municipality'),
                'statuses' => $optionValues('status', 'status'),
            ],
            'editOptions' => [
                'distribution_networks' => WarehouseLibraryValue::query()->where('library_type', 'distribution_network')->orderBy('value')->pluck('value')->unique()->values(),
                'warehouse_types' => WarehouseLibraryValue::query()->where('library_type', 'warehouse_type')->orderBy('value')->pluck('value')->unique()->values(),
                'categories' => WarehouseLibraryValue::query()->where('library_type', 'warehouse_category')->orderBy('value')->pluck('value')->unique()->values(),
                'ownerships' => WarehouseLibraryValue::query()->where('library_type', 'ownership')->orderBy('value')->pluck('value')->unique()->values(),
                'partnerships' => WarehouseLibraryValue::query()->where('library_type', 'partnership')->orderBy('value')->pluck('value')->unique()->values(),
                'classificationRules' => $this->classificationRules($allWarehouses),
            ],
            'sync' => [
                'url' => config('services.google_sheets.warehouse_master_url'),
                'worksheet' => config('services.google_sheets.warehouse_master_worksheet'),
                'last_synced_at' => Warehouse::max('master_synced_at'),
            ],
            'addressDefaults' => [
                'default_region_code' => SystemSetting::getValue('default_region_code', '1600000000'),
                'default_region_name' => PsgcAddress::query()
                    ->where('code', SystemSetting::getValue('default_region_code', '1600000000'))
                    ->value('short_name') ?? 'CARAGA',
            ],
        ]);
    }

    public function syncGoogleSheet(Request $request, WarehouseMasterSheetImportService $importer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageWarehouses($request->user()), 403);

        $url = config('services.google_sheets.warehouse_master_url');

        if (! $url) {
            return back()->with('error', 'Warehouse master Google Sheet URL is not configured.');
        }

        try {
            $summary = $importer->import($url, config('services.google_sheets.warehouse_master_worksheet'));
            $audit->log('warehouses.google_sheet_synced', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Warehouse master sync failed. Please try again.');
        }

        return back()->with('success', "Warehouse sync complete: {$summary['warehouses_synced']} synced, {$summary['rows_skipped']} skipped.");
    }

    public function generateIdentity(Request $request, WarehouseIdentityService $identity): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request->user()), 403);

        $data = $request->validate([
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'barangay_name' => ['nullable', 'string', 'max:255'],
            'warehouse_number' => ['nullable', 'string', 'max:255'],
            'distribution_network' => ['nullable', 'string', 'max:255'],
            'warehouse_type' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'ownership' => ['nullable', 'string', 'max:255'],
            'partnership' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($identity->generate($data));
    }

    private function canManageWarehouses(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA'])
            || $user->can('manage warehouses');
    }

    public function store(WarehouseRequest $request, AuditLogger $audit, WarehouseIdentityService $identity): RedirectResponse
    {
        $warehouse = Warehouse::create($identity->fillMissing($request->validated()));
        $audit->log('warehouse.created', $warehouse, [], $warehouse->toArray());

        return back()->with('success', 'Warehouse created.');
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse, AuditLogger $audit, WarehouseIdentityService $identity): RedirectResponse
    {
        $old = $warehouse->toArray();
        $warehouse->update($identity->fillMissing($request->validated(), $warehouse));
        $audit->log('warehouse.updated', $warehouse, $old, $warehouse->fresh()->toArray());

        return back()->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse, AuditLogger $audit): RedirectResponse
    {
        $warehouse->update(['status' => 'archived']);
        $warehouse->delete();
        $audit->log('warehouse.archived', $warehouse);

        return back()->with('success', 'Warehouse archived.');
    }

    private function summaryBy($warehouses, string|\Closure $field): array
    {
        return $warehouses
            ->groupBy(fn (Warehouse $warehouse): string => trim((string) (is_string($field) ? data_get($warehouse, $field) : $field($warehouse))) ?: 'Unspecified')
            ->map(fn ($group, string $label): array => [
                'label' => $label,
                'total' => $group->count(),
                'active' => $group->where('status', 'active')->count(),
                'inactive' => $group->where('status', 'inactive')->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function filterArray(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        return collect($value)
            ->flatten()
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function classificationRules($warehouses): array
    {
        return [
            'prepositioning' => [
                'distribution_network' => WarehouseLibraryValue::values('distribution_network', 'prepositioning')[0] ?? 'Last Mile',
                'categories' => WarehouseLibraryValue::values('warehouse_category', 'prepositioning'),
                'ownerships' => WarehouseLibraryValue::values('ownership', 'prepositioning'),
                'partnerships' => WarehouseLibraryValue::values('partnership', 'prepositioning'),
            ],
            'other' => [
                'distribution_network' => WarehouseLibraryValue::values('distribution_network', 'other')[0] ?? 'Spokes',
                'categories' => WarehouseLibraryValue::values('warehouse_category', 'other'),
                'ownerships' => WarehouseLibraryValue::values('ownership', 'other'),
                'partnerships' => WarehouseLibraryValue::values('partnership', 'other'),
            ],
        ];
    }
}
