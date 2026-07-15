import { useMemo, useState } from 'react';
import { ExportableCard } from '@/Layouts/AppLayout';

const chartColors = ['#3b82f6', '#f97316', '#a855f7', '#9dbb4f', '#2db6c4'];
const chartHoverColors = ['#2563eb', '#ea580c', '#9333ea', '#82983f', '#0891b2'];

const number = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

export default function NearExpiryMonthSummary({
    rows = [],
    id = 'near-expiry-month-summary',
    title = 'Near Expiry Summary',
    description = 'Shows current expiring stockpile grouped by expiry month and item.',
    className = '',
}) {
    const [hoveredMonth, setHoveredMonth] = useState(null);
    const chart = useMemo(() => buildChart(rows), [rows]);

    return (
        <div className={`min-w-0 scroll-mt-28 ${className}`}>
            <ExportableCard
                id={id}
                title={title}
                className="min-w-0 overflow-visible"
                renderHeader={({ exportButtons, exportMessage }) => (
                    <div className="mb-4 flex flex-col gap-3 border-b border-slate-100 pb-3 lg:flex-row lg:items-start lg:justify-between dark:border-zinc-800">
                        <div className="min-w-0">
                            <h2 className="text-lg font-black">{title}</h2>
                            <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{description}</p>
                            {exportMessage && <p className="mt-2 text-xs font-bold text-brand-700 dark:text-brand-200">{exportMessage}</p>}
                        </div>
                        <div data-html2canvas-ignore="true" className="shrink-0 self-start lg:self-center">{exportButtons}</div>
                    </div>
                )}
            >
                <div className="min-w-0 overflow-x-auto">
                    <div className="min-w-[760px] max-w-full">
                        <div className="mb-4 flex flex-wrap gap-x-6 gap-y-2">
                            {chart.items.map((item) => (
                                <div key={item} className="flex items-center gap-2 text-sm font-semibold">
                                    <span className="h-3.5 w-8 rounded-sm" style={{ backgroundColor: itemColor(item) }} />
                                    <span>{item}</span>
                                </div>
                            ))}
                        </div>

                        <div className="relative h-[340px] rounded-md border border-slate-200 bg-white px-4 pb-12 pt-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                            <div className="absolute inset-x-4 top-8 bottom-12 flex flex-col justify-between">
                                {[1, 0.8, 0.6, 0.4, 0.2, 0].map((line) => (
                                    <div key={line} className="border-t border-slate-200 dark:border-zinc-800" />
                                ))}
                            </div>
                            <div className="relative z-10 flex h-full items-end justify-around gap-4">
                                {chart.months.map((month, monthIndex) => {
                                    const monthTotal = chart.monthTotals[month] ?? 0;
                                    const monthRows = chart.matrix[month] ?? {};
                                    const height = chart.maxTotal > 0 ? Math.max(2, (monthTotal / chart.maxTotal) * 88) : 0;
                                    const tooltipClass = tooltipPosition(monthIndex, chart.months.length);
                                    const nonZeroItems = chart.items.filter((item) => (monthRows[item] ?? 0) > 0);

                                    return (
                                        <div
                                            key={month}
                                            className="group relative flex h-full min-w-24 flex-1 flex-col items-center justify-end"
                                            onMouseEnter={() => setHoveredMonth(month)}
                                            onMouseLeave={() => setHoveredMonth(null)}
                                        >
                                            {hoveredMonth === month && (
                                                <div className={`absolute bottom-10 z-30 w-64 rounded-md border border-slate-200 bg-white p-3 text-xs font-semibold shadow-xl dark:border-zinc-700 dark:bg-zinc-900 ${tooltipClass}`}>
                                                    <p className="mb-2 text-sm font-black">{month}</p>
                                                    <div className="space-y-1.5">
                                                        {chart.items.map((item) => (
                                                            <div key={item} className="flex items-center justify-between gap-3">
                                                                <span className="flex min-w-0 items-center gap-2">
                                                                    <span className="h-2 w-2 shrink-0 rounded-sm" style={{ backgroundColor: itemColor(item) }} />
                                                                    <span className="truncate">{item}</span>
                                                                </span>
                                                                <span className="font-black">{number(monthRows[item] ?? 0)}</span>
                                                            </div>
                                                        ))}
                                                        <div className="border-t border-slate-100 pt-1.5 dark:border-zinc-800">
                                                            <div className="flex justify-between">
                                                                <span>Total</span>
                                                                <span className="font-black">{number(monthTotal)}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                            <div className="relative flex w-full max-w-24 flex-col justify-end overflow-visible rounded-lg shadow-sm transition group-hover:shadow-md" style={{ height: `${height}%` }}>
                                                {chart.items.map((item) => {
                                                    const value = monthRows[item] ?? 0;
                                                    if (value <= 0) {
                                                        return null;
                                                    }

                                                    const segmentHeight = monthTotal > 0 ? Math.max(5, (value / monthTotal) * 100) : 0;
                                                    const color = itemColor(item);
                                                    const hoverColor = itemColor(item, true);
                                                    const indexAmong = nonZeroItems.indexOf(item);
                                                    const totalNonZero = nonZeroItems.length;
                                                    const roundedClass = totalNonZero === 1
                                                        ? 'rounded-lg'
                                                        : indexAmong === 0
                                                            ? 'rounded-b-lg'
                                                            : indexAmong === totalNonZero - 1
                                                                ? 'rounded-t-lg'
                                                                : '';

                                                    return (
                                                        <div
                                                            key={item}
                                                            className={`relative min-h-3 transition ${roundedClass}`}
                                                            style={{ height: `${segmentHeight}%`, backgroundColor: color }}
                                                            onMouseEnter={(event) => { event.currentTarget.style.backgroundColor = hoverColor; }}
                                                            onMouseLeave={(event) => { event.currentTarget.style.backgroundColor = color; }}
                                                        >
                                                            {segmentHeight >= 18 ? (
                                                                <span className="absolute left-1/2 top-1 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-950/90 px-1.5 py-0.5 text-xs font-black leading-none text-white shadow-lg">{number(value)}</span>
                                                            ) : (
                                                                <span className="absolute -top-6 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-white/90 px-1.5 py-0.5 text-xs font-black leading-none text-slate-950 shadow">{number(value)}</span>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                            <p className="absolute -bottom-8 w-28 text-center text-xs font-semibold leading-tight text-slate-700 dark:text-zinc-300">{month}</p>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </div>
            </ExportableCard>
        </div>
    );
}

function buildChart(rows) {
    const validRows = rows.filter((row) => Number(row.quantity ?? row.total ?? 0) > 0 && row.expiry_month);
    const itemTotals = validRows.reduce((acc, row) => {
        const item = row.item || 'Unspecified item';
        acc[item] = (acc[item] ?? 0) + Number(row.quantity ?? row.total ?? 0);
        return acc;
    }, {});
    const items = Object.entries(itemTotals)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 6)
        .map(([item]) => item);
    const months = Array.from(new Set(validRows.map((row) => row.expiry_month)))
        .sort((a, b) => monthTime(a) - monthTime(b));
    const matrix = {};
    const monthTotals = {};

    validRows.forEach((row) => {
        const item = row.item || 'Unspecified item';
        const month = row.expiry_month;
        if (!items.includes(item)) {
            return;
        }

        const quantity = Number(row.quantity ?? row.total ?? 0);
        matrix[month] ??= {};
        matrix[month][item] = (matrix[month][item] ?? 0) + quantity;
        monthTotals[month] = (monthTotals[month] ?? 0) + quantity;
    });

    const maxTotal = Math.max(...Object.values(monthTotals), 0);
    const grandTotal = Object.values(monthTotals).reduce((sum, value) => sum + Number(value || 0), 0);

    return { items, months, matrix, monthTotals, maxTotal, grandTotal };
}

function monthTime(value) {
    const parsed = new Date(`01 ${value}`);
    return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime();
}

function itemColor(item, hover = false) {
    const palette = hover ? chartHoverColors : chartColors;
    const hash = String(item || '').split('').reduce((total, char) => total + char.charCodeAt(0), 0);

    return palette[hash % palette.length];
}

function tooltipPosition(index, total) {
    if (total <= 1) {
        return 'left-1/2 -translate-x-1/2';
    }

    if (index === 0) {
        return 'left-0';
    }

    if (index === total - 1) {
        return 'right-0';
    }

    return 'left-1/2 -translate-x-1/2';
}
