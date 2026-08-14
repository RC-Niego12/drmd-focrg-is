<?php

namespace App\Http\Controllers;

use App\Models\PsgcAddress;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PsgcDistrictService;
use App\Services\PsgcSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PsgcAddressController extends Controller
{
    public function index(): Response
    {
        $selectedRegionCode = '1600000000';
        SystemSetting::setValue('default_region_code', $selectedRegionCode);
        $selectedRegion = PsgcAddress::query()
            ->where('level', 'region')
            ->where('is_active', true)
            ->where('code', $selectedRegionCode)
            ->first(['code', 'name', 'short_name']);

        if (! $selectedRegion) {
            $selectedRegion = PsgcAddress::query()
                ->where('level', 'region')
                ->where('is_active', true)
                ->where('code', $selectedRegionCode)
                ->first(['code', 'name', 'short_name']);
        }

        $provinces = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->where('parent_code', $selectedRegionCode)
            ->orderBy('name')
            ->get(['code', 'name', 'parent_code']);
        $provinceCodes = $provinces->pluck('code');

        $districts = PsgcAddress::query()
            ->where('level', 'district')
            ->where('is_active', true)
            ->whereIn('parent_code', $provinceCodes)
            ->orderBy('name')
            ->get(['id', 'code', 'parent_code', 'name', 'short_name', 'synced_at']);

        $citiesMunicipalities = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('parent_code', $provinceCodes)->orWhere('code', '1630400000'))
            ->orderBy('name')
            ->get(['id', 'code', 'parent_code', 'district_code', 'district', 'name']);
        $cityCodes = $citiesMunicipalities->pluck('code');

        $barangayCodes = PsgcAddress::query()
            ->where('level', 'barangay')
            ->where('is_active', true)
            ->whereIn('parent_code', $cityCodes)
            ->pluck('code');

        $metrics = [
            'regions' => $selectedRegion ? 1 : 0,
            'provinces' => $provinces->count(),
            'districts' => $districts->count(),
            'cities_municipalities' => $citiesMunicipalities->count(),
            'barangays' => $barangayCodes->count(),
        ];

        return Inertia::render('PsgcAddresses/Index', [
            'metrics' => $metrics,
            'regions' => PsgcAddress::query()
                ->where('level', 'region')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name', 'short_name', 'synced_at']),
            'selectedRegion' => $selectedRegion,
            'provinces' => $provinces,
            'districts' => $districts,
            'citiesMunicipalities' => $citiesMunicipalities,
            'settings' => [
                'field_office_label' => SystemSetting::getValue('field_office_label', 'DSWD Field Office Caraga'),
            ],
            'lastSyncedAt' => PsgcAddress::query()->max('synced_at'),
            'source' => [
                'base_url' => config('services.psgc.base_url'),
                'publication' => config('services.psgc.publication_name'),
                'publication_url' => config('services.psgc.publication_url'),
            ],
        ]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);

        $validated = $request->validate([
            'field_office_label' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $old = [
            'field_office_label' => SystemSetting::getValue('field_office_label', 'DSWD Field Office Caraga'),
        ];

        SystemSetting::setValue('default_region_code', '1600000000');
        SystemSetting::setValue('field_office_label', $validated['field_office_label']);
        $audit->log('psgc_settings.updated', null, $old, $validated);

        return back()->with('success', 'Field Office label updated.');
    }

    public function syncDistricts(PsgcDistrictService $districtService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses(request()->user()), 403);

        $summary = $districtService->syncFromWarehouses();
        $audit->log('psgc_districts.synced_from_warehouses', null, [], $summary);

        return back()->with('success', "District options synced: {$summary['created']} created, {$summary['updated']} updated, {$summary['assigned_cities']} city/municipality assignments updated, {$summary['skipped']} skipped.");
    }

    public function syncDistrictReferenceSheet(PsgcDistrictService $districtService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses(request()->user()), 403);

        try {
            $summary = $districtService->syncFromReferenceSheet();
            $audit->log('psgc_districts.synced_from_reference_sheet', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'District reference sheet sync failed.');
        }

        return back()->with('success', "District reference synced: {$summary['created']} created, {$summary['updated']} updated, {$summary['assigned_cities']} city/municipality assignments updated, {$summary['skipped']} skipped.");
    }

    public function storeDistrict(Request $request, PsgcDistrictService $districtService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);

        $validated = $request->validate([
            'province_code' => [
                'required',
                'string',
                Rule::exists('psgc_addresses', 'code')->where(fn ($query) => $query->where('level', 'province')->where('is_active', true)),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $district = PsgcAddress::updateOrCreate(
            ['code' => $districtService->districtCode($validated['province_code'], $validated['name'])],
            [
                'parent_code' => $validated['province_code'],
                'level' => 'district',
                'name' => $validated['name'],
                'short_name' => $validated['name'],
                'type' => 'Warehouse District',
                'district' => $validated['name'],
                'source' => 'manual',
                'source_version' => 'Super Admin district setting',
                'synced_at' => now(),
                'is_active' => true,
                'raw_payload' => $validated,
            ]
        );

        $audit->log('psgc_district.created', $district, [], $district->toArray());

        return back()->with('success', 'District option saved.');
    }

    public function updateDistrict(Request $request, PsgcAddress $district, PsgcDistrictService $districtService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);
        abort_unless($district->level === 'district', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $old = $district->toArray();
        $district->update([
            'code' => $districtService->districtCode($district->parent_code, $validated['name']),
            'name' => $validated['name'],
            'short_name' => $validated['name'],
            'district' => $validated['name'],
            'source' => 'manual',
            'source_version' => 'Super Admin district setting',
            'synced_at' => now(),
        ]);

        PsgcAddress::query()
            ->where('district_code', $old['code'])
            ->update([
                'district_code' => $district->code,
                'district' => $district->short_name,
            ]);

        $audit->log('psgc_district.updated', $district, $old, $district->fresh()->toArray());

        return back()->with('success', 'District option updated.');
    }

    public function assignCityDistrict(Request $request, PsgcAddress $city, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);
        abort_unless($city->level === 'city_municipality', 404);

        $validated = $request->validate([
            'district_code' => [
                'required',
                'string',
                Rule::exists('psgc_addresses', 'code')->where(fn ($query) => $query->where('level', 'district')->where('is_active', true)),
            ],
        ]);

        $district = PsgcAddress::query()
            ->where('code', $validated['district_code'])
            ->where('level', 'district')
            ->firstOrFail();

        $old = $city->toArray();
        $city->update([
            'district_code' => $district->code,
            'district' => $district->short_name ?: $district->name,
        ]);
        $audit->log('psgc_city.district_assigned', $city, $old, $city->fresh()->toArray());

        return back()->with('success', 'City/Municipality district assignment updated.');
    }

    public function sync(Request $request, PsgcSyncService $syncService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);

        $validated = $request->validate([
            'include_barangays' => ['nullable', 'boolean'],
        ]);

        try {
            $summary = $syncService->sync((bool) ($validated['include_barangays'] ?? true));
            $audit->log('psgc_addresses.synced', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'PSGC address sync failed.');
        }

        return back()->with('success', "PSGC sync complete: {$summary['regions']} regions, {$summary['provinces']} provinces, {$summary['cities_municipalities']} cities/municipalities, {$summary['barangays']} barangays.");
    }

    public function upload(Request $request, PsgcSyncService $syncService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManagePsgcAddresses($request->user()), 403);

        $validated = $request->validate([
            'publication_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'include_barangays' => ['nullable', 'boolean'],
        ]);

        $path = $request->file('publication_file')->store('psgc/uploads');

        try {
            $summary = $syncService->syncUploadedPublication(
                Storage::path($path),
                (bool) ($validated['include_barangays'] ?? true)
            );
            $audit->log('psgc_addresses.upload_synced', null, [], $summary);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Uploaded PSGC publication import failed.');
        } finally {
            Storage::delete($path);
        }

        return back()->with('success', "Uploaded PSGC file imported: {$summary['regions']} regions, {$summary['provinces']} provinces, {$summary['cities_municipalities']} cities/municipalities, {$summary['barangays']} barangays.");
    }

    private function canManagePsgcAddresses(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('Super Admin')
            || $user->can('manage users')
            || $user->can('manage psgc addresses');
    }
}
