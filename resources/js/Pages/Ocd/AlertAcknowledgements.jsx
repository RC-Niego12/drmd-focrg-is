import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock3, Printer, RadioTower, RefreshCw, Search, ShieldAlert, UsersRound } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { isRealtimeConnected, listenRealtime } from '@/realtime';

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '-';

const levelStyles = {
    white: 'border-slate-300 bg-white text-slate-900',
    blue: 'border-blue-600 bg-blue-600 text-white',
    red: 'border-red-600 bg-red-600 text-white',
};

const statusStyles = {
    acknowledged: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
    awaiting: 'bg-amber-100 text-amber-900 ring-amber-200',
    superseded: 'bg-slate-100 text-slate-600 ring-slate-200',
};

function SummaryCard({ label, data, icon: Icon, color }) {
    const rate = data?.total ? Math.round((data.acknowledged / data.total) * 100) : 0;

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-black uppercase tracking-widest text-slate-500">{label}</p>
                    <p className="mt-2 text-3xl font-black">{data?.acknowledged || 0}<span className="text-base text-slate-400"> / {data?.total || 0}</span></p>
                    <p className="mt-1 text-xs font-bold text-slate-500">{data?.awaiting || 0} awaiting acknowledgment</p>
                </div>
                <span className={`flex h-12 w-12 items-center justify-center rounded-xl ${color}`}><Icon className="h-6 w-6" /></span>
            </div>
            <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${rate}%` }} />
            </div>
            <p className="mt-2 text-right text-xs font-black text-emerald-700">{rate}% acknowledged</p>
        </article>
    );
}

export default function AlertAcknowledgements({ alerts = [], selectedAlert, recipients: initialRecipients = [], summary: initialSummary = {} }) {
    const [category, setCategory] = useState('all');
    const [status, setStatus] = useState('all');
    const [search, setSearch] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [recipients, setRecipients] = useState(initialRecipients);
    const [summary, setSummary] = useState(initialSummary);

    const refresh = async () => {
        setRefreshing(true);
        try {
            const query = selectedAlert?.id ? `?alert_id=${selectedAlert.id}` : '';
            const response = await fetch(`/alert-acknowledgments${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const payload = await response.json();
            setRecipients(payload.recipients || []);
            setSummary(payload.summary || {});
        } catch {
            // Retain the last successful consolidated list and poll again later.
        } finally {
            setRefreshing(false);
        }
    };

    useEffect(() => {
        const stopRealtime = listenRealtime('regional-alert.acknowledgement.changed', ({ alert_id: alertId }) => {
            if (!alertId || Number(alertId) === Number(selectedAlert?.id)) refresh();
        });
        const stopAlertChanges = listenRealtime('regional-alert.changed', () => {
            window.location.reload();
        });
        const interval = window.setInterval(
            () => refresh(),
            isRealtimeConnected() ? 300000 : 60000,
        );
        return () => {
            stopRealtime();
            stopAlertChanges();
            window.clearInterval(interval);
        };
    }, [selectedAlert?.id]);

    useEffect(() => {
        setRecipients(initialRecipients);
        setSummary(initialSummary);
    }, [initialRecipients, initialSummary]);

    const visibleRows = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return recipients.filter((row) => {
            if (category !== 'all' && row.recipient_category !== category) return false;
            if (status !== 'all' && row.status !== status) return false;
            if (!needle) return true;
            return [row.recipient_name, row.recipient_role, row.office, row.lgu_name]
                .some((value) => String(value || '').toLowerCase().includes(needle));
        });
    }, [recipients, category, status, search]);

    const selectAlert = (alertId) => {
        window.location.assign(`/alert-acknowledgments?alert_id=${encodeURIComponent(alertId)}`);
    };

    return (
        <AppLayout title="Alert Acknowledgments">
            <Head title="Regional Alert Acknowledgment Monitor" />
            <div className="space-y-6 print:space-y-3">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="grid gap-5 bg-gradient-to-r from-slate-950 via-blue-950 to-brand-800 p-6 text-white lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <p className="text-xs font-black uppercase tracking-[.22em] text-blue-200">OCD Caraga and DSWD Consolidated Monitoring</p>
                            <h1 className="mt-2 text-3xl font-black">Regional Alert Acknowledgment Board</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-blue-100">Live accountability list of LGUs and DSWD DRMD/QRT personnel who received and explicitly acknowledged the selected regional alert.</p>
                        </div>
                        <RadioTower className="h-16 w-16 text-blue-200" />
                    </div>
                    <div className="grid gap-4 p-5 lg:grid-cols-[minmax(280px,.7fr)_1fr_auto] lg:items-end">
                        <label>
                            <span className="text-xs font-black uppercase text-slate-500">Alert issuance</span>
                            <select className="mt-1 w-full rounded-xl" value={selectedAlert?.id || ''} onChange={(event) => selectAlert(event.target.value)}>
                                {alerts.map((alert) => (
                                    <option key={alert.id} value={alert.id}>{String(alert.alert_level).toUpperCase()} · {alert.incident_name || 'Regional monitoring'} · {formatDateTime(alert.effective_at)}</option>
                                ))}
                            </select>
                        </label>
                        {selectedAlert && (
                            <div className={`rounded-xl border-2 px-4 py-3 ${levelStyles[selectedAlert.alert_level] || levelStyles.white}`}>
                                <p className="text-xs font-black uppercase tracking-widest">{selectedAlert.alert_level} Alert · {selectedAlert.coverage}</p>
                                <p className="mt-1 font-black">{selectedAlert.incident_name || 'Regional monitoring'}</p>
                                <p className="mt-1 text-xs opacity-80">Effective {formatDateTime(selectedAlert.effective_at)} · Set by {selectedAlert.setter?.name || 'OCD Caraga'}</p>
                            </div>
                        )}
                        <div className="flex gap-2 print:hidden">
                            <button type="button" onClick={refresh} className="rounded-xl border border-slate-300 p-3 hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-900" title="Refresh now"><RefreshCw className={`h-5 w-5 ${refreshing ? 'animate-spin' : ''}`} /></button>
                            <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white hover:bg-slate-800"><Printer className="h-5 w-5" /> Print List</button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <SummaryCard label="All recipients" data={summary.all} icon={UsersRound} color="bg-indigo-100 text-indigo-700" />
                    <SummaryCard label="LGU accounts" data={summary.lgu} icon={RadioTower} color="bg-emerald-100 text-emerald-700" />
                    <SummaryCard label="DSWD DRMD / QRT" data={summary.dswd} icon={ShieldAlert} color="bg-blue-100 text-blue-700" />
                </section>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                            <div>
                                <h2 className="text-xl font-black">Recipient Accountability List</h2>
                                <p className="mt-1 text-sm text-slate-500">{visibleRows.length} displayed of {recipients.length} notified recipients · refreshes every 15 seconds</p>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-3 print:hidden">
                                <label className="relative">
                                    <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                                    <input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full rounded-xl pl-9 text-sm" placeholder="Search person, LGU, office…" />
                                </label>
                                <select value={category} onChange={(event) => setCategory(event.target.value)} className="rounded-xl text-sm"><option value="all">All recipients</option><option value="lgu">LGUs</option><option value="dswd">DSWD personnel</option></select>
                                <select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-xl text-sm"><option value="all">All statuses</option><option value="acknowledged">Acknowledged</option><option value="awaiting">Awaiting</option><option value="superseded">Superseded</option></select>
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-zinc-900 dark:text-zinc-300">
                                <tr><th className="p-3">#</th><th className="p-3">Recipient</th><th className="p-3">Category / Role</th><th className="p-3">LGU / Office</th><th className="p-3">Notified</th><th className="p-3">Status</th><th className="p-3">Acknowledged</th><th className="p-3">Response Time</th></tr>
                            </thead>
                            <tbody>
                                {visibleRows.map((row, index) => (
                                    <tr key={row.id} className="border-t border-slate-100 align-top dark:border-zinc-800">
                                        <td className="p-3 font-black text-slate-400">{index + 1}</td>
                                        <td className="p-3 font-black">{row.recipient_name}</td>
                                        <td className="p-3"><span className="font-black uppercase">{row.recipient_category === 'lgu' ? 'LGU' : 'DSWD'}</span><p className="mt-1 text-xs text-slate-500">{row.recipient_role || '-'}</p></td>
                                        <td className="p-3">{row.lgu_name || row.office || '-'}</td>
                                        <td className="whitespace-nowrap p-3">{formatDateTime(row.notified_at)}</td>
                                        <td className="p-3"><span className={`inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase ring-1 ${statusStyles[row.status]}`}>{row.status === 'awaiting' ? 'Awaiting acknowledgment' : row.status}</span></td>
                                        <td className="whitespace-nowrap p-3">{formatDateTime(row.acknowledged_at)}</td>
                                        <td className="p-3">{row.response_minutes === null ? '-' : `${row.response_minutes} min`}</td>
                                    </tr>
                                ))}
                            </tbody>
                            {visibleRows.length > 0 && <tfoot className="bg-cyan-50 font-black dark:bg-cyan-950/20"><tr><td className="p-3 uppercase">Total</td><td className="p-3" colSpan="3">{visibleRows.length} recipient(s)</td><td className="p-3" colSpan="2">{visibleRows.filter((row) => row.status === 'awaiting').length} awaiting</td><td className="p-3" colSpan="2">{visibleRows.filter((row) => row.status === 'acknowledged').length} acknowledged</td></tr></tfoot>}
                        </table>
                    </div>
                    {visibleRows.length === 0 && <div className="p-10 text-center"><Clock3 className="mx-auto h-10 w-10 text-slate-300" /><p className="mt-3 font-black">No recipients match the selected filters.</p><p className="mt-1 text-sm text-slate-500">Newly issued alerts populate this board as notifications are distributed.</p></div>}
                </section>
            </div>
        </AppLayout>
    );
}
