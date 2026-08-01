import { Head } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Boxes, PackageMinus, PackagePlus, Warehouse } from 'lucide-react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';
import NearExpiryMonthSummary from '@/Components/NearExpiryMonthSummary';
import ExportButtons, { exportFilename } from '@/Components/ExportButtons';
import { formatDateTime } from '@/Utils/dateFormat';

const chartColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4'];
const chartHoverColors = ['#2563eb', '#ea580c', '#9333ea', '#82983f', '#0891b2'];
const ffpChartColors = ['#F6D88A', '#8FEF6A', '#B8E6C9', '#00BCEB', '#F4A07A'];
const ffpChartHoverColors = ['#F6D88A', '#8FEF6A', '#B8E6C9', '#00BCEB', '#F4A07A'];

export default function Index({
    dashboardTitle = 'Dashboard',
    metrics,
    recentTransactions,
    warehouseBreakdown,
    warehouseInventory,
    categoryBreakdown,
    familyFoodPackDashboard,
    foodItemSummaries = {},
    standbyStockpileSummary,
    warehouseSummary,
    nearExpirySummary = [],
}) {
    const warehouseTypeRows = objectRows(warehouseBreakdown?.type);
    const provinceRows = objectRows(warehouseBreakdown?.province);
    const partnershipRows = objectRows(warehouseBreakdown?.partnership);
    const categoryRows = objectRows(warehouseBreakdown?.category);
    const networkRows = objectRows(warehouseBreakdown?.distribution_network);
    const stockpileRows = objectRows(categoryBreakdown);
    const warehouseActivePercent = Number(metrics.warehouses ?? 0) > 0
        ? Math.round((Number(metrics.active_warehouses ?? 0) / Number(metrics.warehouses ?? 0)) * 100)
        : 0;

    return (
        <AppLayout title={dashboardTitle}>
            <Head title={dashboardTitle} />

            <div className="max-w-full overflow-x-hidden">
            <div id="dashboard-overview" className="scroll-mt-28 grid gap-4 xl:grid-cols-4">
                <HeroMetric
                    title="Warehouses"
                    value={metrics.warehouses}
                    icon={Warehouse}
                    subtext={`${warehouseActivePercent}% active`}
                    showExport={false}
                />
                <HeroMetric
                    title="Current Stockpile"
                    value={metrics.inventory_total}
                    icon={Boxes}
                    subtext={`Cost: ₱${formatCurrency(metrics.stockpile_cost)}`}
                    showExport={false}
                />
                <HeroMetric
                    title="Total Receipts"
                    value={metrics.total_items_received}
                    icon={PackagePlus}
                    subtext={`Cost: ₱${formatCurrency(metrics.total_items_received_cost)}`}
                    showExport={false}
                />
                <HeroMetric
                    title="Total Issuances"
                    value={metrics.total_releases}
                    icon={PackageMinus}
                    subtext={`Cost: ₱${formatCurrency(metrics.total_releases_cost)}`}
                    showExport={false}
                />
            </div>

            <section id="warehouse-summary" className="mt-6 scroll-mt-28">
                <WarehouseSummary summary={warehouseSummary} />
            </section>

            <section id="ffp-summary" className="mt-6 scroll-mt-28">
                <FamilyFoodPackReport data={familyFoodPackDashboard} />
            </section>

            <DashboardExportSection
                id="food-items-summary"
                title="Food Items Summary"
                description="Ready-to-eat food and bottled water stockpile by warehouse using current inventory records."
                className="mt-6"
            >
                <div className="grid min-w-0 gap-6 xl:grid-cols-2">
                <div id="rtef-summary" className="min-w-0">
                    <FoodItemWarehouseSummary
                        title="RTEF"
                        description=""
                        summary={foodItemSummaries?.rtef}
                    />
                </div>
                <div id="bottled-water-summary" className="min-w-0">
                    <FoodItemWarehouseSummary
                        title="Bottled Water"
                        description=""
                        summary={foodItemSummaries?.bottled_water}
                    />
                </div>
                </div>
            </DashboardExportSection>

            <section className="mt-6 grid min-w-0 gap-6">
                <DashboardExportSection
                    id="non-food-summary"
                    title="Non-Food Items Summary"
                    description="Non-food item stockpile grouped by item, brand/specification, available quantity, and cost."
                >
                    <FoodItemWarehouseSummary
                        title="Non-Food Items Summary"
                        description="Non-food item stockpile by warehouse."
                        summary={foodItemSummaries?.non_food_items}
                        showHeader={false}
                        embedded
                    />
                </DashboardExportSection>
                <DashboardExportSection
                    id="other-nfi-summary"
                    title="Other Non-Food Items Summary"
                    description="Other NFI stockpile grouped by item, brand/specification, available quantity, and cost."
                >
                    <FoodItemWarehouseSummary
                        title="Other Non-Food Items Summary"
                        description="Other NFI stockpile by warehouse."
                        summary={foodItemSummaries?.other_non_food_items}
                        showHeader={false}
                        embedded
                    />
                </DashboardExportSection>
                <DashboardExportSection
                    id="indirect-raw-materials-summary"
                    title="Indirect & Raw Materials Summary"
                    description="Indirect and raw material stockpile grouped by item, brand/specification, available quantity, and cost."
                >
                    <FoodItemWarehouseSummary
                        title="Indirect & Raw Materials Summary"
                        description="Indirect and raw material stockpile by warehouse."
                        summary={foodItemSummaries?.indirect_raw_materials}
                        showHeader={false}
                        embedded
                    />
                </DashboardExportSection>
                <div id="standby-stockpile-summary" className="min-w-0 scroll-mt-28">
                    <StandbyStockpileSummaryV2 summary={standbyStockpileSummary} />
                </div>
            </section>

            <section id="dashboard-near-expiry-summary" className="mt-6 scroll-mt-28">
                <NearExpiryMonthSummary
                    rows={nearExpirySummary}
                    id="dashboard-near-expiry-chart"
                    description="Current near-to-expire stockpile grouped by expiry month and item specification."
                />
            </section>

            <DashboardExportSection
                id="other-data"
                title="Other Data"
                description="Quick operational references for the largest warehouse stockpile locations and the latest inventory movements."
                className="mt-6"
            >
            <div className="grid min-w-0 w-full gap-6 xl:grid-cols-[minmax(280px,440px)_minmax(0,1fr)]">
                <Card id="top-warehouse-stockpile" className="scroll-mt-28 min-h-[180px]">
                    <h2 className="mb-3 font-black">Top Warehouse Stockpile</h2>
                    <p className="mb-4 text-sm font-semibold text-slate-500 dark:text-zinc-400">Warehouses with the largest current stockpile based on available balance.</p>
                    <div className="space-y-4">
                        {warehouseInventory.slice(0, 6).map((warehouse) => (
                            <ProgressRow
                                key={warehouse.id}
                                label={warehouse.name}
                                value={warehouse.current_balance}
                                total={metrics.inventory_total}
                            />
                        ))}
                    </div>
                </Card>

                <Card id="recent-inventory-transactions" className="min-w-0 scroll-mt-28">
                    <h2 className="mb-3 font-black">Recent Inventory Transactions</h2>
                    <p className="mb-4 text-sm font-semibold text-slate-500 dark:text-zinc-400">Most recent receipt and issuance transactions recorded in the inventory ledger.</p>
                    <div className="max-h-[44vh] max-w-full min-w-0 overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
                        <DataTable columns={['Type', 'Item', 'Warehouse', 'Quantity', 'Personnel']} rows={recentTransactions.map((tx) => (
                            <tr key={tx.id}>
                                <td className="whitespace-nowrap px-4 py-3 capitalize">{tx.type}</td>
                                <td className="whitespace-nowrap px-4 py-3 font-bold">{tx.batch?.item?.name}</td>
                                <td className="whitespace-nowrap px-4 py-3">{tx.batch?.warehouse?.name}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">{formatNumber(tx.quantity)}</td>
                                <td className="whitespace-nowrap px-4 py-3">{tx.user?.name ?? 'System'}</td>
                            </tr>
                        ))} />
                    </div>
                </Card>
            </div>
            </DashboardExportSection>
            </div>
        </AppLayout>
    );
}

