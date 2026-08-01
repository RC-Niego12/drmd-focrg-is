import { Head, router, useForm } from '@inertiajs/react';
import { Download, Pencil, RefreshCw, Save, Search, UsersRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CircleMarker, MapContainer, Popup, TileLayer } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';

const formatNumber = (value) => Number(value || 0).toLocaleString();
const provinceColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4'];
const provinceHoverColors = ['#2563eb', '#ea580c', '#9333ea', '#82983f', '#0891b2'];
const provinceCoordinates = {
    'AGUSAN DEL NORTE': [9.05, 125.55],
    'AGUSAN DEL SUR': [8.55, 125.75],
    'DINAGAT ISLANDS': [10.12, 125.62],
    'SURIGAO DEL NORTE': [9.75, 125.82],
    'SURIGAO DEL SUR': [8.95, 126.18],
};

export default function Index({ activeRegion, metrics, provinceTotals, provinceSummary, cityTotals, distribution, records, provinces, citiesMunicipalities, filters, sourceUrl }) {
    const [showEdit, setShowEdit] = useState(false);
    const [selectedProvinceCode, setSelectedProvinceCode] = useState((provinceSummary || [])[0]?.code || '');
    const [filterState, setFilterState] = useState({
        search: filters.search || '',
        province_code: filters.province_code || '',
        city_code: filters.city_code || '',
    });
    const editForm = useForm({
        id: '',
        barangay_psgc_code: '',
        population: '',
        census_year: '',
        source: '',
        last_updated: '',
        estimated_families: '',
    });

    const provinceOptions = (provinces || []).map((province) => ({
        value: province.code,
        label: province.name,
    }));
    const citiesByProvince = (citiesMunicipalities || []).reduce((grouped, city) => ({
        ...grouped,
        [city.parent_code]: [...(grouped[city.parent_code] || []), city],
    }), {});
    const cityOptions = (filterState.province_code ? (citiesByProvince[filterState.province_code] || []) : (citiesMunicipalities || [])).map((city) => ({
        value: city.code,
        label: city.name,
    }));
    const maxProvincePopulation = Math.max(...(provinceTotals || []).map((row) => Number(row.population || 0)), 1);
    const maxCityPopulation = Math.max(...(cityTotals || []).map((row) => Number(row.population || 0)), 1);
    const totalPopulation = Number(metrics.total_population || 0);
    const selectedProvince = (provinceSummary || []).find((row) => row.code === selectedProvinceCode) || (provinceSummary || [])[0];
    const donutGradient = useMemo(() => {
        let current = 0;
        const stops = (distribution || []).map((row, index) => {
            const value = totalPopulation > 0 ? (Number(row.population || 0) / totalPopulation) * 100 : 0;
            const start = current;
            current += value;
            return `${provinceColors[index % provinceColors.length]} ${start}% ${current}%`;
        });

        return stops.length ? `conic-gradient(${stops.join(', ')})` : 'conic-gradient(#e2e8f0 0 100%)';
    }, [distribution, totalPopulation]);

    const applyFilters = (event) => {
        event.preventDefault();
        router.get('/population', filterState, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const resetFilters = () => {
        setFilterState({ search: '', province_code: '', city_code: '' });
        router.get('/population', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const openEdit = (record) => {
        editForm.setData({
            id: record.id,
            barangay_psgc_code: record.barangay_psgc_code,
            population: record.population ?? '',
            census_year: record.census_year ?? '',
            source: record.source ?? '',
            last_updated: record.last_updated ?? '',
            estimated_families: record.estimated_families ?? '',
        });
        setShowEdit(true);
    };

    const updatePopulation = (event) => {
        event.preventDefault();
        editForm.put(`/population/${editForm.data.id}`, {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    };

    return (
        <AppLayout title="Population">
            <Head title="Population" />

            <div id="population-summary-cards" className="grid scroll-mt-28 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Metric title="Total Population" value={metrics.total_population} icon={UsersRound} />
                <Metric title="Provinces" value={metrics.provinces} />
                <Metric title="Cities / Municipalities" value={metrics.cities_municipalities} />
                <Metric title="Barangays with Population Data" value={metrics.barangays_with_population} />
            </div>

            <Card id="population-management" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 className="text-xl font-black">Population Management</h2>
                        <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Population records are linked to Barangay PSGC Codes under {activeRegion?.short_name || 'CARAGA'}.
                        </p>
                        <p className="mt-2 break-all text-xs font-semibold text-slate-500 dark:text-zinc-400">Source: {sourceUrl}</p>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={() => router.post('/population/import', {}, { preserveScroll: true })}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-brand-800"
                        >
                            <Download className="h-4 w-4" />
                            Import
                        </button>
                        <button
                            type="button"
                            onClick={() => router.reload({ preserveScroll: true })}
                            className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Refresh
                        </button>
                    </div>
                </div>
            </Card>

            <div className="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,0.75fr)]">
                <ExportableCard id="caraga-population-summary" title="Caraga Population Summary" className="scroll-mt-28 overflow-hidden">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-xl font-black">Caraga Population Summary</h2>
                            <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">Based on the 2024 Census of Population and Housing.</p>
                        </div>
                        <div className="rounded-full bg-sky-100 px-5 py-2 text-2xl font-black text-sky-700 dark:bg-sky-950/50 dark:text-sky-100">
                            {formatNumber(metrics.total_population)}
                        </div>
                    </div>
                    <div className="mt-5 overflow-x-auto">
                        <table className="min-w-[760px] w-full text-sm">
                            <thead className="bg-slate-100 text-xs uppercase text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                                <tr>
                                    <th className="px-4 py-3 text-left">Province</th>
                                    <th className="px-4 py-3 text-right">No. of Leg. Districts</th>
                                    <th className="px-4 py-3 text-right">No. of Cities / Municipalities</th>
                                    <th className="px-4 py-3 text-right">No. of Barangays</th>
                                    <th className="px-4 py-3 text-right">Total Population</th>
                                    <th className="px-4 py-3 text-right">Estimated No. of Families</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-zinc-800">
                                {(provinceSummary || []).map((row, index) => (
                                    <tr key={row.code} className="transition hover:bg-brand-50/70 dark:hover:bg-brand-950/30">
                                        <td className="px-4 py-3 font-black">
                                            <span className="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full text-xs text-white" style={{ backgroundColor: provinceColors[index % provinceColors.length] }}>
                                                {index + 1}
                                            </span>
                                            {row.name}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold">{formatNumber(row.districts_count)}</td>
                                        <td className="px-4 py-3 text-right font-semibold">{formatNumber(row.cities_count)}</td>
                                        <td className="px-4 py-3 text-right font-semibold">{formatNumber(row.barangays_count)}</td>
                                        <td className="px-4 py-3 text-right font-black">{formatNumber(row.population)}</td>
                                        <td className="px-4 py-3 text-right font-black">{formatNumber(row.estimated_families)}</td>
                                    </tr>
                                ))}
                                <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-800/70">
                                    <td className="px-4 py-3">Total</td>
                                    <td className="px-4 py-3 text-right">{formatNumber((provinceSummary || []).reduce((sum, row) => sum + Number(row.districts_count || 0), 0))}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(metrics.cities_municipalities)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(metrics.barangays_with_population)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(metrics.total_population)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(metrics.estimated_families)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ExportableCard>

                <ExportableCard id="population-map" title="Interactive Caraga Map" className="scroll-mt-28">
                    <h2 className="text-lg font-black">Interactive Caraga Map</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">Select a province marker to inspect its 2024 population.</p>
                    <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_190px] xl:grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_190px]">
                        <div className="h-[380px] overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                            <MapContainer
                                center={[9.2, 125.85]}
                                zoom={8}
                                minZoom={7}
                                scrollWheelZoom={false}
                                className="h-full w-full"
                            >
                                <TileLayer
                                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                                />
                                {(provinceSummary || []).map((row, index) => {
                                    const coordinates = provinceCoordinates[row.name?.toUpperCase()];
                                    const isSelected = selectedProvince?.code === row.code;

                                    if (!coordinates) {
                                        return null;
                                    }

                                    return (
                                        <CircleMarker
                                            key={row.code}
                                            center={coordinates}
                                            radius={isSelected ? 18 : Math.max(9, Math.min(17, Number(row.population || 0) / 50000))}
                                            pathOptions={{
                                                color: provinceColors[index % provinceColors.length],
                                                fillColor: provinceColors[index % provinceColors.length],
                                                fillOpacity: isSelected ? 0.75 : 0.55,
                                                weight: isSelected ? 4 : 2,
                                            }}
                                            eventHandlers={{
                                                click: () => setSelectedProvinceCode(row.code),
                                                mouseover: () => setSelectedProvinceCode(row.code),
                                            }}
                                        >
                                            <Popup>
                                                <div className="text-sm">
                                                    <p className="font-bold">{row.name}</p>
                                                    <p>Population: {formatNumber(row.population)}</p>
                                                    <p>Barangays: {formatNumber(row.barangays_count)}</p>
                                                </div>
                                            </Popup>
                                        </CircleMarker>
                                    );
                                })}
                            </MapContainer>
                        </div>
                        <div className="rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <p className="text-xs font-black uppercase text-brand-700 dark:text-brand-200">{selectedProvince?.name || 'Caraga'}</p>
                            <p className="mt-2 text-3xl font-black">{formatNumber(selectedProvince?.population)}</p>
                            <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">Total population</p>
                            <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                <MiniStat label="Districts" value={selectedProvince?.districts_count} />
                                <MiniStat label="Cities/Munis" value={selectedProvince?.cities_count} />
                                <MiniStat label="Barangays" value={selectedProvince?.barangays_count} />
                                <MiniStat label="Families" value={selectedProvince?.estimated_families} />
                            </div>
                        </div>
                    </div>
                </ExportableCard>
            </div>

            <div className="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.75fr)]">
                <ExportableCard id="population-by-province" title="Population by Province" className="scroll-mt-28">
                    <h2 className="text-lg font-black">Population by Province</h2>
                    <div className="mt-5 flex min-h-[280px] items-end gap-3 overflow-x-auto pb-2">
                        {(provinceTotals || []).map((row, index) => {
                            const height = Math.max((Number(row.population || 0) / maxProvincePopulation) * 220, 16);
                            const color = provinceColors[index % provinceColors.length];
                            const hoverColor = provinceHoverColors[index % provinceHoverColors.length];
                            return (
                                <button
                                    key={row.code}
                                    type="button"
                                    onClick={() => setSelectedProvinceCode(row.code)}
                                    className="group flex min-w-[112px] flex-1 flex-col items-center justify-end gap-2"
                                    title={`${row.name}: ${formatNumber(row.population)}`}
                                >
                                    <span className="text-sm font-black text-slate-700 transition group-hover:scale-105 dark:text-zinc-200">{formatNumber(row.population)}</span>
                                    <span
                                        className="w-full rounded-t-md shadow-sm transition group-hover:shadow-md"
                                        style={{ height, backgroundColor: color }}
                                        onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                        onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                    />
                                    <span className="text-center text-xs font-bold leading-tight">{row.name}</span>
                                </button>
                            );
                        })}
                        {(provinceTotals || []).length === 0 && <p className="text-sm font-semibold text-slate-500">No population data imported yet.</p>}
                    </div>
                </ExportableCard>

                <ExportableCard id="population-distribution" title="Population Distribution" className="scroll-mt-28">
                    <h2 className="text-lg font-black">Population Distribution</h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-[180px_minmax(0,1fr)] xl:grid-cols-1 2xl:grid-cols-[180px_minmax(0,1fr)]">
                        <div className="mx-auto h-44 w-44 rounded-full p-8" style={{ background: donutGradient }}>
                            <div className="flex h-full w-full items-center justify-center rounded-full bg-white text-center text-sm font-black dark:bg-zinc-900">
                                {totalPopulation > 0 ? 'Share' : 'No data'}
                            </div>
                        </div>
                        <div className="space-y-2">
                            {(distribution || []).map((row, index) => {
                                const percent = totalPopulation > 0 ? (Number(row.population || 0) / totalPopulation) * 100 : 0;

                                return (
                                    <button
                                        type="button"
                                        key={row.code}
                                        onClick={() => setSelectedProvinceCode(row.code)}
                                        className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-1 text-left text-sm transition hover:bg-slate-50 dark:hover:bg-zinc-800"
                                    >
                                        <span className="flex min-w-0 items-center gap-2 font-bold">
                                            <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: provinceColors[index % provinceColors.length] }} />
                                            <span className="truncate">{row.name}</span>
                                        </span>
                                        <span className="font-semibold text-slate-500 dark:text-zinc-400">{percent.toFixed(1)}%</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </ExportableCard>
            </div>

            <ExportableCard id="top-cities-population" title="Top 10 Most Populated Cities / Municipalities" className="mt-6 scroll-mt-28">
                <h2 className="text-lg font-black">Top 10 Most Populated Cities / Municipalities</h2>
                <div className="mt-5 space-y-3">
                    {(cityTotals || []).map((row, index) => {
                        const color = provinceColors[index % provinceColors.length];
                        const hoverColor = provinceHoverColors[index % provinceHoverColors.length];

                        return (
                        <div key={row.code} className="grid gap-2 sm:grid-cols-[220px_minmax(0,1fr)_90px] sm:items-center">
                            <div className="min-w-0">
                                <p className="truncate font-black">{row.name}</p>
                                <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.province_name}</p>
                            </div>
                            <div className="h-7 rounded-r-full bg-slate-100 dark:bg-zinc-800">
                                <div
                                    className="flex h-full items-center justify-end rounded-r-full pr-2 text-xs font-black text-white transition"
                                    style={{ width: `${Math.max((Number(row.population || 0) / maxCityPopulation) * 100, 4)}%`, backgroundColor: color }}
                                    onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                    onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                >
                                    {formatNumber(row.population)}
                                </div>
                            </div>
                            <p className="hidden text-right text-sm font-black sm:block">{formatNumber(row.population)}</p>
                        </div>
                        );
                    })}
                </div>
            </ExportableCard>

            <ExportableCard id="population-records" title="Population Records" className="mt-6 scroll-mt-28">
                <form onSubmit={applyFilters} className="grid gap-3 xl:grid-cols-[minmax(220px,1fr)_minmax(220px,1fr)_minmax(260px,1.3fr)_auto_auto]">
                    <SearchableSelect
                        label="Province"
                        placeholder="All provinces"
                        options={provinceOptions}
                        value={filterState.province_code}
                        onChange={(value) => setFilterState((current) => ({ ...current, province_code: value, city_code: '' }))}
                    />
                    <SearchableSelect
                        label="City / Municipality"
                        placeholder="All cities/municipalities"
                        options={cityOptions}
                        value={filterState.city_code}
                        onChange={(value) => setFilterState((current) => ({ ...current, city_code: value }))}
                    />
                    <label className="text-sm font-medium">
                        Search
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className="w-full pl-9"
                                value={filterState.search}
                                onChange={(event) => setFilterState((current) => ({ ...current, search: event.target.value }))}
                                placeholder="Search barangay, city, province, PSGC"
                            />
                        </div>
                    </label>
                    <button className="self-end rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white transition hover:bg-brand-800">Filter</button>
                    <button type="button" onClick={resetFilters} className="self-end rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">Reset</button>
                </form>

                <DataTable
                    stickyHeader
                    className="mt-4 max-h-[calc(100vh-310px)] min-h-[280px] overflow-y-auto"
                    columns={['Province', 'City / Municipality', 'Barangay', 'PSGC Code', 'Population', 'Est. Families', 'Census Year', 'Source', { label: 'Actions', align: 'right', actionColumn: true }]}
                    rows={(records || []).map((record) => (
                        <tr key={record.id}>
                            <td className="whitespace-nowrap px-4 py-3 font-bold">{record.province_name}</td>
                            <td className="whitespace-nowrap px-4 py-3">{record.city_name}</td>
                            <td className="whitespace-nowrap px-4 py-3 font-black">{record.barangay_name}</td>
                            <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{record.barangay_psgc_code}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatNumber(record.population)}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatNumber(record.estimated_families)}</td>
                            <td className="whitespace-nowrap px-4 py-3">{record.census_year || '-'}</td>
                            <td className="whitespace-nowrap px-4 py-3">{record.source || '-'}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right">
                                <TableActionButton icon={Pencil} label="Edit" onClick={() => openEdit(record)} tone="brand" />
                            </td>
                        </tr>
                    ))}
                />
            </ExportableCard>

            {showEdit && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
                    <form onSubmit={updatePopulation} className="w-full max-w-xl rounded-md border border-slate-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-xl font-black">Edit Population Record</h2>
                                <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">Poor families and poor individuals remain blank for now.</p>
                            </div>
                            <button type="button" onClick={() => setShowEdit(false)} className="rounded-md p-1.5 text-slate-500 transition hover:bg-slate-100 dark:hover:bg-zinc-800">
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-medium sm:col-span-2">
                                Barangay PSGC Code
                                <input className="mt-1 w-full bg-slate-50 dark:bg-zinc-950" value={editForm.data.barangay_psgc_code} readOnly />
                            </label>
                            <label className="text-sm font-medium">
                                Population
                                <input type="number" min="0" className="mt-1 w-full" value={editForm.data.population} onChange={(event) => editForm.setData('population', event.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                Estimated No. of Families
                                <input type="number" min="0" className="mt-1 w-full" value={editForm.data.estimated_families} onChange={(event) => editForm.setData('estimated_families', event.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                Census Year
                                <input type="number" min="1900" max="2100" className="mt-1 w-full" value={editForm.data.census_year} onChange={(event) => editForm.setData('census_year', event.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                Source
                                <input className="mt-1 w-full" value={editForm.data.source} onChange={(event) => editForm.setData('source', event.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                Last Updated
                                <input type="date" className="mt-1 w-full" value={editForm.data.last_updated || ''} onChange={(event) => editForm.setData('last_updated', event.target.value)} />
                            </label>
                        </div>
                        <button disabled={editForm.processing} className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white transition hover:bg-brand-800 disabled:opacity-60">
                            <Save className="h-4 w-4" />
                            Save Population
                        </button>
                    </form>
                </div>
            )}
        </AppLayout>
    );
}

function Metric({ title, value, icon: Icon }) {
    return (
        <Card className="relative overflow-hidden">
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <div className="relative flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{formatNumber(value)}</p>
                </div>
                {Icon && (
                    <span className="flex h-11 w-11 items-center justify-center rounded-md border border-slate-200 bg-white text-brand-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-brand-100">
                        <Icon className="h-5 w-5" />
                    </span>
                )}
            </div>
        </Card>
    );
}

function MiniStat({ label, value }) {
    return (
        <div className="rounded-md bg-slate-50 p-3 dark:bg-zinc-800">
            <p className="text-[10px] font-black uppercase text-slate-500 dark:text-zinc-400">{label}</p>
            <p className="mt-1 text-lg font-black">{formatNumber(value)}</p>
        </div>
    );
}
