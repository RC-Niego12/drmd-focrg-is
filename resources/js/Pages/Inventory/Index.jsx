import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Boxes, Eye, Filter, PackageMinus, PackagePlus, RefreshCw, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import LookerMultiSelect from '@/Components/LookerMultiSelect';

const today = new Date().toISOString().slice(0, 10);
const money = (value) => `₱${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const number = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const selectOptions = (placeholder, values = []) => [
    { value: '', label: placeholder },
    ...values.map((value) => ({ value, label: value })),
];
const multiOptions = (values = []) => values.map((value) => typeof value === 'object' ? { ...value, value: String(value.value) } : { value: String(value), label: String(value) });
const categoryColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4', '#64748b'];

export default function Index({ warehouses, selectedWarehouse, balanceRows, categoryReferenceRows = [], batches, items, fniLibraryItems = [], libraryOptions = {}, filters, filterOptions, sync }) {
    const canManageInventory = (usePage().props.auth.user?.permissions ?? []).includes('manage inventory');
    const [showFilters, setShowFilters] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const [activeModal, setActiveModal] = useState(null);
    const [activeCategory, setActiveCategory] = useState(null);
    const [activeTableTab, setActiveTableTab] = useState('stockpile');
    const [detailRow, setDetailRow] = useState(null);
    const [selectedLibraryItem, setSelectedLibraryItem] = useState('');
    const receipt = useForm({
        warehouse_id: selectedWarehouse?.id ?? '',
        inventory_item_id: '',
        item_name: '',
        category: 'non_food',
        unit: 'unit',
        brand_description: '',
        transaction_date: today,
        source_of_goods: '',
        sender_supplier: '',
        reference_number: '',
        expiration_date: '',
        quantity: '',
        unit_cost: '',
        remarks: '',
    });

    const release = useForm({
        inventory_batch_id: batches[0]?.id ?? '',
        transaction_date: today,
        purpose: '',
        ris_if_stf: '',
        reference_number: '',
        quantity: '',
        recipient: '',
        delivery_site: '',
        expected_delivery_date: '',
        land_transportation_type: '',
        land_transportation_source: '',
        plate: '',
        driver: '',
        contact_number: '',
        sea_transportation_type: '',
        sea_transportation_source: '',
        sea_transportation_details: '',
        air_transportation_type: '',
        air_transportation_source: '',
        air_transportation_details: '',
        remarks: '',
    });

    const syncForm = useForm({});
    const warehouseOptions = warehouses.map((warehouse) => ({
        value: warehouse.id,
        label: warehouse.display_name ?? warehouse.name,
    }));
    const itemOptions = [
        { value: '', label: 'New item or select existing' },
        ...items.map((item) => ({ value: item.id, label: `${item.name} (${item.unit})` })),
    ];
    const batchOptions = batches.map((batch) => ({
        value: batch.id,
        label: `${batch.label} · ${batch.warehouse ?? 'Warehouse'} · Avail: ${number(batch.available_quantity)}`,
    }));

    const changeFilter = (key, value) => {
        router.get('/inventory', { ...filters, [key]: value }, { preserveState: true, preserveScroll: true });
    };

    const applySearch = (event) => {
        event.preventDefault();
        router.get('/inventory', { ...filters, search: searchQuery }, { preserveState: true, preserveScroll: true });
    };

    const clearSearch = () => {
        setSearchQuery('');
        router.get('/inventory', { ...filters, search: '' }, { preserveState: true, preserveScroll: true });
    };

    const resetFilters = () => {
        setSearchQuery('');
        router.get('/inventory', {}, { preserveState: true, preserveScroll: true });
    };

    const changeSort = (key) => {
        const nextDirection = filters.sort === key && filters.direction === 'asc' ? 'desc' : 'asc';
        router.get('/inventory', { ...filters, sort: key, direction: nextDirection }, { preserveState: true, preserveScroll: true });
    };

    const totalStockpile = balanceRows.reduce((sum, row) => sum + Number(row.current_balance ?? 0), 0);
    const categoryCards = useMemo(() => {
        const categoryOrder = ['Family Food Packs', 'Food Items', 'Non Food Items', 'Other NFIs', 'Indirect & Raw Materials'];
        const grouped = balanceRows.reduce((acc, row) => {
            const category = ['Indirect Materials', 'Raw Materials'].includes(row.category)
                ? 'Indirect & Raw Materials'
                : row.category || 'Uncategorized';
            acc[category] ??= { category, rows: 0, stockpile: 0, cost: 0 };
            acc[category].rows += 1;
            acc[category].stockpile += Number(row.current_balance ?? 0);
            acc[category].cost += Number(row.cost ?? 0);

            return acc;
        }, {});

        return Object.values(grouped).sort((a, b) => {
            const aIndex = categoryOrder.indexOf(a.category);
            const bIndex = categoryOrder.indexOf(b.category);

            if (aIndex !== -1 || bIndex !== -1) {
                if (aIndex === -1) {
                    return 1;
                }
                if (bIndex === -1) {
                    return -1;
                }
                return aIndex - bIndex;
            }

            return b.stockpile - a.stockpile;
        });
    }, [balanceRows]);
    const activeFilterCount = ['warehouse_id', 'warehouse_province', 'warehouse_municipality', 'category', 'item', 'brand', 'partnership', 'expiry'].filter((key) => Array.isArray(filters[key]) ? filters[key].length > 0 : Boolean(filters[key])).length;
    const stockpileColumns = [
        selectedWarehouse ? null : { label: 'Warehouse', sortKey: 'warehouse' },
        { label: 'Category', sortKey: 'category' },
        { label: 'Item', sortKey: 'item' },
        { label: 'Expiry', sortKey: 'expiry' },
        { label: 'Current Stockpile', sortKey: 'current_balance', align: 'right' },
        { label: 'Cost', sortKey: 'cost', align: 'right' },
        { label: 'Action', align: 'right', actionColumn: true },
    ].filter(Boolean);
    const itemTotals = useMemo(() => {
        const grouped = balanceRows.reduce((acc, row) => {
            const key = `${row.category || 'Uncategorized'}|${row.item || '-'}|${row.brand_description || '-'}`;
            acc[key] ??= { category: row.category || 'Uncategorized', item: row.item || '-', brand_description: row.brand_description || '-', rows: 0, stockpile: 0, cost: 0 };
            acc[key].rows += 1;
            acc[key].stockpile += Number(row.current_balance ?? 0);
            acc[key].cost += Number(row.cost ?? 0);

            return acc;
        }, {});

        return Object.values(grouped).sort((a, b) => a.category.localeCompare(b.category) || a.item.localeCompare(b.item) || a.brand_description.localeCompare(b.brand_description));
    }, [balanceRows]);
    const categoryTotalColumns = [
        { label: 'Category', sortKey: 'category' },
        { label: 'Item', sortKey: 'item' },
        { label: 'Inventory Rows', align: 'right' },
        { label: 'Current Stockpile', align: 'right' },
        { label: 'Cost', align: 'right' },
        { label: 'Share', align: 'right' },
        { label: 'Action', align: 'right', actionColumn: true },
    ];
    const categoryGrandTotal = itemTotals.reduce((total, category) => ({
        rows: total.rows + category.rows,
        stockpile: total.stockpile + category.stockpile,
        cost: total.cost + category.cost,
    }), { rows: 0, stockpile: 0, cost: 0 });

    const submitReceipt = (event) => {
        event.preventDefault();
        receipt.post('/inventory/receipts', {
            preserveScroll: true,
            onSuccess: () => {
                receipt.reset('item_name', 'brand_description', 'quantity', 'unit_cost', 'remarks');
                setActiveModal(null);
            },
        });
    };

    const submitRelease = (event) => {
        event.preventDefault();
        release.post('/inventory/releases', {
            preserveScroll: true,
            onSuccess: () => {
                release.reset('quantity', 'recipient', 'purpose', 'remarks');
                setActiveModal(null);
            },
        });
    };

    return (
        <AppLayout title="Inventory">
            <Head title="Inventory" />

            <ExportableCard id="inventory-overview" title="Inventory Overview" className="scroll-mt-28 overflow-hidden" showExportButtons={false}>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Warehouse Stockpile</p>
                        <h2 className="mt-1 text-2xl font-black">Inventory</h2>
                        <p className="mt-2 max-w-3xl text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Current stockpile follows the WIT Data Entry formula per warehouse, item, brand/description, and expiry: receipts minus issuances.
                        </p>
                    </div>
                    {canManageInventory && <div className="grid gap-2 sm:grid-cols-3 lg:min-w-[520px]">
                        <button type="button" onClick={() => setActiveModal('receipt')} className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-brand-700">
                            <PackagePlus className="h-4 w-4" />
                            Add Receipt
                        </button>
                        <button type="button" onClick={() => setActiveModal('release')} className="inline-flex items-center justify-center gap-2 rounded-md bg-signal-coral px-4 py-2 text-sm font-black text-white shadow-sm hover:opacity-90">
                            <PackageMinus className="h-4 w-4" />
                            Release Items
                        </button>
                        <button type="button" disabled={syncForm.processing} onClick={() => syncForm.post('/inventory/sync-google-sheet', { preserveScroll: true })} className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-70 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                            <RefreshCw className={`h-4 w-4 ${syncForm.processing ? 'animate-spin' : ''}`} />
                            Sync
                        </button>
                    </div>}
                </div>
            </ExportableCard>

            <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                {categoryCards.map((category, index) => (
                    <CategoryMetric
                        key={category.category}
                        category={category}
                        color={categoryColors[index % categoryColors.length]}
                        percent={totalStockpile > 0 ? (category.stockpile / totalStockpile) * 100 : 0}
                        onOpen={() => setActiveCategory(category.category)}
                    />
                ))}
                {categoryCards.length === 0 && (
                    <Card className="border-dashed text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        No item categories found for the selected filters.
                    </Card>
                )}
            </div>

            <Card id="inventory-filters" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <button type="button" onClick={() => setShowFilters((current) => !current)} className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700 dark:hover:bg-zinc-700">
                        <Filter className="h-4 w-4" />
                        {showFilters ? 'Hide additional filters' : 'Show additional filters'}
                        {activeFilterCount > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount}</span>}
                    </button>
                    <form onSubmit={applySearch} className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input className="w-full pl-9 pr-24" type="search" placeholder="Search warehouse stockpile table..." value={searchQuery} onChange={(event) => setSearchQuery(event.target.value)} />
                        {searchQuery && (
                            <button type="button" onClick={clearSearch} className="absolute right-16 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                                <X className="h-4 w-4" />
                            </button>
                        )}
                        <button type="submit" className="absolute right-1 top-1/2 -translate-y-1/2 rounded-md bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">Search</button>
                    </form>
                    <button type="button" onClick={resetFilters} className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">Reset</button>
                </div>
                <p className="mt-3 text-sm font-semibold text-slate-500 dark:text-zinc-400">Use the "Show additional filters" button when you need more precise filtering.</p>

                <div className="mt-4 grid gap-3 md:grid-cols-4">
                    <LookerMultiSelect label="Warehouse" allLabel="--select--" options={multiOptions(filterOptions.warehouses?.length ? filterOptions.warehouses : warehouseOptions)} value={filters.warehouse_id} onApply={(value) => changeFilter('warehouse_id', value)} placeholder="Search warehouse..." />
                    <LookerMultiSelect label="Category" allLabel="--select--" options={multiOptions(filterOptions.categories)} value={filters.category} onApply={(value) => changeFilter('category', value)} placeholder="Search category..." />
                    <LookerMultiSelect label="Item" allLabel="--select--" options={multiOptions(filterOptions.items)} value={filters.item} onApply={(value) => changeFilter('item', value)} placeholder="Search item..." />
                    <LookerMultiSelect label="Brand / Description" allLabel="--select--" options={multiOptions(filterOptions.brands)} value={filters.brand} onApply={(value) => changeFilter('brand', value)} placeholder="Search brand..." />
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2 xl:grid-cols-4 dark:border-zinc-800">
                        <LookerMultiSelect label="Province" allLabel="--select--" options={multiOptions(filterOptions.warehouse_provinces)} value={filters.warehouse_province} onApply={(value) => changeFilter('warehouse_province', value)} placeholder="Search province..." />
                        <LookerMultiSelect label="City / Municipality" allLabel="--select--" options={multiOptions(filterOptions.warehouse_municipalities)} value={filters.warehouse_municipality} onApply={(value) => changeFilter('warehouse_municipality', value)} placeholder="Search city / municipality..." />
                        <LookerMultiSelect label="Partnership" allLabel="--select--" options={multiOptions(filterOptions.partnerships)} value={filters.partnership} onApply={(value) => changeFilter('partnership', value)} placeholder="Search partnership..." />
                        <LookerMultiSelect label="Expiry" allLabel="--select--" options={multiOptions(filterOptions.expiries)} value={filters.expiry} onApply={(value) => changeFilter('expiry', value)} placeholder="Search expiry..." />
                    </div>
                )}
            </Card>

            <ExportableCard
                id="warehouse-stockpile"
                title="Warehouse Stockpile"
                className="mt-6 min-w-0 scroll-mt-28"
                showExportButtons={true}
                exportButtonProps={{ showOnlyFullscreen: true }}
                renderHeader={({ exportButtons }) => (
                    <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-black">Warehouse Stockpile</h2>
                            <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                {selectedWarehouse ? `${selectedWarehouse.display_name ?? selectedWarehouse.name} · ${selectedWarehouse.external_warehouse_id ?? 'No warehouse ID'}` : 'All warehouses are shown by default.'}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            {exportButtons}
                            <p className="text-sm font-black text-slate-500 dark:text-zinc-400">{number(balanceRows.length)} rows shown</p>
                        </div>
                    </div>
                )}>
                <div className="mb-4 grid rounded-md bg-slate-100 p-1 text-sm font-black text-slate-600 sm:grid-cols-2 dark:bg-zinc-900 dark:text-zinc-300">
                    {[
                        ['stockpile', 'Warehouse Stockpile'],
                        ['category-totals', 'Per Item Grand Totals'],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setActiveTableTab(key)}
                            className={`rounded px-3 py-2 transition ${activeTableTab === key ? 'bg-white text-brand-700 shadow-sm dark:bg-zinc-800 dark:text-brand-100' : 'hover:bg-white/70 dark:hover:bg-zinc-800/70'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <DataTable
                    stickyHeader
                    className="max-h-[calc(100vh-260px)] overflow-auto"
                    columns={activeTableTab === 'stockpile' ? stockpileColumns : categoryTotalColumns}
                    sort={{ key: filters.sort ?? 'warehouse', direction: filters.direction ?? 'asc' }}
                    onSort={changeSort}
                    rows={activeTableTab === 'stockpile'
                        ? [
                            ...balanceRows.map((row, index) => (
                            <tr key={`${row.warehouse}-${row.category}-${row.item}-${row.brand_description}-${row.expiry}-${index}`} className={`transition ${Number(row.current_balance) === 0 ? 'bg-rose-50 text-rose-900 dark:bg-rose-950/40 dark:text-rose-100 hover:bg-rose-100/90 dark:hover:bg-rose-900/60' : 'hover:bg-brand-50/60 dark:hover:bg-brand-950/20'}`}>
                                {!selectedWarehouse && (
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <p className="font-black">{row.warehouse}</p>
                                        <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership || '-'}</p>
                                    </td>
                                )}
                                <td className="whitespace-nowrap px-4 py-3">{row.category}</td>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <p className="font-black">{row.item}</p>
                                    <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.brand_description || '-'}</p>
                                </td>
                                <td className="whitespace-nowrap px-4 py-3">{row.expiry || 'N/A'}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right font-black">{number(row.current_balance)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold">{money(row.cost)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    <TableActionButton icon={Eye} label="View" onClick={() => setDetailRow({ type: 'stockpile', ...row })} tone="brand" />
                                </td>
                            </tr>
                        )),
                            <StickySummaryRow
                                key="stockpile-grand-total"
                                colSpan={stockpileColumns.length + 1}
                                values={[
                                    ['Rows', number(balanceRows.length)],
                                    ['Current Stockpile', number(categoryGrandTotal.stockpile)],
                                    ['Cost', money(categoryGrandTotal.cost)],
                                ]}
                            />,
                        ]
                        : [
                            ...itemTotals.map((category, index) => {
                                const percent = totalStockpile > 0 ? (category.stockpile / totalStockpile) * 100 : 0;

                                return (
                                    <tr key={`${category.category}-${category.item}`} className="transition hover:bg-brand-50/60 dark:hover:bg-brand-950/20">
                                        <td className="whitespace-nowrap px-4 py-3 font-black">{category.category}</td>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            <p className="font-black">{category.item}</p>
                                            <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">{category.brand_description || '-'}</p>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">{number(category.rows)}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right font-black">{number(category.stockpile)}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right font-semibold">{money(category.cost)}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">{percent.toFixed(1)}%</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">
                                            <TableActionButton icon={Eye} label="View" onClick={() => setDetailRow({ type: 'category', ...category, percent })} tone="brand" />
                                        </td>
                                    </tr>
                                );
                            }),
                            <StickySummaryRow
                                key="category-grand-total"
                                colSpan={categoryTotalColumns.length + 1}
                                values={[
                                    ['Rows', number(categoryGrandTotal.rows)],
                                    ['Current Stockpile', number(categoryGrandTotal.stockpile)],
                                    ['Cost', money(categoryGrandTotal.cost)],
                                ]}
                            />,
                        ]}
                />
            </ExportableCard>

            {canManageInventory && <TransactionModal open={activeModal === 'receipt'} title="Add Receipt" tone="brand" onClose={() => setActiveModal(null)}>
                <form className="space-y-4" onSubmit={submitReceipt}>
                    <SearchableSelect label="Warehouse *" options={warehouseOptions} value={receipt.data.warehouse_id} onChange={(value) => receipt.setData('warehouse_id', value)} placeholder="Search warehouse..." />
                    <SearchableSelect label="Item *" options={itemOptions} value={receipt.data.inventory_item_id} onChange={(value) => receipt.setData('inventory_item_id', value)} placeholder="Search item..." />
                    {!receipt.data.inventory_item_id && (
                        <div className="space-y-3">
                            <SearchableSelect
                                label="FNI Library"
                                options={[{ value: '', label: 'Select approved FNI' }, ...fniLibraryItems.map((entry) => ({ value: String(entry.id), label: `${entry.item_category} - ${entry.item_name}${entry.brand_description ? ` - ${entry.brand_description}` : ''}` }))]}
                                value={selectedLibraryItem}
                                onChange={(value) => {
                                    setSelectedLibraryItem(value);
                                    const entry = fniLibraryItems.find((row) => String(row.id) === String(value));
                                    if (!entry) return;
                                    receipt.setData((current) => ({
                                        ...current,
                                        item_name: entry.item_name,
                                        brand_description: entry.brand_description || '',
                                        unit: entry.unit_of_measure || current.unit,
                                        category: ['Family Food Packs', 'Food Items'].includes(entry.item_category) ? 'food' : 'non_food',
                                    }));
                                }}
                                placeholder="Search the FNI library..."
                            />
                            <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="New Item Name *" value={receipt.data.item_name} onChange={(value) => receipt.setData('item_name', value)} />
                            <label className="text-sm font-bold">Category *<select className="mt-1 w-full" value={receipt.data.category} onChange={(event) => receipt.setData('category', event.target.value)}><option value="food">Food Items</option><option value="non_food">Non-Food Items</option></select></label>
                            <Field label="Unit *" suggestions={[...new Set(fniLibraryItems.map((entry) => entry.unit_of_measure).filter(Boolean))]} value={receipt.data.unit} onChange={(value) => receipt.setData('unit', value)} />
                            </div>
                        </div>
                    )}
                    <Field label="Brand / Description" value={receipt.data.brand_description} onChange={(value) => receipt.setData('brand_description', value)} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Date Received *" type="date" value={receipt.data.transaction_date} onChange={(value) => receipt.setData('transaction_date', value)} />
                        <Field label="Expiration Date" type="date" value={receipt.data.expiration_date} onChange={(value) => receipt.setData('expiration_date', value)} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Receipt Quantity *" type="number" value={receipt.data.quantity} onChange={(value) => receipt.setData('quantity', value)} />
                        <Field label="Unit Cost" type="number" value={receipt.data.unit_cost} onChange={(value) => receipt.setData('unit_cost', value)} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Source of Goods" suggestions={libraryOptions.source_of_goods} value={receipt.data.source_of_goods} onChange={(value) => receipt.setData('source_of_goods', value)} />
                        <Field label="Sender / Supplier" suggestions={libraryOptions.supplier_sender} value={receipt.data.sender_supplier} onChange={(value) => receipt.setData('sender_supplier', value)} />
                    </div>
                    <Field label="DR / Reference" value={receipt.data.reference_number} onChange={(value) => receipt.setData('reference_number', value)} />
                    <Field label="Remarks" value={receipt.data.remarks} onChange={(value) => receipt.setData('remarks', value)} />
                    <button disabled={receipt.processing} className="w-full rounded-md bg-brand-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-brand-700 disabled:opacity-70">{receipt.processing ? 'Recording...' : 'Record Receipt'}</button>
                </form>
            </TransactionModal>}

            {canManageInventory && <TransactionModal open={activeModal === 'release'} title="Release Items" tone="coral" onClose={() => setActiveModal(null)}>
                <form className="space-y-4" onSubmit={submitRelease}>
                    <SearchableSelect label="Stock Batch *" options={batchOptions} value={release.data.inventory_batch_id} onChange={(value) => release.setData('inventory_batch_id', value)} placeholder="Search stock batch..." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Issuance Date *" type="date" value={release.data.transaction_date} onChange={(value) => release.setData('transaction_date', value)} />
                        <Field label="Issuance Quantity *" type="number" value={release.data.quantity} onChange={(value) => release.setData('quantity', value)} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Recipient *" suggestions={libraryOptions.recipient_requesting_party} value={release.data.recipient} onChange={(value) => release.setData('recipient', value)} />
                        <Field label="Purpose" suggestions={libraryOptions.transaction_purpose} value={release.data.purpose} onChange={(value) => release.setData('purpose', value)} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="RIS / IF / STF" value={release.data.ris_if_stf} onChange={(value) => release.setData('ris_if_stf', value)} />
                        <Field label="DR / Reference" value={release.data.reference_number} onChange={(value) => release.setData('reference_number', value)} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Delivery Site" suggestions={libraryOptions.delivery_site} value={release.data.delivery_site} onChange={(value) => release.setData('delivery_site', value)} />
                        <Field label="Expected Delivery Date" type="date" value={release.data.expected_delivery_date} onChange={(value) => release.setData('expected_delivery_date', value)} />
                    </div>
                    <div className="rounded-md border border-slate-200 p-3 dark:border-zinc-700">
                        <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Transportation Details</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-3">
                            <Field label="Land Type" suggestions={libraryOptions.vehicle_type} value={release.data.land_transportation_type} onChange={(value) => release.setData('land_transportation_type', value)} />
                            <Field label="Land Source" suggestions={libraryOptions.transportation_source} value={release.data.land_transportation_source} onChange={(value) => release.setData('land_transportation_source', value)} />
                            <Field label="Plate" value={release.data.plate} onChange={(value) => release.setData('plate', value)} />
                            <Field label="Driver" value={release.data.driver} onChange={(value) => release.setData('driver', value)} />
                            <Field label="Contact Number" value={release.data.contact_number} onChange={(value) => release.setData('contact_number', value)} />
                            <Field label="Sea Type" suggestions={libraryOptions.vehicle_type} value={release.data.sea_transportation_type} onChange={(value) => release.setData('sea_transportation_type', value)} />
                            <Field label="Sea Source" suggestions={libraryOptions.transportation_source} value={release.data.sea_transportation_source} onChange={(value) => release.setData('sea_transportation_source', value)} />
                            <Field label="Sea Details" value={release.data.sea_transportation_details} onChange={(value) => release.setData('sea_transportation_details', value)} />
                            <Field label="Air Type" suggestions={libraryOptions.vehicle_type} value={release.data.air_transportation_type} onChange={(value) => release.setData('air_transportation_type', value)} />
                            <Field label="Air Source" suggestions={libraryOptions.transportation_source} value={release.data.air_transportation_source} onChange={(value) => release.setData('air_transportation_source', value)} />
                            <Field label="Air Details" value={release.data.air_transportation_details} onChange={(value) => release.setData('air_transportation_details', value)} />
                        </div>
                    </div>
                    <Field label="Remarks" value={release.data.remarks} onChange={(value) => release.setData('remarks', value)} />
                    <button disabled={release.processing} className="w-full rounded-md bg-signal-coral px-4 py-2.5 text-sm font-black text-white shadow-sm hover:opacity-90 disabled:opacity-70">{release.processing ? 'Recording...' : 'Record Issuance'}</button>
                </form>
            </TransactionModal>}
            {detailRow && <InventoryDetailModal row={detailRow} onClose={() => setDetailRow(null)} />}
            {activeCategory && (
                <CategorySummaryModal
                    category={activeCategory}
                    rows={balanceRows.filter((row) => activeCategory === 'Indirect & Raw Materials'
                        ? ['Indirect Materials', 'Raw Materials'].includes(row.category)
                        : row.category === activeCategory)}
                    referenceRows={categoryReferenceRows.filter((row) => activeCategory === 'Indirect & Raw Materials'
                        ? ['Indirect Materials', 'Raw Materials'].includes(row.category)
                        : row.category === activeCategory)}
                    onClose={() => setActiveCategory(null)}
                />
            )}
        </AppLayout>
    );
}