function DashboardExportSection({ id, title, description, children, className = '' }) {
    return (
        <section id={id} className={`min-w-0 scroll-mt-28 ${className}`}>
            <ExportableCard
                title={title}
                className="min-w-0 overflow-visible p-3 sm:p-4"
                renderHeader={({ exportButtons, exportMessage }) => (
                    <div className="mb-3 flex flex-col gap-2 border-b border-slate-100 pb-3 lg:flex-row lg:items-center lg:justify-between dark:border-zinc-800">
                        <div className="min-w-0">
                            <h2 className="text-xl font-black">{title}</h2>
                            {description && <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{description}</p>}
                            {exportMessage && <p className="mt-2 text-xs font-bold text-brand-700 dark:text-brand-200">{exportMessage}</p>}
                        </div>
                        <div className="shrink-0 self-start lg:self-center">{exportButtons}</div>
                    </div>
                )}
            >
                {children}
            </ExportableCard>
        </section>
    );
}

function WarehouseSummary({ summary }) {
    if (!summary) {
        return null;
    }

    return (
        <ExportableCard
            className="min-w-0 overflow-hidden min-h-[260px] p-3 sm:p-4"
            title="Warehouses Summary"
            renderHeader={({ exportButtons, exportMessage }) => (
                <div className="mb-3 flex flex-col gap-2 border-b border-slate-100 pb-3 lg:flex-row lg:items-center lg:justify-between dark:border-zinc-800">
                    <div className="min-w-0">
                        <h2 className="text-xl font-black">Warehouses Summary</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">Managed warehouse coverage and classification using current warehouse master data.</p>
                        {exportMessage && <p className="mt-2 text-xs font-bold text-brand-700 dark:text-brand-200">{exportMessage}</p>}
                    </div>
                    <div className="shrink-0 self-start lg:self-center">{exportButtons}</div>
                </div>
            )}
        >
            <div className="grid min-w-0 items-stretch gap-4 lg:grid-cols-2 2xl:grid-cols-4">
                <div className="flex min-w-0 flex-col gap-4">
                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-1 2xl:grid-cols-2">
                        <WarehouseStatusCallout label="Active" value={summary.active} tone="green" />
                        <WarehouseStatusCallout label="Inactive" value={summary.inactive} tone="red" />
                    </div>
                    <WarehouseBreakdownPanel title="Distribution Network" rows={summary.distribution_networks} className="flex-1" />
                </div>
                <WarehouseBreakdownPanel title="Warehouse Type" rows={summary.warehouse_types} />
                <WarehouseBreakdownPanel title="Category" rows={summary.categories} />
                <WarehouseBreakdownPanel title="Partnership" rows={summary.partnerships} />
            </div>
        </ExportableCard>
    );
}

function WarehouseStatusCallout({ label, value, tone }) {
    const tones = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-900',
        red: 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-950 dark:text-rose-200 dark:ring-rose-900',
    };

    return (
        <div className={`min-w-0 rounded-md px-4 py-3 ring-1 ${tones[tone]}`}>
            <p className="text-[11px] font-black uppercase tracking-wide opacity-75">{label}</p>
            <p className="mt-1 text-2xl font-black">{formatNumber(value)}</p>
        </div>
    );
}

function WarehouseBreakdownPanel({ title, rows = [], className = '' }) {
    const total = rows.reduce((sum, row) => sum + Number(row.total ?? 0), 0);

    return (
        <div className={`flex h-full min-w-0 flex-col overflow-hidden rounded-md border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 ${className}`}>
            <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-center justify-between gap-3">
                    <h3 className="text-sm font-black uppercase tracking-wide">{title}</h3>
                    <span className="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-600 shadow-sm dark:bg-zinc-900 dark:text-zinc-300">{formatNumber(total)}</span>
                </div>
            </div>
            <div className="flex-1 space-y-3 p-4">
                {rows.map((row, index) => {
                    const percent = total > 0 ? Math.round((Number(row.total ?? 0) / total) * 100) : 0;
                    const color = chartColors[index % chartColors.length];
                    const hoverColor = chartHoverColors[index % chartHoverColors.length];

                    return (
                        <div key={row.label} className="group space-y-2.5 py-1.5">
                            <div className="flex items-start justify-between gap-3 text-sm leading-snug">
                                <span className="min-w-0 flex-1 whitespace-normal break-words font-black">{row.label}</span>
                                <span className="whitespace-nowrap text-xs text-slate-500 dark:text-zinc-400">{formatNumber(row.total)} | {percent}%</span>
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                <div
                                    className="h-full rounded-full transition"
                                    style={{ width: `${percent}%`, backgroundColor: color }}
                                    onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                    onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                />
                            </div>
                            <div className="mt-1 flex flex-wrap gap-2 text-[11px] text-slate-500 dark:text-zinc-400">
                                <span>{formatNumber(row.active)} active</span>
                                <span>{formatNumber(row.inactive)} inactive</span>
                            </div>
                        </div>
                    );
                })}
                {rows.length === 0 && <p className="text-sm text-slate-500 dark:text-zinc-400">No data available.</p>}
            </div>
        </div>
    );
}

