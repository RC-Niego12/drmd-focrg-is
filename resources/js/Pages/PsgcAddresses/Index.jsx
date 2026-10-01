import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Link2,
    MapPinned,
    Plus,
    RefreshCw,
    Save,
    Search,
    UploadCloud,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDateTime } from '@/Utils/dateFormat';

const regionLabel = (region) => String(region?.short_name || region?.name || '').toUpperCase();
const BUTUAN_CITY_CODE = '1630400000';
const AGUSAN_DEL_NORTE_CODE = '1600200000';

const cityProvinceCode = (city) => (
    city?.code === BUTUAN_CITY_CODE ? AGUSAN_DEL_NORTE_CODE : city?.parent_code
);

export default function Index({ metrics, regions, selectedRegion, provinces, districts, citiesMunicipalities, populationCrossmatch, settings, lastSyncedAt, source }) {
    const [processing, setProcessing] = useState(false);
    const [file, setFile] = useState(null);
    const [selectedProvinceCode, setSelectedProvinceCode] = useState(provinces?.[0]?.code || '');
    const [selectedCityCode, setSelectedCityCode] = useState('');
    const [barangays, setBarangays] = useState([]);
    const [barangayLoading, setBarangayLoading] = useState(false);
    const [coverageProvinceCode, setCoverageProvinceCode] = useState(provinces?.[0]?.code || '');
    const [coverageQuery, setCoverageQuery] = useState('');
    const [expandedDistricts, setExpandedDistricts] = useState(() => new Set());

    const settingsForm = useForm({
        field_office_label: settings.field_office_label || 'DSWD Field Office Caraga',
    });
    const districtForm = useForm({
        province_code: '',
        name: '',
    });
    const districtEditForm = useForm({
        district_id: '',
        name: '',
    });
    const assignmentForm = useForm({
        city_id: '',
        district_code: '',
    });

    const provinceMap = useMemo(() => Object.fromEntries((provinces || []).map((province) => [province.code, province.name])), [provinces]);
    const provinceOptions = (provinces || []).map((province) => ({
        value: province.code,
        label: province.name,
        raw: province,
    }));
    const citiesByProvince = useMemo(() => (citiesMunicipalities || []).reduce((grouped, city) => {
        const provinceCode = cityProvinceCode(city);
        return {
            ...grouped,
            [provinceCode]: [...(grouped[provinceCode] || []), city],
        };
    }, {}), [citiesMunicipalities]);
    const districtOptions = (districts || []).map((district) => ({
        value: district.code,
        label: `${district.name}`,
        hint: provinceMap[district.parent_code] || 'Province',
        raw: district,
    }));
    const cityOptions = (citiesMunicipalities || []).map((city) => ({
        value: String(city.id),
        label: `${provinceMap[cityProvinceCode(city)] || 'Province'} - ${city.name}`,
        raw: city,
    }));
    const cityBrowserOptions = (citiesByProvince[selectedProvinceCode] || []).map((city) => ({
        value: city.code,
        label: city.name,
        raw: city,
    }));
    const selectedCity = cityBrowserOptions.find((option) => option.value === selectedCityCode)?.raw;

    const coverageTree = useMemo(() => (
        (provinces || []).map((province) => {
            const provinceDistricts = (districts || [])
                .filter((district) => district.parent_code === province.code)
                .slice()
                .sort((a, b) => String(a.name).localeCompare(String(b.name)));
            const provinceCities = (citiesMunicipalities || [])
                .filter((city) => cityProvinceCode(city) === province.code)
                .slice()
                .sort((a, b) => String(a.name).localeCompare(String(b.name)));

            const districtBuckets = provinceDistricts.map((district) => {
                const cities = provinceCities.filter((city) => (
                    city.district_code === district.code
                    || city.district === district.name
                    || city.district === district.short_name
                ));

                return { district, cities };
            });

            const assignedCodes = new Set(districtBuckets.flatMap((bucket) => bucket.cities.map((city) => city.code)));
            const unassigned = provinceCities.filter((city) => !assignedCodes.has(city.code));

            return {
                province,
                districts: districtBuckets,
                unassigned,
                totalCities: provinceCities.length,
                assignedCities: assignedCodes.size,
            };
        })
    ), [provinces, districts, citiesMunicipalities]);

    const activeCoverage = coverageTree.find((row) => row.province.code === coverageProvinceCode) || coverageTree[0];

    useEffect(() => {
        setSelectedProvinceCode(provinces?.[0]?.code || '');
        setCoverageProvinceCode(provinces?.[0]?.code || '');
        setSelectedCityCode('');
        setBarangays([]);
    }, [selectedRegion?.code]);

    useEffect(() => {
        if (!activeCoverage) {
            return;
        }

        setExpandedDistricts(new Set([
            ...activeCoverage.districts.map((bucket) => bucket.district.code),
            ...(activeCoverage.unassigned.length > 0 ? ['unassigned'] : []),
        ]));
    }, [activeCoverage?.province?.code]);

    const focusCityAssignment = (city) => {
        assignmentForm.setData({
            city_id: String(city.id),
            district_code: city.district_code || '',
        });
        document.getElementById('psgc-city-assignment')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const toggleDistrict = (key) => {
        setExpandedDistricts((current) => {
            const next = new Set(current);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const loadBarangays = async (cityCode) => {
        setSelectedCityCode(cityCode);
        setBarangays([]);

        if (!cityCode) {
            return;
        }

        setBarangayLoading(true);

        try {
            const response = await fetch(`/psgc/cities-municipalities/${cityCode}/barangays`, {
                headers: { Accept: 'application/json' },
            });
            setBarangays(response.ok ? await response.json() : []);
        } catch (exception) {
            setBarangays([]);
        } finally {
            setBarangayLoading(false);
        }
    };

    const sync = (includeBarangays) => {
        setProcessing(true);
        router.post('/psgc-addresses/sync', { include_barangays: includeBarangays }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const upload = () => {
        if (!file) {
            return;
        }

        const payload = new FormData();
        payload.append('publication_file', file);
        payload.append('include_barangays', '1');

        setProcessing(true);
        router.post('/psgc-addresses/upload', payload, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    };

    const saveSettings = (event) => {
        event.preventDefault();
        settingsForm.patch('/psgc-addresses/settings', { preserveScroll: true });
    };

    const saveDistrict = (event) => {
        event.preventDefault();
        districtForm.post('/psgc-addresses/districts', {
            preserveScroll: true,
            onSuccess: () => districtForm.reset('name'),
        });
    };

    const updateDistrict = (event) => {
        event.preventDefault();

        if (!districtEditForm.data.district_id) {
            return;
        }

        districtEditForm.patch(`/psgc-addresses/districts/${districtEditForm.data.district_id}`, { preserveScroll: true });
    };

    const assignCityDistrict = (event) => {
        event.preventDefault();

        if (!assignmentForm.data.city_id) {
            return;
        }

        assignmentForm.patch(`/psgc-addresses/cities/${assignmentForm.data.city_id}/district`, { preserveScroll: true });
    };

    return (
        <AppLayout title="PSGC Addresses">
            <Head title="PSGC Addresses" />

            <section id="psgc-summary-cards" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <Metric title="Regions" value={metrics.regions} />
                <Metric title="Provinces" value={metrics.provinces} />
                <Metric title="Districts" value={metrics.districts} />
                <Metric title="Cities / Municipalities" value={metrics.cities_municipalities} />
                <Metric title="Barangays" value={metrics.barangays} />
            </section>

            <ExportableCard id="psgc-reference" title="Local PSGC Address Reference" className="mt-6">
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-black">
                            <MapPinned className="h-5 w-5 text-brand-700 dark:text-brand-100" />
                            Local PSGC Address Reference
                        </h2>
                        <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            PSGC address management is optimized for Caraga operations. The Field Office label below is used in the sidebar and address-related pages.
                        </p>
                        <form onSubmit={saveSettings} className="mt-4 grid max-w-3xl gap-3 md:grid-cols-[minmax(0,1fr)_auto]">
                            <label className="text-sm font-medium">
                                Field Office Label
                                <input
                                    className="mt-1 w-full"
                                    value={settingsForm.data.field_office_label}
                                    onChange={(event) => settingsForm.setData('field_office_label', event.target.value)}
                                    placeholder="DSWD Field Office Caraga"
                                />
                            </label>
                            <button
                                disabled={settingsForm.processing}
                                className="self-end inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white transition hover:bg-brand-800 disabled:opacity-60"
                            >
                                <Save className="h-4 w-4" />
                                Save Label
                            </button>
                        </form>
                        {settingsForm.errors.field_office_label && <p className="mt-1 text-xs text-rose-600">{settingsForm.errors.field_office_label}</p>}
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Operational Region</dt>
                                <dd className="font-bold">{regionLabel(selectedRegion) || 'CARAGA'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Field Office Label</dt>
                                <dd className="font-bold">{settings.field_office_label || 'DSWD Field Office Caraga'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Source</dt>
                                <dd className="font-bold">{source.publication}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">PSA Publication URL</dt>
                                <dd className="break-all font-bold">{source.publication_url || source.base_url}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Last synced</dt>
                                <dd className="font-bold">{lastSyncedAt || 'Not synced yet'}</dd>
                            </div>
                        </dl>
                    </div>
                    <SyncPanel file={file} setFile={setFile} processing={processing} sync={sync} upload={upload} />
                </div>
            </ExportableCard>

            <ExportableCard id="psgc-barangay-browser" title="PSGC Barangay Browser" className="mt-6 overflow-hidden p-0">
                <div className="border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <h2 className="text-lg font-black">PSGC Barangay Browser</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        Select a province, then a city or municipality. Barangays load only for the selected city/municipality.
                    </p>
                </div>

                <div className="p-5">
                    <div className="grid gap-3 lg:grid-cols-2">
                        <SearchableSelect
                            label="Province"
                            placeholder="Select province"
                            options={provinceOptions}
                            value={selectedProvinceCode}
                            onChange={(value) => {
                                setSelectedProvinceCode(value);
                                loadBarangays('');
                            }}
                        />
                        <SearchableSelect
                            label="City / Municipality"
                            placeholder={selectedProvinceCode ? 'Select city or municipality' : 'Select province first'}
                            options={cityBrowserOptions}
                            value={selectedCityCode}
                            onChange={loadBarangays}
                        />
                    </div>

                    <DataTable
                        className="mt-4"
                        columns={['Barangay PSGC Code', 'Barangay', 'City / Municipality']}
                        rows={
                            selectedCityCode
                                ? (barangayLoading
                                    ? [<tr key="loading"><td colSpan="3" className="px-4 py-8 text-center text-sm font-semibold text-slate-500">Loading barangays...</td></tr>]
                                    : barangays.length > 0
                                        ? barangays.map((barangay) => (
                                            <tr key={barangay.code}>
                                                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs font-bold">{barangay.code}</td>
                                                <td className="whitespace-nowrap px-4 py-3 font-black">{barangay.name}</td>
                                                <td className="whitespace-nowrap px-4 py-3">{selectedCity?.name || '-'}</td>
                                            </tr>
                                        ))
                                        : [<tr key="empty"><td colSpan="3" className="px-4 py-8 text-center text-sm font-semibold text-slate-500">No barangays found for the selected city/municipality.</td></tr>])
                                : [<tr key="select-city"><td colSpan="3" className="px-4 py-8 text-center text-sm font-semibold text-slate-500">Select a city or municipality to display barangays.</td></tr>]
                        }
                    />
                </div>
            </ExportableCard>

            <ExportableCard id="psgc-district-options" title="Province District Options" className="mt-6">
                <div className="space-y-4">
                    <div>
                        <h2 className="text-lg font-black">Province District Options</h2>
                        <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Districts are stored in the local address table and shown in the warehouse modal after selecting a province.
                        </p>
                    </div>
                    <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_auto_auto]">
                        <form onSubmit={saveDistrict} className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                            <SearchableSelect
                                label="Province"
                                placeholder="Select province"
                                options={provinceOptions}
                                value={districtForm.data.province_code}
                                onChange={(value) => districtForm.setData('province_code', value)}
                            />
                            <label className="text-sm font-medium">
                                District
                                <input
                                    className="mt-1 w-full"
                                    value={districtForm.data.name}
                                    onChange={(event) => districtForm.setData('name', event.target.value)}
                                    placeholder="Example: Lone ADN"
                                />
                            </label>
                            <button
                                disabled={districtForm.processing}
                                className="self-end inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-black text-white transition hover:bg-slate-800 disabled:opacity-60 dark:bg-white dark:text-slate-950"
                            >
                                <Plus className="h-4 w-4" />
                                Add
                            </button>
                        </form>
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => router.post('/psgc-addresses/districts/sync-warehouses', {}, { preserveScroll: true })}
                            className="self-end inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Sync from Warehouses
                        </button>
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => router.post('/psgc-addresses/districts/sync-reference-sheet', {}, { preserveScroll: true })}
                            className="self-end inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Sync District Sheet
                        </button>
                    </div>
                </div>
            </ExportableCard>

            <ExportableCard id="psgc-city-assignment" title="District Assignments" className="mt-6">
                <div className="grid gap-6 xl:grid-cols-2">
                    <form onSubmit={updateDistrict} className="space-y-3">
                        <div>
                            <h2 className="text-lg font-black">Edit District Name</h2>
                            <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                Keep short labels such as SDN1, ADS1, Lone ADN, and Lone PDI.
                            </p>
                        </div>
                        <SearchableSelect
                            label="District"
                            placeholder="Select district"
                            options={districtOptions.map((option) => ({
                                value: String(option.raw.id),
                                label: `${option.hint} - ${option.label}`,
                                raw: option.raw,
                            }))}
                            value={districtEditForm.data.district_id}
                            onChange={(value) => {
                                const selected = districtOptions.find((option) => String(option.raw.id) === String(value))?.raw;
                                districtEditForm.setData({ district_id: value, name: selected?.name || '' });
                            }}
                        />
                        <label className="text-sm font-medium">
                            Short District Name
                            <input className="mt-1 w-full" value={districtEditForm.data.name} onChange={(event) => districtEditForm.setData('name', event.target.value)} placeholder="Example: SDN1" />
                        </label>
                        <button disabled={districtEditForm.processing} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white transition hover:bg-brand-800 disabled:opacity-60">
                            <Save className="h-4 w-4" />
                            Save District Name
                        </button>
                    </form>

                    <form onSubmit={assignCityDistrict} className="space-y-3">
                        <div>
                            <h2 className="text-lg font-black">Assign City/Municipality</h2>
                            <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                This controls the District to City/Municipality dropdown in Add Warehouse. Pick a city from the atlas below to jump here.
                            </p>
                        </div>
                        <SearchableSelect
                            label="City / Municipality"
                            placeholder="Select city or municipality"
                            options={cityOptions}
                            value={assignmentForm.data.city_id}
                            onChange={(value) => {
                                const selected = cityOptions.find((option) => String(option.value) === String(value))?.raw;
                                assignmentForm.setData({
                                    city_id: value,
                                    district_code: selected?.district_code || '',
                                });
                            }}
                        />
                        <SearchableSelect
                            label="District"
                            placeholder="Select district"
                            options={districtOptions.map((option) => ({
                                value: option.value,
                                label: `${option.label} · ${option.hint}`,
                                raw: option.raw,
                            }))}
                            value={assignmentForm.data.district_code}
                            onChange={(value) => assignmentForm.setData('district_code', value)}
                        />
                        <button disabled={assignmentForm.processing} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white transition hover:bg-brand-800 disabled:opacity-60">
                            <Save className="h-4 w-4" />
                            Save Assignment
                        </button>
                    </form>
                </div>
            </ExportableCard>

            <ExportableCard id="psgc-district-coverage" title="District Coverage Atlas" className="mt-6 overflow-hidden p-0">
                <DistrictCoverageAtlas
                    coverageTree={coverageTree}
                    activeCoverage={activeCoverage}
                    coverageProvinceCode={coverageProvinceCode || activeCoverage?.province?.code || ''}
                    setCoverageProvinceCode={setCoverageProvinceCode}
                    coverageQuery={coverageQuery}
                    setCoverageQuery={setCoverageQuery}
                    expandedDistricts={expandedDistricts}
                    toggleDistrict={toggleDistrict}
                    onCitySelect={focusCityAssignment}
                    selectedCityId={assignmentForm.data.city_id}
                    populationCrossmatch={populationCrossmatch}
                />
            </ExportableCard>
        </AppLayout>
    );
}

function DistrictCoverageAtlas({
    coverageTree,
    activeCoverage,
    coverageProvinceCode,
    setCoverageProvinceCode,
    coverageQuery,
    setCoverageQuery,
    expandedDistricts,
    toggleDistrict,
    onCitySelect,
    selectedCityId,
    populationCrossmatch,
}) {
    const needle = coverageQuery.trim().toLowerCase();
    const crossmatchCities = populationCrossmatch?.cities || {};
    const crossmatchSummary = populationCrossmatch?.summary || {};
    const crossmatchIssues = (populationCrossmatch?.issues || []).filter((issue) => (
        !coverageProvinceCode || issue.province_code === coverageProvinceCode
    ));

    const cityMeta = (city) => crossmatchCities[city.code] || {
        status: 'no_population',
        population: 0,
        barangay_count: 0,
        sheet_district: null,
        expected_short: null,
        psgc_district: city.district || null,
    };

    const coveragePercent = activeCoverage?.totalCities
        ? Math.round((activeCoverage.assignedCities / activeCoverage.totalCities) * 100)
        : 0;

    const provincePopulation = (activeCoverage?.districts || []).reduce((sum, bucket) => (
        sum + bucket.cities.reduce((inner, city) => inner + Number(cityMeta(city).population || 0), 0)
    ), 0) + (activeCoverage?.unassigned || []).reduce((sum, city) => sum + Number(cityMeta(city).population || 0), 0);

    const provinceMatchCount = [...(activeCoverage?.districts || []).flatMap((bucket) => bucket.cities), ...(activeCoverage?.unassigned || [])]
        .filter((city) => cityMeta(city).status === 'match').length;

    const matchesCity = (city) => {
        if (!needle) {
            return true;
        }

        const meta = cityMeta(city);

        return [city.name, city.district, city.code, meta.sheet_district, meta.expected_short]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(needle));
    };

    const visibleDistricts = (activeCoverage?.districts || [])
        .map((bucket) => ({
            ...bucket,
            cities: bucket.cities.filter(matchesCity),
            population: bucket.cities.reduce((sum, city) => sum + Number(cityMeta(city).population || 0), 0),
        }))
        .filter((bucket) => !needle || bucket.cities.length > 0 || String(bucket.district.name).toLowerCase().includes(needle));

    const visibleUnassigned = (activeCoverage?.unassigned || []).filter(matchesCity);
    const visibleCityCount = visibleDistricts.reduce((sum, bucket) => sum + bucket.cities.length, 0) + visibleUnassigned.length;
    const aligned = Boolean(crossmatchSummary.aligned);

    return (
        <div>
            <div className="relative overflow-hidden border-b border-emerald-900/10 bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.18),_transparent_42%),linear-gradient(135deg,#ecfdf5_0%,#f8fafc_48%,#eff6ff_100%)] px-5 py-5 dark:border-zinc-800 dark:bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.12),_transparent_40%),linear-gradient(135deg,#052e1f_0%,#18181b_55%,#0f172a_100%)]">
                <div className="pointer-events-none absolute -right-10 top-0 h-40 w-40 rounded-full bg-brand-400/20 blur-3xl" />
                <div className="relative flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div className="max-w-2xl">
                        <div className="inline-flex items-center gap-2 rounded-full border border-brand-700/15 bg-white/70 px-3 py-1 text-[11px] font-black uppercase tracking-[0.18em] text-brand-800 shadow-sm backdrop-blur dark:border-brand-300/20 dark:bg-zinc-950/50 dark:text-brand-100">
                            <Building2 className="h-3.5 w-3.5" />
                            Province · District · LGU · Population
                        </div>
                        <h2 className="mt-3 text-2xl font-black tracking-tight text-slate-950 dark:text-white">District Coverage Atlas</h2>
                        <p className="mt-1 text-sm font-semibold text-slate-600 dark:text-zinc-300">
                            Crossmatched against population sheet legislative districts. Click an LGU to load it in Assign City/Municipality.
                        </p>
                    </div>

                    {activeCoverage && (
                        <div className="grid min-w-[280px] gap-3 sm:grid-cols-2">
                            <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-950/60">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Coverage</p>
                                        <p className="mt-1 text-2xl font-black text-slate-950 dark:text-white">{coveragePercent}%</p>
                                    </div>
                                    <div className="relative h-14 w-14">
                                        <svg viewBox="0 0 36 36" className="h-14 w-14 -rotate-90">
                                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="currentColor" strokeWidth="3" className="text-slate-200 dark:text-zinc-700" pathLength="100" />
                                            <circle
                                                cx="18"
                                                cy="18"
                                                r="15.5"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="3"
                                                strokeLinecap="round"
                                                pathLength="100"
                                                strokeDasharray={`${coveragePercent} ${100 - coveragePercent}`}
                                                className="text-brand-700 dark:text-brand-300"
                                            />
                                        </svg>
                                        <span className="absolute inset-0 grid place-items-center text-[10px] font-black text-slate-700 dark:text-zinc-200">
                                            {activeCoverage.assignedCities}/{activeCoverage.totalCities}
                                        </span>
                                    </div>
                                </div>
                                <p className="mt-2 text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                    {activeCoverage.districts.length} district{activeCoverage.districts.length === 1 ? '' : 's'}
                                    {activeCoverage.unassigned.length > 0 ? ` · ${activeCoverage.unassigned.length} unassigned` : ' · fully mapped'}
                                </p>
                            </div>
                            <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-950/60">
                                <p className="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Population</p>
                                <p className="mt-1 text-2xl font-black text-slate-950 dark:text-white">{Number(provincePopulation).toLocaleString()}</p>
                                <p className="mt-2 text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                    {provinceMatchCount}/{activeCoverage.totalCities} LGUs aligned with sheet districts
                                </p>
                            </div>
                        </div>
                    )}
                </div>

                <div className={`mt-4 flex flex-col gap-2 rounded-2xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between ${
                    aligned
                        ? 'border-emerald-300/80 bg-emerald-50/90 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-50'
                        : 'border-amber-300/80 bg-amber-50/90 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-50'
                }`}>
                    <div className="flex items-start gap-3">
                        {aligned ? <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" /> : <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />}
                        <div>
                            <p className="text-sm font-black">
                                {aligned
                                    ? `Population area crossmatch passed · ${crossmatchSummary.match || 0}/${crossmatchSummary.cities_with_population || 0} cities match short district labels`
                                    : `Population area issues found · ${crossmatchSummary.mismatch || 0} mismatch, ${crossmatchSummary.psgc_missing_district || 0} missing PSGC district, ${crossmatchSummary.no_population || 0} without population`}
                            </p>
                            <p className="mt-0.5 text-xs font-semibold opacity-80">
                                Source: population sheet LEG. DISTRICT values shortened to ADS1 / Lone ADN style and compared with managed PSGC assignments.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 text-[11px] font-black uppercase tracking-wide">
                        <span className="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/20">Match {crossmatchSummary.match || 0}</span>
                        <span className="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/20">Mismatch {crossmatchSummary.mismatch || 0}</span>
                        <span className="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/20">No pop {crossmatchSummary.no_population || 0}</span>
                    </div>
                </div>
            </div>

            <div className="grid gap-0 xl:grid-cols-[280px_minmax(0,1fr)]">
                <aside className="border-b border-slate-200 bg-slate-50/80 p-4 dark:border-zinc-800 dark:bg-zinc-950/40 xl:border-b-0 xl:border-r">
                    <p className="mb-3 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Provinces</p>
                    <div className="flex gap-2 overflow-x-auto pb-1 xl:flex-col xl:overflow-visible xl:pb-0">
                        {coverageTree.map((row) => {
                            const active = row.province.code === coverageProvinceCode;
                            const percent = row.totalCities ? Math.round((row.assignedCities / row.totalCities) * 100) : 0;
                            const pop = [...row.districts.flatMap((bucket) => bucket.cities), ...row.unassigned]
                                .reduce((sum, city) => sum + Number(cityMeta(city).population || 0), 0);

                            return (
                                <button
                                    key={row.province.code}
                                    type="button"
                                    onClick={() => setCoverageProvinceCode(row.province.code)}
                                    className={`min-w-[180px] rounded-2xl border px-3 py-3 text-left transition xl:min-w-0 ${
                                        active
                                            ? 'border-brand-700 bg-brand-700 text-white shadow-md shadow-brand-700/20'
                                            : 'border-slate-200 bg-white text-slate-800 hover:border-brand-300 hover:bg-brand-50/60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-brand-500/40'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm font-black leading-tight">{row.province.name}</span>
                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-black ${active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-300'}`}>
                                            {row.districts.length}d
                                        </span>
                                    </div>
                                    <div className={`mt-3 h-1.5 overflow-hidden rounded-full ${active ? 'bg-white/25' : 'bg-slate-200 dark:bg-zinc-700'}`}>
                                        <div
                                            className={`h-full rounded-full transition-all ${active ? 'bg-white' : 'bg-brand-600'}`}
                                            style={{ width: `${percent}%` }}
                                        />
                                    </div>
                                    <p className={`mt-2 text-[11px] font-semibold ${active ? 'text-white/80' : 'text-slate-500 dark:text-zinc-400'}`}>
                                        {row.assignedCities}/{row.totalCities} LGUs · {Number(pop).toLocaleString()} pop
                                    </p>
                                </button>
                            );
                        })}
                    </div>
                </aside>

                <div className="p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-lg font-black text-slate-950 dark:text-white">{activeCoverage?.province?.name || 'Select a province'}</h3>
                            <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                Showing {visibleCityCount} LGU{visibleCityCount === 1 ? '' : 's'}
                                {needle ? ` matching “${coverageQuery.trim()}”` : ''}
                            </p>
                        </div>
                        <label className="relative block w-full sm:max-w-xs">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className="w-full rounded-xl border-slate-200 bg-white pl-9 text-sm font-semibold dark:border-zinc-700 dark:bg-zinc-900"
                                value={coverageQuery}
                                onChange={(event) => setCoverageQuery(event.target.value)}
                                placeholder="Search city, district, or sheet label..."
                            />
                        </label>
                    </div>

                    {crossmatchIssues.length > 0 && (
                        <div className="mt-4 overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/80 dark:border-amber-900/40 dark:bg-amber-950/20">
                            <div className="border-b border-amber-200/80 px-4 py-3 dark:border-amber-900/40">
                                <p className="text-sm font-black text-amber-950 dark:text-amber-50">Area issues in this province</p>
                                <p className="text-xs font-semibold text-amber-800/80 dark:text-amber-200/80">
                                    PSGC district label differs from the population sheet, or population coverage is incomplete.
                                </p>
                            </div>
                            <div className="divide-y divide-amber-200/70 dark:divide-amber-900/30">
                                {crossmatchIssues.slice(0, 12).map((issue) => (
                                    <button
                                        key={issue.code}
                                        type="button"
                                        onClick={() => {
                                            const city = [...(activeCoverage?.districts || []).flatMap((bucket) => bucket.cities), ...(activeCoverage?.unassigned || [])]
                                                .find((row) => row.code === issue.code);
                                            if (city) {
                                                onCitySelect(city);
                                            }
                                        }}
                                        className="flex w-full flex-col gap-1 px-4 py-3 text-left transition hover:bg-amber-100/70 dark:hover:bg-amber-950/40 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <p className="text-sm font-black text-amber-950 dark:text-amber-50">{issue.name}</p>
                                            <p className="text-xs font-semibold text-amber-800/80 dark:text-amber-200/80">
                                                PSGC: {issue.psgc_district || '—'} · Sheet: {issue.sheet_district || '—'} → {issue.expected_short || '—'}
                                            </p>
                                        </div>
                                        <span className="text-[11px] font-black uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                            {issue.status.replaceAll('_', ' ')}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="mt-5 space-y-3">
                        {visibleDistricts.map((bucket, index) => {
                            const open = expandedDistricts.has(bucket.district.code);
                            const accent = DISTRICT_ACCENTS[index % DISTRICT_ACCENTS.length];

                            return (
                                <section
                                    key={bucket.district.code}
                                    className={`overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950 ${accent.ring}`}
                                >
                                    <button
                                        type="button"
                                        onClick={() => toggleDistrict(bucket.district.code)}
                                        className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50/80 dark:hover:bg-zinc-900/80"
                                    >
                                        <span className={`grid h-10 w-10 place-items-center rounded-xl text-sm font-black ${accent.badge}`}>
                                            {bucket.district.name}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-black text-slate-950 dark:text-white">{bucket.district.name}</p>
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                    {bucket.cities.length} LGU{bucket.cities.length === 1 ? '' : 's'}
                                                </span>
                                                <span className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-brand-800 dark:bg-brand-950/40 dark:text-brand-100">
                                                    <Users className="h-3 w-3" />
                                                    {Number(bucket.population || 0).toLocaleString()}
                                                </span>
                                            </div>
                                            <p className="truncate text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                Code {bucket.district.code}
                                                {bucket.district.synced_at ? ` · synced ${formatDateTime(bucket.district.synced_at)}` : ''}
                                            </p>
                                        </div>
                                        {open ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
                                    </button>

                                    {open && (
                                        <div className={`border-t border-slate-100 px-4 py-4 dark:border-zinc-800 ${accent.panel}`}>
                                            {bucket.cities.length > 0 ? (
                                                <div className="flex flex-wrap gap-2">
                                                    {bucket.cities.map((city) => (
                                                        <CityChip
                                                            key={city.code}
                                                            city={city}
                                                            meta={cityMeta(city)}
                                                            selected={String(selectedCityId) === String(city.id)}
                                                            onSelect={onCitySelect}
                                                        />
                                                    ))}
                                                </div>
                                            ) : (
                                                <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">No cities or municipalities assigned to this district yet.</p>
                                            )}
                                        </div>
                                    )}
                                </section>
                            );
                        })}

                        {(visibleUnassigned.length > 0 || (!needle && (activeCoverage?.unassigned?.length || 0) > 0)) && (
                            <section className="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/70 shadow-sm dark:border-amber-900/40 dark:bg-amber-950/20">
                                <button
                                    type="button"
                                    onClick={() => toggleDistrict('unassigned')}
                                    className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-amber-100/60 dark:hover:bg-amber-950/40"
                                >
                                    <span className="grid h-10 w-10 place-items-center rounded-xl bg-amber-200 text-amber-950 dark:bg-amber-900 dark:text-amber-100">
                                        <Link2 className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-black text-amber-950 dark:text-amber-50">Unassigned LGUs</p>
                                        <p className="text-xs font-semibold text-amber-800/80 dark:text-amber-200/80">
                                            {visibleUnassigned.length} cit{visibleUnassigned.length === 1 ? 'y' : 'ies'} / municipalities still need a district
                                        </p>
                                    </div>
                                    {expandedDistricts.has('unassigned') ? <ChevronDown className="h-4 w-4 text-amber-700" /> : <ChevronRight className="h-4 w-4 text-amber-700" />}
                                </button>
                                {expandedDistricts.has('unassigned') && (
                                    <div className="border-t border-amber-200/80 px-4 py-4 dark:border-amber-900/40">
                                        {visibleUnassigned.length > 0 ? (
                                            <div className="flex flex-wrap gap-2">
                                                {visibleUnassigned.map((city) => (
                                                    <CityChip
                                                        key={city.code}
                                                        city={city}
                                                        meta={cityMeta(city)}
                                                        selected={String(selectedCityId) === String(city.id)}
                                                        onSelect={onCitySelect}
                                                        tone="warning"
                                                    />
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-sm font-semibold text-amber-800/80 dark:text-amber-200/80">No unassigned matches for this search.</p>
                                        )}
                                    </div>
                                )}
                            </section>
                        )}

                        {visibleDistricts.length === 0 && visibleUnassigned.length === 0 && (
                            <div className="rounded-2xl border border-dashed border-slate-300 px-4 py-10 text-center dark:border-zinc-700">
                                <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                    {activeCoverage?.districts?.length
                                        ? 'No cities or districts match this search.'
                                        : 'No district options yet for this province. Add one above or sync the district sheet.'}
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

const DISTRICT_ACCENTS = [
    { badge: 'bg-brand-700 text-white', panel: 'bg-brand-50/40 dark:bg-brand-950/20', ring: '' },
    { badge: 'bg-teal-700 text-white', panel: 'bg-teal-50/40 dark:bg-teal-950/20', ring: '' },
    { badge: 'bg-sky-700 text-white', panel: 'bg-sky-50/40 dark:bg-sky-950/20', ring: '' },
    { badge: 'bg-lime-700 text-white', panel: 'bg-lime-50/50 dark:bg-lime-950/20', ring: '' },
    { badge: 'bg-cyan-800 text-white', panel: 'bg-cyan-50/40 dark:bg-cyan-950/20', ring: '' },
];

function CityChip({ city, meta, selected, onSelect, tone = 'default' }) {
    const isWarning = tone === 'warning' || ['mismatch', 'psgc_missing_district', 'no_sheet_district'].includes(meta?.status);
    const isMatch = meta?.status === 'match';
    const title = [
        'Load in Assign City/Municipality',
        meta?.sheet_district ? `Sheet: ${meta.sheet_district}` : null,
        meta?.expected_short ? `Expected: ${meta.expected_short}` : null,
        meta?.population ? `Population: ${Number(meta.population).toLocaleString()}` : 'No population rows',
        meta?.status ? `Status: ${String(meta.status).replaceAll('_', ' ')}` : null,
    ].filter(Boolean).join(' · ');

    return (
        <button
            type="button"
            onClick={() => onSelect(city)}
            title={title}
            className={`group inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-left text-sm font-bold transition ${
                selected
                    ? 'border-brand-700 bg-brand-700 text-white shadow-sm'
                    : isWarning
                        ? 'border-amber-300 bg-white text-amber-950 hover:border-amber-500 hover:bg-amber-100 dark:border-amber-800 dark:bg-zinc-950 dark:text-amber-50 dark:hover:bg-amber-950/50'
                        : 'border-slate-200 bg-white text-slate-800 hover:border-brand-400 hover:bg-brand-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-brand-500/50'
            }`}
        >
            {isMatch && !selected && <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />}
            {isWarning && !selected && <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-300" />}
            <span className="truncate">{city.name}</span>
            {meta?.population > 0 && (
                <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-black tabular-nums ${
                    selected ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-300'
                }`}>
                    {Number(meta.population).toLocaleString()}
                </span>
            )}
            {city.district && !isWarning && (
                <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wide ${
                    selected ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-400'
                }`}>
                    {city.district}
                </span>
            )}
        </button>
    );
}

function SyncPanel({ file, setFile, processing, sync, upload }) {
    return (
        <div className="space-y-3">
            <button
                type="button"
                disabled={processing}
                onClick={() => sync(true)}
                className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
            >
                <RefreshCw className={`h-4 w-4 ${processing ? 'animate-spin' : ''}`} />
                Sync Full PSGC
            </button>
            <button
                type="button"
                disabled={processing}
                onClick={() => sync(false)}
                className="inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
                <RefreshCw className={`h-4 w-4 ${processing ? 'animate-spin' : ''}`} />
                Sync Regions to Cities Only
            </button>
            <div className="rounded-md border border-dashed border-slate-300 p-3 dark:border-zinc-700">
                <label className="block text-xs font-black uppercase text-slate-500 dark:text-zinc-400">
                    Upload PSA Excel
                </label>
                <input
                    type="file"
                    accept=".xlsx,.xls"
                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                    className="mt-2 block w-full text-xs font-semibold file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-xs file:font-black file:text-brand-800 dark:file:bg-brand-950 dark:file:text-brand-100"
                />
                <button
                    type="button"
                    disabled={processing || !file}
                    onClick={upload}
                    className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-zinc-200"
                >
                    <UploadCloud className="h-4 w-4" />
                    Import Uploaded PSA File
                </button>
            </div>
        </div>
    );
}

function Metric({ title, value }) {
    return (
        <Card className="relative overflow-hidden">
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <div className="relative">
                <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{Number(value || 0).toLocaleString()}</p>
            </div>
        </Card>
    );
}
