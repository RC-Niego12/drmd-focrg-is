import CalloutCard from '@/Components/CalloutCard';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatExpiryMonth } from '@/Utils/dateFormat';
import LookerMultiSelect from '@/Components/LookerMultiSelect';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, BarChart3, CalendarClock, CheckCircle2, ClipboardList, Eye, Filter, PackageCheck, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import NearExpiryMonthSummary from '@/Components/NearExpiryMonthSummary';
import SectionTabs from '@/Components/SectionTabs';

const number = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const peso = (value) => `₱${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const titleCase = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const chartColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4'];
const chartHoverColors = ['#2563eb', '#ea580c', '#9333ea', '#82983f', '#0891b2'];

export default function NearExpiry({ monitoring, nearExpiry, plans, libraryOptions = {}, workspace = 'rros' }) {
    const isRros = (usePage().props.auth.user?.roles ?? []).some((role) => ['RROS', 'RROS AA'].includes(role));
    const isLguWorkspace = workspace === 'lgu';
    const canCreatePlans = !isRros && !isLguWorkspace;
    const [tab, setTab] = useState('expiry');
    const [showFilters, setShowFilters] = useState(false);
    const [filters, setFilters] = useState({ q: '', category: [], item: [], brand: [], warehouse: [], status: [] });
    const [showPlanModal, setShowPlanModal] = useState(false);
    const [detailRow, setDetailRow] = useState(null);
    const form = useForm({
        inventory_batch_id: '',
        program_type: 'food_for_work',
        beneficiary: '',
        location: '',
        quantity: '',
        activity: '',
        activity_date: '',
        priority: 'high',
        status: 'for_distribution',
        remarks: '',
    });

    useEffect(() => {
        if (!detailRow) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setDetailRow(null);
            }
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [detailRow]);

    const expiryRows = monitoring?.expiryRows ?? [];
    const ageingRows = monitoring?.ageingRows ?? [];
    const planRows = plans?.data ?? [];
    const filteredExpiry = useMemo(() => applyFilters(expiryRows, filters), [expiryRows, filters]);
    const filteredAgeing = useMemo(() => applyFilters(ageingRows, filters), [ageingRows, filters]);
    const activeRows = tab === 'ageing' ? filteredAgeing : filteredExpiry;
    const activeSourceRows = tab === 'ageing' ? ageingRows : expiryRows;
    const cascadingOptions = useMemo(() => ({
        categories: optionValues(applyFilters(activeSourceRows, filters, 'category'), 'category'),
        items: optionValues(applyFilters(activeSourceRows, filters, 'item'), 'item'),
        brands: optionValues(applyFilters(activeSourceRows, filters, 'brand'), 'brand'),
        warehouses: optionValues(applyFilters(activeSourceRows, filters, 'warehouse'), 'warehouse'),
        statuses: optionValues(applyFilters(activeSourceRows, filters, 'status'), 'status'),
    }), [activeSourceRows, filters]);
    const totalQty = activeRows.reduce((sum, row) => sum + Number(row.quantity ?? row.total ?? 0), 0);
    const totalCost = activeRows.reduce((sum, row) => sum + Number(row.cost ?? 0), 0);
    const statusSummaries = useMemo(() => statusSummaryCards(filteredExpiry), [filteredExpiry]);
    const batchOptions = (nearExpiry?.data ?? []).map((batch) => ({
        value: batch.id,
        label: `${batch.item?.name ?? 'Item'} - ${batch.warehouse?.name ?? 'Warehouse'} - Exp: ${formatExpiryMonth(batch.expiration_date)}`,
    }));

    const submitPlan = (event) => {
        event.preventDefault();
        form.post('/near-expiry/plans', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('beneficiary', 'location', 'quantity', 'activity', 'activity_date', 'remarks');
                setShowPlanModal(false);
            },
        });
    };

    return (
        <AppLayout title="Near Expiry">
            <Head title="Near Expiry" />

            <ExportableCard id="near-expiry-overview" title="Expiry and Ageing Overview" className="scroll-mt-28 overflow-hidden" showExportButtons={false}>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Inventory Monitoring</p>
                        <h2 className="mt-1 text-2xl font-black">Expiry and Ageing</h2>
                        <p className="mt-2 max-w-4xl text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            {isLguWorkspace
                                ? 'Uses your LGU warehouse stockpile only (partnership = LGU) and follows the reference sheet columns for Expiry status, expiry month, warehouse, category, item, brand/spec, quantity, cost, and month-based ageing.'
                                : 'Uses the local stockpile database and follows the reference sheet columns for Expiry status, expiry month, warehouse, category, item, brand/spec, quantity, cost, and month-based ageing.'}
                        </p>
                    </div>
                    {canCreatePlans && <button type="button" onClick={() => setShowPlanModal(true)} className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-brand-700">
                        <Plus className="h-4 w-4" />
                        Create Distribution Plan
                    </button>}
                </div>
            </ExportableCard>

            <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
                {statusSummaries.map((status) => (
                    <ExpiryStatusCard key={status.label} status={status} />
                ))}
            </div>

            <Card id="near-expiry-filters" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <SectionTabs
                        appearance="plain"
                        value={tab}
                        onChange={setTab}
                        ariaLabel="Near-expiry views"
                        tabs={[
                            { id: 'expiry', label: 'Expiry' },
                            { id: 'ageing', label: 'Ageing' },
                            ...(!isLguWorkspace ? [{ id: 'plans', label: 'Distribution Plan' }] : []),
                        ]}
                    />
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button type="button" onClick={() => setShowFilters(!showFilters)} className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black shadow-sm hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-950 dark:hover:bg-zinc-900">
                            <Filter className="h-4 w-4" />
                            Filters
                            {activeFilterCount(filters) > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount(filters)}</span>}
                        </button>
                        <input className="w-full sm:w-80" type="search" placeholder="Search monitoring table..." value={filters.q} onChange={(event) => setFilters({ ...filters, q: event.target.value })} />
                    </div>
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <SelectFilter label="Category" value={filters.category} options={cascadingOptions.categories} onChange={(value) => setFilters({ ...filters, category: value })} />
                        <SelectFilter label="Item" value={filters.item} options={cascadingOptions.items} onChange={(value) => setFilters({ ...filters, item: value })} />
                        <SelectFilter label="Brand / Spec" value={filters.brand} options={cascadingOptions.brands} onChange={(value) => setFilters({ ...filters, brand: value })} />
                        <SelectFilter label="Warehouse" value={filters.warehouse} options={cascadingOptions.warehouses} onChange={(value) => setFilters({ ...filters, warehouse: value })} />
                        <SelectFilter label="Status" value={filters.status} options={cascadingOptions.statuses} onChange={(value) => setFilters({ ...filters, status: value })} />
                    </div>
                )}
            </Card>

            {tab === 'expiry' && (
                <NearExpiryMonthSummary
                    rows={activeRows}
                    className="mt-6"
                    description="Interactive month-by-month view of the current filtered expiring stockpile by item."
                />
            )}

            {tab !== 'plans' && (
                <>
                    {tab === 'expiry' && (
                        <ExpiryTable
                            rows={filteredExpiry}
                            onView={(row) => setDetailRow({ type: 'expiry', ...row })}
                        />
                    )}
                    {tab === 'ageing' && (
                        <AgeingTable
                            rows={filteredAgeing}
                            months={monitoring?.ageingMonths ?? []}
                            onView={(row) => setDetailRow({
                                ...row,
                                type: 'ageing',
                                monthKeys: monitoring?.ageingMonths ?? [],
                            })}
                        />
                    )}
                </>
            )}
            {tab === 'plans' && <PlansSection rows={planRows} />}

            {tab !== 'plans' && !isLguWorkspace && (
                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <BreakdownCard id="near-expiry-item-breakdown" title="Item-Level Breakdown" description="Shows which expiring or ageing items make up the largest share of the current filtered stockpile." rows={groupRows(activeRows, 'item')} />
                    <BreakdownCard id="near-expiry-warehouse-breakdown" title="Warehouse-Level Breakdown" description="Shows which warehouses currently hold the largest quantity of the filtered expiring or ageing stockpile." rows={groupRows(activeRows, 'warehouse')} />
                </div>
            )}

            {detailRow && <NearExpiryDetailModal row={detailRow} onClose={() => setDetailRow(null)} />}

            {showPlanModal && (
                <PlanModal form={form} batchOptions={batchOptions} libraryOptions={libraryOptions} onClose={() => setShowPlanModal(false)} onSubmit={submitPlan} />
            )}
        </AppLayout>
    );
}

function SelectFilter({ label, value, options, onChange }) {
    return (
        <LookerMultiSelect
            label={label}
            options={(options ?? []).map((option) => ({ value: option, label: option }))}
            value={value}
            onApply={onChange}
            placeholder={`Search ${label.toLowerCase()}...`}
        />
    );
}

function ExpiryStatusCard({ status }) {
    const style = getStatusStyle(status.label);

    return (
        <Card className="relative overflow-hidden">
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full opacity-15" style={{ backgroundColor: style.bg }} />
            <div className="relative">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[11px] font-black uppercase leading-tight tracking-wide text-slate-500 dark:text-zinc-400">{status.label}</p>
                        <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{number(status.quantity)}</p>
                    </div>
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" style={{ color: style.color === '#ffffff' ? '#0f5132' : style.color }}>
                        <AlertTriangle className="h-5 w-5" />
                    </span>
                </div>
                <p className="mt-2 text-xs font-bold text-slate-500 dark:text-zinc-400">{number(status.rows)} item rows · {peso(status.cost)}</p>
                <div className="mt-3 space-y-1">
                    {status.topItems.map((item) => (
                        <div key={item.label} className="flex items-center justify-between gap-2 text-xs font-bold">
                            <span className="truncate" title={item.label}>{item.label}</span>
                            <span className="shrink-0 text-slate-500 dark:text-zinc-400">{number(item.quantity)}</span>
                        </div>
                    ))}
                    {status.topItems.length === 0 && <p className="text-xs font-semibold text-slate-400">No current stockpile under this status.</p>}
                </div>
            </div>
        </Card>
    );
}

function BreakdownCard({ id, title, description, rows }) {
    const max = Math.max(...rows.map((row) => row.quantity), 1);

    return (
        <ExportableCard id={id} title={title} className="scroll-mt-28" showExportButtons={false}>
            <h2 className="text-lg font-black">{title}</h2>
            <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{description}</p>
            <div className="mt-4 space-y-4">
                {rows.slice(0, 8).map((row, index) => {
                    const color = chartColors[index % chartColors.length];
                    const hoverColor = chartHoverColors[index % chartHoverColors.length];

                    return (
                    <div key={row.label} className="group">
                        <div className="flex items-center justify-between gap-3 text-sm font-black">
                            <span className="truncate" title={row.label}>{row.label || '-'}</span>
                            <span className="shrink-0 text-slate-500 dark:text-zinc-400">{number(row.quantity)}</span>
                        </div>
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                            <div
                                className="h-full rounded-full transition"
                                style={{ width: `${Math.max(4, (row.quantity / max) * 100)}%`, backgroundColor: color }}
                                onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                            />
                        </div>
                    </div>
                    );
                })}
            </div>
        </ExportableCard>
    );
}

function ExpiryTable({ rows, onView }) {
    return (
        <ExportableCard
            id="near-expiry-stock"
            title="Expiry Table"
            className="mt-6 scroll-mt-28"
            showExportButtons={true}
            exportButtonProps={{ showOnlyFullscreen: true }}
            renderHeader={({ exportButtons }) => (
                <div className="mb-4 flex items-end justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-black">Expiry Table</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">Columns follow the Expiry reference: Status, Date, Warehouse, Category, Item, Brand/Spec, Quantity, Cost.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {exportButtons}
                        <p className="text-sm font-black text-slate-500 dark:text-zinc-400">{number(rows.length)} rows</p>
                    </div>
                </div>
            )}>
            <div className="mb-4 flex flex-wrap gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs font-black uppercase tracking-wide text-slate-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <StatusLegendItem label="expiring within 6 months and up" />
                <StatusLegendItem label="expiring within 4-5 months" />
                <StatusLegendItem label="expiring within 2-3 months" />
                <StatusLegendItem label="expiring less than 2 months month" />
                <StatusLegendItem label="expiring within the month / expired" />
            </div>
            <DataTable
                stickyHeader
                className="max-h-[calc(100vh-420px)] overflow-auto"
                columns={['Status', 'Date', 'Warehouse', 'Category', 'Item', { label: 'Quantity', align: 'right' }, { label: 'Cost', align: 'right' }, { label: 'Action', align: 'right', actionColumn: true }]}
                rows={[
                    ...rows.map((row) => (
                    <tr key={row.id} className="transition hover:bg-brand-50/60 dark:hover:bg-brand-950/20">
                        <td className="whitespace-nowrap px-4 py-3"><StatusBadge value={row.status} /></td>
                        <td className="whitespace-nowrap px-4 py-3">{formatExpiryMonth(row.expiry_month)}</td>
                        <td className="whitespace-nowrap px-4 py-3">
                            <p className="font-black">{row.warehouse}</p>
                            <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership || '-'}</p>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3">{row.category}</td>
                        <td className="whitespace-nowrap px-4 py-3">
                            <p className="font-black">{row.item}</p>
                            <p className="text-xs text-slate-500 dark:text-zinc-400">{row.brand}</p>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{number(row.quantity)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{peso(row.cost)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">
                            <TableActionButton icon={Eye} label="View" onClick={() => onView?.(row)} tone="brand" />
                        </td>
                    </tr>
                    )),
                    <StickySummaryRow
                        key="expiry-grand-total"
                        colSpan={9}
                        values={[
                            ['Rows', number(rows.length)],
                            ['Quantity', number(rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0))],
                            ['Cost', peso(rows.reduce((sum, row) => sum + Number(row.cost || 0), 0))],
                        ]}
                    />,
                ]}
            />
        </ExportableCard>
    );
}

function AgeingTable({ rows, months, onView }) {
    return (
        <ExportableCard
            id="near-expiry-stock"
            title="Ageing Table"
            className="mt-6 scroll-mt-28"
            showExportButtons={true}
            exportButtonProps={{ showOnlyFullscreen: true }}
            renderHeader={({ exportButtons }) => (
                <div className="mb-4 flex items-end justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-black">Ageing Table</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">Rows follow the Ageing reference: Warehouse, Category, Item, Brand/Spec, Total, and month columns.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {exportButtons}
                        <p className="text-sm font-black text-slate-500 dark:text-zinc-400">{number(rows.length)} rows</p>
                    </div>
                </div>
            )}>
            <DataTable
                stickyHeader
                className="max-h-[calc(100vh-340px)] overflow-auto"
                columns={['Warehouse', 'Category', 'Item', { label: 'Total', align: 'right' }, { label: 'Cost', align: 'right' }, ...months.map((month) => ({ label: month, align: 'right' })), { label: 'Action', align: 'right', actionColumn: true }]}
                rows={[
                    ...rows.map((row) => (
                    <tr key={row.id} className="transition hover:bg-brand-50/60 dark:hover:bg-brand-950/20">
                        <td className="whitespace-nowrap px-4 py-3">
                            <p className="font-black">{row.warehouse}</p>
                            <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership || '-'}</p>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3">{row.category}</td>
                        <td className="whitespace-nowrap px-4 py-3">
                            <p className="font-black">{row.item}</p>
                            <p className="text-xs text-slate-500 dark:text-zinc-400">{row.brand}</p>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{number(row.total)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{peso(row.cost)}</td>
                        {months.map((month) => (
                            <td key={month} className="whitespace-nowrap px-4 py-3 text-right">{number(row.months?.[month] ?? 0)}</td>
                        ))}
                        <td className="whitespace-nowrap px-4 py-3 text-right">
                            <TableActionButton icon={Eye} label="View" onClick={() => onView?.(row)} tone="brand" />
                        </td>
                    </tr>
                    )),
                    <StickySummaryRow
                        key="ageing-grand-total"
                        colSpan={months.length + 7}
                        values={[
                            ['Rows', number(rows.length)],
                            ['Quantity', number(rows.reduce((sum, row) => sum + Number(row.total || 0), 0))],
                            ['Cost', peso(rows.reduce((sum, row) => sum + Number(row.cost || 0), 0))],
                        ]}
                    />,
                ]}
            />
        </ExportableCard>
    );
}

function NearExpiryDetailModal({ row, onClose }) {
    const isAgeing = row.type === 'ageing';
    const quantity = Number(row.quantity ?? row.total ?? 0);
    const cost = Number(row.cost ?? 0);
    const unitCost = Number(row.unit_cost ?? 0) || (quantity > 0 ? cost / quantity : 0);
    const monthKeys = Array.isArray(row.monthKeys) && row.monthKeys.length > 0
        ? row.monthKeys
        : Object.keys(row.months || {});
    const monthBreakdown = isAgeing
        ? monthKeys
            .map((month) => ({
                month,
                quantity: Number(row.months?.[month] ?? 0),
            }))
            .filter((entry) => entry.quantity !== 0)
        : [];

    const details = [
        ['Warehouse', row.warehouse || '-'],
        ['Partnership', row.partnership || '-'],
        ['Category', row.category || '-'],
        ['Item', row.item || '-'],
        ['Brand / Spec', row.brand || '-'],
        ...(isAgeing
            ? [
                ['Total Quantity', number(quantity)],
                ['Total Cost', peso(cost)],
            ]
            : [
                ['Expiry Status', row.status || '-'],
                ['Expiry Month', formatExpiryMonth(row.expiry_month || row.expiration_date)],
                ['Expiration Date', row.expiration_date || '-'],
                ['Quantity', number(quantity)],
                ['Unit Cost', peso(unitCost)],
                ['Stock Value', peso(cost)],
                ['Months to Expiry', row.status_months != null ? number(row.status_months) : '-'],
            ]),
    ];

    return createPortal(
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="near-expiry-detail-title"
            onClick={onClose}
        >
            <div
                className="max-h-[94vh] w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 bg-gradient-to-r from-white via-amber-50/60 to-orange-50/60 px-6 py-5 dark:border-zinc-800 dark:from-zinc-950 dark:via-zinc-950 dark:to-brand-950/30">
                    <div>
                        <p className="text-[11px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
                            {isAgeing ? 'Ageing Details' : 'Expiry Details'}
                        </p>
                        <h2 id="near-expiry-detail-title" className="mt-1 text-xl font-black">{row.item || 'Stock item'}</h2>
                        <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{row.warehouse || '-'}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 dark:hover:bg-zinc-800" aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="max-h-[79vh] space-y-4 overflow-y-auto p-5">
                    {!isAgeing && (
                        <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                            <StatusBadge value={row.status} />
                            <div>
                                <p className="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Expiry status</p>
                                <p className="text-sm font-bold text-slate-900 dark:text-zinc-100">{row.status || '-'}</p>
                            </div>
                        </div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        {details.map(([label, value]) => (
                            <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 text-xs shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                <p className="font-black uppercase tracking-[0.08em] text-slate-500 dark:text-zinc-400">{label}</p>
                                <p className="mt-2 break-words text-sm font-bold text-slate-900 dark:text-zinc-100">{value || '-'}</p>
                            </div>
                        ))}
                    </div>
                    {isAgeing && (
                        <section className="overflow-hidden rounded-2xl border border-amber-100 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div className="flex items-center justify-between gap-3 border-b border-amber-100 bg-gradient-to-r from-amber-950 to-orange-800 px-4 py-3 text-white dark:border-zinc-700">
                                <div className="flex items-center gap-2.5">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/15"><CalendarClock className="h-5 w-5" /></span>
                                    <div>
                                        <h3 className="text-sm font-black">Quantity by Expiry Month</h3>
                                        <p className="text-[10px] font-semibold text-amber-100">Non-zero month columns from the ageing table</p>
                                    </div>
                                </div>
                                <span className="rounded-full bg-white/15 px-3 py-1 text-[10px] font-black uppercase tracking-wide">{number(monthBreakdown.length)} months</span>
                            </div>
                            <div className="divide-y divide-slate-100 dark:divide-zinc-800">
                                {monthBreakdown.length > 0 ? monthBreakdown.map((entry) => (
                                    <div key={entry.month} className="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                        <span className="font-black text-slate-800 dark:text-zinc-100">{formatExpiryMonth(entry.month)}</span>
                                        <span className="font-black tabular-nums">{number(entry.quantity)}</span>
                                    </div>
                                )) : (
                                    <p className="px-4 py-8 text-center text-sm font-semibold text-slate-500">No month quantities for this row.</p>
                                )}
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}

function StickySummaryRow({ colSpan, values }) {
    return (
        <tr className="border-l-4 border-slate-500">
            <td colSpan={colSpan} className="sticky bottom-0 z-20 border-t border-slate-300 bg-slate-50 px-4 py-3 font-black shadow-[0_-6px_14px_rgba(15,23,42,0.08)] dark:border-zinc-700 dark:bg-zinc-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span>Total</span>
                    <span className="inline-flex flex-wrap items-center gap-x-5 gap-y-1 text-right text-sm">
                        {values.map(([label, value]) => <span key={label} title={label}>{value}</span>)}
                    </span>
                </div>
            </td>
        </tr>
    );
}

function PlansSection({ rows }) {
    return (
        <ExportableCard
            id="near-expiry-plans"
            title="Distribution Plans"
            className="mt-6 scroll-mt-28"
            showExportButtons={true}
            exportButtonProps={{ showOnlyFullscreen: true }}
            renderHeader={({ exportButtons }) => (
                <div className="mb-4 flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-black">Distribution Plans</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">Implementation records for monitored stockpile distribution.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {exportButtons}
                    </div>
                </div>
            )}>
            <DataTable
                stickyHeader
                className="max-h-[calc(100vh-340px)] overflow-auto"
                columns={['Program', 'Beneficiary', 'Location', { label: 'Quantity', align: 'right' }, 'Priority', 'Status']}
                rows={rows.map((plan) => (
                    <tr key={plan.id} className="transition hover:bg-brand-50/60 dark:hover:bg-brand-950/20">
                        <td className="whitespace-nowrap px-4 py-3 font-black">{titleCase(plan.program_type)}</td>
                        <td className="whitespace-nowrap px-4 py-3">{plan.beneficiary || '-'}</td>
                        <td className="whitespace-nowrap px-4 py-3">{plan.location}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{number(plan.quantity)}</td>
                        <td className="whitespace-nowrap px-4 py-3">{titleCase(plan.priority)}</td>
                        <td className="whitespace-nowrap px-4 py-3">{titleCase(plan.status)}</td>
                    </tr>
                ))}
            />
        </ExportableCard>
    );
}

function PlanModal({ form, batchOptions, libraryOptions = {}, onClose, onSubmit }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div className="max-h-[92vh] w-full max-w-2xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="h-1.5 bg-brand-600" />
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Inventory Monitoring Action</p>
                        <h2 className="text-lg font-black">Create Distribution Plan</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-zinc-900 dark:hover:text-zinc-100">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <form className="max-h-[calc(92vh-5.5rem)] space-y-4 overflow-y-auto p-5" onSubmit={onSubmit}>
                    <SearchableSelect label="Stock Batch" options={[{ value: '', label: 'Select batch' }, ...batchOptions]} value={form.data.inventory_batch_id} onChange={(value) => form.setData('inventory_batch_id', value)} placeholder="Search monitored batch..." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm font-bold">Program Type<select className="mt-1 w-full" value={form.data.program_type} onChange={(event) => form.setData('program_type', event.target.value)}><option value="food_for_work">Food-for-Work</option><option value="non_food_for_work">Non-Food-for-Work</option><option value="relief_distribution">Relief Distribution</option><option value="other">Other</option></select></label>
                        <label className="text-sm font-bold">Priority<select className="mt-1 w-full" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
                    </div>
                    <Field label="Beneficiary" suggestions={libraryOptions.recipient_requesting_party} value={form.data.beneficiary} onChange={(value) => form.setData('beneficiary', value)} />
                    <Field label="Location *" suggestions={libraryOptions.delivery_site} value={form.data.location} onChange={(value) => form.setData('location', value)} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Quantity *" type="number" value={form.data.quantity} onChange={(value) => form.setData('quantity', value)} />
                        <Field label="Activity Date" type="date" value={form.data.activity_date} onChange={(value) => form.setData('activity_date', value)} />
                    </div>
                    <Field label="Activity" suggestions={libraryOptions.program_activity_type} value={form.data.activity} onChange={(value) => form.setData('activity', value)} />
                    <label className="text-sm font-bold">Status<select className="mt-1 w-full" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}><option value="for_distribution">For Distribution</option><option value="scheduled">Scheduled</option><option value="distributed">Distributed</option><option value="cancelled">Cancelled</option></select></label>
                    <Field label="Remarks" value={form.data.remarks} onChange={(value) => form.setData('remarks', value)} />
                    <button disabled={form.processing} className="w-full rounded-md bg-brand-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-brand-700 disabled:opacity-70">{form.processing ? 'Saving...' : 'Save Distribution Plan'}</button>
                </form>
            </div>
        </div>
    );
}

function StatusBadge({ value }) {
    const style = getStatusStyle(value);

    return (
        <span title={value} aria-label={value} className="inline-flex items-center justify-center">
            <span
                style={{ backgroundColor: style.bg, color: style.color }}
                className="h-3.5 w-3.5 rounded-full inline-block"
            />
            <span className="sr-only">{value}</span>
        </span>
    );
}

function StatusLegendItem({ label, tone }) {
    const style = getStatusStyle(label);

    return (
        <span
            style={{ backgroundColor: style.bg, color: style.color }}
            className="inline-flex items-center gap-2 rounded-full px-2.5 py-1"
        >
            <span className="h-2.5 w-2.5 rounded-full bg-current" />
            {label}
        </span>
    );
}

function getStatusStyle(value) {
    const normalized = String(value || '').toLowerCase();

    // Use explicit mapping based on the provided legend image
    if (normalized.includes('expiring within the month') || normalized.includes('expired')) {
        return { bg: '#000000', color: '#ffffff' }; // black
    }

    if (normalized.includes('less than 2 months')) {
        return { bg: '#b30000', color: '#ffffff' }; // red
    }

    if (normalized.includes('2-3 months') || normalized.includes('within 2-3 months')) {
        return { bg: '#ff9a00', color: '#2b1500' }; // orange
    }

    if (normalized.includes('4-5 months') || normalized.includes('within 4-5 months')) {
        return { bg: '#fff200', color: '#1f1f1f' }; // yellow
    }

    if (normalized.includes('6 months') || normalized.includes('within 6 months') || normalized.includes('6 months and up')) {
        return { bg: '#dff7ea', color: '#0f5132' }; // green
    }

    // fallback
    return { bg: '#dff7ea', color: '#0f5132' };
}

function Field({ label, value, onChange, type = 'text', suggestions = [] }) {
    const listId = `near-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    return (
        <label className="block text-sm font-bold">
            {label}
            <input className="mt-1 w-full" type={type} list={suggestions?.length ? listId : undefined} value={value} onChange={(event) => onChange(event.target.value)} />
            {suggestions?.length > 0 && <datalist id={listId}>{suggestions.map((option) => <option key={option} value={option} />)}</datalist>}
        </label>
    );
}

