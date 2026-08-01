import { Head, router } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, Boxes, Coins, Eye, Filter, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import LookerMultiSelect from '@/Components/LookerMultiSelect';
import SystemTabs from '@/Components/SystemTabs';

const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const formatDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '-';
const formatCurrency = (value) => `₱${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function EStockCard({ filters, warehouses, items, filterOptions = {}, metrics, rows }) {
    const [showFilters, setShowFilters] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const [activeTab, setActiveTab] = useState('all');
    const [loadedTabs, setLoadedTabs] = useState({ all: true });
    const [detailRow, setDetailRow] = useState(null);
    const [ledgerSort, setLedgerSort] = useState({ key: 'date', direction: 'asc' });
    const warehouseOptions = (filterOptions.warehouses?.length ? filterOptions.warehouses : warehouses.map((warehouse) => ({
        value: warehouse.id,
        label: warehouse.display_name ?? warehouse.name,
    }))).map((option) => ({ ...option, value: String(option.value) }));
    const itemOptions = (filterOptions.items?.length ? filterOptions.items : items.map((item) => ({
        value: item.name,
        label: item.name,
    }))).map((option) => ({ ...option, value: String(option.value) }));
    const categoryOptions = (filterOptions.categories || []).map((option) => ({ ...option, value: String(option.value) }));
    const partnershipOptions = (filterOptions.partnerships || []).map((option) => ({ ...option, value: String(option.value) }));
    const brandOptions = (filterOptions.brands || []).map((option) => ({ ...option, value: String(option.value) }));
    const expiryOptions = (filterOptions.expiries || []).map((option) => ({ ...option, value: String(option.value) }));
    const sourceOptions = (filterOptions.sources || []).map((option) => ({ ...option, value: String(option.value) }));
    const purposeOptions = (filterOptions.purposes || []).map((option) => ({ ...option, value: String(option.value) }));
    const senderRecipientOptions = (filterOptions.senderRecipients || []).map((option) => ({ ...option, value: String(option.value) }));
    const multiOptions = (values = []) => values.map((value) => (typeof value === 'object' ? { ...value, value: String(value.value) } : { value: String(value), label: String(value) }));
    const typeOptions = [
        { value: 'receipt', label: 'Receipts only' },
        { value: 'release', label: 'Issuances only' },
    ];
    const activeFilterCount = ['warehouse_id', 'warehouse_province', 'warehouse_municipality', 'partnership', 'category', 'item', 'source_of_goods', 'purpose', 'sender_recipient', 'brand', 'expiry', 'type', 'date_from', 'date_to'].filter((key) => Array.isArray(filters[key]) ? filters[key].length > 0 : Boolean(filters[key])).length;
    const hasRequiredLedgerFilters = Array.isArray(filters.warehouse_id) && filters.warehouse_id.length > 0
        && Array.isArray(filters.item) && filters.item.length > 0;
    const filteredTotals = useMemo(() => {
        if (!hasRequiredLedgerFilters) {
            return null;
        }

        const lastRow = rows.length > 0 ? rows[rows.length - 1] : null;

        return {
            balance: Number(lastRow?.balance || 0),
            balanceCost: Number(lastRow?.balance_cost || 0),
            receipts: rows.reduce((sum, row) => sum + Number(row.receipt_quantity || 0), 0),
            receiptCost: rows.reduce((sum, row) => sum + Number(row.receipt_cost || 0), 0),
            issuances: rows.reduce((sum, row) => sum + Number(row.issuance_quantity || 0), 0),
            issuanceCost: rows.reduce((sum, row) => sum + Number(row.issuance_cost || 0), 0),
        };
    }, [hasRequiredLedgerFilters, rows]);
    const displayTotals = filteredTotals ?? null;
    const tabRows = useMemo(() => {
        if (activeTab === 'receipts') {
            return rows.filter((row) => row.type === 'receipt');
        }

        if (activeTab === 'issuances') {
            return rows.filter((row) => row.type === 'release');
        }

        return rows;
    }, [activeTab, rows]);
    const sortedTabRows = useMemo(() => sortLedgerRows(tabRows, ledgerSort), [ledgerSort, tabRows]);

    const changeTab = (tab) => {
        setActiveTab(tab);
        setLoadedTabs((current) => ({ ...current, [tab]: true }));
    };

    const changeFilter = (key, value) => {
        router.get('/inventory/e-stock-card', { ...filters, [key]: value }, { preserveState: true, preserveScroll: true });
    };

    const changeLedgerSort = (key) => {
        setLedgerSort((current) => ({
            key,
            direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc',
        }));
    };

    const applySearch = (event) => {
        event.preventDefault();
        router.get('/inventory/e-stock-card', { ...filters, search: searchQuery }, { preserveState: true, preserveScroll: true });
    };

    const clearSearch = () => {
        setSearchQuery('');
        router.get('/inventory/e-stock-card', { ...filters, search: '' }, { preserveState: true, preserveScroll: true });
    };

    const resetFilters = () => {
        setSearchQuery('');
        router.get('/inventory/e-stock-card', {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout title="E-Stock Card">
            <Head title="E-Stock Card" />

            <ExportableCard id="estock-overview" title="E-Stock Card Overview" className="scroll-mt-28 overflow-hidden" showExportButtons={false}>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Warehouse Movement Ledger</p>
                        <h2 className="mt-1 text-2xl font-black">E-Stock Card</h2>
                        <p className="mt-2 max-w-3xl text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Tracks what goes in and what goes out of warehouses using the same stock-card flow as the WIT E-Stock Card sheet: receipts, issuances, running stockpile, and cost.
                        </p>
                    </div>
                </div>
            </ExportableCard>

            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Metric title="Current Balance" value={displayTotals ? formatNumber(displayTotals.balance) : '-'} icon={Boxes} />
                <Metric title="Balance Cost" value={displayTotals ? formatCurrency(displayTotals.balanceCost) : '-'} icon={Coins} />
                <Metric title="Total Receipts /Incoming" value={displayTotals ? formatNumber(displayTotals.receipts) : '-'} subtext={displayTotals ? formatCurrency(displayTotals.receiptCost) : '-'} icon={ArrowDownToLine} tone="green" />
                <Metric title="Total Issuances /Outgoing" value={displayTotals ? formatNumber(displayTotals.issuances) : '-'} subtext={displayTotals ? formatCurrency(displayTotals.issuanceCost) : '-'} icon={ArrowUpFromLine} tone="red" />
            </div>

            {!hasRequiredLedgerFilters && (
                <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-100">
                    <p>Warehouse and Item are required to populate the E-Stock Card ledger.</p>
                    <p className="mt-2 text-sm font-semibold text-amber-900 dark:text-amber-100">Use the "Show additional filters" button when you need more precise filtering.</p>
                </div>
            )}

            <Card id="estock-filters" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <label className="min-w-[11rem] text-sm font-black text-slate-600 dark:text-zinc-300">
                        Transaction Year
                        <select className="mt-1 h-10 w-full" value={filters.transaction_year ?? ''} onChange={(event) => changeFilter('transaction_year', event.target.value)}>
                            {(filterOptions.transaction_years || []).map((year) => (
                                <option key={year.value ?? year} value={year.value ?? year}>{year.label ?? year}</option>
                            ))}
                        </select>
                    </label>
                    <button
                        type="button"
                        onClick={() => setShowFilters((current) => !current)}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-100 px-4 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700 dark:hover:bg-zinc-700"
                    >
                        <Filter className="h-4 w-4" />
                        {showFilters ? 'Hide additional filters' : 'Show additional filters'}
                        {activeFilterCount > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount}</span>}
                    </button>
                    <form onSubmit={applySearch} className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            className="h-10 w-full pl-9 pr-24"
                            placeholder="Search E-Stock Card ledger..."
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
                    <button type="button" onClick={resetFilters} className="h-10 rounded-md border border-slate-200 bg-white px-4 text-sm font-black text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        Reset
                    </button>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-4">
                    <LookerMultiSelect label="Warehouse" allLabel="--select--" options={warehouseOptions} value={filters.warehouse_id} onApply={(value) => changeFilter('warehouse_id', value)} placeholder="Search warehouse..." />
                    <LookerMultiSelect label="Category" allLabel="--select--" options={categoryOptions} value={filters.category} onApply={(value) => changeFilter('category', value)} placeholder="Search category..." />
                    <LookerMultiSelect label="Item" allLabel="--select--" options={itemOptions} value={filters.item} onApply={(value) => changeFilter('item', value)} placeholder="Search item..." />
                    <LookerMultiSelect label="Brand / Specification" allLabel="--select--" options={brandOptions} value={filters.brand} onApply={(value) => changeFilter('brand', value)} placeholder="Search brand..." />
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 dark:border-zinc-800">
                        <LookerMultiSelect label="Province" allLabel="--select--" options={multiOptions(filterOptions.warehouse_provinces)} value={filters.warehouse_province} onApply={(value) => changeFilter('warehouse_province', value)} placeholder="Search province..." />
                        <LookerMultiSelect label="City / Municipality" allLabel="--select--" options={multiOptions(filterOptions.warehouse_municipalities)} value={filters.warehouse_municipality} onApply={(value) => changeFilter('warehouse_municipality', value)} placeholder="Search city / municipality..." />
                        <LookerMultiSelect label="Partnership" allLabel="--select--" options={partnershipOptions} value={filters.partnership} onApply={(value) => changeFilter('partnership', value)} placeholder="Search partnership..." />
                        <LookerMultiSelect label="Source of Goods" allLabel="--select--" options={sourceOptions} value={filters.source_of_goods} onApply={(value) => changeFilter('source_of_goods', value)} placeholder="Search source..." />
                        <LookerMultiSelect label="Purpose" allLabel="--select--" options={purposeOptions} value={filters.purpose} onApply={(value) => changeFilter('purpose', value)} placeholder="Search purpose..." />
                        <LookerMultiSelect label="Sender / Recipient" allLabel="--select--" options={senderRecipientOptions} value={filters.sender_recipient} onApply={(value) => changeFilter('sender_recipient', value)} placeholder="Search sender or recipient..." />
                        <LookerMultiSelect label="Transaction Type" allLabel="--select--" options={typeOptions} value={filters.type} onApply={(value) => changeFilter('type', value)} placeholder="Search transaction type..." />
                        <LookerMultiSelect label="Expiry" allLabel="--select--" options={expiryOptions} value={filters.expiry} onApply={(value) => changeFilter('expiry', value)} placeholder="Search expiry..." />
                    </div>
                )}

                {showFilters && (
                    <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label className="text-sm font-medium">
                            Date From
                            <input className="mt-1 w-full" type="date" value={filters.date_from ?? ''} onChange={(event) => changeFilter('date_from', event.target.value)} />
                        </label>
                        <label className="text-sm font-medium">
                            Date To
                            <input className="mt-1 w-full" type="date" value={filters.date_to ?? ''} onChange={(event) => changeFilter('date_to', event.target.value)} />
                        </label>
                    </div>
                )}
            </Card>

            <ExportableCard
                id="estock-ledger"
                title="E-Stock Card Ledger"
                className="mt-6 scroll-mt-28"
                showExportButtons={true}
                exportButtonProps={{ showOnlyFullscreen: true }}
                renderHeader={({ exportButtons }) => (
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-black">E-Stock Card Ledger</h2>
                            <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                The complete stock-card tab shows both sides of the ledger. Receipt and issuance tabs render only after being opened for easier focused review.
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            {exportButtons}
                            <p className="text-sm font-black text-slate-500 dark:text-zinc-400">{tabRows.length.toLocaleString()} rows shown</p>
                        </div>
                    </div>
                )}>
                <SystemTabs
                    active={activeTab}
                    ariaLabel="E-Stock Card views"
                    className="mt-4"
                    items={[
                        { key: 'all', label: 'Complete E-Stock Card', onClick: () => changeTab('all') },
                        { key: 'receipts', label: 'Receipts', onClick: () => changeTab('receipts') },
                        { key: 'issuances', label: 'Issuances / Releases', onClick: () => changeTab('issuances') },
                    ]}
                />
                {hasRequiredLedgerFilters ? (
                    <DataTable
                        stickyHeader
                        className="mt-4 max-h-[calc(100vh-260px)] overflow-auto"
                        columns={ledgerColumns(activeTab)}
                        sort={ledgerSort}
                        onSort={changeLedgerSort}
                        rows={loadedTabs[activeTab] ? [...sortedTabRows.map((row) => renderLedgerRow(row, activeTab, setDetailRow)), renderGrandTotalRow(tabRows, activeTab)] : []}
                    />
                ) : (
                    <div className="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-sm font-black text-slate-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                        Select Warehouse and Item to display ledger rows. Add Brand / Specification if needed.
                    </div>
                )}
            </ExportableCard>

            {detailRow && <LedgerDetailModal row={detailRow} onClose={() => setDetailRow(null)} />}
        </AppLayout>
    );
}

function Metric({ title, value, subtext, icon: Icon, tone = 'brand' }) {
    const tones = {
        brand: 'text-brand-700 dark:text-brand-100',
        green: 'text-brand-700 dark:text-brand-100',
        red: 'text-signal-coral',
    };

    return (
        <Card className="relative overflow-hidden">
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <div className="relative flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
                    {subtext && <p className="mt-1 text-sm font-bold text-slate-500 dark:text-zinc-400">{subtext}</p>}
                </div>
                {Icon && (
                    <span className={`flex h-11 w-11 items-center justify-center rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 ${tones[tone]}`}>
                        <Icon className="h-5 w-5" />
                    </span>
                )}
            </div>
        </Card>
    );
}

function SplitBar({ label, receipts, issuances, max }) {
    return (
        <div>
            <div className="mb-1 flex items-start justify-between gap-3 text-sm">
                <p className="min-w-0 truncate font-black" title={label}>{label}</p>
                <p className="shrink-0 text-xs font-bold text-slate-500 dark:text-zinc-400">
                    +{formatNumber(receipts)} / -{formatNumber(issuances)}
                </p>
            </div>
            <div className="grid gap-1">
                <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                    <div className="h-full rounded-full bg-brand-700" style={{ width: `${Math.max((Number(receipts || 0) / max) * 100, receipts > 0 ? 2 : 0)}%` }} />
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                    <div className="h-full rounded-full bg-signal-coral" style={{ width: `${Math.max((Number(issuances || 0) / max) * 100, issuances > 0 ? 2 : 0)}%` }} />
                </div>
            </div>
        </div>
    );
}

function ledgerColumns(tab) {
    return [
        { label: 'Date', sortKey: 'date' },
        { label: 'Reference Number', sortKey: 'reference' },
        { label: 'Warehouse', sortKey: 'warehouse' },
        { label: 'Category', sortKey: 'category' },
        { label: 'Item', sortKey: 'item' },
        { label: 'Expiry', sortKey: 'expiry' },
        { label: 'Source of Goods', sortKey: 'source_of_goods' },
        { label: 'Purpose', sortKey: 'purpose' },
        { label: 'Sender / Recipient', sortKey: 'sender_recipient' },
        tab === 'issuances' ? null : { label: 'Incoming Quantity', sortKey: 'receipt_quantity', align: 'right' },
        tab === 'receipts' ? null : { label: 'Outgoing Quantity', sortKey: 'issuance_quantity', align: 'right' },
        { label: 'Balance', sortKey: 'balance', align: 'right' },
        { label: 'Unit Cost', sortKey: 'unit_cost', align: 'right' },
        { label: 'Balance Cost', sortKey: 'balance_cost', align: 'right' },
        { label: 'Action', align: 'right', actionColumn: true },
    ].filter(Boolean);
}

function sortLedgerRows(rows, sort) {
    if (!sort?.key) {
        return rows;
    }

    const direction = sort.direction === 'desc' ? -1 : 1;

    return [...rows].sort((a, b) => {
        const aValue = ledgerSortValue(a, sort.key);
        const bValue = ledgerSortValue(b, sort.key);

        if (typeof aValue === 'number' || typeof bValue === 'number') {
            return ((Number(aValue) || 0) - (Number(bValue) || 0)) * direction;
        }

        return String(aValue ?? '').localeCompare(String(bValue ?? ''), undefined, { numeric: true, sensitivity: 'base' }) * direction;
    });
}

function ledgerSortValue(row, key) {
    if (key === 'unit_cost') {
        return Number(row.type === 'receipt' ? row.receipt_unit_cost : row.issuance_unit_cost);
    }

    if (['receipt_quantity', 'issuance_quantity', 'balance', 'balance_cost'].includes(key)) {
        return Number(row[key] || 0);
    }

    return row[key] ?? '';
}

function renderLedgerRow(row, tab, onView) {
    const receiptCell = 'whitespace-nowrap bg-emerald-50 px-4 py-3 text-right font-black text-brand-800 dark:bg-emerald-950/30 dark:text-brand-100';
    const issuanceCell = 'whitespace-nowrap bg-rose-50 px-4 py-3 text-right font-black text-signal-coral dark:bg-rose-950/25';
    const balanceCell = 'whitespace-nowrap bg-amber-50 px-4 py-3 text-right font-black dark:bg-amber-950/20';
    const unitCost = row.type === 'receipt' ? row.receipt_unit_cost : row.issuance_unit_cost;

    return (
        <tr key={row.id} className={row.type === 'receipt' ? 'border-l-4 border-brand-600' : 'border-l-4 border-signal-coral'}>
            <td className="whitespace-nowrap px-4 py-3 font-semibold">{formatDate(row.date)}</td>
            <td className="whitespace-nowrap px-4 py-3">{row.reference}</td>
            <td className="whitespace-nowrap px-4 py-3">
                <p className="font-black">{row.warehouse}</p>
                <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership || '-'}</p>
            </td>
            <td className="whitespace-nowrap px-4 py-3">{row.category}</td>
            <td className="whitespace-nowrap px-4 py-3">
                <p className="font-black">{row.item}</p>
                <p className="text-xs text-slate-500 dark:text-zinc-400">{row.brand_specification}</p>
            </td>
            <td className="whitespace-nowrap px-4 py-3">{row.expiry}</td>
            <td className="whitespace-nowrap px-4 py-3">{row.source_of_goods}</td>
            <td className="whitespace-nowrap px-4 py-3">{row.purpose}</td>
            <td className="whitespace-nowrap px-4 py-3">{row.sender_recipient}</td>
            {tab !== 'issuances' && <td className={receiptCell}>{row.receipt_quantity ? formatNumber(row.receipt_quantity) : '-'}</td>}
            {tab !== 'receipts' && <td className={issuanceCell}>{row.issuance_quantity ? formatNumber(row.issuance_quantity) : '-'}</td>}
            <td className={balanceCell}>{formatNumber(row.balance)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right">{unitCost ? formatCurrency(unitCost) : '-'}</td>
            <td className={balanceCell}>{formatCurrency(row.balance_cost)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right">
                <TableActionButton icon={Eye} label="View" onClick={() => onView(row)} tone="brand" />
            </td>
        </tr>
    );
}

function renderGrandTotalRow(rows, tab) {
    const receiptQuantity = rows.reduce((total, row) => total + Number(row.receipt_quantity || 0), 0);
    const issuanceQuantity = rows.reduce((total, row) => total + Number(row.issuance_quantity || 0), 0);
    const lastRow = rows.length > 0 ? rows[rows.length - 1] : null;
    const latestBalance = Number(lastRow?.balance || 0);
    const latestBalanceCost = Number(lastRow?.balance_cost || 0);
    const colSpan = ledgerColumns(tab).length + 1;

    return (
        <tr key="grand-total" className="border-l-4 border-slate-500">
            <td colSpan={colSpan} className="sticky bottom-0 z-20 border-t border-slate-300 bg-slate-50 px-4 py-3 font-black shadow-[0_-6px_14px_rgba(15,23,42,0.08)] dark:border-zinc-700 dark:bg-zinc-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span>Total</span>
                    <span className="inline-flex flex-wrap items-center gap-x-5 gap-y-1 text-right text-sm">
                        {tab !== 'issuances' && <span title="Incoming Quantity">{formatNumber(receiptQuantity)}</span>}
                        {tab !== 'receipts' && <span title="Outgoing Quantity">{formatNumber(issuanceQuantity)}</span>}
                        <span title="Running Balance">{formatNumber(latestBalance)}</span>
                        <span title="Balance Cost">{formatCurrency(latestBalanceCost)}</span>
                    </span>
                </div>
            </td>
        </tr>
    );
}

function LedgerDetailModal({ row, onClose }) {
    const details = [
        ['Date', formatDate(row.date)],
        ['Warehouse', row.warehouse],
        ['Reference Number', row.reference],
        ['RIS / STF', row.ris],
        ['Partnership', row.partnership],
        ['Category', row.category],
        ['Item', row.item],
        ['Brand / Specification', row.brand_specification],
        ['Expiry', row.expiry],
        ['Source of Goods', row.source_of_goods],
        ['Purpose', row.purpose],
        ['Sender / Recipient', row.sender_recipient],
        ['Incoming Quantity', row.receipt_quantity ? formatNumber(row.receipt_quantity) : '-'],
        ['Outgoing Quantity', row.issuance_quantity ? formatNumber(row.issuance_quantity) : '-'],
        ['Running Balance', formatNumber(row.balance)],
        ['Unit Cost', formatCurrency(row.type === 'receipt' ? row.receipt_unit_cost : row.issuance_unit_cost)],
        ['Balance Cost', formatCurrency(row.balance_cost)],
        ['Personnel', row.personnel],
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div className="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-4 dark:border-zinc-800">
                    <div>
                        <p className="text-[11px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">E-Stock Card Details</p>
                        <h2 className="mt-1 text-lg font-black">{row.item}</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 dark:hover:bg-zinc-800">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="grid max-h-[70vh] gap-2 overflow-y-auto p-4 sm:grid-cols-2 xl:grid-cols-3">
                    {details.map(([label, value]) => (
                        <div key={label} className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <p className="font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{label}</p>
                            <p className="mt-1 break-words text-sm font-semibold text-slate-900 dark:text-zinc-100">{value || '-'}</p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
