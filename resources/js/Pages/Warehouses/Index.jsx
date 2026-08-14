import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Eye, Filter, Pencil, Plus, RefreshCw, Save, Search, Warehouse as WarehouseIcon, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import LookerMultiSelect from '@/Components/LookerMultiSelect';
import { formatDateTime } from '@/Utils/dateFormat';

const filterConfig = [
    ['province', 'Province', 'provinces', 'All Provinces'],
    ['district', 'District', 'districts', 'All Districts'],
    ['municipality', 'Municipality', 'municipalities', 'All Municipalities'],
    ['warehouse_name', 'Warehouse Name', 'warehouse_names', 'All Warehouses'],
    ['status', 'Status', 'statuses', 'All Statuses'],
    ['partnership', 'Partnership', 'partnerships', 'All Partnerships'],
    ['category', 'Category', 'categories', 'All Categories'],
    ['wh_focal', 'WH Focal', 'wh_focals', 'All WH Focals'],
    ['storekeeper', 'Storekeeper', 'storekeepers', 'All Storekeepers'],
];

const clean = (value) => value || '-';
const number = (value) => Number(value ?? 0).toLocaleString();
const cellClass = 'whitespace-nowrap px-4 py-3';
const psgcAddressFields = ['office', 'province', 'district', 'municipality', 'barangay_name', 'barangay_code'];
const identityFields = ['external_warehouse_id', 'name', 'warehouse_number'];

const editableFields = [
    ['office', 'Field Office'],
    ['province', 'Province'],
    ['district', 'District'],
    ['municipality', 'Municipality'],
    ['barangay_name', 'Barangay'],
    ['barangay_code', 'Barangay Code'],
    ['warehouse_type', 'Warehouse Type'],
    ['distribution_network', 'Distribution Network'],
    ['category', 'Category'],
    ['ownership', 'Ownership'],
    ['partnership', 'Partnership'],
    ['external_warehouse_id', 'Warehouse ID'],
    ['name', 'Warehouse Name'],
    ['warehouse_number', 'Warehouse Number'],
    ['contact_person', 'WH Focal'],
    ['contact_number', 'Contact Number'],
    ['email', 'WH Focal Email'],
    ['designated_storekeepers', 'Storekeeper'],
    ['storekeeper_contact_number', 'Storekeeper Contact'],
    ['rtef_capacity', 'RTEF Capacity', 'number'],
    ['ffp_capacity', 'FFP Capacity', 'number'],
    ['longitude', 'Longitude', 'number'],
    ['latitude', 'Latitude', 'number'],
    ['rpa_start_date', 'RPA Start Date', 'date'],
    ['rpa_end_date', 'RPA End Date', 'date'],
    ['validity', 'Validity'],
];

const dropdownFields = {
    distribution_network: 'distribution_networks',
    warehouse_type: 'warehouse_types',
    category: 'categories',
    ownership: 'ownerships',
    partnership: 'partnerships',
};

const toSelectOptions = (values = [], currentValue = '') => Array.from(new Set([...values, currentValue].filter(Boolean)))
    .sort()
    .map((option) => ({ label: option, value: option }));
