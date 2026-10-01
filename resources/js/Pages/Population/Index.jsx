import { Head, router, useForm } from '@inertiajs/react';
import { Download, Pencil, RefreshCw, Save, Search, UsersRound, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CircleMarker, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';

const formatNumber = (value) => Number(value || 0).toLocaleString();
const BUTUAN_CITY_CODE = '1630400000';
const AGUSAN_DEL_NORTE_CODE = '1600200000';
const cityProvinceCode = (city) => (city?.code === BUTUAN_CITY_CODE ? AGUSAN_DEL_NORTE_CODE : city?.parent_code);
const provinceColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4'];
const provinceHoverColors = ['#2563eb', '#ea580c', '#9333ea', '#82983f', '#0891b2'];
const provinceCoordinates = {
    'AGUSAN DEL NORTE': [9.05, 125.55],
    'AGUSAN DEL SUR': [8.55, 125.75],
    'DINAGAT ISLANDS': [10.12, 125.62],
    'SURIGAO DEL NORTE': [9.75, 125.82],
    'SURIGAO DEL SUR': [8.95, 126.18],
};

function FitMapToPoints({ points }) {
    const map = useMap();

    useEffect(() => {
        if (!points?.length) {
            return;
        }

        if (points.length === 1) {
            map.setView([points[0].lat, points[0].lng], 12);
            return;
        }

        map.fitBounds(
            points.map((point) => [point.lat, point.lng]),
            { padding: [36, 36], maxZoom: 13 },
        );
    }, [map, points]);

    return null;
}

const emptyFilters = {
    search: '',
    province_code: '',
    district_code: '',
    city_code: '',
};

export default function Index({
    activeRegion,
    metrics,
    provinceTotals,
    provinceSummary,
    caragaProvinceSummary = null,
    caragaMetrics = null,
    lguMapPoints = [],
    cityTotals,
    distribution,
    records,
    provinces,
    districts,
    citiesMunicipalities,
    filters,
    lockedFilters = {},
    sourceUrl,
    workspace = 'admin',
    readOnly = false,
    filterBasePath = '/population',
    lgu = null,
}) {
    const isLguWorkspace = workspace === 'lgu' || readOnly;
    const populationPath = filterBasePath || '/population';
    const lockedProvince = String(lockedFilters.province_code || '');
    const lockedDistrict = String(lockedFilters.district_code || '');
    const lockedCity = String(lockedFilters.city_code || '');
    const [showEdit, setShowEdit] = useState(false);
    const [selectedBarangayCode, setSelectedBarangayCode] = useState('');
    const [filterState, setFilterState] = useState({
        search: filters.search || '',
        province_code: lockedProvince || filters.province_code || '',
        district_code: lockedDistrict || filters.district_code || '',
        city_code: lockedCity || filters.city_code || '',
    });
    const searchTimerRef = useRef(null);
    const [hoveredProvinceCode, setHoveredProvinceCode] = useState('');
    const editForm = useForm({
        id: '',
        barangay_psgc_code: '',
        population: '',
        census_year: '',
        source: '',
        last_updated: '',
        estimated_families: '',
    });

    const withLockedFilters = (nextFilters) => ({
        ...nextFilters,
        ...(lockedProvince ? { province_code: lockedProvince } : {}),
        ...(lockedDistrict ? { district_code: lockedDistrict } : {}),
        ...(lockedCity ? { city_code: lockedCity } : {}),
    });

    const provinceOptions = (provinces || []).map((province) => ({
        value: province.code,
        label: province.name,
    }));
    const districtOptions = (districts || [])
        .filter((district) => !filterState.province_code || district.parent_code === filterState.province_code)
        .map((district) => ({
            value: district.code,
            label: district.short_name || district.name,
        }));
    const selectedDistrict = (districts || []).find((district) => district.code === filterState.district_code);
    const cityOptions = (citiesMunicipalities || [])
        .filter((city) => {
            const matchesProvince = !filterState.province_code || cityProvinceCode(city) === filterState.province_code;
            const matchesDistrict = !filterState.district_code
                || city.district_code === filterState.district_code
                || (selectedDistrict && (
                    city.district === selectedDistrict.name
                    || city.district === selectedDistrict.short_name
                ));

            return matchesProvince && matchesDistrict;
        })
        .map((city) => ({
            value: city.code,
            label: city.name,
        }));
    const maxProvincePopulation = Math.max(...(provinceTotals || []).map((row) => Number(row.population || 0)), 1);
    const maxCityPopulation = Math.max(...(cityTotals || []).map((row) => Number(row.population || 0)), 1);
    const totalPopulation = Number(metrics.total_population || 0);
    const selectedProvince = (provinceSummary || []).find((row) => row.code === (filters.province_code || hoveredProvinceCode))
        || (provinceSummary || [])[0];
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

    useEffect(() => {
        setFilterState({
            search: filters.search || '',
            province_code: lockedProvince || filters.province_code || '',
            district_code: lockedDistrict || filters.district_code || '',
            city_code: lockedCity || filters.city_code || '',
        });
    }, [filters.search, filters.province_code, filters.district_code, filters.city_code, lockedProvince, lockedDistrict, lockedCity]);

    useEffect(() => () => {
        if (searchTimerRef.current) {
            window.clearTimeout(searchTimerRef.current);
        }
    }, []);

    const pushFilters = (nextFilters) => {
        const payload = Object.fromEntries(
            Object.entries(withLockedFilters(nextFilters)).filter(([, value]) => String(value || '').trim() !== ''),
        );

        router.get(populationPath, payload, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const updateFilters = (patch, { debounceSearch = false } = {}) => {
        const next = withLockedFilters({ ...filterState, ...patch });
        setFilterState(next);

        if (searchTimerRef.current) {
            window.clearTimeout(searchTimerRef.current);
            searchTimerRef.current = null;
        }

        if (debounceSearch) {
            searchTimerRef.current = window.setTimeout(() => pushFilters(next), 350);
            return;
        }

        pushFilters(next);
    };

    const resetFilters = () => {
        if (searchTimerRef.current) {
            window.clearTimeout(searchTimerRef.current);
        }
        const next = withLockedFilters({ ...emptyFilters });
        setFilterState(next);
        pushFilters(next);
    };

    const openEdit = (record) => {
        if (isLguWorkspace) {
            return;
        }
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
        if (isLguWorkspace) {
            return;
        }
        editForm.put(`/population/${editForm.data.id}`, {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    };

    const pageTitle = isLguWorkspace
        ? `${lgu?.name || 'LGU'} Population Data`
        : 'Population';
    const summaryTitle = 'Caraga Population Summary';
    const summaryDescription = 'Based on the 2024 Census of Population and Housing.';
    const summaryRows = isLguWorkspace ? (caragaProvinceSummary || provinceSummary || []) : (provinceSummary || []);
    const summaryMetrics = isLguWorkspace ? (caragaMetrics || metrics) : metrics;
    const barangayMapPoints = isLguWorkspace ? (lguMapPoints || []) : [];
    const selectedBarangay = barangayMapPoints.find((point) => point.code === selectedBarangayCode) || null;
    const mapTitle = isLguWorkspace ? 'Interactive LGU Map' : 'Interactive Caraga Map';
    const mapDescription = isLguWorkspace
        ? `Barangay markers for ${lgu?.name || 'your LGU'} only. Click a point to inspect population and estimated families.`
        : 'Select a province marker to inspect its 2024 population.';
    const recordsList = records || [];
    const recordsPopulationTotal = recordsList.reduce((sum, record) => sum + Number(record.population || 0), 0);
    const recordsFamiliesTotal = recordsList.reduce((sum, record) => sum + Number(record.estimated_families || 0), 0);
    const lguMapCenter = useMemo(() => {
        if (!barangayMapPoints.length) {
            return [9.2, 125.85];
        }

        const lat = barangayMapPoints.reduce((sum, point) => sum + Number(point.lat || 0), 0) / barangayMapPoints.length;
        const lng = barangayMapPoints.reduce((sum, point) => sum + Number(point.lng || 0), 0) / barangayMapPoints.length;

        return [lat, lng];
    }, [barangayMapPoints]);
    const showGeoFilters = !lockedCity;
    const showProvinceCharts = !isLguWorkspace || Boolean(lockedProvince);

    useEffect(() => {
        if (!isLguWorkspace) {
            return;
        }

        if (!barangayMapPoints.some((point) => point.code === selectedBarangayCode)) {
            setSelectedBarangayCode(barangayMapPoints[0]?.code || '');
        }
    }, [isLguWorkspace, barangayMapPoints, selectedBarangayCode]);

    return (
        <AppLayout title={pageTitle}>
            <Head title={pageTitle} />

            <div id="population-summary-cards" className="grid scroll-mt-28 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Metric title="Total Population" value={metrics.total_population} icon={UsersRound} />
                <Metric title={isLguWorkspace ? 'Estimated Families' : 'Provinces'} value={isLguWorkspace ? metrics.estimated_families : metrics.provinces} />
                <Metric title="Cities / Municipalities" value={metrics.cities_municipalities} />
                <Metric title="Barangays with Population Data" value={metrics.barangays_with_population} />
            </div>

            <Card id="population-management" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 className="text-xl font-black">{isLguWorkspace ? 'Population Data' : 'Population Management'}</h2>
                        <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            {isLguWorkspace
                                ? `View-only barangay population for ${lgu?.name || 'your LGU'} under ${activeRegion?.short_name || 'CARAGA'}.`
                                : `Population records are linked to Barangay PSGC Codes under ${activeRegion?.short_name || 'CARAGA'}.`}
                        </p>
                        {!isLguWorkspace && (
                            <p className="mt-2 break-all text-xs font-semibold text-slate-500 dark:text-zinc-400">Source: {sourceUrl}</p>
                        )}
                    </div>
                    {!isLguWorkspace && (
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
                    )}
                </div>

                <div className={`mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-5 dark:border-zinc-800 ${showGeoFilters ? 'sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)_minmax(0,1fr)_minmax(0,1.25fr)_auto] xl:items-end' : 'sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end'}`}>
                    {showGeoFilters && !lockedProvince && (
                        <SearchableSelect
                            label="Province"
                            placeholder="All provinces"
                            options={provinceOptions}
                            value={filterState.province_code}
                            onChange={(value) => updateFilters({
                                province_code: value,
                                district_code: '',
                                city_code: '',
                            })}
                        />
                    )}
                    {showGeoFilters && (
                        <SearchableSelect
                            label="District"
                            placeholder="All districts"
                            options={districtOptions}
                            value={filterState.district_code}
                            onChange={(value) => updateFilters({
                                district_code: value,
                                city_code: lockedCity || '',
                            })}
                        />
                    )}
                    {showGeoFilters && !lockedCity && (
                        <SearchableSelect
                            label="City / Municipality"
                            placeholder="All cities/municipalities"
                            options={cityOptions}
                            value={filterState.city_code}
                            onChange={(value) => updateFilters({ city_code: value })}
                        />
                    )}
                    <label className={`text-sm font-medium ${showGeoFilters ? 'sm:col-span-2 xl:col-span-1' : ''}`}>
                        Search
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className="w-full pl-9"
                                value={filterState.search}
                                onChange={(event) => updateFilters({ search: event.target.value }, { debounceSearch: true })}
                                placeholder={isLguWorkspace ? 'Search barangay or PSGC' : 'Search barangay, city, province, PSGC'}
                            />
                        </div>
                    </label>
                    <button
                        type="button"
                        onClick={resetFilters}
                        className="self-end rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                    >
                        Reset
                    </button>
                </div>
            </Card>

            <div className="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,0.75fr)]">
                <ExportableCard id="caraga-population-summary" title={summaryTitle} className="scroll-mt-28 overflow-hidden">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-xl font-black">{summaryTitle}</h2>
                            <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">{summaryDescription}</p>
                        </div>
                        <div className="rounded-full bg-sky-100 px-5 py-2 text-2xl font-black text-sky-700 dark:bg-sky-950/50 dark:text-sky-100">
                            {formatNumber(summaryMetrics.total_population)}
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
                                {summaryRows.map((row, index) => (
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
                                    <td className="px-4 py-3 text-right">{formatNumber(summaryRows.reduce((sum, row) => sum + Number(row.districts_count || 0), 0))}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(summaryMetrics.cities_municipalities)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(summaryMetrics.barangays_with_population)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(summaryMetrics.total_population)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(summaryMetrics.estimated_families)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ExportableCard>

                <ExportableCard id="population-map" title={mapTitle} className="scroll-mt-28">
                    <h2 className="text-lg font-black">{mapTitle}</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{mapDescription}</p>
                    <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_190px] xl:grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_190px]">
                        <div className="h-[380px] overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                            {isLguWorkspace ? (
                                <MapContainer
                                    center={lguMapCenter}
                                    zoom={11}
                                    minZoom={9}
                                    scrollWheelZoom={false}
                                    className="h-full w-full"
                                >
                                    <TileLayer
                                        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                                        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                                    />
                                    <FitMapToPoints points={barangayMapPoints} />
                                    {barangayMapPoints.map((point, index) => {
                                        const isSelected = selectedBarangayCode === point.code;
                                        const color = provinceColors[index % provinceColors.length];

                                        return (
                                            <CircleMarker
                                                key={point.code}
                                                center={[point.lat, point.lng]}
                                                radius={isSelected ? 12 : Math.max(6, Math.min(11, Number(point.estimated_families || 0) / 80))}
                                                pathOptions={{
                                                    color,
                                                    fillColor: color,
                                                    fillOpacity: isSelected ? 0.85 : 0.55,
                                                    weight: isSelected ? 3 : 2,
                                                }}
                                                eventHandlers={{
                                                    click: () => setSelectedBarangayCode(point.code),
                                                }}
                                            >
                                                <Popup>
                                                    <div className="text-sm">
                                                        <p className="font-bold">{point.name}</p>
                                                        <p className="text-xs text-slate-500">{point.city_name || lgu?.name || 'LGU'}</p>
                                                        <p>Estimated families: {formatNumber(point.estimated_families)}</p>
                                                    </div>
                                                </Popup>
                                            </CircleMarker>
                                        );
                                    })}
                                </MapContainer>
                            ) : (
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
                                                    click: () => updateFilters({
                                                        province_code: row.code,
                                                        district_code: '',
                                                        city_code: '',
                                                    }),
                                                    mouseover: () => setHoveredProvinceCode(row.code),
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
                            )}
                        </div>
                        <div className="rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            {isLguWorkspace ? (
                                <>
                                    <p className="text-xs font-black uppercase text-brand-700 dark:text-brand-200">
                                        {selectedBarangay?.name || lgu?.name || 'LGU'}
                                    </p>
                                    <div className="mt-4 grid grid-cols-1 gap-2 text-sm">
                                        <MiniStat
                                            label="Population"
                                            value={selectedBarangay?.population ?? metrics.total_population}
                                        />
                                        <MiniStat
                                            label="Estimated Families"
                                            value={selectedBarangay?.estimated_families ?? metrics.estimated_families}
                                        />
                                    </div>
                                    {!barangayMapPoints.length && (
                                        <p className="mt-3 text-xs font-semibold text-slate-500">No barangay population points for this LGU.</p>
                                    )}
                                </>
                            ) : (
                                <>
                                    <p className="text-xs font-black uppercase text-brand-700 dark:text-brand-200">{selectedProvince?.name || 'Caraga'}</p>
                                    <p className="mt-2 text-3xl font-black">{formatNumber(selectedProvince?.population)}</p>
                                    <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">Total population</p>
                                    <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                        <MiniStat label="Districts" value={selectedProvince?.districts_count} />
                                        <MiniStat label="Cities/Munis" value={selectedProvince?.cities_count} />
                                        <MiniStat label="Barangays" value={selectedProvince?.barangays_count} />
                                        <MiniStat label="Families" value={selectedProvince?.estimated_families} />
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </ExportableCard>
            </div>

            {showProvinceCharts && (
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
                                    onClick={() => !isLguWorkspace && updateFilters({
                                        province_code: row.code,
                                        district_code: '',
                                        city_code: '',
                                    })}
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
                                        onClick={() => updateFilters({
                                            province_code: row.code,
                                            district_code: '',
                                            city_code: '',
                                        })}
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
            )}

            <ExportableCard id="top-cities-population" title={isLguWorkspace ? 'Cities / Municipalities' : 'Top 10 Most Populated Cities / Municipalities'} className="mt-6 scroll-mt-28">
                <h2 className="text-lg font-black">{isLguWorkspace ? 'Cities / Municipalities' : 'Top 10 Most Populated Cities / Municipalities'}</h2>
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
                <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-lg font-black">Population Records</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            {isLguWorkspace
                                ? `Barangay population rows for ${lgu?.name || 'your LGU'}.`
                                : 'Showing barangay rows for the active Population Management filters.'}
                        </p>
                    </div>
                </div>

                <DataTable
                    stickyHeader
                    className="max-h-[calc(100vh-310px)] min-h-[280px] overflow-y-auto"
                    columns={[
                        'Province',
                        'City / Municipality',
                        'Barangay',
                        'PSGC Code',
                        'Population',
                        'Est. Families',
                        'Census Year',
                        'Source',
                        ...(!isLguWorkspace ? [{ label: 'Actions', align: 'right', actionColumn: true }] : []),
                    ]}
                    rows={[
                        ...recordsList.map((record) => (
                            <tr key={record.id}>
                                <td className="whitespace-nowrap px-4 py-3 font-bold">{record.province_name}</td>
                                <td className="whitespace-nowrap px-4 py-3">{record.city_name}</td>
                                <td className="whitespace-nowrap px-4 py-3 font-black">{record.barangay_name}</td>
                                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{record.barangay_psgc_code}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatNumber(record.population)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatNumber(record.estimated_families)}</td>
                                <td className="whitespace-nowrap px-4 py-3">{record.census_year || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">{record.source || '-'}</td>
                                {!isLguWorkspace && (
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <TableActionButton icon={Pencil} label="Edit" onClick={() => openEdit(record)} tone="brand" />
                                    </td>
                                )}
                            </tr>
                        )),
                        ...(recordsList.length > 0
                            ? [
                                <tr
                                    key="population-records-total"
                                    className="sticky bottom-0 z-10 border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950"
                                >
                                    <td colSpan={isLguWorkspace ? 5 : 6} className="px-4 py-3">Total</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">{formatNumber(recordsPopulationTotal)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">{formatNumber(recordsFamiliesTotal)}</td>
                                    <td className="px-4 py-3">—</td>
                                    <td className="px-4 py-3">—</td>
                                </tr>,
                            ]
                            : []),
                    ]}
                />
            </ExportableCard>

            {!isLguWorkspace && showEdit && (
                <div className="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/50 p-4">
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
