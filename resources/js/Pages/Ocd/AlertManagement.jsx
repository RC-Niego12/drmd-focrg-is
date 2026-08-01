import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, BellRing, CalendarClock, CheckCircle2, Clock3, RadioTower } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';

const levels = {
    white: { label: 'White Alert', color: 'border-slate-300 bg-white text-slate-900', accent: 'bg-slate-100', times: '2:00 PM' },
    blue: { label: 'Blue Alert', color: 'border-blue-700 bg-blue-700 text-white', accent: 'bg-blue-50', times: '10:00 AM and 10:00 PM' },
    red: { label: 'Red Alert', color: 'border-red-600 bg-red-600 text-white', accent: 'bg-red-50', times: '10:00 AM and 10:00 PM' },
};

const localInputDateTime = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '-';

export default function AlertManagement({ activeAlert, alerts, reportingTimelines }) {
    const form = useForm({
        alert_level: activeAlert?.alert_level || 'white',
        incident_name: activeAlert?.incident_name || '',
        coverage: activeAlert?.coverage || 'Caraga Region',
        reason: '',
        effective_at: localInputDateTime(),
        expires_at: '',
    });
    const selected = levels[form.data.alert_level];
    const submit = (event) => {
        event.preventDefault();
        form.post('/ocd/alerts', { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    };

    return (
        <AppLayout title="Regional Alert Management">
            <Head title="OCD Caraga Regional Alerts" />
            <div className="space-y-6">
                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="grid gap-5 bg-gradient-to-r from-indigo-950 via-blue-900 to-brand-800 p-6 text-white lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <p className="text-xs font-black uppercase tracking-[.2em] text-blue-200">OCD Caraga · Regional Disaster Alert</p>
                            <h1 className="mt-2 text-3xl font-black">Alert Level and LGU Reporting Timeline</h1>
                            <p className="mt-2 max-w-3xl text-sm text-blue-100">Set the operational alert for Caraga. Every active LGU account is notified of a raised, lowered, or updated alert and reminded before the corresponding DROMIC / Situational Report deadline.</p>
                        </div>
                        <RadioTower className="h-16 w-16 text-blue-200" />
                    </div>
                    <div className="grid gap-4 p-5 md:grid-cols-3">
                        {Object.entries(levels).map(([key, meta]) => (
                            <div key={key} className={`rounded-xl border-2 p-4 ${activeAlert?.alert_level === key ? meta.color : 'border-slate-200 bg-slate-50 dark:border-zinc-800 dark:bg-zinc-900'}`}>
                                <p className="text-xs font-black uppercase tracking-widest">{meta.label}</p>
                                <p className="mt-3 flex items-center gap-2 text-lg font-black"><Clock3 className="h-5 w-5" /> {meta.times}</p>
                                <p className="mt-1 text-xs opacity-80">LGU report transmission to DSWD Field Office Caraga and OCD Caraga</p>
                            </div>
                        ))}
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,.75fr)]">
                    <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-black uppercase tracking-widest text-brand-700">Issue an alert update</p>
                                <h2 className="mt-1 text-xl font-black">Regional Alert Control</h2>
                            </div>
                            <BellRing className="h-7 w-7 text-brand-700" />
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-3">
                            {Object.entries(levels).map(([key, meta]) => (
                                <button key={key} type="button" onClick={() => form.setData('alert_level', key)} className={`rounded-lg border-2 px-4 py-4 text-left transition ${form.data.alert_level === key ? meta.color : 'border-slate-200 hover:border-brand-300 dark:border-zinc-700'}`}>
                                    <span className="block font-black uppercase">{meta.label}</span>
                                    <span className="mt-1 block text-xs opacity-80">{meta.times}</span>
                                </button>
                            ))}
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <label><span className="text-xs font-black">Incident / Hazard Name</span><input className="mt-1 w-full" value={form.data.incident_name} onChange={(e) => form.setData('incident_name', e.target.value)} placeholder="Optional incident reference" /></label>
                            <label><span className="text-xs font-black">Coverage *</span><input required className="mt-1 w-full" value={form.data.coverage} onChange={(e) => form.setData('coverage', e.target.value)} /></label>
                            <label><span className="text-xs font-black">Effective Date and Time *</span><input required type="datetime-local" className="mt-1 w-full" value={form.data.effective_at} onChange={(e) => form.setData('effective_at', e.target.value)} /></label>
                            <label><span className="text-xs font-black">Expiration / Review Time</span><input type="datetime-local" className="mt-1 w-full" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} /></label>
                            <label className="sm:col-span-2"><span className="text-xs font-black">Basis and Operational Guidance *</span><textarea required rows="4" className="mt-1 w-full" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="State why the alert is raised, maintained, or lowered and the action expected from LGUs." /></label>
                        </div>
                        {Object.values(form.errors).length > 0 && <div className="mt-4 rounded-md bg-rose-50 p-3 text-sm font-bold text-rose-700">{Object.values(form.errors)[0]}</div>}
                        <div className={`mt-5 rounded-lg p-4 ${selected.accent} dark:bg-zinc-900`}>
                            <p className="font-black">{selected.label}: reports due at {selected.times}</p>
                            <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">Saving sends an immediate notification to all LGU reporting accounts. Deadline reminders are sent two hours before each due time.</p>
                        </div>
                        <button disabled={form.processing} className="mt-5 w-full rounded-md bg-brand-700 px-5 py-3 font-black text-white disabled:opacity-60">{form.processing ? 'Issuing alert…' : `Issue ${selected.label}`}</button>
                    </form>

                    <aside className="space-y-4">
                        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                            <p className="text-xs font-black uppercase tracking-widest text-brand-700">Current operational status</p>
                            {activeAlert ? (
                                <>
                                    <div className={`mt-3 rounded-lg border-2 p-5 ${levels[activeAlert.alert_level].color}`}>
                                        <p className="text-2xl font-black uppercase">{levels[activeAlert.alert_level].label}</p>
                                        <p className="mt-2 text-sm font-bold">{activeAlert.coverage}</p>
                                    </div>
                                    <dl className="mt-4 space-y-3 text-sm">
                                        <div><dt className="text-xs font-black uppercase text-slate-400">Incident</dt><dd className="font-bold">{activeAlert.incident_name || '-'}</dd></div>
                                        <div><dt className="text-xs font-black uppercase text-slate-400">Effective</dt><dd className="font-bold">{formatDateTime(activeAlert.effective_at)}</dd></div>
                                        <div><dt className="text-xs font-black uppercase text-slate-400">Set by</dt><dd className="font-bold">{activeAlert.setter?.name || 'OCD Caraga'}</dd></div>
                                        <div><dt className="text-xs font-black uppercase text-slate-400">Guidance</dt><dd>{activeAlert.reason}</dd></div>
                                    </dl>
                                    <a href={`/alert-acknowledgments?alert_id=${activeAlert.id}`} className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white hover:bg-slate-800">
                                        <CheckCircle2 className="h-5 w-5" /> View Consolidated Acknowledgments
                                    </a>
                                </>
                            ) : <p className="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-slate-500">No active regional alert has been recorded.</p>}
                        </section>
                        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                            <h3 className="flex items-center gap-2 font-black"><CalendarClock className="h-5 w-5 text-brand-700" /> Standard Reporting Schedule</h3>
                            <div className="mt-4 space-y-3">
                                {Object.entries(reportingTimelines).map(([level, times]) => <div key={level} className="flex items-center justify-between rounded-md bg-slate-50 px-4 py-3 dark:bg-zinc-900"><span className="font-black uppercase">{level}</span><span className="text-sm font-bold">{times.join(' & ')}</span></div>)}
                            </div>
                        </section>
                    </aside>
                </div>

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="border-b border-slate-200 p-5 dark:border-zinc-800"><h2 className="font-black">Alert History</h2><p className="mt-1 text-sm text-slate-500">Audit-friendly record of alert changes and reporting instructions.</p></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-zinc-900"><tr><th className="p-3">Level</th><th className="p-3">Incident</th><th className="p-3">Coverage</th><th className="p-3">Effective</th><th className="p-3">Guidance</th><th className="p-3">Set By</th></tr></thead>
                            <tbody>{(alerts?.data || []).map((alert) => <tr key={alert.id} className="border-t border-slate-100 dark:border-zinc-800"><td className="p-3 font-black uppercase">{alert.alert_level}</td><td className="p-3">{alert.incident_name || '-'}</td><td className="p-3">{alert.coverage}</td><td className="p-3 whitespace-nowrap">{formatDateTime(alert.effective_at)}</td><td className="p-3 min-w-[300px]">{alert.reason}</td><td className="p-3">{alert.setter?.name || '-'}</td></tr>)}</tbody></table>
                    </div>
                    {(alerts?.data || []).length === 0 && <div className="p-8 text-center text-sm text-slate-500">No alert history yet.</div>}
                    {alerts?.links?.length > 3 && <div className="flex gap-1 border-t p-4">{alerts.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded border px-3 py-1 text-xs ${link.active ? 'bg-brand-700 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>}
                </section>
            </div>
        </AppLayout>
    );
}
