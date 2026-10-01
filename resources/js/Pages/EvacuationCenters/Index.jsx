import { Head, router } from '@inertiajs/react';
import {
    Camera,
    CheckCircle2,
    Droplets,
    ExternalLink,
    LayoutDashboard,
    ListChecks,
    MapPin,
    Search,
    ShowerHead,
    Tent,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CircleMarker, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import SectionTabs from '@/Components/SectionTabs';

const formatNumber = (value) => Number(value || 0).toLocaleString();
const typeColors = ['#0f766e', '#0369a1', '#c2410c', '#7c3aed', '#be185d', '#4d7c0f', '#b45309', '#0e7490'];
const chartPalette = ['#0f766e', '#ea580c', '#2563eb', '#db2777', '#ca8a04', '#7c3aed', '#0891b2', '#65a30d'];

function FitMapToPoints({ points }) {
    const map = useMap();

    useEffect(() => {
        if (!points?.length) {
            return;
        }
        if (points.length === 1) {
            map.setView([points[0].lat, points[0].lng], 14);
            return;
        }
        map.fitBounds(
            points.map((point) => [point.lat, point.lng]),
            { padding: [28, 28], maxZoom: 13 },
        );
    }, [map, points]);

    return null;
}

function photoCandidates(center) {
    const urls = [];
    const push = (value) => {
        const url = String(value || '').trim();
        if (url && !urls.includes(url)) {
            urls.push(url);
        }
    };

    if (Array.isArray(center?.photo_urls)) {
        center.photo_urls.forEach(push);
    }
    push(center?.photo_url);

    const openUrls = [
        ...(Array.isArray(center?.photo_open_urls) ? center.photo_open_urls : []),
        center?.photo_open_url,
    ].filter(Boolean);

    openUrls.forEach((openUrl) => {
        const match = String(openUrl).match(/\/file\/d\/([a-zA-Z0-9_-]+)/) || String(openUrl).match(/[?&]id=([a-zA-Z0-9_-]+)/);
        const id = match?.[1];
        if (!id) {
            return;
        }
        push(`https://lh3.googleusercontent.com/d/${id}=w1600`);
        push(`https://drive.google.com/thumbnail?id=${id}&sz=w1600`);
        push(`https://drive.google.com/uc?export=view&id=${id}`);
    });

    push(center?.preview_image_url);
    return urls;
}

function EcPhoto({ center, className = '', preferSitePhoto = false, fit = 'cover' }) {
    const candidates = useMemo(() => {
        const all = photoCandidates(center);
        if (!preferSitePhoto) {
            return all;
        }
        return all.filter((url) => !String(url).includes('staticmap.openstreetmap.de'));
    }, [center, preferSitePhoto]);
    const [index, setIndex] = useState(0);
    const src = candidates[index] || null;

    useEffect(() => {
        setIndex(0);
    }, [center?.id, candidates.join('|')]);

    if (!src || index < 0) {
        return (
            <div className={`flex items-center justify-center bg-gradient-to-br from-brand-100 via-emerald-50 to-slate-100 dark:from-brand-950 dark:via-zinc-900 dark:to-zinc-950 ${className}`}>
                <div className="text-center">
                    <Tent className="mx-auto h-8 w-8 text-brand-700/70 dark:text-brand-200/70" />
                    <p className="mt-2 text-[10px] font-black uppercase tracking-wide text-slate-500">No site photo yet</p>
                </div>
            </div>
        );
    }

    return (
        <img
            src={src}
            alt={`${center?.name || 'Evacuation center'} photo`}
            className={`${fit === 'contain' ? 'object-contain' : 'object-cover'} ${className}`}
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => setIndex((current) => (current + 1 < candidates.length ? current + 1 : -1))}
        />
    );
}

const TABLE_SCROLL_CLASS = 'max-h-[min(58vh,640px)] min-h-[200px] overflow-y-auto';

function MetricPill({ label, value, tone = 'teal' }) {
    const tones = {
        amber: 'from-amber-500 to-orange-500',
        teal: 'from-teal-600 to-cyan-600',
        emerald: 'from-emerald-600 to-green-600',
        rose: 'from-rose-500 to-orange-500',
        indigo: 'from-indigo-600 to-sky-600',
    };

    return (
        <div className={`rounded-full bg-gradient-to-r ${tones[tone] || tones.teal} px-5 py-4 text-white shadow-md shadow-slate-900/10`}>
            <p className="text-[11px] font-black uppercase tracking-[0.14em] text-white/85">{label}</p>
            <p className="mt-1 text-3xl font-black tabular-nums">{formatNumber(value)}</p>
        </div>
    );
}