const toPsgcOptions = (values = [], formatter = (value) => value.name) => values.map((value) => ({
    label: formatter(value),
    value: formatter(value),
    code: value.code,
    raw: value,
}));
const withCurrentOption = (options, currentValue) => {
    if (!currentValue || options.some((option) => option.value === currentValue)) {
        return options;
    }

    return [{ label: currentValue, value: currentValue, code: '' }, ...options];
};
const filterMultiOptions = (values = [], formatter = (value) => value) => values.map((value) => ({ value: String(value), label: formatter(value) }));
const formatStatus = (status) => status ? status.charAt(0).toUpperCase() + status.slice(1) : status;
const regionLabel = (region) => String(region.short_name || region.name || '').toUpperCase();
const areaLabel = (area) => String(area.name ?? '').trim();
const normalizeClassification = (value) => String(value ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '');
const isPrepositioningType = (value) => normalizeClassification(value).includes('preposition');
const classificationOptions = (rules, warehouseType, field) => {
    if (!warehouseType) {
        return [];
    }

    const group = isPrepositioningType(warehouseType) ? 'prepositioning' : 'other';
    const map = {
        category: 'categories',
        ownership: 'ownerships',
        partnership: 'partnerships',
    };

    return rules?.[group]?.[map[field]] ?? [];
};
const classificationDistributionNetwork = (rules, warehouseType) => {
    if (!warehouseType) {
        return '';
    }

    const group = isPrepositioningType(warehouseType) ? 'prepositioning' : 'other';

    return rules?.[group]?.distribution_network ?? (group === 'prepositioning' ? 'Last Mile' : 'Spokes');
};
const categoryLocksOwnership = (category) => ['owned', 'rented'].includes(normalizeClassification(category));
const humanAutoValue = (value) => normalizeClassification(value) === 'rented' ? 'Rented' : 'Owned';
const requiredFields = ['province', 'municipality', 'warehouse_type', 'category', 'ownership', 'partnership'];
const emptyWarehouseData = {
    external_warehouse_id: '',
    name: '',
    warehouse_number: '',
    office: '',
    province: '',
    district: '',
    municipality: '',
    barangay_name: '',
    barangay_code: '',
    distribution_network: '',
    warehouse_type: '',
    category: '',
    ownership: '',
    partnership: '',
    contact_person: '',
    contact_number: '',
    email: '',
    designated_storekeepers: '',
    storekeeper_contact_number: '',
    rtef_capacity: '',
    ffp_capacity: '',
    longitude: '',
    latitude: '',
    rpa_start_date: '',
    rpa_end_date: '',
    validity: '',
    status: 'active',
};
const warehouseFormData = (warehouse = {}) => ({
    ...emptyWarehouseData,
    external_warehouse_id: warehouse.external_warehouse_id ?? '',
    name: warehouse.name ?? '',
    warehouse_number: warehouse.warehouse_number ?? '',
    office: warehouse.office ?? '',
    province: warehouse.province ?? '',
    district: warehouse.district ?? '',
    municipality: warehouse.municipality ?? '',
    barangay_name: warehouse.barangay_name ?? '',
    barangay_code: warehouse.barangay_code ?? '',
    distribution_network: warehouse.distribution_network ?? '',
    warehouse_type: warehouse.warehouse_type ?? '',
    category: warehouse.category ?? '',
    ownership: warehouse.ownership ?? '',
    partnership: warehouse.partnership ?? '',
    contact_person: warehouse.contact_person ?? '',
    contact_number: warehouse.contact_number ?? '',
    email: warehouse.email ?? '',
    designated_storekeepers: warehouse.designated_storekeepers ?? '',
    storekeeper_contact_number: warehouse.storekeeper_contact_number ?? '',
    rtef_capacity: warehouse.rtef_capacity ?? warehouse.capacity ?? '',
    ffp_capacity: warehouse.ffp_capacity ?? '',
    longitude: warehouse.longitude ?? '',
    latitude: warehouse.latitude ?? '',
    rpa_start_date: warehouse.rpa_start_date ?? '',
    rpa_end_date: warehouse.rpa_end_date ?? '',
    validity: warehouse.validity ?? '',
    status: warehouse.status ?? 'active',
});

function StatusBadge({ status }) {
    const isInactive = status === 'inactive';

    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ${isInactive ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'}`}>
            {status || 'active'}
        </span>
    );
}

function StatPill({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700 dark:bg-zinc-800 dark:text-zinc-200',
        green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
        red: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-200',
    };

    return (
        <div className={`rounded-md px-3 py-2 ${tones[tone]}`}>
            <p className="text-[11px] font-semibold uppercase tracking-wide opacity-75">{label}</p>
            <p className="text-lg font-bold">{number(value)}</p>
        </div>
    );
}

function BreakdownCard({ title, rows }) {
    const total = rows.reduce((sum, row) => sum + Number(row.total ?? 0), 0);

        return (
            <ExportableCard title={title} className="overflow-hidden" showExportButtons={false}>
            <div className="-mx-4 -mt-4 border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-center justify-between gap-3">
                    <h3 className="text-sm font-bold uppercase tracking-wide">{title}</h3>
                    <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 shadow-sm dark:bg-zinc-900 dark:text-zinc-300">{number(total)}</span>
                </div>
            </div>
            <div className="mt-4 space-y-3">
                {rows.map((row) => {
                    const percent = total > 0 ? Math.round((Number(row.total ?? 0) / total) * 100) : 0;

                    return (
                        <div key={row.label}>
                            <div className="mb-1 flex items-center justify-between gap-3 text-sm">
                                <span className="truncate font-semibold">{row.label}</span>
                                <span className="text-xs text-slate-500 dark:text-zinc-400">{number(row.total)} | {percent}%</span>
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                <div className="h-full rounded-full bg-brand-600" style={{ width: `${percent}%` }} />
                            </div>
                            <div className="mt-1 flex gap-2 text-[11px] text-slate-500 dark:text-zinc-400">
                                <span>{number(row.active)} active</span>
                                <span>{number(row.inactive)} inactive</span>
                            </div>
                        </div>
                    );
                })}
                {rows.length === 0 && <p className="text-sm text-slate-500 dark:text-zinc-400">No data available.</p>}
            </div>
        </ExportableCard>
    );
}

