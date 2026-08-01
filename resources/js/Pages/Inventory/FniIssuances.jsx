import { Head } from '@inertiajs/react';
import { Boxes, CalendarDays, Eye, Filter, PackageCheck, Search, Truck, UsersRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import LookerMultiSelect from '@/Components/LookerMultiSelect';

const colors = ['#3b82f6', '#f97316', '#a855f7', '#9fbd4b', '#31b7c2', '#ef4444', '#14b8a6', '#f59e0b'];
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const formatCurrency = (value) => `\u20b1${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const categoryNameMap = {
    'food': 'Food Items',
    'non_food': 'Non-Food Items',
    'Food Items': 'Food Items',
    'Non-Food Items': 'Non-Food Items',
    'Family Food Packs': 'Family Food Packs',
};
const formatCategoryName = (category) => categoryNameMap[category] || category;

export default function FniIssuances({ rows = [], years = [], generatedAt }) {
    const currentYear = new Date().getFullYear();
    const yearOptions = (years.length > 0 ? years : [currentYear]).map(String);
    const defaultYear = yearOptions.includes(String(currentYear)) ? String(currentYear) : yearOptions[0];
    const [year, setYear] = useState(defaultYear);
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [filters, setFilters] = useState({
        source_of_goods: [],
        warehouse: [],
        item: [],
        purpose: [],
        recipient: [],
        remarks: [],
        expiry_month: [],
        province: [],
        category: [],
    });
    const [detailRow, setDetailRow] = useState(null);
    const [sort, setSort] = useState({ key: 'sort_date', direction: 'desc' });

    const baseRows = useMemo(() => rows.filter((row) => String(row.year) === String(year)), [rows, year]);
    const filteredRows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return baseRows.filter((row) => {
            const inDateRange = (!dateFrom || row.sort_date >= dateFrom) && (!dateTo || row.sort_date <= dateTo);
            const filterMatch = Object.entries(filters).every(([key, selected]) => matchesMulti(selected, row[key]));
            const searchMatch = !needle || [
                row.reference,
                row.source_of_goods,
                row.warehouse,
                row.purpose,
                row.item,
                row.brand,
                row.recipient,
                row.delivery_site,
                row.remarks,
                row.personnel,
            ].some((value) => String(value ?? '').toLowerCase().includes(needle));

            return inDateRange && filterMatch && searchMatch;
        });
    }, [baseRows, dateFrom, dateTo, filters, search]);

    const sortedRows = useMemo(() => sortRows(filteredRows, sort), [filteredRows, sort]);
    const summary = useMemo(() => summarize(filteredRows), [filteredRows]);
    const monthly = useMemo(() => monthlyTrend(filteredRows), [filteredRows]);
    const categories = useMemo(() => groupRows(filteredRows, 'category'), [filteredRows]);
    const warehouses = useMemo(() => groupRows(filteredRows, 'warehouse').slice(0, 8), [filteredRows]);
    const purposes = useMemo(() => groupRows(filteredRows, 'purpose').slice(0, 8), [filteredRows]);
    const activeFilterCount = Object.values(filters).filter((value) => Array.isArray(value) && value.length > 0).length + (dateFrom ? 1 : 0) + (dateTo ? 1 : 0);

    const optionsFor = (key) => {
        const scopedRows = baseRows.filter((row) => Object.entries(filters).every(([filterKey, selected]) => (
            filterKey === key ? true : matchesMulti(selected, row[filterKey])
        )));

        return [...new Set(scopedRows.map((row) => row[key]).filter((value) => value !== null && value !== undefined && String(value).trim() !== ''))]
            .sort((a, b) => String(a).localeCompare(String(b)))
            .map((value) => ({ value: String(value), label: String(value) }));
    };

    const changeFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const resetFilters = () => {
        setSearch('');
        setDateFrom('');
        setDateTo('');
        setFilters({
            source_of_goods: [],
            warehouse: [],
            item: [],
            purpose: [],
            recipient: [],
            remarks: [],
            expiry_month: [],
            province: [],
            category: [],
        });
    };

    const changeSort = (key) => setSort((current) => ({
        key,
        direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc',
    }));

    return (
        <AppLayout title="FNI Issuances">
            <Head title="FNI Issuances" />

            <ExportableCard id="fni-issuance-overview" title={`FNI Issuances ${year}`} className="scroll-mt-28 overflow-hidden" showExportButtons={false}>
                <div className="space-y-4">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Food and Non-Food Item Releases</p>
                        <h2 className="mt-1 text-2xl font-black">FNI Issuances {year}</h2>
                        <p className="mt-2 max-w-3xl text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Tracks released FNI stockpile by source, warehouse, purpose, recipient, delivery site, expiry month, quantity, cost, and encoding details.
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <Metric title="Total Issuances" value={formatNumber(summary.quantity)} subtext={formatCurrency(summary.cost)} icon={PackageCheck} />
                        <Metric title="Transactions" value={formatNumber(summary.count)} subtext={`${formatNumber(summary.items)} issued items`} icon={CalendarDays} />
                        <Metric title="Warehouses" value={formatNumber(summary.warehouses)} subtext="issuing locations" icon={Truck} />
                        <Metric title="Recipients" value={formatNumber(summary.recipients)} subtext="receiving entities" icon={UsersRound} />
                        <Metric title="Categories" value={formatNumber(summary.categories)} subtext="FNI groups issued" icon={Boxes} />
                    </div>
                </div>
            </ExportableCard>

            <Card id="fni-issuance-filters" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-end">
                    <label className="min-w-[11rem] text-sm font-black text-slate-600 dark:text-zinc-300">
                        Transaction Year
                        <select className="mt-1 h-11 w-full" value={year} onChange={(event) => setYear(event.target.value)}>
                            {yearOptions.map((availableYear) => (
                                <option key={availableYear} value={availableYear}>{availableYear}</option>
                            ))}
                        </select>
                    </label>
                    <label className="min-w-[11rem] text-sm font-black text-slate-600 dark:text-zinc-300">
                        Date From
                        <input type="date" className="mt-1 h-11 w-full" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
                    </label>
                    <label className="min-w-[11rem] text-sm font-black text-slate-600 dark:text-zinc-300">
                        Date To
                        <input type="date" className="mt-1 h-11 w-full" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
                    </label>
                    <button
                        type="button"
                        onClick={() => setShowFilters((current) => !current)}
                        className="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-slate-100 px-4 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700 dark:hover:bg-zinc-700"
                    >
                        <Filter className="h-4 w-4" />
                        Filters
                        {activeFilterCount > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount}</span>}
                    </button>
                    <form onSubmit={(event) => event.preventDefault()} className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            className="h-11 w-full pl-9 pr-10"
                            placeholder="Search source, warehouse, item, recipient, remarks..."
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        {search && (
                            <button type="button" onClick={() => setSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                                <X className="h-4 w-4" />
                            </button>
                        )}
                    </form>
                    <button type="button" onClick={resetFilters} className="h-11 rounded-md border border-slate-200 bg-white px-4 text-sm font-black text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        Reset
                    </button>
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2 xl:grid-cols-4 dark:border-zinc-800">
                        <LookerMultiSelect label="Source of Goods" options={optionsFor('source_of_goods')} value={filters.source_of_goods} onApply={(value) => changeFilter('source_of_goods', value)} placeholder="Search source..." />
                        <LookerMultiSelect label="Warehouse Name" options={optionsFor('warehouse')} value={filters.warehouse} onApply={(value) => changeFilter('warehouse', value)} placeholder="Search warehouse..." />
                        <LookerMultiSelect label="Item" options={optionsFor('item')} value={filters.item} onApply={(value) => changeFilter('item', value)} placeholder="Search item..." />
                        <LookerMultiSelect label="Purpose" options={optionsFor('purpose')} value={filters.purpose} onApply={(value) => changeFilter('purpose', value)} placeholder="Search purpose..." />
                        <LookerMultiSelect label="Recipient" options={optionsFor('recipient')} value={filters.recipient} onApply={(value) => changeFilter('recipient', value)} placeholder="Search recipient..." />
                        <LookerMultiSelect label="Remarks" options={optionsFor('remarks')} value={filters.remarks} onApply={(value) => changeFilter('remarks', value)} placeholder="Search remarks..." />
                        <LookerMultiSelect label="Expiry Month" options={optionsFor('expiry_month')} value={filters.expiry_month} onApply={(value) => changeFilter('expiry_month', value)} placeholder="Search expiry..." />
                        <LookerMultiSelect label="Category" options={optionsFor('category').map(opt => ({ ...opt, label: formatCategoryName(opt.label) }))} value={filters.category} onApply={(value) => changeFilter('category', value)} placeholder="Search category..." />
                    </div>
                )}
            </Card>

            <div className="mt-6 space-y-6">
                <ChartCard
                    id="fni-monthly-trend"
                    title="Monthly Distribution of FNIs"
                    description="Compares the total quantity of food and non-food items released each month for the selected year and active filters, making seasonal peaks and changes in distribution activity easy to identify."
                >
                    <VerticalBars rows={monthly} labelKey="month" valueKey="quantity" />
                </ChartCard>

                <div className="grid gap-6 xl:grid-cols-3">
                    <ChartCard
                        id="fni-category-breakdown"
                        title="Item Category"
                        description="Shows issued quantity, total cost, and transaction count for each distinct FNI category under the selected year and active filters."
                    >
                        <HorizontalBars rows={categories} />
                    </ChartCard>
                    <ChartCard
                        id="fni-warehouse-breakdown"
                        title="Warehouse Ranking"
                        description="Ranks the issuing warehouses by released quantity and shows the associated cost and number of release transactions for the current filters."
                    >
                        <HorizontalBars rows={warehouses} formatLabel={false} />
                    </ChartCard>
                    <ChartCard
                        id="fni-purpose-breakdown"
                        title="Purpose of Transaction"
                        description="Summarizes released quantity, total cost, and transaction count by recorded issuance purpose for the selected year and active filters."
                    >
                        <HorizontalBars rows={purposes} formatLabel={false} />
                    </ChartCard>
                </div>
            </div>

            <ExportableCard
                id="fni-issuance-ledger"
                title="FNI Issuance Ledger"
                className="mt-6 scroll-mt-28"
                exportButtonProps={{ showOnlyFullscreen: true }}
            >
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        WIT-style issuance records. Use filters above to narrow source, warehouse, item, purpose, recipient, remarks, and expiry month.
                    </p>
                    <p className="text-sm font-black text-slate-500 dark:text-zinc-400">{filteredRows.length.toLocaleString()} rows shown</p>
                </div>
                <DataTable
                    stickyHeader
                    className="mt-4 max-h-[calc(100vh-250px)] overflow-auto"
                    columns={[
                        { label: 'Action', actionColumn: true },
                        { label: 'Transaction Date', sortKey: 'sort_date' },
                        { label: 'Source of Goods', sortKey: 'source_of_goods' },
                        { label: 'Warehouse Name', sortKey: 'warehouse' },
                        { label: 'Purpose', sortKey: 'purpose' },
                        { label: 'Item', sortKey: 'item' },
                        { label: 'Issuance', align: 'right', sortKey: 'quantity' },
                        { label: 'Unit Cost', align: 'right', sortKey: 'unit_cost' },
                        { label: 'Cost', align: 'right', sortKey: 'cost' },
                        { label: 'Expiry Month', sortKey: 'expiry_sort' },
                        { label: 'Recipient', sortKey: 'recipient' },
                        { label: 'Delivery Site', sortKey: 'delivery_site' },
                        { label: 'Expected Delivery Date', sortKey: 'expected_delivery_date' },
                        { label: 'Remarks', sortKey: 'remarks' },
                        { label: 'Time Stamp Encoded', sortKey: 'encoded_at' },
                        { label: 'Time Stamp Edited', sortKey: 'edited_at' },
                    ]}
                    sort={sort}
                    onSort={changeSort}
                    rows={[...sortedRows.map((row) => renderLedgerRow(row, setDetailRow)), renderGrandTotalRow(filteredRows)]}
                />
            </ExportableCard>

            {detailRow && <IssuanceDetailModal row={detailRow} onClose={() => setDetailRow(null)} />}
        </AppLayout>
    );
}

function ChartCard({ id, title, description, children }) {
    return (
        <ExportableCard id={id} title={title} className="scroll-mt-28 overflow-hidden p-0" showExportButtons={false}>
            <div className="border-b border-slate-200 bg-slate-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                <h2 className="text-sm font-black uppercase tracking-wide text-slate-950 dark:text-white">{title}</h2>
            </div>
            <div className="p-5">
                <p className="mb-5 text-sm font-semibold leading-6 text-slate-500 dark:text-zinc-400">{description}</p>
                {children}
            </div>
        </ExportableCard>
    );
}

function MiniMetric({ title, value }) {
    return (
        <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div className="absolute -right-7 -top-8 h-20 w-20 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <p className="relative text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="relative mt-2 text-xl font-black text-slate-950 dark:text-white">{value}</p>
        </div>
    );
}

function Metric({ title, value, subtext, icon: Icon }) {
    return (
        <Card className="relative overflow-hidden">
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <div className="relative flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
                    <p className="mt-1 text-sm font-bold text-slate-500 dark:text-zinc-400">{subtext}</p>
                </div>
                <div className="rounded-md border border-slate-200 bg-white p-3 text-brand-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-brand-100">
                    <Icon className="h-6 w-6" />
                </div>
            </div>
        </Card>
    );
}

function VerticalBars({ rows, labelKey, valueKey }) {
    const max = Math.max(...rows.map((row) => Math.abs(Number(row[valueKey] || 0))), 1);

    return (
        <div className="overflow-x-auto">
            <div className="flex min-h-72 min-w-[42rem] items-end gap-4 border-b border-slate-200 px-2 pb-8 pt-4 dark:border-zinc-800">
                {rows.map((row, index) => {
                    const value = Number(row[valueKey] || 0);
                    const height = value === 0 ? 4 : Math.max(10, (Math.abs(value) / max) * 220);

                    return (
                        <div key={row[labelKey]} className="group relative flex min-w-20 flex-1 flex-col items-center">
                            <span className="mb-2 text-xs font-black text-slate-950 dark:text-white">{formatNumber(value)}</span>
                            <div
                                className="w-full max-w-20 rounded-t-lg transition group-hover:-translate-y-1"
                                style={{ height, backgroundColor: colors[index % colors.length] }}
                            />
                            <span className="absolute -bottom-6 text-center text-xs font-semibold text-slate-700 dark:text-zinc-300">{row[labelKey]}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function HorizontalBars({ rows, formatLabel = true }) {
    const max = Math.max(...rows.map((row) => Math.abs(Number(row.quantity || 0))), 1);

    return (
        <div className="max-h-[28rem] space-y-4 overflow-y-auto pr-2">
            {rows.length === 0 && <p className="py-6 text-center text-sm font-bold text-slate-500">No issuance data found.</p>}
            {rows.map((row, index) => (
                <div key={row.label}>
                    <div className="mb-1 flex items-center justify-between gap-3 text-sm">
                        <span className="font-black text-slate-950 dark:text-white">{formatLabel ? formatCategoryName(row.label) : row.label}</span>
                        <span className="shrink-0 font-semibold text-slate-500 dark:text-zinc-400">{formatNumber(row.quantity)} | {formatCurrency(row.cost)}</span>
                    </div>
                    <div className="h-2 rounded-full bg-slate-100 dark:bg-zinc-800">
                        <div
                            className="h-2 rounded-full"
                            style={{ width: `${Math.max(3, (Math.abs(Number(row.quantity || 0)) / max) * 100)}%`, backgroundColor: colors[index % colors.length] }}
                        />
                    </div>
                    <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{formatNumber(row.count)} transactions</p>
                </div>
            ))}
        </div>
    );
}

function renderLedgerRow(row, setDetailRow) {
    return (
        <tr key={row.id}>
            <td className="whitespace-nowrap px-4 py-3 text-sm font-semibold">{row.date}</td>
            <td className="max-w-40 px-4 py-3 text-sm">{row.source_of_goods}</td>
            <td className="min-w-56 px-4 py-3">
                <p className="font-black">{row.warehouse}</p>
                <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership}</p>
            </td>
            <td className="max-w-44 px-4 py-3 text-sm">{row.purpose}</td>
            <td className="min-w-40 px-4 py-3">
                <p className="font-black">{row.item}</p>
                <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.brand}</p>
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatNumber(row.quantity)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold">{formatCurrency(row.unit_cost)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right font-black">{formatCurrency(row.cost)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm">{row.expiry_month}</td>
            <td className="max-w-44 px-4 py-3 text-sm">{row.recipient}</td>
            <td className="max-w-52 px-4 py-3 text-sm">{row.delivery_site}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm">{row.expected_delivery_date}</td>
            <td className="max-w-72 px-4 py-3 text-sm leading-tight">{row.remarks}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm">{row.encoded_at}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm">{row.edited_at}</td>
            <td className="whitespace-nowrap px-4 py-3 text-center">
                <TableActionButton icon={Eye} label="View details" tone="brand" onClick={() => setDetailRow(row)} />
            </td>
        </tr>
    );
}

function renderGrandTotalRow(rows) {
    const totalQuantity = rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0);
    const totalCost = rows.reduce((sum, row) => sum + Number(row.cost || 0), 0);

    return (
        <tr key="grand-total" className="sticky bottom-0 z-10 bg-slate-50 text-sm font-black dark:bg-zinc-950">
            <td colSpan={7} className="px-4 py-3">Total</td>
            <td className="whitespace-nowrap px-4 py-3 text-right">{formatNumber(totalQuantity)}</td>
            <td />
            <td className="whitespace-nowrap px-4 py-3 text-right">{formatCurrency(totalCost)}</td>
            <td colSpan={7} />
        </tr>
    );
}

function IssuanceDetailModal({ row, onClose }) {
    const details = [
        ['Transaction Date', row.date],
        ['Reference No.', row.reference],
        ['RIS / TF / STF', row.ris_if_stf],
        ['Source of Goods', row.source_of_goods],
        ['Warehouse Name', row.warehouse],
        ['Warehouse Type', row.warehouse_type],
        ['Province', row.province],
        ['District', row.district],
        ['City / Municipality', row.municipality],
        ['Partnership', row.partnership],
        ['Category', formatCategoryName(row.category)],
        ['Item', row.item],
        ['Brand / Specification', row.brand],
        ['Unit of Measurement', row.unit],
        ['Purpose', row.purpose],
        ['Recipient', row.recipient],
        ['Delivery Site', row.delivery_site],
        ['Expected Delivery Date', row.expected_delivery_date],
        ['Expiry Month', row.expiry_month],
        ['Issuance', formatNumber(row.quantity)],
        ['Unit Cost', formatCurrency(row.unit_cost)],
        ['Cost', formatCurrency(row.cost)],
        ['Time Stamp Encoded', row.encoded_at],
        ['Time Stamp Edited', row.edited_at],
        ['Responsible Personnel', row.personnel],
        ['Remarks', row.remarks],
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div className="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-lg bg-white shadow-2xl dark:bg-zinc-950">
                <div className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <div>
                        <h2 className="text-xl font-black">Issuance Details</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">{row.item} issued to {row.recipient}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-zinc-800 dark:hover:text-white">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="grid gap-3 p-6 sm:grid-cols-2 xl:grid-cols-3">
                    {details.map(([label, value]) => (
                        <div key={label} className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                            <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{label}</p>
                            <p className="mt-1 font-bold text-slate-950 dark:text-white">{value}</p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function summarize(rows) {
    return {
        count: rows.length,
        quantity: rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0),
        cost: rows.reduce((sum, row) => sum + Number(row.cost || 0), 0),
        warehouses: new Set(rows.map((row) => row.warehouse)).size,
        recipients: new Set(rows.map((row) => row.recipient)).size,
        items: new Set(rows.map((row) => row.item)).size,
        categories: new Set(rows.map((row) => row.category)).size,
    };
}

function groupRows(rows, key) {
    const grouped = rows.reduce((acc, row) => {
        const label = row[key] || 'Unspecified';
        acc[label] ??= { label, quantity: 0, cost: 0, count: 0 };
        acc[label].quantity += Number(row.quantity || 0);
        acc[label].cost += Number(row.cost || 0);
        acc[label].count += 1;

        return acc;
    }, {});

    return Object.values(grouped).sort((a, b) => Math.abs(b.quantity) - Math.abs(a.quantity));
}

function monthlyTrend(rows) {
    const grouped = months.map((month) => ({ month, quantity: 0, cost: 0, count: 0 }));

    rows.forEach((row) => {
        if (!row.sort_date) {
            return;
        }

        const monthIndex = Number(row.sort_date.slice(5, 7)) - 1;

        if (grouped[monthIndex]) {
            grouped[monthIndex].quantity += Number(row.quantity || 0);
            grouped[monthIndex].cost += Number(row.cost || 0);
            grouped[monthIndex].count += 1;
        }
    });

    return grouped;
}

function matchesMulti(selected, value) {
    return !Array.isArray(selected) || selected.length === 0 || selected.map(String).includes(String(value));
}

function sortRows(rows, sort) {
    return [...rows].sort((a, b) => {
        const first = a[sort.key];
        const second = b[sort.key];
        const modifier = sort.direction === 'asc' ? 1 : -1;

        if (typeof first === 'number' || typeof second === 'number') {
            return (Number(first || 0) - Number(second || 0)) * modifier;
        }

        return String(first ?? '').localeCompare(String(second ?? '')) * modifier;
    });
}