function YnBadge({ value }) {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'yes') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-black text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-800">
                <CheckCircle2 className="h-3 w-3" /> Yes
            </span>
        );
    }
    if (normalized === 'no') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-black text-rose-700 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-200 dark:ring-rose-800">
                <XCircle className="h-3 w-3" /> No
            </span>
        );
    }
    return <span className="text-xs font-semibold text-slate-400">—</span>;
}

function DonutChart({ title, items }) {
    const total = items.reduce((sum, item) => sum + Number(item.value || 0), 0) || 1;
    let cursor = 0;
    const stops = items.map((item, index) => {
        const share = (Number(item.value || 0) / total) * 100;
        const start = cursor;
        cursor += share;
        return `${chartPalette[index % chartPalette.length]} ${start}% ${cursor}%`;
    });

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h3 className="text-sm font-black text-slate-900 dark:text-white">{title}</h3>
            <div className="mt-4 flex items-center gap-4">
                <div
                    className="relative h-28 w-28 shrink-0 rounded-full"
                    style={{ background: stops.length ? `conic-gradient(${stops.join(', ')})` : 'conic-gradient(#e2e8f0 0 100%)' }}
                >
                    <div className="absolute inset-3 rounded-full bg-white dark:bg-zinc-900" />
                    <div className="absolute inset-0 flex items-center justify-center">
                        <p className="text-lg font-black tabular-nums text-slate-900 dark:text-white">{formatNumber(total === 1 && items.every((i) => !i.value) ? 0 : items.reduce((s, i) => s + Number(i.value || 0), 0))}</p>
                    </div>
                </div>
                <div className="space-y-2">
                    {items.map((item, index) => {
                        const pct = total ? ((Number(item.value || 0) / total) * 100).toFixed(1) : '0.0';
                        return (
                            <div key={item.label} className="flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-zinc-300">
                                <span className="h-2.5 w-2.5 rounded-full" style={{ background: chartPalette[index % chartPalette.length] }} />
                                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                <span className="font-black text-slate-900 dark:text-white">{pct}%</span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function VerticalBars({ title, items, onSelect }) {
    const max = Math.max(...items.map((item) => Number(item.value || 0)), 1);
    const plotHeight = 200;

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h3 className="text-sm font-black text-slate-900 dark:text-white">{title}</h3>
            <div className="mt-3 overflow-x-auto">
                <div
                    className="relative flex items-end gap-2 px-1"
                    style={{
                        minWidth: `${Math.max(items.length, 1) * 48}px`,
                        height: `${plotHeight + 72}px`,
                        paddingBottom: '64px',
                    }}
                >
                    {items.map((item) => {
                        const value = Number(item.value || 0);
                        const barHeight = Math.max(8, Math.round((value / max) * plotHeight));

                        return (
                            <button
                                key={item.label}
                                type="button"
                                onClick={() => onSelect?.(item.label)}
                                className="group relative flex w-10 shrink-0 flex-col items-center"
                                style={{ height: `${plotHeight + 18}px` }}
                                title={`${item.label}: ${value}`}
                            >
                                <div className="flex h-full w-full flex-col items-center justify-end">
                                    <span className="mb-1 text-[10px] font-black tabular-nums text-slate-600 dark:text-zinc-300">{value}</span>
                                    <span
                                        className="w-8 rounded-t-md bg-gradient-to-t from-teal-700 to-cyan-400 shadow-sm transition group-hover:from-brand-800 group-hover:to-brand-400"
                                        style={{ height: `${barHeight}px` }}
                                    />
                                </div>
                                <span className="pointer-events-none absolute left-1/2 top-[calc(100%+6px)] w-28 -translate-x-1/2 origin-top text-center text-[10px] font-bold leading-tight text-slate-600 dark:text-zinc-300"
                                    style={{ transform: 'translateX(-50%) rotate(-40deg)', transformOrigin: 'top center' }}
                                >
                                    {item.label}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function HorizontalBars({ title, items, onSelect }) {
    const max = Math.max(...items.map((item) => Number(item.value || 0)), 1);

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h3 className="text-sm font-black text-slate-900 dark:text-white">{title}</h3>
            <div className="mt-4 max-h-[360px] space-y-2.5 overflow-y-auto pr-1">
                {items.map((item, index) => (
                    <button
                        key={item.label}
                        type="button"
                        onClick={() => onSelect?.(item.label)}
                        className="grid w-full grid-cols-[minmax(7rem,0.9fr)_minmax(0,1.4fr)_auto] items-center gap-2 text-left"
                    >
                        <span className="truncate text-xs font-semibold text-slate-700 dark:text-zinc-200" title={item.label}>{item.label}</span>
                        <span className="h-3.5 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                            <span
                                className="block h-full rounded-full transition-[width]"
                                style={{
                                    width: `${(Number(item.value || 0) / max) * 100}%`,
                                    background: chartPalette[index % chartPalette.length],
                                }}
                            />
                        </span>
                        <span className="min-w-[1.5rem] text-right text-xs font-black tabular-nums text-slate-900 dark:text-white">{formatNumber(item.value)}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function countBy(rows, keyFn) {
    const map = new Map();
    rows.forEach((row) => {
        const key = String(keyFn(row) || 'Unknown').trim() || 'Unknown';
        map.set(key, (map.get(key) || 0) + 1);
    });
    return [...map.entries()]
        .map(([label, value]) => ({ label, value }))
        .sort((a, b) => b.value - a.value || a.label.localeCompare(b.label));
}

function facility(center, key) {
    return center?.facilities?.[key] ?? null;
}

const SECTION_TABS = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'details', label: 'Details', icon: ListChecks },
    { id: 'latrines', label: 'Latrines', icon: ShowerHead },
    { id: 'facilities', label: 'Facilities', icon: Droplets },
    { id: 'documentation', label: 'Documentation', icon: ListChecks },
    { id: 'photos', label: 'Photos', icon: Camera },
];

export default function Index({
    workspace = 'drims',
    title = 'Evacuation Centers',
    scopeLabel = 'Caraga',
    lgu = null,
    metrics = {},
    centers = [],
    municipalities = [],
    filters = {},
    featuredDashboards = [],
    activeFeatured = null,
    sourceUrl = null,
    generatedAt = null,
    filterBasePath = '/evacuation-centers',
    temporaryNotice = '',
}) {
    const isLguWorkspace = workspace === 'lgu';
    const [section, setSection] = useState('dashboard');
    const [search, setSearch] = useState(filters.search || '');
    const [barangay, setBarangay] = useState('');
    const [availability, setAvailability] = useState('');
    const [ecType, setEcType] = useState('');
    const [buildingStatus, setBuildingStatus] = useState('');
    const [selectedId, setSelectedId] = useState(centers[0]?.id || '');
    const [lightbox, setLightbox] = useState(null);
    const [mapLayer, setMapLayer] = useState('street');

    useEffect(() => {
        setSearch(filters.search || '');
        setBarangay('');
        setAvailability('');
        setEcType('');
        setBuildingStatus('');
    }, [filters.municipality, filters.search]);

    const filteredCenters = useMemo(() => {
        const query = search.trim().toLowerCase();
        const seen = new Set();

        return centers
            .filter((center) => {
                if (barangay && center.barangay !== barangay) return false;
                if (availability && center.availability !== availability) return false;
                if (ecType && center.ec_type !== ecType) return false;
                if (buildingStatus && center.building_status !== buildingStatus) return false;
                if (!query) return true;
                const haystack = [
                    center.name,
                    center.barangay,
                    center.municipality,
                    center.address,
                    center.ec_type,
                    center.camp_manager,
                    ...(center.amenities || []),
                ].join(' ').toLowerCase();
                return haystack.includes(query);
            })
            .filter((center) => {
                const lat = center.lat != null ? Number(center.lat).toFixed(5) : '';
                const lng = center.lng != null ? Number(center.lng).toFixed(5) : '';
                const key = [
                    String(center.municipality || '').toLowerCase(),
                    String(center.barangay || '').toLowerCase(),
                    String(center.name || '').toLowerCase(),
                    lat,
                    lng,
                ].join('|');
                if (seen.has(key) || seen.has(center.id)) {
                    return false;
                }
                seen.add(key);
                seen.add(center.id);
                return true;
            })
            .slice()
            .sort((a, b) => {
                const byMunicipality = String(a.municipality || '').localeCompare(String(b.municipality || ''), undefined, { sensitivity: 'base' });
                if (byMunicipality !== 0) return byMunicipality;
                const byBarangay = String(a.barangay || '').localeCompare(String(b.barangay || ''), undefined, { sensitivity: 'base' });
                if (byBarangay !== 0) return byBarangay;
                return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
            });
    }, [centers, search, barangay, availability, ecType, buildingStatus]);

    useEffect(() => {
        if (!filteredCenters.some((center) => center.id === selectedId)) {
            setSelectedId(filteredCenters[0]?.id || '');
        }
    }, [filteredCenters, selectedId]);

    const selected = filteredCenters.find((center) => center.id === selectedId) || null;
    const mapPoints = filteredCenters.filter((center) => Number(center.lat) && Number(center.lng));
    const mapCenter = mapPoints.length
        ? [
            mapPoints.reduce((sum, point) => sum + Number(point.lat), 0) / mapPoints.length,
            mapPoints.reduce((sum, point) => sum + Number(point.lng), 0) / mapPoints.length,
        ]
        : [9.55, 125.55];

    const uniqueOptions = (key) => [...new Set(centers.map((center) => center[key]).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b))
        .map((value) => ({ value, label: value }));

    const liveMetrics = useMemo(() => ({
        barangays: new Set(filteredCenters.map((c) => c.barangay).filter(Boolean)).size,
        total: filteredCenters.length,
        family_capacity: filteredCenters.reduce((sum, c) => sum + Number(c.family_capacity || 0), 0),
        individual_capacity: filteredCenters.reduce((sum, c) => sum + Number(c.individual_capacity || 0), 0),
        with_photos: filteredCenters.filter((c) => c.photo_source === 'lgu_geotag_sheet' || c.photo_url || (Array.isArray(c.photo_urls) && c.photo_urls.length)).length,
        operational: filteredCenters.filter((c) => /operational/i.test(String(c.building_status || ''))).length,
    }), [filteredCenters]);

    const showMunicipalityColumn = !isLguWorkspace && new Set(filteredCenters.map((c) => c.municipality).filter(Boolean)).size > 1;
    const showProvinceColumn = !isLguWorkspace && new Set(filteredCenters.map((c) => c.province).filter(Boolean)).size > 1;
    const geotagSheetUrl = activeFeatured?.geotag_sheet_url
        || featuredDashboards.find((dashboard) => dashboard.sheet_municipality === filters.municipality)?.geotag_sheet_url
        || null;

    const barangayCounts = useMemo(() => countBy(filteredCenters, (c) => c.barangay), [filteredCenters]);
    const typeCounts = useMemo(() => countBy(filteredCenters, (c) => c.ec_type), [filteredCenters]);
    const availabilityCounts = useMemo(() => countBy(filteredCenters, (c) => c.availability), [filteredCenters]);
    const buildingCounts = useMemo(() => countBy(filteredCenters, (c) => c.building_status), [filteredCenters]);
    const photoCenters = useMemo(
        () => filteredCenters.filter((c) => c.photo_source === 'lgu_geotag_sheet' || (Array.isArray(c.photo_urls) && c.photo_urls.length) || c.photo_url),
        [filteredCenters],
    );

    const pushFilters = (next) => {
        router.get(filterBasePath, {
            municipality: next.municipality ?? filters.municipality ?? '',
            search: next.search ?? search,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const clearLocalFilters = () => {
        setBarangay('');
        setAvailability('');
        setEcType('');
        setBuildingStatus('');
        setSearch('');
    };

    const filterBar = (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <SearchableSelect
                label="Barangay"
                options={[{ value: '', label: 'All barangays' }, ...uniqueOptions('barangay')]}
                value={barangay}
                onChange={setBarangay}
                placeholder="Barangay..."
            />
            <label className="text-sm font-medium">
                Search / EC name
                <div className="relative mt-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        className="w-full pl-9"
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Name, address, camp manager..."
                    />
                </div>
            </label>
            <SearchableSelect
                label="Availability status"
                options={[{ value: '', label: 'All statuses' }, ...uniqueOptions('availability')]}
                value={availability}
                onChange={setAvailability}
            />
            <SearchableSelect
                label="EC type"
                options={[{ value: '', label: 'All types' }, ...uniqueOptions('ec_type')]}
                value={ecType}
                onChange={setEcType}
            />
            <SearchableSelect
                label="Building status"
                options={[{ value: '', label: 'All building statuses' }, ...uniqueOptions('building_status')]}
                value={buildingStatus}
                onChange={setBuildingStatus}
            />
        </div>
    );

    const recordsColumns = {
        dashboard: [
            ...(showProvinceColumn ? [{ key: 'province', label: 'Province' }] : []),
            ...(showMunicipalityColumn ? [{ key: 'municipality', label: 'Municipality' }] : []),
            { key: 'barangay', label: 'Barangay' },
            { key: 'address', label: 'Address' },
            { key: 'name', label: 'Evacuation Center Name' },
            { key: 'availability', label: 'Availability' },
            { key: 'ec_type', label: 'EC Type' },
            { key: 'other_ec_type', label: 'Other EC Type' },
        ],
        details: [
            ...(showProvinceColumn ? [{ key: 'province', label: 'Province' }] : []),
            ...(showMunicipalityColumn ? [{ key: 'municipality', label: 'Municipality' }] : []),
            { key: 'barangay', label: 'Barangay' },
            { key: 'address', label: 'Address' },
            { key: 'name', label: 'Evacuation Center Name' },
            { key: 'geo', label: 'Geolocation' },
            { key: 'availability', label: 'Availability' },
            { key: 'ec_type', label: 'EC Type' },
            { key: 'building_status', label: 'Building Status' },
            { key: 'camp_manager', label: 'Camp Manager' },
            { key: 'ffps', label: 'FFPs Storage' },
            { key: 'floor_area_sqm', label: 'Floor Area' },
            { key: 'family_capacity', label: 'Family Cap.' },
            { key: 'individual_capacity', label: 'Individual Cap.' },
            { key: 'rooms', label: 'Rooms' },
        ],
        latrines: [
            ...(showProvinceColumn ? [{ key: 'province', label: 'Province' }] : []),
            ...(showMunicipalityColumn ? [{ key: 'municipality', label: 'Municipality' }] : []),
            { key: 'barangay', label: 'Barangay' },
            { key: 'address', label: 'Address' },
            { key: 'name', label: 'Evacuation Center Name' },
            { key: 'compost_pit', label: 'Compost Pit' },
            { key: 'sealed', label: 'Sealed Latrine' },
            { key: 'female_cr', label: 'Female CR' },
            { key: 'male_cr', label: 'Male CR' },
            { key: 'common_cr', label: 'Common CR' },
        ],
        facilities: [
            ...(showProvinceColumn ? [{ key: 'province', label: 'Province' }] : []),
            ...(showMunicipalityColumn ? [{ key: 'municipality', label: 'Municipality' }] : []),
            { key: 'barangay', label: 'Barangay' },
            { key: 'name', label: 'Evacuation Center Name' },
            { key: 'potable', label: 'Potable Water' },
            { key: 'potable_src', label: 'Potable Source' },
            { key: 'non_potable', label: 'Non-potable' },
            { key: 'non_potable_src', label: 'Non-potable Source' },
            { key: 'laundry', label: 'Laundry' },
            { key: 'health', label: 'Health Station' },
            { key: 'mrf', label: 'Material Recovery Facility' },
            { key: 'animals_area', label: 'Domestic & Livestock Animals Area' },
            { key: 'cfs', label: 'CFS' },
            { key: 'wfs', label: 'WFS' },
            { key: 'couples_room', label: "Couple's Room" },
            { key: 'prayer_room', label: 'Prayer Room' },
            { key: 'kitchen', label: 'Kitchen' },
            { key: 'wash', label: 'WASH Facility / Water Source' },
            { key: 'ramp', label: 'PWD Ramp' },
            { key: 'help_desk', label: 'Help Desk' },
            { key: 'info_board', label: 'Information Board' },
        ],
        documentation: [
            ...(showProvinceColumn ? [{ key: 'province', label: 'Province' }] : []),
            ...(showMunicipalityColumn ? [{ key: 'municipality', label: 'Municipality' }] : []),
            { key: 'barangay', label: 'Barangay' },
            { key: 'name', label: 'Evacuation Center Name' },
            { key: 'camp_manager', label: 'Camp Manager' },
            { key: 'inventory_date', label: 'Date' },
            { key: 'plotted_by', label: 'Plotted By' },
            { key: 'data_source', label: 'Data Source' },
            { key: 'remarks', label: 'Remarks' },
        ],
    };

    const cellValue = (row, key) => {
        switch (key) {
            case 'geo':
                return row.lat && row.lng ? `${row.lat}, ${row.lng}` : '—';
            case 'ffps':
                return <YnBadge value={facility(row, 'ffps_storage')} />;
            case 'compost_pit':
                return <YnBadge value={facility(row, 'compost_pit')} />;
            case 'sealed':
                return <YnBadge value={facility(row, 'sealed_latrine')} />;
            case 'female_cr':
                return <YnBadge value={facility(row, 'female_cr')} />;
            case 'male_cr':
                return <YnBadge value={facility(row, 'male_cr')} />;
            case 'common_cr':
                return <YnBadge value={facility(row, 'common_cr')} />;
            case 'potable':
                return <YnBadge value={facility(row, 'potable_water')} />;
            case 'potable_src':
                return facility(row, 'potable_water_source') || '—';
            case 'non_potable':
                return <YnBadge value={facility(row, 'non_potable_water')} />;
            case 'non_potable_src':
                return facility(row, 'non_potable_water_source') || 'â€”';
            case 'laundry':
                return <YnBadge value={facility(row, 'laundry_space')} />;
            case 'health':
                return <YnBadge value={facility(row, 'health_station')} />;
            case 'mrf':
                return <YnBadge value={facility(row, 'mrf')} />;
            case 'animals_area':
                return <YnBadge value={facility(row, 'animals_area')} />;
            case 'cfs':
                return <YnBadge value={facility(row, 'child_friendly_space')} />;
            case 'wfs':
                return <YnBadge value={facility(row, 'women_friendly_space')} />;
            case 'couples_room':
                return <YnBadge value={facility(row, 'couples_room')} />;
            case 'prayer_room':
                return <YnBadge value={facility(row, 'prayer_room')} />;
            case 'kitchen':
                return <YnBadge value={facility(row, 'community_kitchen')} />;
            case 'wash':
                return <YnBadge value={facility(row, 'wash_facility')} />;
            case 'ramp':
                return <YnBadge value={facility(row, 'ramp_pwd')} />;
            case 'help_desk':
                return <YnBadge value={facility(row, 'help_desk')} />;
            case 'info_board':
                return <YnBadge value={facility(row, 'info_board')} />;
            default:
                return row[key] ?? '—';
        }
    };

    const renderRows = (columnKeys, onClick) => filteredCenters.map((row) => (
        <tr
            key={row.id}
            onClick={() => onClick?.(row)}
            className={`cursor-pointer ${row.id === selectedId ? 'bg-brand-50/80 dark:bg-brand-950/30' : ''}`}
        >
            {columnKeys.map((column) => (
                <td key={column.key} className="whitespace-nowrap px-4 py-3 text-sm font-semibold text-slate-700 dark:text-zinc-200">
                    {cellValue(row, column.key)}
                </td>
            ))}
        </tr>
    ));

    return (
        <AppLayout title={title}>
            <Head title={title} />

            <div className="space-y-4">
                <div className="overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-950 via-slate-900 to-teal-950 text-white shadow-lg">
                    <div className="grid gap-4 p-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(260px,0.8fr)] lg:items-end">
                        <div>
                            <p className="text-[11px] font-black uppercase tracking-[0.2em] text-teal-200/90">
                                {isLguWorkspace ? 'LGU evacuation command view' : 'DRIMS evacuation command view'}
                            </p>
                            <h1 className="mt-2 text-3xl font-black tracking-tight">
                                {isLguWorkspace ? (lgu?.name || scopeLabel) : 'Evacuation Centers'}
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm font-semibold text-slate-300">
                                {temporaryNotice || 'Interactive inventory dashboard with details, latrines, facilities, map, and geotagged photos.'}
                            </p>
                            <p className="mt-2 text-xs font-semibold text-slate-400">
                                Scope: <span className="font-black text-white">{scopeLabel}</span>
                                {generatedAt ? ` · Snapshot ${new Date(generatedAt).toLocaleString()}` : ''}
                                {' · '}
                                <span className="font-black text-teal-200">{formatNumber(filteredCenters.length)}</span> centers
                                {' · '}
                                <span className="font-black text-teal-200">{formatNumber(liveMetrics.with_photos)}</span> photos
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <MetricPill label="Barangays" value={liveMetrics.barangays} tone="amber" />
                            <MetricPill label="Identified ECs" value={liveMetrics.total} tone="teal" />
                            <MetricPill label="Geotagged photos" value={liveMetrics.with_photos} tone="indigo" />
                            <MetricPill label="Family capacity" value={liveMetrics.family_capacity} tone="emerald" />
                        </div>
                    </div>
                </div>

                {!isLguWorkspace && (
                    <Card className="p-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div className="w-full max-w-md">
                                <SearchableSelect
                                    label="Municipality / City"
                                    options={[
                                        { value: '', label: 'All Caraga inventory' },
                                        { value: 'featured', label: 'Featured: Mainit, Alegria, Sison' },
                                        ...municipalities,
                                    ]}
                                    value={filters.municipality || ''}
                                    onChange={(value) => pushFilters({ municipality: value })}
                                    placeholder="Filter LGU..."
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {featuredDashboards?.map((dashboard) => (
                                    <button
                                        key={dashboard.code || dashboard.name}
                                        type="button"
                                        onClick={() => pushFilters({ municipality: dashboard.sheet_municipality })}
                                        className={`rounded-full border px-3 py-1.5 text-xs font-black transition ${
                                            filters.municipality === dashboard.sheet_municipality
                                                ? 'border-brand-600 bg-brand-700 text-white'
                                                : 'border-brand-200 bg-brand-50 text-brand-800 hover:bg-brand-100 dark:border-brand-800 dark:bg-brand-950/40 dark:text-brand-100'
                                        }`}
                                    >
                                        {dashboard.name} · {formatNumber(dashboard.count)}
                                        {typeof dashboard.photo_count === 'number' ? ` / ${formatNumber(dashboard.photo_count)} photos` : ''}
                                    </button>
                                ))}
                                {geotagSheetUrl && (
                                    <a
                                        href={geotagSheetUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-2 rounded-full border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-black text-teal-800 dark:border-teal-800 dark:bg-teal-950/40 dark:text-teal-100"
                                    >
                                        LGU geotag sheet <ExternalLink className="h-3.5 w-3.5" />
                                    </a>
                                )}
                                {sourceUrl && (
                                    <a
                                        href={sourceUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-black text-slate-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                                    >
                                        DRIMS inventory sheet <ExternalLink className="h-3.5 w-3.5" />
                                    </a>
                                )}
                            </div>
                        </div>
                    </Card>
                )}

                <SectionTabs
                    label="Evacuation center views"
                    tabs={SECTION_TABS}
                    value={section}
                    onChange={setSection}
                />

                <Card className="p-4">
                    {filterBar}
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" onClick={clearLocalFilters} className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-black text-slate-600 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-300">
                            Reset filters
                        </button>
                        <p className="text-xs font-semibold text-slate-500">
                            Showing <span className="font-black text-slate-800 dark:text-zinc-100">{formatNumber(filteredCenters.length)}</span> of {formatNumber(centers.length || metrics.total)}
                        </p>
                    </div>
                </Card>

                {section === 'dashboard' && (
                    <div className="space-y-4">
                        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)]">
                            <ExportableCard id="evac-dashboard-table" title="Identified evacuation centers" className="scroll-mt-28">
                                <h2 className="mb-3 text-lg font-black">Identified evacuation centers</h2>
                                <DataTable
                                    stickyHeader
                                    className={TABLE_SCROLL_CLASS}
                                    columns={recordsColumns.dashboard.map((column) => column.label)}
                                    rows={renderRows(recordsColumns.dashboard, (row) => setSelectedId(row.id))}
                                />
                            </ExportableCard>
                            <div id="evac-dashboard-charts" className="scroll-mt-28">
                                <VerticalBars
                                    title="No. of identified ECs per barangay"
                                    items={barangayCounts}
                                    onSelect={(label) => {
                                        setBarangay(label);
                                        setSection('details');
                                    }}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <HorizontalBars
                                title="No. of ECs per EC type"
                                items={typeCounts}
                                onSelect={(label) => {
                                    setEcType(label);
                                    setSection('details');
                                }}
                            />
                            <ExportableCard id="evac-map" title="Evacuation center map" className="scroll-mt-28 overflow-hidden p-0">
                                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-zinc-800">
                                    <h2 className="text-sm font-black">Map</h2>
                                    <div className="flex gap-1 rounded-full bg-slate-100 p-1 text-[10px] font-black dark:bg-zinc-800">
                                        <button type="button" onClick={() => setMapLayer('street')} className={`rounded-full px-2 py-1 ${mapLayer === 'street' ? 'bg-white shadow dark:bg-zinc-950' : ''}`}>Street</button>
                                        <button type="button" onClick={() => setMapLayer('satellite')} className={`rounded-full px-2 py-1 ${mapLayer === 'satellite' ? 'bg-white shadow dark:bg-zinc-950' : ''}`}>Satellite</button>
                                    </div>
                                </div>
                                <div className="h-[min(36vh,320px)] min-h-[240px]">
                                    <MapContainer center={mapCenter} zoom={11} minZoom={8} scrollWheelZoom={false} className="h-full w-full">
                                        <TileLayer
                                            attribution={mapLayer === 'satellite' ? '&copy; Esri' : '&copy; OpenStreetMap'}
                                            url={mapLayer === 'satellite'
                                                ? 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'
                                                : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'}
                                        />
                                        <FitMapToPoints points={mapPoints} />
                                        {mapPoints.map((point, index) => {
                                            const color = typeColors[index % typeColors.length];
                                            const isSelected = selectedId === point.id;
                                            return (
                                                <CircleMarker
                                                    key={point.id}
                                                    center={[point.lat, point.lng]}
                                                    radius={isSelected ? 12 : 8}
                                                    pathOptions={{ color, fillColor: color, fillOpacity: isSelected ? 0.9 : 0.55, weight: isSelected ? 3 : 2 }}
                                                    eventHandlers={{ click: () => setSelectedId(point.id) }}
                                                >
                                                    <Popup>
                                                        <div className="text-sm">
                                                            <p className="font-bold">{point.barangay}</p>
                                                            <p>{point.name}</p>
                                                            <p className="text-xs text-slate-500">Family capacity: {formatNumber(point.family_capacity)}</p>
                                                        </div>
                                                    </Popup>
                                                </CircleMarker>
                                            );
                                        })}
                                    </MapContainer>
                                </div>
                            </ExportableCard>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <DonutChart title="% breakdown · availability" items={availabilityCounts} />
                            <DonutChart title="% breakdown · building status" items={buildingCounts} />
                        </div>
                    </div>
                )}

                {['details', 'latrines', 'facilities', 'documentation'].includes(section) && (
                    <ExportableCard id={`evac-${section}`} title={`EC ${section}`} className="scroll-mt-28">
                        <div className="mb-3 flex items-end justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-black capitalize">EC {section}</h2>
                                <p className="text-sm font-semibold text-slate-500">{formatNumber(filteredCenters.length)} centers in current filters</p>
                            </div>
                        </div>
                        <DataTable
                            stickyHeader
                            className={TABLE_SCROLL_CLASS}
                            columns={recordsColumns[section].map((column) => column.label)}
                            rows={renderRows(recordsColumns[section], (row) => setSelectedId(row.id))}
                        />
                    </ExportableCard>
                )}

                {section === 'photos' && (
                    <ExportableCard id="evac-photo-gallery" title="Photo documentation" className="scroll-mt-28">
                        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-black">Photo documentation</h2>
                                <p className="text-sm font-semibold text-slate-500">
                                    {formatNumber(photoCenters.length)} geotagged site photos in current filters
                                </p>
                            </div>
                        </div>

                        {photoCenters.length ? (
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                {photoCenters.map((center) => (
                                    <button
                                        key={center.id}
                                        type="button"
                                        onClick={() => {
                                            setSelectedId(center.id);
                                            setLightbox(center);
                                        }}
                                        className={`overflow-hidden rounded-xl border text-left transition hover:-translate-y-0.5 hover:shadow-md ${
                                            selectedId === center.id
                                                ? 'border-brand-600 ring-2 ring-brand-300'
                                                : 'border-slate-200 dark:border-zinc-800'
                                        }`}
                                    >
                                        <div className="flex h-44 items-center justify-center bg-slate-100 dark:bg-zinc-900">
                                            <EcPhoto center={center} preferSitePhoto fit="contain" className="max-h-44 max-w-full" />
                                        </div>
                                        <div className="space-y-1 p-3">
                                            <p className="line-clamp-2 text-sm font-black text-slate-900 dark:text-white">{center.name}</p>
                                            <p className="text-xs font-semibold text-slate-500">{center.barangay} · {center.address || '—'}</p>
                                            <div className="flex flex-wrap gap-1 pt-1">
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">{center.availability || 'Status n/a'}</span>
                                                <span className="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-teal-700 dark:bg-teal-950/40 dark:text-teal-200">{center.building_status || 'Building n/a'}</span>
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm font-semibold text-slate-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                                No geotagged photos in the current filter set.
                            </div>
                        )}
                    </ExportableCard>
                )}

                {selected && !['dashboard', 'photos'].includes(section) && (
                    <Card className="p-4">
                        <div className="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)]">
                            <div className="flex h-40 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-100 dark:border-zinc-800 dark:bg-zinc-900">
                                <EcPhoto center={selected} preferSitePhoto fit="contain" className="max-h-40 max-w-full" />
                            </div>
                            <div>
                                <p className="text-[11px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-200">{selected.ec_type || 'Evacuation center'}</p>
                                <h3 className="mt-1 text-xl font-black text-slate-950 dark:text-white">{selected.name}</h3>
                                <p className="mt-1 flex items-start gap-1.5 text-sm font-semibold text-slate-600 dark:text-zinc-300">
                                    <MapPin className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{[selected.address, selected.barangay, selected.municipality, selected.province].filter(Boolean).join(', ')}</span>
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-700 dark:bg-zinc-800 dark:text-zinc-200">{selected.availability || '—'}</span>
                                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">{selected.building_status || '—'}</span>
                                    <span className="rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-black text-brand-800 dark:bg-brand-950/40 dark:text-brand-100">Families {formatNumber(selected.family_capacity)}</span>
                                    <span className="rounded-full bg-orange-50 px-2.5 py-1 text-[11px] font-black text-orange-700 dark:bg-orange-950/40 dark:text-orange-200">Persons {formatNumber(selected.individual_capacity)}</span>
                                </div>
                                {(selected.photo_open_url || selected.photo_open_urls?.[0]) && (
                                    <a
                                        href={selected.photo_open_urls?.[0] || selected.photo_open_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-black text-brand-800 hover:underline dark:text-brand-200"
                                    >
                                        Open photo in Google Drive <ExternalLink className="h-3.5 w-3.5" />
                                    </a>
                                )}
                            </div>
                        </div>
                    </Card>
                )}
            </div>

            {lightbox && (
                <div
                    className="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/80 p-3 sm:p-6"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) {
                            setLightbox(null);
                        }
                    }}
                >
                    <div className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-950">
                        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-zinc-800">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-black text-slate-900 dark:text-white">{lightbox.name}</p>
                                <p className="truncate text-xs font-semibold text-slate-500">{lightbox.barangay} · {lightbox.municipality}</p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {(lightbox.photo_open_url || lightbox.photo_open_urls?.[0]) && (
                                    <a
                                        href={lightbox.photo_open_urls?.[0] || lightbox.photo_open_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.5 text-xs font-black text-slate-700 dark:border-zinc-700 dark:text-zinc-200"
                                    >
                                        Drive <ExternalLink className="h-3.5 w-3.5" />
                                    </a>
                                )}
                                <button type="button" onClick={() => setLightbox(null)} className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-black dark:border-zinc-700">Close</button>
                            </div>
                        </div>
                        <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-slate-950/95 p-3 sm:p-4">
                            <EcPhoto
                                center={lightbox}
                                preferSitePhoto
                                fit="contain"
                                className="max-h-[calc(92vh-5.5rem)] max-w-full"
                            />
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
