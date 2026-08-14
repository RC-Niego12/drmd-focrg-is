import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Eye, ListChecks, RefreshCw, Route, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppLayout, { Card } from '@/Layouts/AppLayout';
import EpirmaSignedDocumentsModal from '@/Components/EpirmaSignedDocumentsModal';
import PdfPreviewModal from '@/Components/PdfPreviewModal';
import SectionTabs from '@/Components/SectionTabs';
import { formatDateTime } from '@/Utils/dateFormat';
import { closeEpirmaTab, navigateEpirmaTab, openEpirmaTabPlaceholder } from '@/Utils/epirmaTab';
import { listenRealtime } from '@/realtime';

const badge = (status) => {
    if (status === 'signed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'partially_signed') return 'bg-sky-100 text-sky-800';
    if (['routed', 'pending'].includes(status)) return 'bg-blue-100 text-blue-800';
    if (['failed', 'cancelled'].includes(status)) return 'bg-rose-100 text-rose-800';
    return 'bg-amber-100 text-amber-900';
};

export default function Index({ queue, filters = {}, summary = {} }) {
    const flash = usePage().props.flash || {};
    const [search, setSearch] = useState(filters.search || '');
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);
    const [tracker, setTracker] = useState(null);
    const [preview, setPreview] = useState(null);
    const realtimeReloadTimer = useRef(null);
    const rows = useMemo(() => queue?.data || [], [queue]);
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const applyFilters = (next = {}) => router.get('/rros-aa/epirma', { search: next.search ?? search, status: next.status ?? filters.status ?? 'active' }, { preserveState: true, replace: true });

    const routeRis = async (row) => {
        setBusyId(row.id); setError(null);
        const tab = openEpirmaTabPlaceholder('Opening RIS in e-PIRMA…');
        try {
            const response = await fetch(`/rros/ris/${row.id}/epirma/route`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() } });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload?.redirect_url) throw new Error(payload?.message || 'Unable to route this RIS.');
            if (!navigateEpirmaTab(tab, payload.redirect_url)) throw new Error('Allow pop-ups for this site so e-PIRMA can open.');
            router.reload({ only: ['queue', 'summary'], preserveScroll: true });
        } catch (exception) {
            closeEpirmaTab(tab); setError(exception.message);
        } finally { setBusyId(null); }
    };

    const refreshRow = async (row) => {
        setBusyId(row.id); setError(null);
        try {
            const response = await fetch(`/rros/ris/${row.id}/epirma/status`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const payload = await response.json().catch(() => null);
            if (!response.ok) throw new Error(payload?.message || 'Unable to refresh status.');
            router.reload({ only: ['queue', 'summary'], preserveScroll: true });
        } catch (exception) { setError(exception.message); }
        finally { setBusyId(null); }
    };

    useEffect(() => {
        const activeRows = rows.filter((row) => ['pending', 'routed', 'partially_signed'].includes(row.status));
        if (activeRows.length === 0) return undefined;
        const poll = window.setInterval(async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const results = await Promise.all(activeRows.slice(0, 20).map(async (row) => {
                    const response = await fetch(`/rros/ris/${row.id}/epirma/status`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const payload = response.ok ? await response.json().catch(() => null) : null;
                    return payload?.workflow?.status !== row.status;
                }));
                if (results.some(Boolean)) router.reload({ only: ['queue', 'summary'], preserveScroll: true, preserveState: true });
            } catch { /* Manual refresh remains available during transient failures. */ }
        }, 30000);
        return () => window.clearInterval(poll);
    }, [rows]);

    useEffect(() => {
        const stop = listenRealtime('ris.epirma.status.changed', (payload = {}) => {
            const applyWorkflow = (current) => {
                if (!current || Number(current.id) !== Number(payload.ris_id) || !payload.workflow) return current;
                return {
                    ...current,
                    status: payload.workflow.status,
                    signed_at: payload.workflow.signed_at,
                    document: payload.workflow.document,
                    preview_kind: payload.workflow.complete ? 'signed' : 'draft',
                    preview_url: payload.workflow.signed_preview_url || current.preview_url,
                    can_route: false,
                };
            };
            setTracker(applyWorkflow);
            setPreview(applyWorkflow);
            window.clearTimeout(realtimeReloadTimer.current);
            realtimeReloadTimer.current = window.setTimeout(() => {
                router.reload({ only: ['queue', 'summary'], preserveScroll: true, preserveState: true });
            }, 150);
        });
        return () => {
            stop();
            window.clearTimeout(realtimeReloadTimer.current);
        };
    }, []);

    useEffect(() => {
        const stop = listenRealtime('epirma.status.changed', async (payload = {}) => {
            if (payload.document_type && String(payload.document_type).toLowerCase() !== 'ris') return;
            const row = rows.find((candidate) => Number(candidate.request_id) === Number(payload.request_id));
            if (!row) return;
            try {
                await fetch(`/rros/ris/${row.id}/epirma/status`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch { /* The existing low-frequency poll remains the fallback. */ }
        });
        return stop;
    }, [rows]);

    useEffect(() => {
        setTracker((current) => current ? rows.find((row) => row.id === current.id) || current : null);
        setPreview((current) => current ? rows.find((row) => row.id === current.id) || current : null);
    }, [rows]);

    return <AppLayout title="e-PIRMA">
        <Head title="RROS AA · e-PIRMA" />
        <Card className="overflow-hidden p-0">
            <div className="border-b border-slate-200 bg-gradient-to-r from-emerald-50 via-white to-blue-50 px-5 py-5">
                <p className="text-xs font-black uppercase tracking-wide text-emerald-700">RROS AA workspace</p>
                <h1 className="mt-1 text-2xl font-black text-slate-900">e-PIRMA</h1>
                <p className="mt-1 max-w-3xl text-sm font-semibold leading-6 text-slate-600">RIS documents forwarded by their RROS preparers appear here. Review the routing copy before submission, then preview the signed RIS after e-PIRMA signing is complete.</p>
                <div className="mt-4 grid gap-3 sm:grid-cols-3">{[
                    ['Pending', summary.pending || 0, 'text-amber-700'], ['In progress', summary.in_progress || 0, 'text-blue-700'], ['Completed', summary.completed || 0, 'text-emerald-700'],
                ].map(([label, value, tone]) => <div key={label} className="rounded-lg border border-slate-200 bg-white/90 px-4 py-3"><p className="text-[11px] font-black uppercase text-slate-500">{label}</p><p className={`mt-1 text-2xl font-black ${tone}`}>{value}</p></div>)}</div>
            </div>
            <div className="flex flex-col gap-3 border-b bg-slate-50 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                <form className="flex flex-1 gap-2" onSubmit={(event) => { event.preventDefault(); applyFilters({ search }); }}><div className="relative w-full max-w-md"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search RIS, request, or recipient" className="w-full rounded-md border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm font-semibold" /></div><button className="rounded-md bg-slate-900 px-4 py-2 text-xs font-black text-white">Search</button></form>
                <SectionTabs appearance="plain" value={filters.status || 'active'} onChange={(status) => applyFilters({ status })} tabs={[{ id: 'active', label: 'Active', icon: ListChecks, count: (summary.pending || 0) + (summary.in_progress || 0) }, { id: 'completed', label: 'Completed', icon: CheckCircle2, count: summary.completed || 0 }]} />
            </div>
            {(flash.success || error) && <p className={`mx-5 mt-4 rounded-lg border px-4 py-3 text-sm font-bold ${error ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}>{error || flash.success}</p>}
            <div className="max-h-[calc(100vh-390px)] min-h-64 overflow-auto p-4">
                <table className="min-w-full overflow-hidden rounded-lg border border-slate-200 text-left text-sm"><thead className="sticky top-0 z-10 bg-slate-100 text-[10px] font-black uppercase tracking-wide text-slate-600"><tr>{['#', 'RIS / Request', 'Recipient / Delivery Site', 'Purpose / Source', 'Routing Status', 'Actions'].map((label) => <th key={label} className={`px-4 py-3 ${label === 'Actions' ? 'text-right' : ''}`}>{label}</th>)}</tr></thead>
                    <tbody>{rows.map((row, index) => <tr key={row.id} className="border-t border-slate-100 bg-white align-top"><td className="px-4 py-4 text-center text-xs font-black text-slate-500">{(queue.from || 1) + index}</td><td className="px-4 py-4"><p className="font-black text-slate-900">{row.ris_number}</p><p className="mt-1 text-xs font-bold text-blue-700">{row.reference_number}</p><p className="mt-1 text-[10px] text-slate-500">DR: {row.dr_number || '—'}</p></td><td className="px-4 py-4"><p className="font-bold">{row.recipient}</p><p className="mt-1 text-xs text-slate-500">{row.delivery_site || '—'}</p></td><td className="px-4 py-4"><p>{row.purpose || '—'}</p><p className="mt-1 text-xs text-slate-500">Prepared by {row.prepared_by || 'RROS'}</p></td><td className="px-4 py-4"><span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${badge(row.status)}`}>{String(row.status || 'forwarded').replaceAll('_', ' ')}</span><p className="mt-2 text-[10px] text-slate-500">{formatDateTime(row.signed_at || row.routed_at || row.forwarded_at, 'Awaiting action')}</p></td><td className="px-4 py-4 text-right"><div className="inline-flex flex-wrap justify-end gap-2"><button type="button" onClick={() => setPreview(row)} className="rounded-md border border-slate-200 bg-white p-2 text-slate-700" title={row.preview_kind === 'signed' ? 'Preview signed RIS' : 'Preview routing copy'}><Eye className="h-4 w-4" /></button>{row.can_route && <button type="button" disabled={busyId === row.id} onClick={() => routeRis(row)} className="inline-flex items-center gap-1.5 rounded-md bg-blue-700 px-3 py-2 text-xs font-black text-white disabled:opacity-50"><Route className="h-4 w-4" /> Route RIS</button>}<button type="button" disabled={busyId === row.id} onClick={() => refreshRow(row)} className="rounded-md border border-slate-200 bg-white p-2" title="Refresh status"><RefreshCw className={`h-4 w-4 ${busyId === row.id ? 'animate-spin' : ''}`} /></button><button type="button" onClick={() => setTracker(row)} className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black">Track</button></div></td></tr>)}</tbody>
                </table>{rows.length === 0 && <p className="py-12 text-center text-sm font-semibold text-slate-500">No RIS e-PIRMA records in this view.</p>}
            </div>
            {queue?.links?.length > 3 && <div className="flex flex-wrap justify-end gap-1 border-t px-5 py-3">{queue.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded border px-3 py-1.5 text-xs font-bold ${link.active ? 'bg-emerald-700 text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1.5 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
        </Card>
        <EpirmaSignedDocumentsModal open={Boolean(tracker)} documents={tracker?.document ? [tracker.document] : []} busy={busyId === tracker?.id} canRetry={false} onClose={() => setTracker(null)} onRefresh={() => refreshRow(tracker)} onView={() => setPreview(tracker)} />
        <PdfPreviewModal open={Boolean(preview)} onClose={() => setPreview(null)} title={preview?.preview_kind === 'signed' ? 'Signed RIS Preview' : 'RIS Routing Preview'} subtitle={preview?.ris_number} src={preview?.preview_url} kind={preview?.preview_kind || 'draft'} message={preview?.preview_kind === 'signed' ? 'Official signed RIS retrieved through the e-PIRMA document pipeline.' : 'Unsigned routing source — e-PIRMA applies signatures to its routed copy.'} />
    </AppLayout>;
}