function groupRows(rows, key) {
    const groups = {};
    rows.forEach((row) => {
        const label = row[key] || '-';
        groups[label] = (groups[label] || 0) + Number(row.quantity ?? row.total ?? 0);
    });

    return Object.entries(groups)
        .map(([label, quantity]) => ({ label, quantity }))
        .sort((a, b) => b.quantity - a.quantity);
}

function statusSummaryCards(rows) {
    const statuses = [
        'expiring within the month / expired',
        'expiring less than 2 months month',
        'expiring within 2-3 months',
        'expiring within 4-5 months',
        'expiring within 6 months and up',
    ];

    return statuses.map((label) => {
        const statusRows = rows.filter((row) => row.status === label);
        const itemGroups = groupRows(statusRows, 'item').slice(0, 3);

        return {
            label,
            rows: statusRows.length,
            quantity: statusRows.reduce((sum, row) => sum + Number(row.quantity || 0), 0),
            cost: statusRows.reduce((sum, row) => sum + Number(row.cost || 0), 0),
            topItems: itemGroups,
        };
    });
}

function optionValues(rows, key) {
    return Array.from(new Set(rows.map((row) => row[key]).filter(Boolean))).sort();
}

function activeFilterCount(filters) {
    return ['category', 'item', 'brand', 'warehouse', 'status'].filter((key) => Array.isArray(filters[key]) && filters[key].length > 0).length;
}

function selectedHas(filters, key, value) {
    return !Array.isArray(filters[key]) || filters[key].length === 0 || filters[key].map(String).includes(String(value ?? ''));
}

function applyFilters(rows, filters, except = null) {
    const needle = filters.q.trim().toLowerCase();
    return rows.filter((row) => {
        const searchable = [row.status, row.expiry_month, row.warehouse, row.warehouse_id, row.category, row.item, row.brand].join(' ').toLowerCase();
        return (!needle || searchable.includes(needle))
            && (except === 'category' || selectedHas(filters, 'category', row.category))
            && (except === 'item' || selectedHas(filters, 'item', row.item))
            && (except === 'brand' || selectedHas(filters, 'brand', row.brand))
            && (except === 'warehouse' || selectedHas(filters, 'warehouse', row.warehouse))
            && (except === 'status' || selectedHas(filters, 'status', row.status));
    });
}