function FamilyFoodPackReport({ data }) {
    const summaryRef = useRef(null);
    const [exportMessage, setExportMessage] = useState('');
    const provinceRows = data?.province_rows || [];
    const maxProvince = Math.max(...provinceRows.map((row) => Number(row.current || 0)), 1);
    const grandTotal = {
        province: 'Total',
        capacity: data?.total_capacity || 0,
        current: data?.total_current || 0,
        cost: data?.total_cost || 0,
    };
    const callouts = Object.fromEntries((data?.callouts || []).map((row) => [row.label, row.current]));
    const showExportMessage = (message) => {
        setExportMessage(message);
        window.setTimeout(() => setExportMessage(''), 3000);
    };

    return (
        <div ref={summaryRef} data-export-root="true" className="overflow-visible rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 min-h-[360px]">
            <div className="flex flex-col gap-3 px-5 pt-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 className="text-lg font-black tracking-normal text-slate-950 dark:text-white">FFP Summary</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">Family Food Pack stockpile by province using current inventory data.</p>
                    {exportMessage && <p className="mt-2 text-xs font-bold text-brand-700 dark:text-brand-200">{exportMessage}</p>}
                </div>
                <ExportButtons targetRef={summaryRef} filename="ffp-summary" label="FFP Summary" onMessage={showExportMessage} />
            </div>

            <div className="grid items-start gap-3 p-4 sm:p-5 xl:grid-cols-[440px_minmax(0,1fr)]">
            <div className="relative z-20 flex h-auto flex-col gap-3 xl:h-[740px]">
                <div className="grid gap-3 sm:grid-cols-2">
                    <ThemeKpi title="Total No. of FFPs" value={formatNumber(data?.total_current)} />
                    <ThemeKpi title="Total Cost" value={`₱${formatCurrency(data?.total_cost)}`} />
                </div>

                <div className="relative flex min-h-[220px] flex-1 flex-col overflow-visible rounded-md border border-slate-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="relative mb-2 flex items-center gap-2 text-[11px] font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">
                        <span className="h-2.5 w-7 rounded-full bg-brand-700 dark:bg-brand-300" />
                        Current No. of FFPs
                    </div>
                    <div className="relative grid min-h-0 flex-1 grid-cols-5 gap-1 border-b border-slate-300 pl-1 sm:gap-2 dark:border-zinc-700">
                        {provinceRows.map((row, index) => {
                            const height = Math.max(8, (Number(row.current || 0) / maxProvince) * 86);
                            const tooltipPosition = edgeTooltipPosition(index, provinceRows.length);
                            const color = ffpChartColors[index % ffpChartColors.length];
                            const hoverColor = ffpChartHoverColors[index % ffpChartHoverColors.length];

                            return (
                                <div key={row.province} className="group relative grid h-full grid-rows-[1fr_auto]" title={`${row.province}: ${formatNumber(row.current)}`}>
                                    <div className="relative flex h-full items-end justify-center">
                                        <div
                                            className="relative w-full max-w-16 rounded-t-md text-center text-[9px] font-bold text-white shadow-sm transition duration-150 group-hover:shadow-md sm:text-[10px]"
                                            style={{ height: `${height}%`, backgroundColor: color }}
                                            onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                            onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                        >
                                            <span className="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-black text-slate-950 dark:text-white">{formatNumber(row.current)}</span>
                                        </div>
                                    </div>
                                    <p className="mt-1 min-h-8 text-center text-[8px] font-semibold leading-tight text-slate-700 sm:text-[10px] dark:text-zinc-300">{shortProvince(row.province)}</p>
                                    <div className={`pointer-events-none absolute bottom-10 z-50 hidden w-56 rounded-md border border-slate-200 bg-white px-3 py-2 text-center text-xs font-black text-slate-900 shadow-xl group-hover:block dark:border-zinc-700 dark:bg-zinc-950 dark:text-white ${tooltipPosition}`}>
                                        {row.province}: {formatNumber(row.current)}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="relative z-30 flex flex-none flex-col">
                    <h3 className="mb-2 text-sm font-black">Family Food Packs per Province</h3>
                    <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="absolute -right-8 -top-10 h-24 w-24 rounded-full bg-brand-50 dark:bg-brand-800/30" />
                        <table className="relative w-full table-fixed text-[9px] sm:text-[11px]">
                            <thead className="bg-slate-100 text-left text-[8px] uppercase text-slate-600 sm:text-[10px] dark:bg-zinc-800 dark:text-zinc-300">
                                <tr>
                                    <th className="w-[26%] px-1.5 py-2 sm:px-2">Province</th>
                                    <th className="w-[19%] px-1.5 py-2 text-right sm:px-2">FFPs Full Capacity</th>
                                    <th className="w-[21%] px-1.5 py-2 text-right sm:px-2">No. of Available FFPs</th>
                                    <th className="w-[34%] px-1.5 py-2 text-right sm:px-2">Total Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {[...provinceRows, grandTotal].map((row) => (
                                    <tr key={row.province} className={row.province === 'Total' ? 'border-t border-slate-300 font-black dark:border-zinc-700' : 'border-t border-slate-100 dark:border-zinc-800'}>
                                        <td className="px-1.5 py-2 leading-tight sm:px-2">{row.province}</td>
                                        <td className="px-1.5 py-2 text-right sm:px-2">{formatNumber(row.capacity)}</td>
                                        <td className="px-1.5 py-2 text-right sm:px-2">{formatNumber(row.current)}</td>
                                        <td className="break-words px-1.5 py-2 text-right sm:px-2">₱{formatCurrency(row.cost)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div className="relative z-10 overflow-x-auto overflow-y-hidden xl:overflow-visible">
                <CaragaPhotoMap callouts={callouts} />
            </div>
            </div>
        </div>
    );
}

function ThemeKpi({ title, value }) {
    return (
        <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="absolute -right-8 -top-10 h-24 w-24 rounded-full bg-brand-50 dark:bg-brand-800/30" />
            <p className="relative text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="relative mt-2 break-words text-[1.05rem] font-black tracking-normal text-slate-950 sm:text-[1.3rem] dark:text-white">{value}</p>
        </div>
    );
}

function StandbyStockpileSummaryV2({ summary }) {
    const summaryRef = useRef(null);
    const [exportMessage, setExportMessage] = useState('');

    if (!summary) {
        return null;
    }

    const ffpBreakdown = summary.ffp_breakdown || [];
    const otherBreakdown = summary.other_breakdown || [];
    const ffpTotal = ffpBreakdown.reduce((sum, row) => sum + Number(row.current || 0), 0);
    const regionalAndSatellite = ffpBreakdown
        .filter((row) => String(row.warehouse_type || '').toLowerCase().includes('regional') || String(row.warehouse_type || '').toLowerCase().includes('satellite'))
        .reduce((sum, row) => sum + Number(row.current || 0), 0);
    const prepositioned = ffpBreakdown
        .filter((row) => String(row.warehouse_type || '').toLowerCase().includes('preposition'))
        .reduce((sum, row) => sum + Number(row.current || 0), 0);
    const showExportMessage = (message) => {
        setExportMessage(message);
        window.setTimeout(() => setExportMessage(''), 3000);
    };

    return (
        <div ref={summaryRef} data-export-root="true" className="min-w-0">
        <Card className="min-w-0 overflow-hidden p-0">
            <div className="border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex flex-col gap-1">
                        <h2 className="text-lg font-black tracking-normal text-slate-950 dark:text-white">Standby Funds and Prepositioned Stockpile Summary</h2>
                        <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                            Standby fund and current stockpile valuation using the inventory database and WIT standby fund source.
                        </p>
                        {summary.synced_at && (
                            <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                Standby fund source: {summary.source || 'System'} · Last synced {formatDateTime(summary.synced_at)}
                            </p>
                        )}
                    </div>
                    <ExportButtons targetRef={summaryRef} filename="standby-funds-prepositioned-stockpile-summary" label="Standby summary" onMessage={showExportMessage} />
                </div>
            </div>

            <div className="min-w-0 p-4 sm:p-5">
                <StandbyMatrix summary={summary} />

                <div className="mt-5 grid min-w-0 gap-5 xl:grid-cols-2">
                    <div className="min-w-0">
                        <h3 className="mb-3 text-lg font-black">Family Food Packs Breakdown</h3>
                        <div className="max-w-full overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
                            <table className="w-full min-w-[500px] text-base">
                                <thead className="bg-slate-100 text-left text-sm uppercase text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    <tr>
                                        <th className="px-4 py-3">Warehouse Type</th>
                                        <th className="px-4 py-3 text-right">FFPs Current</th>
                                        <th className="px-4 py-3 text-right">FFPs Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ffpBreakdown.map((row) => (
                                        <tr key={row.warehouse_type} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                            <td className="px-4 py-3 font-bold">{row.warehouse_type}</td>
                                            <td className="px-4 py-3 text-right">{formatNumber(row.current)}</td>
                                            <td className="px-4 py-3 text-right">₱{formatCurrency(row.cost)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                                        <td className="px-4 py-3">Total</td>
                                        <td className="px-4 py-3 text-right">{formatNumber(summary.ffp_quantity)}</td>
                                        <td className="px-4 py-3 text-right">₱{formatCurrency(summary.ffp_cost)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div className="min-w-0">
                        <h3 className="mb-3 text-lg font-black">Other Food and Non-Food Items Amount Breakdown</h3>
                        <div className="max-w-full overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
                            <table className="w-full min-w-[460px] text-base">
                                <tbody>
                                    {otherBreakdown.map((row) => (
                                        <tr key={row.label} className="border-t border-slate-100 first:border-t-0 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                            <td className="bg-slate-100 px-4 py-3 text-right font-black dark:bg-zinc-800">{row.label}</td>
                                            <td className="px-4 py-3 text-right font-semibold">₱{formatCurrency(row.cost)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                                        <td className="px-4 py-3 text-right">Total</td>
                                        <td className="px-4 py-3 text-right">₱{formatCurrency(summary.other_food_non_food_cost)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="mt-5 space-y-3 border-t border-slate-200 pt-4 text-base font-semibold leading-relaxed text-slate-600 dark:border-zinc-800 dark:text-zinc-300">
                    <p>
                        {formatNumber(ffpTotal)} FFPs are available in the region; of which {formatNumber(regionalAndSatellite)} FFPs are at the DSWD Regional and Satellite Warehouses, and {formatNumber(prepositioned)} FFPs are prepositioned at LGU warehouses.
                    </p>
                    <p>
                        ₱{formatCurrency(summary.other_food_non_food_cost)} available other food and non-food items, of which {otherBreakdown.map((row) => `₱${formatCurrency(row.cost)} for ${row.label.toLowerCase()}`).join(', ')}.
                    </p>
                </div>
            </div>
        </Card>
        </div>
    );
}

function StandbyMatrix({ summary }) {
    return (
        <div className="max-w-full overflow-x-auto rounded-md border border-slate-300 shadow-sm dark:border-zinc-700">
            <div className="min-w-[860px] text-center md:min-w-[1080px]">
                <div className="grid grid-cols-[1.25fr_0.95fr_1fr_1.05fr_1.1fr_1.25fr] bg-[#052963] text-white">
                    <div className="row-span-3 flex min-h-32 items-center justify-center border border-slate-400 px-3 py-5 text-base font-black uppercase tracking-wide md:text-xl">
                        Office
                    </div>
                    <div className="row-span-3 flex min-h-32 items-center justify-center border border-slate-400 px-3 py-5 text-base font-black uppercase tracking-wide md:text-xl">
                        Standby<br />Funds
                    </div>
                    <div className="col-span-3 flex min-h-12 items-center justify-center border border-slate-400 px-3 py-3 text-xl font-black uppercase tracking-wide md:text-3xl">
                        Stockpile
                    </div>
                    <div className="row-span-3 flex min-h-32 items-center justify-center border border-slate-400 px-3 py-5 text-base font-black uppercase tracking-wide md:text-xl">
                        Total<br />Standby<br />Funds &<br />Stockpile
                    </div>
                    <div className="col-span-2 flex min-h-12 items-center justify-center border border-slate-400 px-3 py-3 text-base font-black uppercase tracking-wide md:text-xl">
                        Family Food Packs
                    </div>
                    <div className="row-span-2 flex min-h-20 items-center justify-center border border-slate-400 px-3 py-3 text-sm font-black uppercase tracking-wide md:text-lg">
                        Other Food<br />and Non-Food<br />Items (FNIs)
                    </div>
                    <div className="flex min-h-10 items-center justify-center border border-slate-400 px-3 py-2 text-sm font-black uppercase tracking-wide md:text-lg">
                        Quantity
                    </div>
                    <div className="flex min-h-10 items-center justify-center border border-slate-400 px-3 py-2 text-sm font-black uppercase tracking-wide md:text-lg">
                        Total Cost
                    </div>
                </div>
                <div className="grid grid-cols-[1.25fr_0.95fr_1fr_1.05fr_1.1fr_1.25fr] bg-white text-slate-950 dark:bg-zinc-950 dark:text-white">
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">{summary.office || 'DSWD Field Office Caraga'}</div>
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">₱{formatCurrency(summary.standby_funds)}</div>
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">{formatNumber(summary.ffp_quantity)}</div>
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">₱{formatCurrency(summary.ffp_cost)}</div>
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">₱{formatCurrency(summary.other_food_non_food_cost)}</div>
                    <div className="flex min-h-20 items-center justify-center border border-slate-300 px-3 py-5 text-base font-black md:text-xl">₱{formatCurrency(summary.total_standby_funds_stockpile)}</div>
                </div>
            </div>
        </div>
    );
}

function LegacyStandbyMatrix({ summary }) {
    return (
        <div className="max-w-full overflow-x-auto rounded-md border border-slate-300 shadow-sm dark:border-zinc-700">
            <table className="w-full min-w-[760px] table-fixed text-center md:min-w-[980px]">
                <thead>
                    <tr className="bg-[#052963] text-white">
                        <th rowSpan={3} className="w-[20%] border border-slate-400 px-2 py-4 text-sm font-black uppercase tracking-wide md:px-3 md:py-5 md:text-lg">Office</th>
                        <th rowSpan={3} className="w-[15%] border border-slate-400 px-2 py-4 text-sm font-black uppercase tracking-wide md:px-3 md:py-5 md:text-lg">Standby<br />Funds</th>
                        <th colSpan={3} className="border border-slate-400 px-2 py-2 text-lg font-black uppercase tracking-wide md:px-3 md:text-2xl">Stockpile</th>
                        <th rowSpan={3} className="w-[16%] border border-slate-400 px-2 py-4 text-sm font-black uppercase tracking-wide md:px-3 md:py-5 md:text-lg">Total<br />Standby<br />Funds &<br />Stockpile</th>
                    </tr>
                    <tr className="bg-[#052963] text-white">
                        <th colSpan={2} className="border border-slate-400 px-2 py-2 text-sm font-black uppercase tracking-wide md:px-3 md:text-lg">Family Food Packs</th>
                        <th rowSpan={2} className="w-[16%] border border-slate-400 px-2 py-2 text-xs font-black uppercase tracking-wide md:px-3 md:text-base">Other Food<br />and Non-Food<br />Items (FNIs)</th>
                    </tr>
                    <tr className="bg-[#052963] text-white">
                        <th className="w-[13%] border border-slate-400 px-2 py-2 text-xs font-black uppercase tracking-wide md:px-3 md:text-base">Quantity</th>
                        <th className="w-[16%] border border-slate-400 px-2 py-2 text-xs font-black uppercase tracking-wide md:px-3 md:text-base">Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr className="bg-white text-slate-950 dark:bg-zinc-950 dark:text-white">
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-lg">{summary.office || 'DSWD Field Office Caraga'}</td>
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-xl">₱{formatCurrency(summary.standby_funds)}</td>
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-xl">{formatNumber(summary.ffp_quantity)}</td>
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-xl">₱{formatCurrency(summary.ffp_cost)}</td>
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-xl">₱{formatCurrency(summary.other_food_non_food_cost)}</td>
                        <td className="border border-slate-300 px-2 py-4 text-base font-black md:px-3 md:py-5 md:text-xl">₱{formatCurrency(summary.total_standby_funds_stockpile)}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

function StandbyStockpileSummary({ summary }) {
    if (!summary) {
        return null;
    }

    const ffpBreakdown = summary.ffp_breakdown || [];
    const otherBreakdown = summary.other_breakdown || [];
    const ffpTotal = ffpBreakdown.reduce((sum, row) => sum + Number(row.current || 0), 0);
    const regionalAndSatellite = ffpBreakdown
        .filter((row) => String(row.warehouse_type || '').toLowerCase().includes('regional') || String(row.warehouse_type || '').toLowerCase().includes('satellite'))
        .reduce((sum, row) => sum + Number(row.current || 0), 0);
    const prepositioned = ffpBreakdown
        .filter((row) => String(row.warehouse_type || '').toLowerCase().includes('preposition'))
        .reduce((sum, row) => sum + Number(row.current || 0), 0);

    return (
        <Card className="overflow-hidden p-0">
            <div className="border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                <div className="flex flex-col gap-1">
                    <h2 className="text-lg font-black tracking-normal text-slate-950 dark:text-white">Standby Funds and Prepositioned Stockpile Summary</h2>
                    <p className="text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        Standby fund and current stockpile valuation using the inventory database and WIT standby fund source.
                    </p>
                    {summary.synced_at && (
                        <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">
                            Standby fund source: {summary.source || 'System'} · Last synced {formatDateTime(summary.synced_at)}
                        </p>
                    )}
                </div>
            </div>

            <div className="p-4 sm:p-5">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <SummaryTile className="xl:col-span-2" label="Office" value={summary.office || 'DSWD Field Office Caraga'} compact />
                    <SummaryTile label="Standby Funds" value={`₱${formatCurrency(summary.standby_funds)}`} />
                    <SummaryTile label="FFP Quantity" value={formatNumber(summary.ffp_quantity)} />
                    <SummaryTile label="FFP Total Cost" value={`₱${formatCurrency(summary.ffp_cost)}`} />
                    <SummaryTile label="Other FNIs" value={`₱${formatCurrency(summary.other_food_non_food_cost)}`} />
                    <SummaryTile className="md:col-span-2 xl:col-span-6" label="Total Standby Funds & Stockpile" value={`₱${formatCurrency(summary.total_standby_funds_stockpile)}`} emphasis />
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-2">
                    <div>
                        <h3 className="mb-2 text-sm font-black">Family Food Packs Breakdown</h3>
                        <div className="overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
                            <table className="w-full min-w-[540px] text-sm">
                                <thead className="bg-slate-100 text-left text-xs uppercase text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    <tr>
                                        <th className="px-4 py-3">Warehouse Type</th>
                                        <th className="px-4 py-3 text-right">FFPs Current</th>
                                        <th className="px-4 py-3 text-right">FFPs Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ffpBreakdown.map((row) => (
                                        <tr key={row.warehouse_type} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                            <td className="px-4 py-3 font-bold">{row.warehouse_type}</td>
                                            <td className="px-4 py-3 text-right">{formatNumber(row.current)}</td>
                                            <td className="px-4 py-3 text-right">₱{formatCurrency(row.cost)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                                        <td className="px-4 py-3">Total</td>
                                        <td className="px-4 py-3 text-right">{formatNumber(summary.ffp_quantity)}</td>
                                        <td className="px-4 py-3 text-right">₱{formatCurrency(summary.ffp_cost)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3 className="mb-2 text-sm font-black">Other Food and Non-Food Items Amount Breakdown</h3>
                        <div className="overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
                            <table className="w-full min-w-[480px] text-sm">
                                <tbody>
                                    {otherBreakdown.map((row) => (
                                        <tr key={row.label} className="border-t border-slate-100 first:border-t-0 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                            <td className="bg-slate-100 px-4 py-3 text-right font-black dark:bg-zinc-800">{row.label}</td>
                                            <td className="px-4 py-3 text-right font-semibold">₱{formatCurrency(row.cost)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                                        <td className="px-4 py-3 text-right">Total</td>
                                        <td className="px-4 py-3 text-right">₱{formatCurrency(summary.other_food_non_food_cost)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="mt-5 space-y-2 border-t border-slate-200 pt-4 text-sm font-semibold text-slate-600 dark:border-zinc-800 dark:text-zinc-300">
                    <p>
                        {formatNumber(ffpTotal)} FFPs are available in the region; of which {formatNumber(regionalAndSatellite)} FFPs are at the DSWD Regional and Satellite Warehouses, and {formatNumber(prepositioned)} FFPs are prepositioned at LGU warehouses.
                    </p>
                    <p>
                        ₱{formatCurrency(summary.other_food_non_food_cost)} available other food and non-food items, of which {otherBreakdown.map((row) => `₱${formatCurrency(row.cost)} for ${row.label.toLowerCase()}`).join(', ')}.
                    </p>
                </div>
            </div>
        </Card>
    );
}

function SummaryTile({ label, value, emphasis = false, compact = false, className = '' }) {
    return (
        <div className={`relative overflow-hidden rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 ${className}`}>
            <div className="absolute -right-8 -top-10 h-24 w-24 rounded-full bg-brand-50 dark:bg-brand-800/30" />
            <p className="relative text-[10px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{label}</p>
            <p className={`relative mt-2 break-words font-black tracking-normal text-slate-950 dark:text-white ${emphasis ? 'text-3xl' : compact ? 'text-xl' : 'text-2xl'}`}>{value}</p>
        </div>
    );
}

function FoodItemWarehouseSummary({ title, description, summary, showHeader = true, embedded = false }) {
    const rows = summary?.rows || [];
    const isItemSummary = summary?.group_by === 'item';
    const showCategory = Boolean(summary?.show_category);
    const chartRows = rows.slice(0, 8);
    const maxAvailable = Math.max(...chartRows.map((row) => Number(row.available || 0)), 1);
    const Shell = embedded ? 'div' : Card;

    return (
        <div className="min-w-0">
            <Shell className={`min-w-0 overflow-visible min-h-[320px] ${embedded ? '' : 'p-0'}`}>
                {showHeader && <div className="border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 className="text-lg font-black tracking-normal text-slate-950 dark:text-white">{title}</h2>
                        {description && <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{description}</p>}
                    </div>
                    </div>
                    <div className="flex flex-col items-start gap-3">
                        <div className="grid w-full gap-2 sm:w-auto sm:grid-cols-2">
                            <ThemePill label="Available" value={formatNumber(summary?.total)} />
                            <ThemePill label="Cost" value={`₱${formatCurrency(summary?.cost)}`} />
                        </div>
                    </div>
                </div>
                </div>}

                <div className="min-w-0 space-y-4 p-4 sm:p-5">
                <div className="relative min-w-0 overflow-hidden rounded-md border border-slate-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="relative mb-2 flex items-center gap-2 text-[11px] font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">
                        <span className="h-2.5 w-7 rounded-full bg-brand-700 dark:bg-brand-300" />
                        No. of items available
                    </div>
                    <div className="relative max-w-full overflow-x-auto">
                        <div className="grid h-48 min-w-[520px] grid-cols-8 gap-2 border-b border-slate-300 pl-1 dark:border-zinc-700 sm:min-w-[620px] 2xl:min-w-0">
                            {chartRows.map((row, index) => {
                                const height = Math.max(8, (Number(row.available || 0) / maxAvailable) * 86);
                                const label = isItemSummary ? row.item : row.warehouse;
                                const tooltipPosition = edgeTooltipPosition(index, chartRows.length);
                                const color = chartColors[index % chartColors.length];
                                const hoverColor = chartHoverColors[index % chartHoverColors.length];

                                return (
                                    <div key={`${label}-chart`} className="group relative grid h-full grid-rows-[1fr_auto]" title={label}>
                                        <div className="relative flex h-full items-end justify-center">
                                            <div
                                                className="relative w-full max-w-16 rounded-t-md text-center text-[9px] font-bold text-white shadow-sm transition duration-150 group-hover:shadow-md sm:text-[10px]"
                                                style={{ height: `${height}%`, backgroundColor: color }}
                                                onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                                onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                            >
                                                <span className="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-black text-slate-950 dark:text-white">{formatNumber(row.available)}</span>
                                            </div>
                                        </div>
                                        <p className="mt-1 min-h-9 truncate text-center text-[8px] font-semibold leading-tight text-slate-700 sm:text-[10px] dark:text-zinc-300">{isItemSummary ? shortItem(label) : shortWarehouse(label)}</p>
                                        <div className={`pointer-events-none absolute bottom-10 z-50 hidden w-64 rounded-md border border-slate-200 bg-white px-3 py-2 text-center text-xs font-black text-slate-900 shadow-xl group-hover:block dark:border-zinc-700 dark:bg-zinc-950 dark:text-white ${tooltipPosition}`}>
                                            {label}: {formatNumber(row.available)}
                                        </div>
                                    </div>
                                );
                            })}
                            {chartRows.length === 0 && (
                                <div className="col-span-8 flex items-center justify-center text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                    No available stockpile found.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className={`relative max-w-full ${isItemSummary ? 'max-h-[420px] overflow-auto' : 'overflow-x-auto'}`}>
                    <table className="w-full min-w-[620px] text-sm 2xl:min-w-0">
                        <thead className="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                {showCategory && <th className="w-[18%] px-4 py-3">Category</th>}
                                <th className={`${showCategory ? 'w-[24%]' : 'w-[26%]'} px-4 py-3`}>{isItemSummary ? 'Item' : 'Warehouse'}</th>
                                <th className={`${showCategory ? 'w-[28%]' : 'w-[40%]'} px-4 py-3`}>{isItemSummary ? 'Brand / Description' : 'Address'}</th>
                                <th className="w-[16%] px-4 py-3 text-right">No. Available</th>
                                <th className={`${showCategory ? 'w-[14%]' : 'w-[18%]'} px-4 py-3 text-right`}>Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={`${isItemSummary ? row.item : row.warehouse}-${isItemSummary ? row.description : row.address}`} className="border-t border-slate-100 transition hover:bg-brand-50/70 dark:border-zinc-800 dark:hover:bg-brand-950/30">
                                    {showCategory && <td className="px-4 py-3 font-bold text-slate-600 dark:text-zinc-300">{row.category}</td>}
                                    <td className="px-4 py-3 font-black text-slate-950 dark:text-white">{isItemSummary ? row.item : row.warehouse}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-600 dark:text-zinc-300">{isItemSummary ? row.description : row.address}</td>
                                    <td className="px-4 py-3 text-right font-black text-slate-950 dark:text-white">{formatNumber(row.available)}</td>
                                    <td className="px-4 py-3 text-right font-black text-slate-950 dark:text-white">₱{formatCurrency(row.cost)}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={showCategory ? 5 : 4} className="px-4 py-8 text-center text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                        No available stockpile found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-slate-300 bg-slate-50 font-black dark:border-zinc-700 dark:bg-zinc-950">
                                <td colSpan={showCategory ? 3 : 2} className="px-4 py-3 text-slate-950 dark:text-white">Total</td>
                                <td className="px-4 py-3 text-right text-slate-950 dark:text-white">{formatNumber(summary?.total)}</td>
                                <td className="px-4 py-3 text-right text-slate-950 dark:text-white">₱{formatCurrency(summary?.cost)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                </div>
            </Shell>
        </div>
    );
}

function ThemePill({ label, value }) {
    return (
        <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white px-4 py-3 text-right shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="absolute -right-8 -top-10 h-20 w-20 rounded-full bg-brand-50 dark:bg-brand-800/30" />
            <p className="relative text-[10px] font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{label}</p>
            <p className="relative mt-1 text-xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
        </div>
    );
}

function CaragaPhotoMap({ callouts }) {
    const cards = [
        {
            id: 'sdn-mainland',
            label: 'Surigao del Norte - Mainland',
            value: callouts['Surigao del Norte - Mainland'],
            position: { top: '6%', left: '1.5%' },
        },
        {
            id: 'adn',
            label: 'Agusan del Norte',
            value: callouts['Agusan Del Norte'],
            position: { top: '33%', left: '1.5%' },
        },
        {
            id: 'ads',
            label: 'Agusan del Sur',
            value: callouts['Agusan Del Sur'],
            position: { bottom: '39%', left: '0%' },
        },
        {
            id: 'dinagat',
            label: 'Province of Dinagat Islands',
            value: callouts['Province of Dinagat Islands'],
            align: 'right',
            position: { top: '3%', right: '20.5%' },
        },
        {
            id: 'sdn-siargao',
            label: 'Surigao del Norte - Siargao',
            value: callouts['Surigao del Norte - Siargao'],
            align: 'right',
            position: { top: '29%', right: '19%' },
        },
        {
            id: 'sds',
            label: 'Surigao del Sur',
            value: callouts['Surigao Del Sur'],
            align: 'right',
            position: { bottom: '33%', right: '14.5%' },
        },
    ];

    return (
        <div className="relative mx-auto h-[760px] w-[766px] overflow-hidden bg-transparent xl:overflow-visible">
            <div className="relative mx-auto h-full w-full">
                <div
                    role="img"
                    aria-label="Caraga Family Food Pack distribution map"
                    className="absolute left-0 top-0 z-0 flex h-full w-full items-center justify-center overflow-hidden"
                >
                    <img
                        src="/images/caraga-ffp-map-base.png"
                        alt="Caraga Family Food Pack distribution map"
                        className="max-h-full max-w-full object-contain"
                    />
                </div>

                <svg className="pointer-events-none absolute inset-0 z-10 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <g className="stroke-brand-800 dark:stroke-brand-200" strokeWidth="0.28" fill="none" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M28 14 L32 16" />
                        <path d="M33 41 L31 46" />
                        <path d="M28 68 L36 72" />
                        <path d="M70.5 14 L66 13" />
                        <path d="M73 41 L66 36" />
                        <path d="M72.5 68 L69 61" />
                    </g>
                </svg>

                {cards.map((card) => (
                    <MapCallout
                        key={card.id}
                        id={card.id}
                        label={card.label}
                        value={card.value}
                        align={card.align}
                        position={card.position}
                    />
                ))}
            </div>
        </div>
    );
}

function MapCallout({ id, label, value, position, align = 'left' }) {
    return (
        <div
            id={`ffp-map-card-${id}`}
            className="absolute z-20 w-40 overflow-hidden rounded-md border border-slate-200 bg-white p-2.5 shadow-lg dark:border-zinc-800 dark:bg-zinc-900"
            style={position}
        >
            <div className="absolute -right-8 -top-10 h-20 w-20 rounded-full bg-brand-50 dark:bg-brand-800/30" />
            <p className={`relative text-[10px] font-black uppercase leading-tight tracking-wide text-brand-700 dark:text-brand-200 ${align === 'right' ? 'text-right' : ''}`}>{label}</p>
            <p className={`relative mt-1.5 text-[10px] font-semibold text-slate-500 dark:text-zinc-400 ${align === 'right' ? 'text-right' : ''}`}>Current No. of FFPs</p>
            <p className={`relative text-xl font-black tracking-normal text-slate-950 dark:text-white ${align === 'right' ? 'text-right' : ''}`}>{formatNumber(value)}</p>
        </div>
    );
}

function HeroMetric({ title, value, subtext, icon: Icon, showExport = true }) {
    const metricRef = useRef(null);

    return (
        <div ref={metricRef} data-export-root="true" className="min-w-0">
            <Card className="relative overflow-hidden p-3 min-h-[140px]">
                {showExport && (
                    <div data-html2canvas-ignore="true" className="absolute right-3 top-3 z-20">
                        <ExportButtons targetRef={metricRef} filename={exportFilename(title)} label={title} />
                    </div>
                )}
                <div className="absolute -right-8 -top-10 h-36 w-36 rounded-full bg-brand-50 dark:bg-brand-800/30" />
                <div className="relative flex items-center justify-between gap-4">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                        <p className="mt-2 text-4xl font-black tracking-normal text-slate-950 dark:text-white">{formatNumber(value)}</p>
                        <p className="mt-2 text-sm font-semibold text-slate-500 dark:text-zinc-400">{subtext}</p>
                    </div>
                    <div className="flex h-16 w-16 items-center justify-center rounded-md bg-brand-50 text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-brand-900 dark:text-brand-200 dark:ring-brand-700">
                        <Icon className="h-8 w-8" />
                    </div>
                </div>
            </Card>
        </div>
    );
}

function BreakdownPanel({ title, rows, compact = false }) {
    const total = rows.reduce((sum, [, value]) => sum + Number(value || 0), 0);

    return (
        <div className="rounded-md border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-sm font-black uppercase tracking-wide">{title}</h3>
                <span className="rounded-full bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200 dark:bg-zinc-950 dark:text-zinc-300 dark:ring-zinc-800">{formatNumber(total)}</span>
            </div>
            <div className={compact ? 'space-y-2' : 'space-y-3'}>
                {rows.map(([label, value], index) => (
                    <ProgressRow key={label} label={label} value={value} total={total} compact={compact} index={index} />
                ))}
                {rows.length === 0 && <p className="text-sm text-slate-500 dark:text-zinc-400">No data available.</p>}
            </div>
        </div>
    );
}

function MiniStat({ title, value }) {
    return (
        <div className="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-zinc-950 dark:ring-zinc-800">
            <p className="text-[10px] font-bold uppercase text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="mt-1 text-lg font-black">{formatNumber(value)}</p>
        </div>
    );
}

function ProgressRow({ label, value, total, compact = false, index = 0 }) {
    const percent = total ? Math.round((Number(value || 0) / Number(total)) * 100) : 0;
    const color = chartColors[index % chartColors.length];
    const hoverColor = chartHoverColors[index % chartHoverColors.length];

    return (
        <div className="group space-y-2.5 py-1.5">
            <div className={`flex items-start justify-between gap-3 leading-snug ${compact ? 'text-xs' : 'text-sm'}`}>
                <span className="min-w-0 flex-1 whitespace-normal break-words font-bold">{label}</span>
                <span className="whitespace-nowrap text-slate-500 dark:text-zinc-400">{formatNumber(value)} | {percent}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                <div
                    className="h-full rounded-full transition"
                    style={{ width: `${Math.min(percent, 100)}%`, backgroundColor: color }}
                    onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                    onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                />
            </div>
        </div>
    );
}

function objectRows(data = {}) {
    return Object.entries(data || {}).filter(([, value]) => Number(value || 0) > 0);
}

function shortProvince(province) {
    return {
        'Agusan Del Norte': 'Agusan Del Norte',
        'Agusan Del Sur': 'Agusan Del Sur',
        'Dinagat Islands': 'Dinagat Islands',
        'Surigao Del Norte': 'Surigao Del Norte',
        'Surigao Del Sur': 'Surigao Del Sur',
    }[province] || province;
}

function shortWarehouse(warehouse) {
    const value = String(warehouse || 'Warehouse').replace(/^Agusan Del Norte,\s*/i, '').replace(/^Surigao Del Sur,\s*/i, '');

    return value.length > 28 ? `${value.slice(0, 25)}...` : value;
}

function shortItem(item) {
    const value = String(item || 'Item');

    return value.length > 26 ? `${value.slice(0, 23)}...` : value;
}

function edgeTooltipPosition(index, total) {
    if (index === 0) {
        return 'left-0';
    }

    if (index === total - 1) {
        return 'right-0';
    }

    return 'left-1/2 -translate-x-1/2';
}

function formatNumber(value) {
    return Number(value || 0).toLocaleString();
}

function formatCurrency(value) {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