function InventoryDetailModal({ row, onClose }) {
    const details = row.type === 'category'
        ? [
            ['Category', row.category],
            ['Item', row.item],
            ['Brand / Description', row.brand_description || '-'],
            ['Inventory Rows', number(row.rows)],
            ['Current Stockpile', number(row.stockpile)],
            ['Cost', money(row.cost)],
            ['Share of Current View', `${Number(row.percent || 0).toFixed(1)}%`],
        ]
        : [
            ['Warehouse', row.warehouse],
            ['Partnership', row.partnership || '-'],
            ['Category', row.category],
            ['Item', row.item],
            ['Unit of Measurement', row.uom || '-'],
            ['Brand / Description', row.brand_description || '-'],
            ['Expiry', row.expiry || 'N/A'],
            ['Current Stockpile', number(row.current_balance)],
            ['Cost', money(row.cost)],
        ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div className="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-4 dark:border-zinc-800">
                    <div>
                        <p className="text-[11px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Inventory Details</p>
                        <h2 className="mt-1 text-lg font-black">{row.type === 'category' ? row.category : row.item}</h2>
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

function StickySummaryRow({ colSpan, values }) {
    return (
        <tr className="border-l-4 border-slate-500">
            <td colSpan={colSpan} className="sticky bottom-0 z-20 border-t border-slate-300 bg-slate-50 px-4 py-3 font-black shadow-[0_-6px_14px_rgba(15,23,42,0.08)] dark:border-zinc-700 dark:bg-zinc-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span>Grand Total</span>
                    <span className="inline-flex flex-wrap items-center gap-x-5 gap-y-1 text-right text-sm">
                        {values.map(([label, value]) => <span key={label} title={label}>{value}</span>)}
                    </span>
                </div>
            </td>
        </tr>
    );
}

function CategoryMetric({ category, color, percent, onOpen }) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="group relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-brand-600"
        >
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full opacity-10" style={{ backgroundColor: color }} />
            <div className="relative flex min-h-[9rem] flex-col justify-between gap-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 pr-1">
                        <h3 className="text-base font-black leading-tight sm:text-lg" title={category.category}>{category.category}</h3>
                    </div>
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" style={{ color }}>
                        <Boxes className="h-5 w-5" />
                    </span>
                </div>
                <div>
                    <div className="flex items-end justify-between gap-3">
                        <div>
                            <p className="text-3xl font-black tracking-normal text-slate-950 dark:text-white">{number(category.stockpile)}</p>
                        </div>
                        <div className="text-right">
                            <p className="text-sm font-black text-slate-700 dark:text-zinc-200">{money(category.cost)}</p>
                            <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{number(category.rows)} rows</p>
                        </div>
                    </div>
                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                        <div className="h-full rounded-full transition group-hover:brightness-95" style={{ width: `${Math.min(100, Math.max(2, percent))}%`, backgroundColor: color }} />
                    </div>
                    <p className="mt-2 text-xs font-black uppercase tracking-wide text-brand-700 opacity-0 transition group-hover:opacity-100 dark:text-brand-200">Open summary</p>
                </div>
            </div>
        </button>
    );
}