function WarehouseOverview({ metrics }) {
    const inactive = Number(metrics.inactive ?? 0);
    const total = Number(metrics.warehouses ?? 0);
    const active = Number(metrics.active ?? 0);
    const activePercent = total > 0 ? Math.round((active / total) * 100) : 0;

        return (
            <ExportableCard title="Warehouse Coverage" className="relative overflow-hidden" showExportButtons={false}>
            <div className="absolute inset-x-0 top-0 h-1 bg-brand-600" />
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">Warehouse Coverage</p>
                    <p className="mt-2 text-4xl font-extrabold">{number(total)}</p>
                    <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">managed warehouse records in the current view</p>
                </div>
                <div className="rounded-full border border-brand-100 bg-brand-50 px-3 py-1 text-sm font-bold text-brand-700 dark:border-brand-900 dark:bg-brand-950 dark:text-brand-100">
                    {activePercent}% active
                </div>
            </div>
            <div className="mt-5 h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                <div className="h-full rounded-full bg-brand-600" style={{ width: `${activePercent}%` }} />
            </div>
            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <StatPill label="Active" value={active} tone="green" />
                <StatPill label="Inactive" value={inactive} tone="red" />
            </div>
        </ExportableCard>
    );
}

function WarehouseFormModal({ mode, warehouse, form, editOptions, onClose }) {
    const [notice, setNotice] = useState(null);
    const [generatedIdentity, setGeneratedIdentity] = useState(null);
    const lastGenerated = useRef({ external_warehouse_id: '', name: '', warehouse_number: '' });
    const isEdit = mode === 'edit';
    const rules = editOptions.classificationRules ?? {};

    const optionsForField = (field) => {
        if (['category', 'ownership', 'partnership'].includes(field)) {
            return classificationOptions(rules, form.data.warehouse_type, field).map((option) => ({ label: option, value: option }));
        }

        return toSelectOptions(editOptions[dropdownFields[field]], form.data[field]);
    };

    useEffect(() => {
        setNotice(null);
    }, [mode, warehouse?.id]);

    useEffect(() => {
        if (!mode || !form.data.warehouse_type) {
            return;
        }

        const distributionNetwork = classificationDistributionNetwork(rules, form.data.warehouse_type);
        const nextData = { ...form.data, distribution_network: distributionNetwork };

        ['category', 'ownership', 'partnership'].forEach((field) => {
            const validValues = classificationOptions(rules, form.data.warehouse_type, field).map((value) => String(value));

            if (nextData[field] && validValues.length > 0 && !validValues.includes(String(nextData[field]))) {
                nextData[field] = '';
            }
        });

        if (
            nextData.distribution_network !== form.data.distribution_network
            || nextData.category !== form.data.category
            || nextData.ownership !== form.data.ownership
            || nextData.partnership !== form.data.partnership
        ) {
            form.setData(nextData);
        }
    }, [mode, form.data.warehouse_type]);

    useEffect(() => {
        if (!mode || !categoryLocksOwnership(form.data.category)) {
            return;
        }

        const lockedValue = humanAutoValue(form.data.category);

        if (form.data.ownership !== lockedValue || form.data.partnership !== lockedValue) {
            form.setData({
                ...form.data,
                ownership: lockedValue,
                partnership: lockedValue,
            });
        }
    }, [mode, form.data.category]);

    useEffect(() => {
        if (!mode || isEdit || !form.data.municipality) {
            return;
        }

        const timeout = setTimeout(async () => {
            try {
                const response = await fetch('/warehouses/generate-identity', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        province: form.data.province,
                        municipality: form.data.municipality,
                        barangay_name: form.data.barangay_name,
                        warehouse_number: form.data.warehouse_number,
                        distribution_network: form.data.distribution_network,
                        warehouse_type: form.data.warehouse_type,
                        category: form.data.category,
                        ownership: form.data.ownership,
                        partnership: form.data.partnership,
                    }),
                });

                if (!response.ok) {
                    return;
                }

                const identity = await response.json();
                setGeneratedIdentity(identity);

                const nextData = { ...form.data };

                ['warehouse_number', 'external_warehouse_id', 'name'].forEach((field) => {
                    if (!nextData[field] || nextData[field] === lastGenerated.current[field]) {
                        nextData[field] = identity[field] ?? nextData[field];
                    }
                });

                lastGenerated.current = {
                    warehouse_number: identity.warehouse_number ?? '',
                    external_warehouse_id: identity.external_warehouse_id ?? '',
                    name: identity.name ?? '',
                };

                form.setData(nextData);
            } catch (exception) {
                // Identity generation is a convenience; validation still happens on submit.
            }
        }, 350);

        return () => clearTimeout(timeout);
    }, [
        mode,
        isEdit,
        form.data.province,
        form.data.municipality,
        form.data.barangay_name,
        form.data.warehouse_number,
        form.data.distribution_network,
        form.data.warehouse_type,
        form.data.category,
        form.data.ownership,
        form.data.partnership,
    ]);

    if (!mode) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        setNotice(null);
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onError: () => setNotice({
                type: 'error',
                message: `Warehouse ${isEdit ? 'update' : 'creation'} was not saved. Please review the highlighted fields and try again.`,
            }),
        };

        if (isEdit) {
            form.put(`/warehouses/${warehouse.id}`, options);
        } else {
            form.post('/warehouses', options);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-6 backdrop-blur-sm">
            <div className="max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-md border border-slate-200 bg-white shadow-2xl shadow-slate-950/20 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-black/40">
                <div className="relative overflow-hidden border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-emerald-300 to-sky-500" />
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 ring-1 ring-brand-100 dark:bg-brand-950/40 dark:text-brand-100 dark:ring-brand-900">
                                <WarehouseIcon className="h-5 w-5" />
                            </div>
                            <div className="min-w-0">
                                <h2 className="truncate text-lg font-bold">{isEdit ? 'Edit Warehouse' : 'Add Warehouse'}</h2>
                                <p className="truncate text-xs text-slate-500 dark:text-zinc-400">
                                    {isEdit ? `${warehouse?.external_warehouse_id || 'No warehouse ID'} · ${warehouse?.name}` : 'Create a manual warehouse record for future WIT-free operations'}
                                </p>
                            </div>
                        </div>
                        <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <form onSubmit={submit}>
                    <div className="max-h-[65vh] overflow-y-auto px-5 py-4">
                        {notice && (
                            <div className="mb-4 flex gap-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/50 dark:text-rose-200">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <div>
                                    <p className="font-bold">{isEdit ? 'Edit failed' : 'Create failed'}</p>
                                    <p>{notice.message}</p>
                                </div>
                            </div>
                        )}
                        <div className="grid gap-3 md:grid-cols-2">
                            {generatedIdentity && !isEdit && (
                                <div className="rounded-md border border-brand-100 bg-brand-50/60 px-3 py-2 text-xs font-semibold text-brand-800 md:col-span-2 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-100">
                                    Auto-generated from location and warehouse classification · suffix {generatedIdentity.suffix || '--'}
                                </div>
                            )}
                            <PsgcAddressFields form={form} addressDefaults={editOptions.addressDefaults} />
                            {editableFields.map(([field, label, type = 'text']) => {
                                if (psgcAddressFields.includes(field)) {
                                    return null;
                                }

                                if (dropdownFields[field]) {
                                    const lockedByCategory = ['ownership', 'partnership'].includes(field) && categoryLocksOwnership(form.data.category);

                                    if (field === 'distribution_network' || lockedByCategory) {
                                        return (
                                            <label key={field} className="text-sm font-medium">
                                                {label}{requiredFields.includes(field) ? ' *' : ''}
                                                <input
                                                    className="mt-1 w-full bg-slate-50 text-slate-600 dark:bg-zinc-950 dark:text-zinc-300"
                                                    value={form.data[field] ?? ''}
                                                    readOnly
                                                />
                                                <p className="mt-1 text-[11px] font-semibold text-slate-500 dark:text-zinc-400">
                                                    {field === 'distribution_network' ? 'Auto-filled from the selected Warehouse Type.' : `Locked because Category is ${form.data.category}.`}
                                                </p>
                                                {form.errors[field] && <p className="mt-1 text-xs text-rose-600">{form.errors[field]}</p>}
                                            </label>
                                        );
                                    }

                                    return (
                                        <div key={field}>
                                            <SearchableSelect
                                                label={`${label}${requiredFields.includes(field) ? ' *' : ''}`}
                                                placeholder={`Select ${label}`}
                                                options={optionsForField(field)}
                                                value={form.data[field] ?? ''}
                                                onChange={(value) => form.setData(field, value)}
                                            />
                                            {form.errors[field] && <p className="mt-1 text-xs text-rose-600">{form.errors[field]}</p>}
                                        </div>
                                    );
                                }

                                const isAutoIdentity = !isEdit && identityFields.includes(field);

                                return (
                                    <label key={field} className="text-sm font-medium">
                                        {label}{requiredFields.includes(field) ? ' *' : ''}
                                        <input
                                            type={type}
                                            className={`mt-1 w-full ${isAutoIdentity ? 'bg-slate-50 text-slate-600 dark:bg-zinc-950 dark:text-zinc-300' : ''}`}
                                            value={form.data[field] ?? ''}
                                            readOnly={isAutoIdentity}
                                            onChange={(event) => form.setData(field, event.target.value)}
                                        />
                                        {isAutoIdentity && <p className="mt-1 text-[11px] font-semibold text-slate-500 dark:text-zinc-400">Auto-generated after location and warehouse classification are complete.</p>}
                                        {form.errors[field] && <p className="mt-1 text-xs text-rose-600">{form.errors[field]}</p>}
                                    </label>
                                );
                            })}
                            <label className="text-sm font-medium">
                                Status
                                <select className="mt-1 w-full" value={form.data.status ?? 'active'} onChange={(event) => form.setData('status', event.target.value)}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                {form.errors.status && <p className="mt-1 text-xs text-rose-600">{form.errors.status}</p>}
                            </label>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4 dark:border-zinc-800">
                        <button type="button" onClick={onClose} className="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white">
                            <X className="h-4 w-4" />
                            Cancel
                        </button>
                        <button disabled={form.processing} className="inline-flex items-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-900/20 transition hover:bg-brand-700 disabled:opacity-70">
                            {form.processing ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                            {form.processing ? 'Saving...' : (isEdit ? 'Save Changes' : 'Create Warehouse')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function PsgcAddressFields({ form, addressDefaults }) {
    const [regions, setRegions] = useState([]);
    const [provinces, setProvinces] = useState([]);
    const [districts, setDistricts] = useState([]);
    const [citiesMunicipalities, setCitiesMunicipalities] = useState([]);
    const [barangays, setBarangays] = useState([]);
    const [codes, setCodes] = useState({ region: '', province: '', district: '', cityMunicipality: '' });
    const [loading, setLoading] = useState('');
    const [error, setError] = useState('');

    const fetchPsgc = async (url, setter, loadingKey) => {
        setLoading(loadingKey);
        setError('');

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error('PSGC request failed.');
            }

            setter(await response.json());
        } catch (exception) {
            setter([]);
            setError('Unable to load PSGC address options. Please check the internet connection and try again.');
        } finally {
            setLoading('');
        }
    };

    useEffect(() => {
        fetchPsgc('/psgc/regions', setRegions, 'regions');
    }, []);

    const regionOptions = withCurrentOption(toPsgcOptions(regions, regionLabel), form.data.office ?? '');
    const provinceOptions = withCurrentOption(toPsgcOptions(provinces, areaLabel), form.data.province ?? '');
    const districtOptions = withCurrentOption(toPsgcOptions(districts, areaLabel), form.data.district ?? '');
    const cityMunicipalityOptions = withCurrentOption(toPsgcOptions(citiesMunicipalities, areaLabel), form.data.municipality ?? '');
    const barangayOptions = withCurrentOption(toPsgcOptions(barangays, areaLabel), form.data.barangay_name ?? '');

    useEffect(() => {
        if (regions.length === 0 || codes.region) {
            return;
        }

        const selected = regions.find((region) => regionLabel(region) === form.data.office)
            ?? regions.find((region) => region.code === addressDefaults?.default_region_code)
            ?? regions.find((region) => region.short_name === addressDefaults?.default_region_name)
            ?? regions.find((region) => region.short_name === 'CARAGA' || region.code === '1600000000');

        if (!selected?.code) {
            return;
        }

        setCodes((current) => ({ ...current, region: selected.code }));

        if (!form.data.office) {
            form.setData('office', regionLabel(selected));
        }

        fetchPsgc(`/psgc/regions/${selected.code}/provinces`, setProvinces, 'provinces');
    }, [regions, addressDefaults?.default_region_code]);

    useEffect(() => {
        if (provinces.length === 0 || !form.data.province || codes.province) {
            return;
        }

        const selected = provinces.find((province) => areaLabel(province) === form.data.province);

        if (!selected?.code) {
            return;
        }

        setCodes((current) => ({ ...current, province: selected.code, cityMunicipality: '' }));
        fetchPsgc(`/psgc/provinces/${selected.code}/districts`, setDistricts, 'districts');
    }, [provinces, form.data.province]);

    useEffect(() => {
        if (districts.length === 0 || !form.data.district || codes.district) {
            return;
        }

        const selected = districts.find((district) => areaLabel(district) === form.data.district);

        if (!selected?.code) {
            return;
        }

        setCodes((current) => ({ ...current, district: selected.code, cityMunicipality: '' }));
        fetchPsgc(`/psgc/districts/${selected.code}/cities-municipalities`, setCitiesMunicipalities, 'cities');
    }, [districts, form.data.district]);

    useEffect(() => {
        if (citiesMunicipalities.length === 0 || !form.data.municipality || codes.cityMunicipality) {
            return;
        }

        const selected = citiesMunicipalities.find((cityMunicipality) => areaLabel(cityMunicipality) === form.data.municipality);

        if (!selected?.code) {
            return;
        }

        setCodes((current) => ({ ...current, cityMunicipality: selected.code }));
        fetchPsgc(`/psgc/cities-municipalities/${selected.code}/barangays`, setBarangays, 'barangays');
    }, [citiesMunicipalities, form.data.municipality]);

    const selectRegion = (value) => {
        const selected = regionOptions.find((option) => option.value === value);

        form.setData({
            ...form.data,
            office: value,
            province: '',
            district: '',
            municipality: '',
            barangay_name: '',
            barangay_code: '',
        });
        setCodes({ region: selected?.code ?? '', province: '', district: '', cityMunicipality: '' });
        setProvinces([]);
        setDistricts([]);
        setCitiesMunicipalities([]);
        setBarangays([]);

        if (selected?.code) {
            fetchPsgc(`/psgc/regions/${selected.code}/provinces`, setProvinces, 'provinces');
        }
    };

    const selectProvince = (value) => {
        const selected = provinceOptions.find((option) => option.value === value);

        form.setData({
            ...form.data,
            province: value,
            district: '',
            municipality: '',
            barangay_name: '',
            barangay_code: '',
        });
        setCodes((current) => ({ ...current, province: selected?.code ?? '', district: '', cityMunicipality: '' }));
        setDistricts([]);
        setCitiesMunicipalities([]);
        setBarangays([]);

        if (selected?.code) {
            fetchPsgc(`/psgc/provinces/${selected.code}/districts`, setDistricts, 'districts');
        }
    };

    const selectDistrict = (value) => {
        const selected = districtOptions.find((option) => option.value === value);

        form.setData({
            ...form.data,
            district: value,
            municipality: '',
            barangay_name: '',
            barangay_code: '',
        });
        setCodes((current) => ({ ...current, district: selected?.code ?? '', cityMunicipality: '' }));
        setCitiesMunicipalities([]);
        setBarangays([]);

        if (selected?.code) {
            fetchPsgc(`/psgc/districts/${selected.code}/cities-municipalities`, setCitiesMunicipalities, 'cities');
        } else if (codes.province) {
            fetchPsgc(`/psgc/provinces/${codes.province}/cities-municipalities`, setCitiesMunicipalities, 'cities');
        }
    };

    const selectCityMunicipality = (value) => {
        const selected = cityMunicipalityOptions.find((option) => option.value === value);

        form.setData({
            ...form.data,
            municipality: value,
            barangay_name: '',
            barangay_code: '',
        });
        setCodes((current) => ({ ...current, cityMunicipality: selected?.code ?? '' }));
        setBarangays([]);

        if (selected?.code) {
            fetchPsgc(`/psgc/cities-municipalities/${selected.code}/barangays`, setBarangays, 'barangays');
        }
    };

    const selectBarangay = (value) => {
        const selected = barangayOptions.find((option) => option.value === value);

        form.setData({
            ...form.data,
            barangay_name: value,
            barangay_code: selected?.code ?? '',
        });
    };

    return (
        <>
            <div>
                <SearchableSelect
                    label="Field Office"
                    placeholder={loading === 'regions' ? 'Loading regions...' : 'Select field office / region'}
                    options={regionOptions}
                    value={form.data.office ?? ''}
                    onChange={selectRegion}
                />
                {form.errors.office && <p className="mt-1 text-xs text-rose-600">{form.errors.office}</p>}
            </div>
            <div>
                <SearchableSelect
                    label="Province"
                    placeholder={codes.region ? (loading === 'provinces' ? 'Loading provinces...' : 'Select province') : 'Select field office first'}
                    options={provinceOptions}
                    value={form.data.province ?? ''}
                    onChange={selectProvince}
                />
                {form.errors.province && <p className="mt-1 text-xs text-rose-600">{form.errors.province}</p>}
            </div>
            <div>
                <SearchableSelect
                    label="District"
                    placeholder={codes.province ? (loading === 'districts' ? 'Loading districts...' : 'Select district') : 'Select province first'}
                    options={districtOptions}
                    value={form.data.district ?? ''}
                    onChange={selectDistrict}
                />
                {form.errors.district && <p className="mt-1 text-xs text-rose-600">{form.errors.district}</p>}
            </div>
            <div>
                <SearchableSelect
                    label="Municipality / City"
                    placeholder={codes.district ? (loading === 'cities' ? 'Loading cities/municipalities...' : 'Select municipality / city') : 'Select district first'}
                    options={cityMunicipalityOptions}
                    value={form.data.municipality ?? ''}
                    onChange={selectCityMunicipality}
                />
                {form.errors.municipality && <p className="mt-1 text-xs text-rose-600">{form.errors.municipality}</p>}
            </div>
            <div>
                <SearchableSelect
                    label="Barangay"
                    placeholder={codes.cityMunicipality ? (loading === 'barangays' ? 'Loading barangays...' : 'Select barangay') : 'Select municipality / city first'}
                    options={barangayOptions}
                    value={form.data.barangay_name ?? ''}
                    onChange={selectBarangay}
                />
                {form.errors.barangay_name && <p className="mt-1 text-xs text-rose-600">{form.errors.barangay_name}</p>}
            </div>
            <label className="text-sm font-medium">
                Barangay PSGC Code
                <input className="mt-1 w-full bg-slate-50 text-slate-600 dark:bg-zinc-950 dark:text-zinc-300" value={form.data.barangay_code ?? ''} readOnly />
                {form.errors.barangay_code && <p className="mt-1 text-xs text-rose-600">{form.errors.barangay_code}</p>}
            </label>
            {error && (
                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 md:col-span-2 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {error}
                </div>
            )}
        </>
    );
}

export default function Index({ warehouses, metrics, filters, filterOptions, editOptions, sync, addressDefaults }) {
    const [warehouseModalMode, setWarehouseModalMode] = useState(null);
    const [selectedWarehouse, setSelectedWarehouse] = useState(null);
    const [showFilters, setShowFilters] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const editForm = useForm({});

    const changeFilter = (key, value) => {
        router.get('/warehouses', { ...filters, [key]: value }, { preserveState: true, preserveScroll: true });
    };

    const applySearch = (event) => {
        event.preventDefault();
        router.get('/warehouses', { ...filters, search: searchQuery }, { preserveState: true, preserveScroll: true });
    };

    const clearSearch = () => {
        setSearchQuery('');
        router.get('/warehouses', { ...filters, search: '' }, { preserveState: true, preserveScroll: true });
    };

    const activeFilterCount = filterConfig.filter(([key]) => Array.isArray(filters[key]) ? filters[key].length > 0 : Boolean(filters[key])).length;

    const openCreateModal = () => {
        setSelectedWarehouse(null);
        editForm.clearErrors();
        editForm.setData({
            ...emptyWarehouseData,
            office: addressDefaults?.default_region_name || 'CARAGA',
        });
        setWarehouseModalMode('create');
    };

    const openEditModal = (warehouse) => {
        setSelectedWarehouse(warehouse);
        editForm.clearErrors();
        editForm.setData(warehouseFormData(warehouse));
        setWarehouseModalMode('edit');
    };

    const closeWarehouseModal = () => {
        setWarehouseModalMode(null);
        setSelectedWarehouse(null);
    };

    return (
        <AppLayout title="Warehouses">
            <Head title="Warehouses" />

            <div className="mx-auto w-full max-w-[1600px] space-y-4">
            <ExportableCard id="warehouse-overview" title="Managed Warehouses" className="min-w-0" showExportButtons={false}>
                <div className="grid min-w-0 gap-4 md:grid-cols-[minmax(0,1fr)_minmax(240px,320px)]">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-semibold">
                            <WarehouseIcon className="h-5 w-5 text-brand-600" />
                            Managed Warehouses
                        </h2>
                        <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                            Warehouse master list mirrored from the Managed Warehouses Google Sheet.
                        </p>
                    </div>
                    <div className="space-y-3">
                        <button
                            type="button"
                            onClick={openCreateModal}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                        >
                            <Plus className="h-4 w-4" />
                            Add Warehouse
                        </button>
                        <p className="mt-2 text-xs text-slate-500 dark:text-zinc-400">
                            {sync?.last_synced_at ? `Last synced: ${formatDateTime(sync.last_synced_at)}` : 'No sync recorded yet.'}
                        </p>
                    </div>
                </div>
            </ExportableCard>

            <div id="warehouse-dashboard" className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_minmax(0,1fr)]">
                <WarehouseOverview metrics={metrics} />
                <BreakdownCard title="Distribution Network" rows={metrics.distribution_networks} />
                <BreakdownCard title="Warehouse Type" rows={metrics.warehouse_types} />
            </div>

            <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                <BreakdownCard title="Category" rows={metrics.categories} />
                <BreakdownCard title="Partnership" rows={metrics.partnerships} />
            </div>

            <Card id="warehouse-filters" className="min-w-0">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <button
                        type="button"
                        onClick={() => setShowFilters((current) => !current)}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700 dark:hover:bg-zinc-700"
                    >
                        <Filter className="h-4 w-4" />
                        Filters
                        {activeFilterCount > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount}</span>}
                    </button>
                    <form onSubmit={applySearch} className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            className="w-full pl-9 pr-24"
                            placeholder="Search warehouse master list..."
                            value={searchQuery}
                            onChange={(event) => setSearchQuery(event.target.value)}
                        />
                        {searchQuery && (
                            <button type="button" onClick={clearSearch} className="absolute right-16 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                                <X className="h-4 w-4" />
                            </button>
                        )}
                        <button type="submit" className="absolute right-1 top-1/2 -translate-y-1/2 rounded-md bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">
                            Search
                        </button>
                    </form>
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2 2xl:grid-cols-3 dark:border-zinc-800">
                        {filterConfig.map(([key, label, optionKey, placeholder]) => (
                            <LookerMultiSelect
                                key={key}
                                label={label}
                                options={filterMultiOptions(filterOptions[optionKey], key === 'status' ? formatStatus : undefined)}
                                value={filters[key] ?? []}
                                onApply={(value) => changeFilter(key, value)}
                                placeholder={`Search ${label.toLowerCase()}...`}
                                allLabel={placeholder}
                            />
                        ))}
                    </div>
                )}
            </Card>

            <ExportableCard
                id="warehouse-master-list"
                title="Warehouse Master List"
                className="min-w-0"
                showExportButtons={false}
                renderHeader={({ exportButtons, exportMessage }) => (
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="font-semibold">Warehouse Master List</h2>
                            {exportMessage && <p className="mt-1 text-xs font-bold text-brand-700 dark:text-brand-200">{exportMessage}</p>}
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            {exportButtons}
                            <p className="text-xs text-slate-500 dark:text-zinc-400">{warehouses.length} records</p>
                        </div>
                    </div>
                )}
            >
                <DataTable
                    stickyHeader
                    className="max-h-[calc(100vh-260px)] w-full overflow-auto"
                    columns={[
                        'Province',
                        'District',
                        'Municipality',
                        'Warehouse Name',
                        'Status',
                        'Partnership',
                        'Category',
                        'WH Focal',
                        'WH Focal Email',
                        'Contact No.',
                        'Storekeeper',
                        'Storekeeper Contact',
                        { label: 'Actions', align: 'right', actionColumn: true },
                    ]}
                    rows={warehouses.map((warehouse, index) => (
                        <tr key={warehouse.id} className={warehouse.status === 'inactive' ? 'bg-rose-50 text-rose-900 dark:bg-rose-950/40 dark:text-rose-100' : undefined}>
                            <td className={cellClass}>{clean(warehouse.province)}</td>
                            <td className={cellClass}>{clean(warehouse.district)}</td>
                            <td className={cellClass}>{clean(warehouse.municipality)}</td>
                            <td className={`${cellClass} font-medium`}>{clean(warehouse.name)}</td>
                            <td className={cellClass}><StatusBadge status={warehouse.status} /></td>
                            <td className={cellClass}>{clean(warehouse.partnership)}</td>
                            <td className={cellClass}>{clean(warehouse.category)}</td>
                            <td className={cellClass}>{clean(warehouse.contact_person)}</td>
                            <td className={cellClass}>{clean(warehouse.email)}</td>
                            <td className={cellClass}>{clean(warehouse.contact_number)}</td>
                            <td className={cellClass}>{clean(warehouse.designated_storekeepers)}</td>
                            <td className={cellClass}>{clean(warehouse.storekeeper_contact_number)}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right">
                                <div className="inline-flex items-center justify-end gap-2">
                                    <TableActionButton icon={Eye} label="View" onClick={() => openEditModal(warehouse)} tone="indigo" />
                                    <TableActionButton icon={Pencil} label="Edit" onClick={() => openEditModal(warehouse)} tone="brand" />
                                </div>
                            </td>
                        </tr>
                    ))}
                />
            </ExportableCard>

            <WarehouseFormModal
                mode={warehouseModalMode}
                warehouse={selectedWarehouse}
                form={editForm}
                editOptions={{ ...editOptions, addressDefaults }}
                onClose={closeWarehouseModal}
            />
            </div>
        </AppLayout>
    );
}
