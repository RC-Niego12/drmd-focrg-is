import { Head, Link, useForm } from '@inertiajs/react';
import { BarChart3, Bot, Download, FilePlus2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatDate } from '@/Utils/dateFormat';

const emptyPayload = (lguProfile, defaultIncidentDate) => ({
    requesting_lgu: lguProfile?.name || '',
    requester_name: '',
    requester_position: '',
    requester_address: '',
    contact_number: '',
    incident_name: '',
    incident_date: defaultIncidentDate || '',
    incident_summary: '',
    province: '',
    municipality: lguProfile?.name || '',
    barangay: '',
    affected_families: '',
    affected_persons: '',
    displaced_families: '',
    damaged_houses: '',
    casualties: '',
    needs: '',
    relief_requested: '',
    has_relief_request: false,
    narrative: '',
    recommendations: '',
    remarks: '',
});

export default function Index({ lguProfile, defaultIncidentDate, requests, monitoringSummary = {} }) {
    const [open, setOpen] = useState(false);
    const [aiMessage, setAiMessage] = useState('');
    const [aiBusy, setAiBusy] = useState(false);
    const form = useForm(emptyPayload(lguProfile, defaultIncidentDate));
    const isProvince = Boolean(lguProfile?.is_province);
    const withReliefRequest = Boolean(form.data.has_relief_request);
    const error = (field) => form.errors[field] && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors[field]}</p>;

    const facts = () => ({
        requesting_lgu: form.data.requesting_lgu,
        incident_name: form.data.incident_name,
        incident_date: form.data.incident_date,
        location: [form.data.barangay, form.data.municipality, form.data.province].filter(Boolean).join(', '),
        affected_families: form.data.affected_families,
        affected_persons: form.data.affected_persons,
        displaced_families: form.data.displaced_families,
        damaged_houses: form.data.damaged_houses,
        casualties: form.data.casualties,
        needs: form.data.needs,
        relief_requested: form.data.relief_requested,
        has_relief_request: form.data.has_relief_request ? 'Yes' : 'No',
        recommendations: form.data.recommendations,
        incident_summary: form.data.incident_summary,
    });

    const enhanceNarrative = async (mode) => {
        setAiBusy(true);
        setAiMessage('');
        try {
            const { data } = await window.axios.post('/lgu/dromic-requests/polish', {
                mode,
                text: form.data.narrative,
                facts: facts(),
            });
            form.setData('narrative', data.polished || '');
            setAiMessage(`${mode === 'generate' ? 'Generated' : 'Polished'} with ${data.provider || 'AI'}. Please review before submitting.`);
        } catch (error) {
            setAiMessage(error?.response?.data?.message || 'AI helper is unavailable. You can still encode and submit manually.');
        } finally {
            setAiBusy(false);
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.post('/lgu/dromic-requests', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData(emptyPayload(lguProfile, defaultIncidentDate));
                setOpen(false);
            },
        });
    };

    return (
        <AppLayout title="LGU DROMIC Requests">
            <Head title={isProvince ? 'PLGU DROMIC Monitoring' : 'LGU DROMIC Reports'} />
            <div className="space-y-6">
                <Card>
                    <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-xs font-black uppercase tracking-wide text-emerald-700">{isProvince ? 'PLGU Monitoring View' : 'LGU Disaster Reporting'}</p>
                            <h1 className="mt-1 text-2xl font-black">{isProvince ? 'Monitor City / Municipal DROMIC Reports' : 'Submit DROMIC Report'}</h1>
                            <p className="mt-1 text-sm text-slate-500">
                                {isProvince
                                    ? 'View consolidated and individual city/municipal reports in your province. Relief request processing details are intentionally kept out of this monitoring view.'
                                    : 'Encode template-style incident facts. If you also need relief augmentation, tick the request option before submitting.'}
                            </p>
                            <p className="mt-2 text-sm font-bold text-slate-700 dark:text-zinc-200">Signed LGU: {lguProfile?.name || 'LGU account'} {lguProfile?.psgc_code ? `(${lguProfile.psgc_code})` : ''}</p>
                        </div>
                        {!isProvince && (
                            <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
                                <FilePlus2 className="h-4 w-4" /> Submit DROMIC report
                            </button>
                        )}
                    </div>
                </Card>

                {isProvince && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <SummaryCard title="Reports monitored" value={monitoringSummary.reports} />
                        <SummaryCard title="Cities / municipalities" value={monitoringSummary.cities_municipalities} />
                        <SummaryCard title="Affected families" value={monitoringSummary.affected_families} />
                        <SummaryCard title="Reports with relief request" value={monitoringSummary.with_requests} />
                    </div>
                )}

                <Card>
                    <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                        <h2 className="font-black">{isProvince ? 'City / Municipal LGU DROMIC reports' : 'Submitted DROMIC reports'}</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {isProvince
                                ? 'Open individual reports or use the figures above for quick provincial monitoring.'
                                : 'Track submitted reports. Reports with relief requests will show DRMD routing status.'}
                        </p>
                    </div>
                    <DataTable columns={isProvince ? ['Reference', 'Submitting LGU', 'Incident', 'Location', 'Affected Families', 'Submitted', 'PDF'] : ['Reference', 'Incident', 'Location', 'Submitted', 'Type / Routing', 'PDF']} rows={(requests?.data ?? []).map((row) => (
                        <tr key={row.id}>
                            <td className="whitespace-nowrap px-4 py-3 font-black">{row.reference_number}</td>
                            {isProvince && <td className="px-4 py-3 font-bold">{row.requesting_agency}</td>}
                            <td className="px-4 py-3">{row.incident?.name || row.lgu_dromic_payload?.incident_name || '-'}</td>
                            <td className="px-4 py-3">{[row.barangay, row.municipality, row.province].filter(Boolean).join(', ')}</td>
                            {isProvince && <td className="whitespace-nowrap px-4 py-3">{Number(row.affected_families || row.lgu_dromic_payload?.affected_families || 0).toLocaleString()}</td>}
                            <td className="whitespace-nowrap px-4 py-3">{formatDate(row.submitted_at)}</td>
                            {!isProvince && <td className="px-4 py-3"><StatusBadge row={row} /></td>}
                            <td className="whitespace-nowrap px-4 py-3"><a href={`/lgu/dromic-requests/${row.id}/pdf?inline=1`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-bold text-brand-700 underline"><Download className="h-3.5 w-3.5" /> Open</a></td>
                        </tr>
                    ))} />
                    {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">{isProvince ? 'No city/municipal LGU DROMIC reports are available for this province yet.' : 'No DROMIC reports submitted yet.'}</div>}
                    {requests?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </Card>
            </div>

            {open && (
                <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 py-6 backdrop-blur-sm">
                    <form onSubmit={submit} className="w-full max-w-5xl overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-900">
                        <div className="sticky top-0 z-10 border-b border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 className="text-xl font-black">Submit LGU DROMIC report</h2>
                                    <p className="mt-1 text-sm text-slate-500">Start with the required report details. Relief augmentation is optional and only appears if selected below.</p>
                                </div>
                                <button type="button" onClick={() => setOpen(false)} className="rounded-md border px-3 py-2 text-sm font-bold">Close</button>
                            </div>
                        </div>
                        <div className="max-h-[calc(100vh-11rem)] overflow-y-auto p-5">
                            <div className="grid gap-4 lg:grid-cols-2">
                                <Input label="Requesting LGU" value={form.data.requesting_lgu} onChange={(value) => form.setData('requesting_lgu', value)} error={error('requesting_lgu')} required />
                                <Input label="Authorized requester / focal person" value={form.data.requester_name} onChange={(value) => form.setData('requester_name', value)} error={error('requester_name')} required />
                                <Input label="Requester position" value={form.data.requester_position} onChange={(value) => form.setData('requester_position', value)} />
                                <Input label="Contact number" value={form.data.contact_number} onChange={(value) => form.setData('contact_number', value)} />
                                <Input label="Incident name" value={form.data.incident_name} onChange={(value) => form.setData('incident_name', value)} error={error('incident_name')} required />
                                <Input label="Incident date" type="date" value={form.data.incident_date} onChange={(value) => form.setData('incident_date', value)} error={error('incident_date')} required />
                                <Input label="Province" value={form.data.province} onChange={(value) => form.setData('province', value)} error={error('province')} required />
                                <Input label="City / Municipality" value={form.data.municipality} onChange={(value) => form.setData('municipality', value)} error={error('municipality')} required />
                                <Input label="Barangay/s" value={form.data.barangay} onChange={(value) => form.setData('barangay', value)} />
                                <Input label="Requester address" value={form.data.requester_address} onChange={(value) => form.setData('requester_address', value)} />
                                <Input label="Affected families" type="number" value={form.data.affected_families} onChange={(value) => form.setData('affected_families', value)} />
                                <Input label="Affected persons" type="number" value={form.data.affected_persons} onChange={(value) => form.setData('affected_persons', value)} />
                                <Input label="Displaced families" type="number" value={form.data.displaced_families} onChange={(value) => form.setData('displaced_families', value)} />
                                <Input label="Damaged houses" type="number" value={form.data.damaged_houses} onChange={(value) => form.setData('damaged_houses', value)} />
                            </div>
                            <div className="mt-4 grid gap-4">
                                <Textarea label="Incident summary / situationer" value={form.data.incident_summary} onChange={(value) => form.setData('incident_summary', value)} />
                                <Textarea label="Casualties / other affected details" value={form.data.casualties} onChange={(value) => form.setData('casualties', value)} />
                                <Textarea label="Identified needs / gaps" value={form.data.needs} onChange={(value) => form.setData('needs', value)} />
                                <label className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                                    <span className="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            checked={withReliefRequest}
                                            onChange={(event) => form.setData('has_relief_request', event.target.checked)}
                                        />
                                        <span>
                                            <span className="block font-black text-slate-900 dark:text-white">Include a Request for Relief Augmentation</span>
                                            <span className="mt-1 block text-slate-500 dark:text-zinc-400">Leave this unchecked if you only need to submit a DROMIC report for monitoring and verification. Check it only when you are formally requesting FNI or other relief augmentation from DRMD.</span>
                                        </span>
                                    </span>
                                </label>
                                {withReliefRequest && (
                                    <Textarea label="Requested relief augmentation" value={form.data.relief_requested} onChange={(value) => form.setData('relief_requested', value)} error={error('relief_requested')} required />
                                )}
                                <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="font-black text-emerald-900 dark:text-emerald-100">Narrative helper</p>
                                            <p className="text-sm text-emerald-800 dark:text-emerald-200">Use Groq AI to draft or polish, then review before submitting.</p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <button type="button" disabled={aiBusy} onClick={() => enhanceNarrative('generate')} className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-xs font-black text-white disabled:opacity-60"><Bot className="h-4 w-4" /> Auto-generate</button>
                                            <button type="button" disabled={aiBusy} onClick={() => enhanceNarrative('polish')} className="inline-flex items-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-xs font-black text-white disabled:opacity-60"><Sparkles className="h-4 w-4" /> Polish</button>
                                        </div>
                                    </div>
                                    {aiMessage && <p className="mt-3 text-sm font-bold text-emerald-900 dark:text-emerald-100">{aiMessage}</p>}
                                </div>
                                <Textarea label="DROMIC narrative" rows={7} value={form.data.narrative} onChange={(value) => form.setData('narrative', value)} error={error('narrative')} required />
                                <Textarea label="Recommendations" value={form.data.recommendations} onChange={(value) => form.setData('recommendations', value)} />
                                <Textarea label="LGU remarks" value={form.data.remarks} onChange={(value) => form.setData('remarks', value)} />
                            </div>
                        </div>
                        <div className="sticky bottom-0 flex justify-end gap-3 border-t border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <button type="button" onClick={() => setOpen(false)} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                            <button disabled={form.processing} className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-60">{form.processing ? 'Submitting...' : withReliefRequest ? 'Submit report + request' : 'Submit DROMIC report only'}</button>
                        </div>
                    </form>
                </div>
            )}
        </AppLayout>
    );
}

function SummaryCard({ title, value }) {
    return (
        <Card>
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500">{title}</p>
                    <p className="mt-2 text-3xl font-black">{Number(value || 0).toLocaleString()}</p>
                </div>
                <div className="flex h-11 w-11 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100">
                    <BarChart3 className="h-5 w-5" />
                </div>
            </div>
        </Card>
    );
}

function StatusBadge({ row }) {
    const hasRequest = Boolean(row.lgu_dromic_payload?.has_relief_request);
    const label = hasRequest ? (row.lgu_routing_status || row.status || 'submitted').replaceAll('_', ' ') : 'report only';
    const classes = hasRequest ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700';

    return <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${classes}`}>{label}</span>;
}

function Input({ label, value, onChange, error, type = 'text', required = false }) {
    return (
        <label className="block text-sm font-bold text-slate-700 dark:text-zinc-100">
            {label}{required && <span className="text-rose-600"> *</span>}
            <input type={type} className="mt-1 w-full" value={value ?? ''} onChange={(event) => onChange(event.target.value)} required={required} />
            {error}
        </label>
    );
}

function Textarea({ label, value, onChange, error, rows = 3, required = false }) {
    return (
        <label className="block text-sm font-bold text-slate-700 dark:text-zinc-100">
            {label}{required && <span className="text-rose-600"> *</span>}
            <textarea rows={rows} className="mt-1 w-full" value={value ?? ''} onChange={(event) => onChange(event.target.value)} required={required} />
            {error}
        </label>
    );
}
