import { Head, router, useForm } from '@inertiajs/react';
import { MapPinned, Plus, RefreshCw, Save, UploadCloud } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDateTime } from '@/Utils/dateFormat';

const regionLabel = (region) => String(region?.short_name || region?.name || '').toUpperCase();

export default function Index({ metrics, regions, selectedRegion, provinces, districts, citiesMunicipalities, settings, lastSyncedAt, source }) {
    const [processing, setProcessing] = useState(false);
    const [file, setFile] = useState(null);
    const [selectedProvinceCode, setSelectedProvinceCode] = useState(provinces?.[0]?.code || '');
    const [selectedCityCode, setSelectedCityCode] = useState('');
    const [barangays, setBarangays] = useState([]);
    const [barangayLoading, setBarangayLoading] = useState(false);

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
    const citiesByProvince = (citiesMunicipalities || []).reduce((grouped, city) => ({
        ...grouped,
        [city.parent_code]: [...(grouped[city.parent_code] || []), city],
    }), {});
    const districtOptions = (districts || []).map((district) => ({
        value: district.code,
        label: `${provinceMap[district.parent_code] || 'Province'} - ${district.name}`,
        raw: district,
    }));
    const cityOptions = (citiesMunicipalities || []).map((city) => ({
        value: String(city.id),
        label: `${provinceMap[city.parent_code] || 'Province'} - ${city.name}`,
        raw: city,
    }));
    const cityBrowserOptions = (citiesByProvince[selectedProvinceCode] || []).map((city) => ({
        value: city.code,
        label: city.name,
        raw: city,
    }));
    const selectedCity = cityBrowserOptions.find((option) => option.value === selectedCityCode)?.raw;

    useEffect(() => {
        setSelectedProvinceCode(provinces?.[0]?.code || '');
        setSelectedCityCode('');
        setBarangays([]);
    }, [selectedRegion?.code]);

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
                            options={districtOptions.map((option) => ({ value: String(option.raw.id), label: option.label, raw: option.raw }))}
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
                                This controls the District to City/Municipality dropdown in Add Warehouse.
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
                            options={districtOptions}
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

            <ExportableCard id="psgc-managed-districts" title="Managed Districts" className="mt-6">
                <h2 className="mb-4 text-lg font-black">Managed Districts</h2>
                <DataTable
                    columns={['Province', 'District', 'Code', 'Synced At']}
                    rows={(districts || []).map((district) => (
                        <tr key={district.code}>
                            <td className="whitespace-nowrap px-4 py-3 font-bold">{provinceMap[district.parent_code] || district.parent_code}</td>
                            <td className="whitespace-nowrap px-4 py-3 font-black">{district.name}</td>
                            <td className="whitespace-nowrap px-4 py-3">{district.code}</td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(district.synced_at)}</td>
                        </tr>
                    ))}
                />
            </ExportableCard>
        </AppLayout>
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
