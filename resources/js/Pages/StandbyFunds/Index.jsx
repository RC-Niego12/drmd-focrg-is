import { Head, router, useForm } from '@inertiajs/react';
import { BadgeDollarSign, RefreshCw, Save } from 'lucide-react';
import AppLayout, { Card, ExportableCard } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';

export default function Index({ standbyFund, defaultSheetUrl, defaultCell }) {
    const form = useForm({
        amount: standbyFund?.amount ?? 3000000,
        source: standbyFund?.source ?? 'Manual update',
        google_sheet_url: standbyFund?.google_sheet_url || defaultSheetUrl || '',
        cell_reference: standbyFund?.cell_reference || defaultCell || 'L2',
    });

    const save = (event) => {
        event.preventDefault();
        form.put('/standby-funds', { preserveScroll: true });
    };

    const sync = () => {
        router.post('/standby-funds/sync-google-sheet', {
            google_sheet_url: form.data.google_sheet_url,
            cell_reference: form.data.cell_reference,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout title="Standby Funds">
            <Head title="Standby Funds" />

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                <ExportableCard id="standby-current" title="Current Standby Funds" className="relative scroll-mt-28 overflow-hidden p-6">
                    <div className="absolute -right-8 -top-10 h-36 w-36 rounded-full bg-brand-50 dark:bg-brand-800/30" />
                    <div className="relative flex items-start justify-between gap-5">
                        <div>
                            <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">Current Standby Funds</p>
                            <p className="mt-2 text-4xl font-black tracking-normal text-slate-950 dark:text-white">₱{formatCurrency(standbyFund?.amount)}</p>
                            <p className="mt-2 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                {standbyFund?.office || 'DSWD Field Office Caraga'}
                            </p>
                            <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                Source: {standbyFund?.source || 'System'}{standbyFund?.synced_at ? ` · Last synced ${formatDateTime(standbyFund.synced_at)}` : ''}
                            </p>
                        </div>
                        <div className="flex h-16 w-16 items-center justify-center rounded-md bg-brand-50 text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-brand-900 dark:text-brand-200 dark:ring-brand-700">
                            <BadgeDollarSign className="h-8 w-8" />
                        </div>
                    </div>
                </ExportableCard>

                <ExportableCard id="standby-sync-source" title="Standby Fund Sync Source" className="scroll-mt-28 p-6">
                    <h2 className="font-black">WIT Sync Source</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        Uses the configured WIT Google Sheet cell for the latest standby fund value.
                    </p>
                    <dl className="mt-4 space-y-3 text-sm">
                        <div>
                            <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Cell</dt>
                            <dd className="font-bold">{form.data.cell_reference || 'L2'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-black uppercase text-slate-500 dark:text-zinc-400">Last updated by</dt>
                            <dd className="font-bold">{standbyFund?.updater?.name || 'System'}</dd>
                        </div>
                    </dl>
                </ExportableCard>
            </div>

            <Card id="standby-update" className="mt-6 scroll-mt-28 p-0">
                <div className="border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <h2 className="text-lg font-black">Update Standby Funds</h2>
                    <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">
                        This page is intended for the DRMD Financial Analyst once manual standby fund maintenance is needed.
                    </p>
                </div>

                <form onSubmit={save} className="grid gap-4 p-5 lg:grid-cols-2">
                    <Field label="Amount">
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={form.data.amount}
                            onChange={(event) => form.setData('amount', event.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-950"
                        />
                        {form.errors.amount && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors.amount}</p>}
                    </Field>

                    <Field label="Source">
                        <input
                            type="text"
                            value={form.data.source}
                            onChange={(event) => form.setData('source', event.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-950"
                        />
                    </Field>

                    <Field label="Google Sheet URL">
                        <input
                            type="url"
                            value={form.data.google_sheet_url}
                            onChange={(event) => form.setData('google_sheet_url', event.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-950"
                        />
                        {form.errors.google_sheet_url && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors.google_sheet_url}</p>}
                    </Field>

                    <Field label="Cell Reference">
                        <input
                            type="text"
                            value={form.data.cell_reference}
                            onChange={(event) => form.setData('cell_reference', event.target.value.toUpperCase())}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold uppercase shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-950"
                        />
                    </Field>

                    <div className="flex flex-wrap gap-2 lg:col-span-2">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-brand-700 disabled:opacity-60"
                        >
                            <Save className="h-4 w-4" />
                            Save Standby Fund
                        </button>
                        <button
                            type="button"
                            onClick={sync}
                            disabled={form.processing}
                            className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Sync WIT Cell
                        </button>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{label}</span>
            {children}
        </label>
    );
}

function formatCurrency(value) {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