function CategorySummaryModal({ category, rows, referenceRows = [], onClose }) {
    const isFfp = category === 'Family Food Packs';
    const modalRows = useMemo(() => {
        const keyed = new Map();
        const makeKey = (row) => [
            row.warehouse_id || '',
            row.category || '',
            row.item || '',
            row.brand_description || '',
            row.expiry || '',
        ].join('|');

        rows.forEach((row) => {
            keyed.set(makeKey(row), row);
        });

        referenceRows.forEach((row) => {
            const key = makeKey(row);

            if (!keyed.has(key)) {
                keyed.set(key, {
                    ...row,
                    current_balance: 0,
                    available_balance: 0,
                    reserved_quantity: 0,
                    cost: 0,
                });
            }
        });

        return Array.from(keyed.values());
    }, [rows, referenceRows]);
    const [modalFilters, setModalFilters] = useState({
        warehouse: [],
        warehouse_type: [],
        ffp_status: [],
        province: [],
        district: [],
        municipality: [],
        partnership: [],
    });
    const totalStockpile = rows.reduce((sum, row) => sum + Number(row.current_balance || 0), 0);
    const totalCost = rows.reduce((sum, row) => sum + Number(row.cost || 0), 0);
    const groupedRows = useMemo(() => {
        const groups = rows.reduce((acc, row) => {
            const key = isFfp
                ? String(row.warehouse_id || row.warehouse || 'Unspecified')
                : `${row.item || '-'}|${row.brand_description || '-'}|${row.warehouse || '-'}`;

            acc[key] ??= isFfp
                ? {
                    warehouse: row.warehouse || 'Unspecified',
                    warehouse_type: row.warehouse_type || '-',
                    ffp_status: '-',
                    province: row.warehouse_province || '-',
                    district: row.warehouse_district || '-',
                    municipality: row.warehouse_municipality || '-',
                    partnership: row.partnership || '-',
                    stockpile: 0,
                    cost: 0,
                    capacity: Number(row.warehouse_ffp_capacity || 0),
                    variance: 0,
                    rows: 0,
                }
                : { label: row.item || '-', brand: row.brand_description || '-', warehouse: row.warehouse || '-', stockpile: 0, cost: 0, rows: 0 };

            acc[key].stockpile += Number(row.current_balance || 0);
            acc[key].cost += Number(row.cost || 0);
            acc[key].capacity = Math.max(Number(acc[key].capacity || 0), Number(row.warehouse_ffp_capacity || 0));
            acc[key].variance = Math.max(0, Number(acc[key].capacity || 0) - Number(acc[key].stockpile || 0));
            acc[key].ffp_status = ffpStatus(acc[key].stockpile, acc[key].capacity);
            acc[key].rows += 1;

            return acc;
        }, {});

        return Object.values(groups).sort((a, b) => {
            if (isFfp) {
                return a.province.localeCompare(b.province) || a.district.localeCompare(b.district) || a.municipality.localeCompare(b.municipality) || a.warehouse.localeCompare(b.warehouse);
            }

            return b.stockpile - a.stockpile;
        });
    }, [rows, isFfp]);
    const selectedIncludes = (selected, value) => !selected?.length || selected.includes(String(value));
    const filteredFfpRows = useMemo(() => groupedRows.filter((row) => (
        selectedIncludes(modalFilters.warehouse, row.warehouse)
        && selectedIncludes(modalFilters.warehouse_type, row.warehouse_type)
        && selectedIncludes(modalFilters.ffp_status, row.ffp_status)
        && selectedIncludes(modalFilters.province, row.province)
        && selectedIncludes(modalFilters.district, row.district)
        && selectedIncludes(modalFilters.municipality, row.municipality)
    )), [groupedRows, modalFilters]);
    const ffpTotals = useMemo(() => ({
        capacity: filteredFfpRows.reduce((sum, row) => sum + Number(row.capacity || 0), 0),
        stockpile: filteredFfpRows.reduce((sum, row) => sum + Number(row.stockpile || 0), 0),
        cost: filteredFfpRows.reduce((sum, row) => sum + Number(row.cost || 0), 0),
        warehouses: filteredFfpRows.filter((row) => Number(row.stockpile || 0) > 0).length,
    }), [filteredFfpRows]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
            <div className="flex max-h-[94vh] w-full max-w-[96rem] flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <div className="min-w-0">
                        <h2 className="text-xl font-black">{isFfp ? 'Family Food Packs Per Warehouse' : category === 'Food Items' ? 'Food Items Per Warehouse' : categoryModalConfig(category).title}</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    {isFfp ? (
                        <FamilyFoodPackWarehouseView
                            rows={filteredFfpRows}
                            allRows={groupedRows}
                            totals={ffpTotals}
                            filters={modalFilters}
                            setFilters={setModalFilters}
                        />
                    ) : (
                        <CategoryWarehouseView
                            category={category}
                            rows={modalRows}
                            totalStockpile={totalStockpile}
                            totalCost={totalCost}
                            filters={modalFilters}
                            setFilters={setModalFilters}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

function FamilyFoodPackWarehouseView({ rows, allRows, totals, filters, setFilters }) {
    const setFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const optionValues = (key) => [...new Set(allRows.map((row) => row[key]).filter(Boolean))].sort((a, b) => String(a).localeCompare(String(b)))
        .map((value) => ({ value, label: value }));

    return (
        <div className="space-y-5">
            <div className="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
                <div className="space-y-4">
                    <div className="flex min-h-[215px] items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <img src="/images/ffps.png" alt="Family Food Packs" className="max-h-[205px] w-full object-contain" />
                    </div>
                </div>

                <div className="space-y-4">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <LookerMultiSelect label="Warehouse Name" allLabel="All Warehouse Name" options={optionValues('warehouse')} value={filters.warehouse} onApply={(value) => setFilter('warehouse', value)} placeholder="Search warehouse..." />
                        <LookerMultiSelect label="Warehouse Type" allLabel="All Warehouse Type" options={optionValues('warehouse_type')} value={filters.warehouse_type} onApply={(value) => setFilter('warehouse_type', value)} placeholder="Search warehouse type..." />
                        <LookerMultiSelect label="FFPs Status" allLabel="All FFPs Status" options={optionValues('ffp_status')} value={filters.ffp_status} onApply={(value) => setFilter('ffp_status', value)} placeholder="Search status..." />
                        <LookerMultiSelect label="Province" allLabel="All Province" options={optionValues('province')} value={filters.province} onApply={(value) => setFilter('province', value)} placeholder="Search province..." />
                        <LookerMultiSelect label="District" allLabel="All District" options={optionValues('district')} value={filters.district} onApply={(value) => setFilter('district', value)} placeholder="Search district..." />
                        <LookerMultiSelect label="City/Municipality" allLabel="All City/Municipality" options={optionValues('municipality')} value={filters.municipality} onApply={(value) => setFilter('municipality', value)} placeholder="Search city or municipality..." />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <BlueMetric title="FFP Full Capacity" value={number(totals.capacity)} />
                        <BlueMetric title="No. of Food Packs" value={number(totals.stockpile)} />
                        <BlueMetric title="No. of WHs w/ FFPs" value={number(totals.warehouses)} />
                    </div>
                </div>
            </div>

            <div className="max-h-[calc(94vh-25rem)] min-h-[360px] overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
                <table className="w-full min-w-[1180px] text-sm">
                    <thead className="sticky top-0 z-10 bg-slate-200 text-left text-xs uppercase tracking-wide text-slate-700 dark:bg-zinc-800 dark:text-zinc-300">
                        <tr>
                            <th className="px-3 py-2 text-right">#</th>
                            <th className="px-3 py-2">FFPs Status</th>
                            <th className="px-3 py-2">Province</th>
                            <th className="px-3 py-2">City/Municipality</th>
                            <th className="px-3 py-2">Warehouse</th>
                            <th className="px-3 py-2 text-right">Current</th>
                            <th className="px-3 py-2 text-right">Total Cost</th>
                            <th className="px-3 py-2 text-right">Average Cost</th>
                            <th className="px-3 py-2 text-right">Capacity</th>
                            <th className="px-3 py-2 text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => (
                            <tr key={`${row.warehouse}-${row.province}-${row.municipality}`} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                <td className="px-3 py-2 text-right text-slate-500">{index + 1}.</td>
                                <td className="px-3 py-2 font-semibold uppercase">{row.ffp_status}</td>
                                <td className="px-3 py-2">{row.province}</td>
                                <td className="px-3 py-2">
                                    <div className="font-semibold">{row.municipality}</div>
                                    <div className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.district}</div>
                                </td>
                                <td className="px-3 py-2">
                                    <div className="font-black">{row.warehouse}</div>
                                    <div className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership}</div>
                                </td>
                                <td className={`px-3 py-2 text-right font-black ${Number(row.stockpile) > 0 ? 'bg-amber-50 dark:bg-amber-950/20' : ''}`}>{number(row.stockpile)}</td>
                                <td className="px-3 py-2 text-right">{money(row.cost)}</td>
                                <td className="px-3 py-2 text-right">{Number(row.stockpile) > 0 ? money(Number(row.cost) / Number(row.stockpile)) : '-'}</td>
                                <td className="px-3 py-2 text-right">{number(row.capacity)}</td>
                                <td className="px-3 py-2 text-right">{number(row.variance)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="sticky bottom-0 border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                            <td colSpan={5} className="px-3 py-3">Grand total</td>
                            <td className="px-3 py-3 text-right">{number(totals.stockpile)}</td>
                            <td className="px-3 py-3 text-right">{money(totals.cost)}</td>
                            <td className="px-3 py-3 text-right">{Number(totals.stockpile) > 0 ? money(Number(totals.cost) / Number(totals.stockpile)) : '-'}</td>
                            <td className="px-3 py-3 text-right">{number(totals.capacity)}</td>
                            <td className="px-3 py-3 text-right">{number(Math.max(0, totals.capacity - totals.stockpile))}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function CategoryWarehouseView({ category, rows, totalStockpile, totalCost, filters, setFilters }) {
    const [foodTab, setFoodTab] = useState('rtef');
    const [materialTab, setMaterialTab] = useState('indirect');
    const setFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const optionValues = (key, sourceRows) => [...new Set(sourceRows.map((row) => row[key]).filter(Boolean))]
        .sort((a, b) => String(a).localeCompare(String(b)))
        .map((value) => ({ value, label: value }));
    const selectedIncludes = (selected, value) => !selected?.length || selected.includes(String(value));
    const isFoodCategory = category === 'Food Items';
    const isMaterialCategory = ['Indirect & Raw Materials', 'Indirect Materials', 'Raw Materials'].includes(category);
    const isWaterTab = isFoodCategory && foodTab === 'water';
    const isRawMaterialRow = (row) => {
        const item = String(row.item || '').trim().toLowerCase();
        return row.category === 'Raw Materials' || item === 'rice';
    };
    const itemColumnLabel = (row) => {
        if (isWaterTab) {
            const brand = cleanBrand(row.brand_description);
            return brand !== '-' ? brand : row.item || '-';
        }

        return row.item || '-';
    };
    const activeRows = useMemo(() => {
        if (isFoodCategory) {
            return rows.filter((row) => {
                const value = `${row.item || ''} ${row.brand_description || ''}`.toLowerCase();
                const isWater = value.includes('water') || value.includes('bottled');

                return foodTab === 'water' ? isWater : !isWater;
            });
        }

        if (isMaterialCategory) {
            return rows.filter((row) => (materialTab === 'raw' ? isRawMaterialRow(row) : !isRawMaterialRow(row)));
        }

        return rows;
    }, [rows, isFoodCategory, foodTab, isMaterialCategory, materialTab]);
    const config = categoryModalConfig(category, isFoodCategory ? foodTab : materialTab);

    const warehouseRows = useMemo(() => {
        const groups = activeRows.reduce((acc, row) => {
            if (!row.warehouse_id && !row.warehouse && Number(row.current_balance || 0) === 0 && Number(row.cost || 0) === 0) {
                return acc;
            }

            const warehouseKey = String(row.warehouse_id || row.warehouse || 'Unspecified');
            const itemName = row.item || '-';
            const brand = cleanBrand(row.brand_description);
            const itemKey = itemColumnLabel(row);

            acc[warehouseKey] ??= {
                province: row.warehouse_province || '-',
                district: row.warehouse_district || '-',
                municipality: row.warehouse_municipality || '-',
                warehouse: row.warehouse || 'Unspecified',
                warehouse_type: row.warehouse_type || '-',
                partnership: row.partnership || '-',
                stockpile: 0,
                cost: 0,
                capacity: 0,
                items: {},
            };

            acc[warehouseKey].stockpile += Number(row.current_balance || 0);
            acc[warehouseKey].cost += Number(row.cost || 0);
            acc[warehouseKey].capacity = Math.max(Number(acc[warehouseKey].capacity || 0), Number(config.capacityKey ? row[config.capacityKey] : 0));
            acc[warehouseKey].items[itemKey] ??= { item: itemKey, sourceItem: itemName, brand, stockpile: 0, cost: 0 };
            acc[warehouseKey].items[itemKey].stockpile += Number(row.current_balance || 0);
            acc[warehouseKey].items[itemKey].cost += Number(row.cost || 0);

            return acc;
        }, {});

        return Object.values(groups).map((group) => {
            group.stockpile = Math.max(0, Number(group.stockpile || 0));
            group.cost = Math.max(0, Number(group.cost || 0));

            Object.values(group.items).forEach((item) => {
                item.stockpile = Math.max(0, Number(item.stockpile || 0));
                item.cost = Math.max(0, Number(item.cost || 0));
            });

            return group;
        }).sort((a, b) => (
            a.province.localeCompare(b.province)
            || a.district.localeCompare(b.district)
            || a.municipality.localeCompare(b.municipality)
            || a.warehouse.localeCompare(b.warehouse)
        ));
    }, [activeRows, config.capacityKey, isWaterTab]);

    const itemLabels = useMemo(() => {
        const labels = [...new Set(activeRows.map((row) => itemColumnLabel(row)).filter(Boolean))];

        return labels.sort((a, b) => {
            const waterOrder = bottledWaterSort(a) - bottledWaterSort(b);
            if (isWaterTab && waterOrder !== 0) {
                return waterOrder;
            }

            return String(a).localeCompare(String(b));
        });
    }, [activeRows, isWaterTab]);

    const filteredRows = useMemo(() => warehouseRows.filter((row) => (
        selectedIncludes(filters.warehouse, row.warehouse)
        && selectedIncludes(filters.warehouse_type, row.warehouse_type)
        && selectedIncludes(filters.province, row.province)
        && selectedIncludes(filters.district, row.district)
        && selectedIncludes(filters.municipality, row.municipality)
        && selectedIncludes(filters.partnership, row.partnership)
    )), [warehouseRows, filters]);

    const itemTotals = useMemo(() => {
        const totals = {};

        filteredRows.forEach((warehouse) => {
            Object.values(warehouse.items).forEach((item) => {
                totals[item.item] ??= { item: item.item, brand: item.brand, stockpile: 0, cost: 0 };
                totals[item.item].stockpile += Math.max(0, Number(item.stockpile || 0));
                totals[item.item].cost += Math.max(0, Number(item.cost || 0));
            });
        });

        return Object.values(totals).sort((a, b) => {
            const waterOrder = bottledWaterSort(a.item) - bottledWaterSort(b.item);
            if (isWaterTab && waterOrder !== 0) {
                return waterOrder;
            }

            return b.stockpile - a.stockpile || a.item.localeCompare(b.item);
        });
    }, [filteredRows]);

    const activeItems = itemTotals.filter((item) => Number(item.stockpile || 0) > 0 || Number(item.cost || 0) > 0);
    const displayItems = activeItems.length
        ? activeItems
        : itemLabels.map((label) => itemTotals.find((item) => item.item === label) ?? { item: label, brand: '-', stockpile: 0, cost: 0 });
    const cardItems = activeItems;
    const filteredTotals = {
        stockpile: filteredRows.reduce((sum, row) => sum + Math.max(0, Number(row.stockpile || 0)), 0),
        cost: filteredRows.reduce((sum, row) => sum + Math.max(0, Number(row.cost || 0)), 0),
        capacity: filteredRows.reduce((sum, row) => sum + Number(row.capacity || 0), 0),
        warehouses: filteredRows.filter((row) => Number(row.stockpile || 0) > 0).length,
    };
    const showCapacity = Boolean(config.capacityKey);
    const useItemColumns = !isFoodCategory || isWaterTab;
    const showVarianceColumn = isFoodCategory && foodTab === 'rtef' && showCapacity;
    const varianceTotal = Math.max(0, Number(filteredTotals.capacity || 0) - Number(filteredTotals.stockpile || 0));

    return (
        <div className="space-y-5">
            {isFoodCategory && (
                <div className="rounded-md bg-slate-100 p-1 dark:bg-zinc-900">
                    <div className="grid gap-1 sm:grid-cols-2">
                        {[
                            { key: 'rtef', label: 'Ready-to-Eat Food' },
                            { key: 'water', label: 'Bottled Water' },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setFoodTab(tab.key)}
                                className={`rounded-md px-4 py-3 text-sm font-black transition ${
                                    foodTab === tab.key
                                        ? 'bg-white text-brand-700 shadow-sm dark:bg-zinc-800 dark:text-brand-200'
                                        : 'text-slate-600 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-zinc-800/70'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {isMaterialCategory && (
                <div className="rounded-md bg-slate-100 p-1 dark:bg-zinc-900">
                    <div className="grid gap-1 sm:grid-cols-2">
                        {[
                            { key: 'indirect', label: 'Indirect Materials' },
                            { key: 'raw', label: 'Raw Materials' },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setMaterialTab(tab.key)}
                                className={`rounded-md px-4 py-3 text-sm font-black transition ${
                                    materialTab === tab.key
                                        ? 'bg-white text-brand-700 shadow-sm dark:bg-zinc-800 dark:text-brand-200'
                                        : 'text-slate-600 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-zinc-800/70'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className={`grid gap-5 ${config.hero ? 'xl:grid-cols-[280px_minmax(0,1fr)]' : ''}`}>
                {config.hero && (
                    <div className="space-y-4">
                        <div className="flex min-h-[215px] items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <CategoryHeroImage config={config} />
                        </div>
                    </div>
                )}

                <div className="space-y-4">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                        <LookerMultiSelect label="Warehouse Name" allLabel="All Warehouse Name" options={optionValues('warehouse', warehouseRows)} value={filters.warehouse} onApply={(value) => setFilter('warehouse', value)} placeholder="Search warehouse..." />
                        <LookerMultiSelect label="Warehouse Type" allLabel="All Warehouse Type" options={optionValues('warehouse_type', warehouseRows)} value={filters.warehouse_type} onApply={(value) => setFilter('warehouse_type', value)} placeholder="Search warehouse type..." />
                        <LookerMultiSelect label="Partnership" allLabel="All Partnership" options={optionValues('partnership', warehouseRows)} value={filters.partnership} onApply={(value) => setFilter('partnership', value)} placeholder="Search partnership..." />
                        <LookerMultiSelect label="Province" allLabel="All Province" options={optionValues('province', warehouseRows)} value={filters.province} onApply={(value) => setFilter('province', value)} placeholder="Search province..." />
                        <LookerMultiSelect label="City/Municipality" allLabel="All City/Municipality" options={optionValues('municipality', warehouseRows)} value={filters.municipality} onApply={(value) => setFilter('municipality', value)} placeholder="Search city or municipality..." />
                        <LookerMultiSelect label="District" allLabel="All District" options={optionValues('district', warehouseRows)} value={filters.district} onApply={(value) => setFilter('district', value)} placeholder="Search district..." />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <BlueMetric title={config.primaryMetric} value={number(filteredTotals.stockpile)} />
                        <BlueMetric title="Total Cost" value={money(filteredTotals.cost)} />
                        <BlueMetric title={config.warehouseMetric} value={number(filteredTotals.warehouses)} />
                        {showCapacity && <BlueMetric title="Capacity" value={number(filteredTotals.capacity)} />}
                    </div>
                </div>
            </div>

            {!isFoodCategory && cardItems.length > 0 && (
                <div className={isMaterialCategory ? 'flex gap-3 overflow-x-auto pb-2' : 'grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6'}>
                    {cardItems.map((item, index) => (
                        <ItemStatTile
                            key={item.item}
                            item={item}
                            color={categoryColors[index % categoryColors.length]}
                            noWrapLabel={isMaterialCategory}
                        />
                    ))}
                </div>
            )}

            <div className="max-h-[calc(94vh-28rem)] min-h-[330px] overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
                <table className="w-full min-w-[980px] text-sm">
                    <thead className="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <tr>
                            <th className="px-3 py-2 text-right">#</th>
                            <th className="px-3 py-2">Province</th>
                            <th className="px-3 py-2">City/Municipality</th>
                            <th className="px-3 py-2">Warehouse</th>
                            {!useItemColumns ? (
                                <th className="px-3 py-2 text-right">Current</th>
                            ) : displayItems.map((item) => (
                                <th key={item.item} className="px-3 py-2 text-right">{item.item}</th>
                            ))}
                            <th className="px-3 py-2 text-right">Total Cost</th>
                            {showCapacity && <th className="px-3 py-2 text-right">Capacity</th>}
                            {showVarianceColumn && <th className="px-3 py-2 text-right">Variance</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {filteredRows.map((row, index) => (
                            <tr key={`${row.warehouse}-${row.province}-${row.municipality}`} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                <td className="px-3 py-2 text-right text-slate-500">{index + 1}.</td>
                                <td className="px-3 py-2">{row.province}</td>
                                <td className="px-3 py-2">
                                    <div className="font-semibold">{row.municipality}</div>
                                    <div className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.district}</div>
                                </td>
                                <td className="px-3 py-2">
                                    <div className="font-black">{row.warehouse}</div>
                                    <div className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{row.partnership}</div>
                                </td>
                                {!useItemColumns ? (
                                    <td className="px-3 py-2 text-right font-black">{number(Math.max(0, Number(row.stockpile || 0)))}</td>
                                ) : displayItems.map((item) => (
                                    <td key={item.item} className="px-3 py-2 text-right font-semibold">
                                        {Number(row.items[item.item]?.stockpile || 0) > 0 ? number(Math.max(0, Number(row.items[item.item].stockpile || 0))) : '0'}
                                    </td>
                                ))}
                                <td className="px-3 py-2 text-right font-black">{money(Math.max(0, Number(row.cost || 0)))}</td>
                                {showCapacity && <td className="px-3 py-2 text-right font-semibold">{number(row.capacity)}</td>}
                                {showVarianceColumn && <td className="px-3 py-2 text-right font-semibold">{number(Math.max(0, Number(row.capacity || 0) - Math.max(0, Number(row.stockpile || 0))))}</td>}
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="sticky bottom-0 border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                            <td colSpan={4} className="px-3 py-3">Grand total</td>
                            {!useItemColumns ? (
                                <td className="px-3 py-3 text-right">{number(filteredTotals.stockpile)}</td>
                            ) : displayItems.map((item) => (
                                <td key={item.item} className="px-3 py-3 text-right">{number(item.stockpile)}</td>
                            ))}
                            <td className="px-3 py-3 text-right">{money(filteredTotals.cost)}</td>
                            {showCapacity && <td className="px-3 py-3 text-right">{number(filteredTotals.capacity)}</td>}
                            {showVarianceColumn && <td className="px-3 py-3 text-right">{number(varianceTotal)}</td>}
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function CategoryHeroImage({ config }) {
    if (config.hero) {
        return <img src={config.hero} alt={config.title} className="max-h-[205px] w-full object-contain" />;
    }

    return null;
}

function ItemStatTile({ item, color = '#2f7d65', noWrapLabel = false }) {
    const image = itemImage(item.item);

    return (
        <div className={`group rounded-lg border border-slate-200 bg-transparent p-4 text-center shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md dark:border-zinc-800 dark:hover:border-brand-800 ${noWrapLabel ? 'min-w-56' : ''}`}>
            <div className="mx-auto flex h-24 w-full max-w-36 items-center justify-center overflow-hidden">
                {image ? (
                    <img src={image} alt={item.item} className="max-h-full w-full object-contain" />
                ) : (
                    <div className="flex h-20 w-20 items-center justify-center rounded-full border border-slate-200 text-center text-xs font-black uppercase leading-tight text-slate-700 dark:border-zinc-700 dark:text-zinc-200">
                        {String(item.item).split(/\s+/).slice(0, 2).map((word) => word[0]).join('')}
                    </div>
                )}
            </div>
            <p
                className={`mt-3 min-h-10 text-sm font-black leading-tight text-slate-950 dark:text-white ${noWrapLabel ? 'whitespace-nowrap text-xs' : ''}`}
                title={item.item}
            >
                {item.item}
            </p>
            <p className="mt-1 text-3xl font-normal tracking-normal">{number(item.stockpile)}</p>
            <div className="mx-auto mt-3 h-1.5 w-16 rounded-full transition group-hover:w-24" style={{ backgroundColor: color }} />
        </div>
    );
}

function categoryModalConfig(category, variant = 'rtef') {
    if (category === 'Food Items') {
        if (variant === 'water') {
            return {
                title: 'Food Items: Bottled Water Per Warehouse',
                primaryMetric: 'Total Bottled Water',
                warehouseMetric: 'No. of WHs w/ Bottled Water',
                hero: '/images/bottled-water.png',
                capacityKey: null,
            };
        }

        return {
            title: 'Food Items: Ready-to-Eat Foods Per Warehouse',
            primaryMetric: 'No. of Ready-to-Eat Foods',
            warehouseMetric: 'No. of WHs w/ RTEFs',
            hero: '/images/ready-to-eat.png',
            capacityKey: 'warehouse_rtef_capacity',
        };
    }

    if (category === 'Non Food Items') {
        return {
            title: 'Non-Food Items Per Warehouse',
            primaryMetric: 'Total Non-Food Items',
            warehouseMetric: 'No. of WHs w/ Non-Food Items',
            hero: null,
        };
    }

    if (category === 'Other NFIs') {
        return {
            title: 'Other Non-Food Items Per Warehouse',
            primaryMetric: 'Total Other NFIs',
            warehouseMetric: 'No. of WHs w/ Other NFIs',
            hero: null,
        };
    }

    if (category === 'Indirect & Raw Materials' || category === 'Indirect Materials' || category === 'Raw Materials') {
        if (variant === 'raw') {
            return {
                title: 'Indirect & Raw Materials',
                primaryMetric: 'Total Raw Materials',
                warehouseMetric: 'No. of WHs w/ Raw Materials',
                hero: '/images/rice-nfa-3kls-lp.png',
            };
        }

        return {
            title: 'Indirect & Raw Materials',
            primaryMetric: 'Total Indirect Materials',
            warehouseMetric: 'No. of WHs w/ Indirect Materials',
            hero: '/images/regular-slotted-carton.png',
        };
    }

    return {
        title: `${category} Per Warehouse`,
        primaryMetric: 'Total Items',
        warehouseMetric: 'No. of WHs w/ Items',
        hero: null,
    };
}

function itemImage(item) {
    const normalized = String(item || '').toLowerCase();

    if (normalized.includes('water') || normalized.includes('bottled')) return '/images/bottled-water.png';
    if (normalized.includes('ready to eat')) return '/images/ready-to-eat.png';
    if (normalized.includes('family clothing')) return '/images/family-clothing-kit.png';
    if (normalized.includes('hygiene')) return '/images/hygiene-kit.png';
    if (normalized.includes('kitchen')) return '/images/kitchen-kit.png';
    if (normalized.includes('sleeping')) return '/images/sleeping-kit.png';
    if (normalized.includes('modular tent')) return '/images/modular-tent.png';
    if (normalized.includes('family tent')) return '/images/family-tent.png';
    if (normalized.includes('laminated sack')) return '/images/laminated-sack-pre-cut.png';
    if (normalized.includes('tarpaulin')) return '/images/tarpaulin-roll.jpg';
    if (normalized.includes('plastic twine')) return '/images/plastic-twine.png';
    if (normalized.includes('packaging tape')) return '/images/packaging-tape-2x100m.png';
    if (normalized.includes('regular slotted carton')) return '/images/regular-slotted-carton.png';
    if (normalized.includes('rice bag')) return '/images/rice-bag-3-kilo-vacuum-plastic.png';
    if (normalized === 'rice' || normalized.includes('nfa')) return '/images/rice-nfa-3kls-lp.png';
    if (normalized.includes('food pack')) return '/images/ffps.png';

    return null;
}

function cleanBrand(value) {
    const brand = String(value || '').trim();
    return brand && brand !== 'Unspecified' ? brand : '-';
}

function bottledWaterSort(value) {
    const normalized = String(value || '').toLowerCase();

    if (normalized.includes('10l')) return 10;
    if (normalized.includes('6l')) return 20;
    if (normalized.includes('4l')) return 30;
    if (normalized.includes('1l')) return 40;

    return 999;
}

function BlueMetric({ title, value }) {
    return (
        <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="absolute -right-8 -top-10 h-24 w-24 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <p className="relative text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="relative mt-2 text-2xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
        </div>
    );
}

function ffpStatus(current, capacity) {
    const currentValue = Number(current || 0);
    const capacityValue = Number(capacity || 0);

    if (capacityValue <= 0 && currentValue <= 0) {
        return '-';
    }

    if (currentValue <= 0 && capacityValue > 0) {
        return 'SEVERE';
    }

    return 'ADEQUATE';
}

function SummaryMetric({ title, value }) {
    return (
        <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="absolute -right-8 -top-10 h-24 w-24 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <p className="relative text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="relative mt-2 text-2xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
        </div>
    );
}

function GenericCategoryTable({ rows, totalStockpile, totalCost }) {
    return (
        <div className="mt-5 max-h-[calc(94vh-18rem)] overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="w-full min-w-[760px] text-sm">
                <thead className="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <tr>
                        <th className="px-4 py-3">Item</th>
                        <th className="px-4 py-3">Brand / Description</th>
                        <th className="px-4 py-3">Warehouse</th>
                        <th className="px-4 py-3 text-right">Current Stockpile</th>
                        <th className="px-4 py-3 text-right">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={`${row.label}-${row.brand}-${row.warehouse}`} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                            <td className="px-4 py-3 font-black">{row.label}</td>
                            <td className="px-4 py-3 font-semibold text-slate-600 dark:text-zinc-300">{row.brand}</td>
                            <td className="px-4 py-3">{row.warehouse}</td>
                            <td className="px-4 py-3 text-right font-black">{number(row.stockpile)}</td>
                            <td className="px-4 py-3 text-right font-semibold">{money(row.cost)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="sticky bottom-0 border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                        <td colSpan={3} className="px-4 py-3">Grand total</td>
                        <td className="px-4 py-3 text-right">{number(totalStockpile)}</td>
                        <td className="px-4 py-3 text-right">{money(totalCost)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', suggestions = [] }) {
    const listId = `options-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    return (
        <label className="block text-sm font-bold">
            {label}
            <input className="mt-1 w-full" type={type} list={suggestions?.length ? listId : undefined} value={value} onChange={(event) => onChange(event.target.value)} />
            {suggestions?.length > 0 && <datalist id={listId}>{suggestions.map((option) => <option key={option} value={option} />)}</datalist>}
        </label>
    );
}

function TransactionModal({ open, title, children, onClose, tone }) {
    if (!open) {
        return null;
    }

    const accent = tone === 'coral' ? 'bg-signal-coral' : 'bg-brand-600';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div className="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className={`h-1.5 ${accent}`} />
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Inventory Transaction</p>
                        <h2 className="text-lg font-black">{title}</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-zinc-900 dark:hover:text-zinc-100">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="max-h-[calc(92vh-5.5rem)] overflow-y-auto p-5">
                    {children}
                </div>
            </div>
        </div>
    );
}
