import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle2, Eye, FilePenLine, ListChecks, Play, Route, Search, X } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import EpirmaSignedDocumentsModal from '@/Components/EpirmaSignedDocumentsModal';
import PdfPreviewModal from '@/Components/PdfPreviewModal';
import SectionTabs from '@/Components/SectionTabs';
import { formatDate, formatDateTime } from '@/Utils/dateFormat';
import { closeEpirmaTab, navigateEpirmaTab, openEpirmaTabPlaceholder } from '@/Utils/epirmaTab';
import { listenRealtime } from '@/realtime';

function statusBadge(status) {
    const value = String(status || 'pending');
    if (value === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (value === 'in_progress') return 'bg-sky-100 text-sky-800';
    return 'bg-amber-100 text-amber-900';
}

function docBadge(doc) {
    const value = String(doc?.routing_status || 'none');
    if (value === 'signed' || value === 'completed') return { label: 'SIGNED', className: 'bg-emerald-600 text-white' };
    if (value === 'partially_signed') return { label: 'PARTIAL', className: 'bg-sky-600 text-white' };
    if (value === 'routed' || value === 'pending') return { label: 'IN e-PIRMA', className: 'bg-amber-500 text-white' };
    if (value === 'cancelled') return { label: 'CANCELLED', className: 'bg-rose-600 text-white' };
    if (value === 'failed') return { label: 'FAILED', className: 'bg-rose-600 text-white' };
    return { label: 'NOT ROUTED', className: 'bg-slate-400 text-white' };
}

export default function Index({ queue, filters = {}, summary = {} }) {
    const flash = usePage().props.flash ?? {};
    const [search, setSearch] = useState(filters.search || '');
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);
    const [tracker, setTracker] = useState({ open: false, row: null });
    const [drnEditor, setDrnEditor] = useState({ open: false, row: null, assessment_drn: '', response_drn: '', errors: {}, saving: false });
    const [pdfPreview, setPdfPreview] = useState({ open: false, title: '', subtitle: null, src: null, kind: null, message: null });
    const openDrnEditor = (row) => setDrnEditor({ open: true, row, assessment_drn: row.assessment_drn || '', response_drn: row.response_drn || '', errors: {}, saving: false });
    const saveDocumentDrns = async (event) => {
        event.preventDefault();
        setDrnEditor((current) => ({ ...current, saving: true, errors: {} }));
        const response = await fetch(`/requests/${drnEditor.row.id}/epirma/document-drns`, {
            method: 'PATCH', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ assessment_drn: drnEditor.assessment_drn, response_drn: drnEditor.response_drn }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            setDrnEditor((current) => ({ ...current, saving: false, errors: payload.errors || { general: payload.message || 'Unable to save document DRNs.' } }));
            return;
        }
        setDrnEditor({ open: false, row: null, assessment_drn: '', response_drn: '', errors: {}, saving: false });
        router.reload({ preserveScroll: true });
    };
    const realtimeReloadTimer = useRef(null);

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const queueIdsRef = useRef([]);

    useEffect(() => {
        queueIdsRef.current = (queue?.data || []).map((row) => row.id).filter(Boolean).slice(0, 30);
    }, [queue?.data]);

    const softReloadQueue = () => {
        window.clearTimeout(realtimeReloadTimer.current);
        realtimeReloadTimer.current = window.setTimeout(() => {
            router.reload({
                only: ['queue', 'summary'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 350);
    };

    useEffect(() => {
        const stopStatus = listenRealtime('epirma.status.changed', () => {
            softReloadQueue();
        });

        // Fallback when sockets miss an event: soft-sync open/cancelled rows periodically.
        const poll = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            const ids = queueIdsRef.current;
            if (ids.length === 0) {
                return;
            }
            fetch('/drrs-aa/epirma/sync-open', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ids }),
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((payload) => {
                    if (payload?.changed) {
                        softReloadQueue();
                    }
                })
                .catch(() => {
                    // Ignore transient poll failures; socket/manual sync remain primary.
                });
        }, 45000);

        return () => {
            stopStatus();
            window.clearInterval(poll);
            window.clearTimeout(realtimeReloadTimer.current);
        };
    }, []);

    const rows = useMemo(() => queue?.data || [], [queue]);

    const applyFilters = (next = {}) => {
        router.get('/drrs-aa/epirma', {
            search: next.search ?? search,
            status: next.status ?? filters.status ?? 'active',
        }, { preserveState: true, replace: true });
    };

    const startRoute = async (row, documentType) => {
        setBusyId(row.id);
        setError(null);
        const tab = openEpirmaTabPlaceholder();
        try {
            const response = await fetch(`/requests/${row.id}/epirma/route`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ document_type: documentType }),
            });
            const payload = await response.json().catch(() => null);
            if (payload?.redirect_url) {
                if (!navigateEpirmaTab(tab, payload.redirect_url)) {
                    setError('Allow pop-ups for this site so the e-PIRMA transaction can open in a new tab.');
                }
                router.reload({ only: ['queue', 'summary'] });
                return;
            }
            closeEpirmaTab(tab);
            setError(payload?.message || 'Unable to start e-PIRMA routing.');
        } catch {
            closeEpirmaTab(tab);
            setError('Unable to start e-PIRMA routing right now.');
        } finally {
            setBusyId(null);
        }
    };

    const continueRoute = async (row, documentType) => {
        setBusyId(row.id);
        setError(null);
        const tab = openEpirmaTabPlaceholder();
        try {
            const response = await fetch(`/requests/${row.id}/epirma/continue`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ document_type: documentType }),
            });
            const payload = await response.json().catch(() => null);
            if (payload?.redirect_url) {
                if (!navigateEpirmaTab(tab, payload.redirect_url)) {
                    setError('Allow pop-ups for this site so the e-PIRMA transaction can open in a new tab.');
                }
                router.reload({ only: ['queue', 'summary'] });
                return;
            }
            closeEpirmaTab(tab);
            setError(payload?.message || 'Unable to continue e-PIRMA routing.');
        } catch {
            closeEpirmaTab(tab);
            setError('Unable to continue e-PIRMA routing right now.');
        } finally {
            setBusyId(null);
        }
    };

    const syncRow = async (row) => {
        setBusyId(row.id);
        setError(null);
        try {
            await fetch(`/drrs-aa/epirma/${row.id}/sync`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
            });
            router.reload({ only: ['queue', 'summary'] });
        } catch {
            setError('Unable to sync document status.');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <AppLayout title="e-Pirma">
            <Head title="DRRS AA · e-Pirma" />
            <div className="space-y-4">
                <Card className="overflow-hidden p-0">
                    <div className="border-b border-slate-200 bg-gradient-to-r from-orange-50 via-white to-sky-50 px-5 py-4 dark:border-zinc-800 dark:from-orange-950/30 dark:via-zinc-950 dark:to-sky-950/20">
                        <p className="text-xs font-black uppercase tracking-wide text-orange-700 dark:text-orange-300">DRRS AA workspace</p>
                        <h1 className="mt-0.5 text-2xl font-black text-slate-900 dark:text-zinc-50">e-Pirma Routing</h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600 dark:text-zinc-300">
                            Assessments forwarded by DRRS PDRC appear here. Route the assessment and response letter through e-PIRMA, track signer progress, then RROS and the concerned LGU are notified when signing completes.
                        </p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            {[
                                { label: 'Pending', value: summary.pending || 0, tone: 'text-amber-700' },
                                { label: 'In progress', value: summary.in_progress || 0, tone: 'text-sky-700' },
                                { label: 'Completed', value: summary.completed || 0, tone: 'text-emerald-700' },
                            ].map((item) => (
                                <div key={item.label} className="rounded-md border border-slate-200 bg-white/80 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/70">
                                    <p className="text-[11px] font-black uppercase tracking-wide text-slate-500">{item.label}</p>
                                    <p className={`mt-1 text-2xl font-black ${item.tone}`}>{item.value}</p>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-900/60 lg:flex-row lg:items-center lg:justify-between">
                        <form
                            className="flex flex-1 items-center gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilters({ search });
                            }}
                        >
                            <div className="relative w-full max-w-md">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Search reference or LGU"
                                    className="w-full rounded-md border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm font-semibold dark:border-zinc-700 dark:bg-zinc-950"
                                />
                            </div>
                            <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-xs font-black text-white dark:bg-zinc-100 dark:text-zinc-900">Search</button>
                        </form>
                        <SectionTabs
                            appearance="plain"
                            value={filters.status || 'active'}
                            onChange={(status) => applyFilters({ status })}
                            ariaLabel="e-Pirma queue views"
                            tabs={[
                                { id: 'active', label: 'Active', icon: ListChecks, count: (summary.pending || 0) + (summary.in_progress || 0) },
                                { id: 'completed', label: 'Completed', icon: CheckCircle2, count: summary.completed || 0 },
                            ]}
                        />
                    </div>

                    {(flash.success || error) && (
                        <p className={`mx-5 mt-4 rounded-md border px-3 py-2 text-xs font-bold ${error ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}>
                            {error || flash.success}
                        </p>
                    )}

                    <div className="p-4">
                        <DataTable
                            columns={['#', 'Request Details', 'Request DRN', 'Requesting Party / Office', 'Purpose / Disaster Incident', 'Routing Status', { label: 'Actions', align: 'right', actionColumn: true }]}
                            numbered={false}
                            rows={rows.map((row, index) => {
                                const assessmentDoc = row.capabilities?.assessment?.route_document
                                    || (row.documents || []).find((doc) => doc.document_type === 'assessment' && doc.action === 'route');
                                const responseDoc = row.capabilities?.response_letter?.route_document
                                    || (row.documents || []).find((doc) => doc.document_type === 'response_letter' && doc.action === 'route');
                                const assessmentBadge = docBadge(assessmentDoc);
                                const responseBadge = docBadge(responseDoc);
                                const busy = busyId === row.id;
                                const isCompleted = row.is_completed || row.epirma_aa_status === 'completed';
                                const purpose = row.assessment_form_data?.response_purpose || row.purpose || '-';
                                return (
                                    <tr key={row.id}>
                                        <td className="px-4 py-3 text-center text-xs font-black text-slate-500">{index + 1}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-black">{row.reference_number}</p>
                                            <p className="mt-1 text-[11px] font-semibold text-slate-500">
                                                {row.submission_type === 'proposal' ? `Proposal - ${row.proposal_type || 'Unspecified'}` : 'FNI Request'} · Received {formatDate(row.date_received_by_drmd || row.date_requested)}
                                            </p>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {row.request_drn || '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-bold">{row.requesting_agency}</p>
                                            <p className="mt-1 text-xs text-slate-500">{row.office_agency_details || '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{purpose}</p>
                                            <p className="mt-1 text-xs text-slate-500">{purpose === 'Relief Augmentation' ? (row.incident?.name || '-') : '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${statusBadge(row.epirma_aa_status)}`}>
                                                {isCompleted ? 'completed' : String(row.epirma_aa_status || 'pending').replace('_', ' ')}
                                            </span>
                                            <p className="mt-1 text-[10px] text-slate-500">Assessment: {assessmentBadge.label}</p>
                                            <p className="text-[10px] text-slate-500">Response: {responseBadge.label}</p>
                                            {row.lgu_acked_at && (
                                                <p className="mt-1 inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700">
                                                    <CheckCircle2 className="h-3 w-3" /> LGU acknowledged
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="inline-flex flex-wrap items-center justify-end gap-2">
                                                {!isCompleted && (
                                                    <button type="button" onClick={() => openDrnEditor(row)} title="Assign Assessment and Response Letter DRNs" className="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-2.5 py-1.5 text-[11px] font-black text-emerald-800">
                                                        <FilePenLine className="h-3.5 w-3.5" /> {row.assessment_drn && row.response_drn ? 'Edit DRNs' : 'Set DRNs'}
                                                    </button>
                                                )}
                                                {!isCompleted && (
                                                    <>
                                                        {row.capabilities?.assessment?.can_continue ? (
                                                            <button
                                                                type="button"
                                                                disabled={busy}
                                                                title="Continue incomplete assessment e-PIRMA route"
                                                                onClick={() => continueRoute(row, 'assessment')}
                                                                className="inline-flex items-center gap-1 rounded-md bg-amber-600 px-2.5 py-1.5 text-[11px] font-black text-white disabled:opacity-50"
                                                            >
                                                                <Play className="h-3.5 w-3.5" /> Continue Assessment
                                                            </button>
                                                        ) : row.capabilities?.assessment?.can_route ? (
                                                            <button
                                                                type="button"
                                                                disabled={busy}
                                                                title={row.capabilities?.assessment?.route_blocked_reason || 'Route assessment'}
                                                                onClick={() => startRoute(row, 'assessment')}
                                                                className="inline-flex items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1.5 text-[11px] font-black text-white disabled:opacity-50"
                                                            >
                                                                <Route className="h-3.5 w-3.5" /> Assessment
                                                            </button>
                                                        ) : (
                                                            <button type="button" disabled title={row.capabilities?.assessment?.route_blocked_reason} className="inline-flex items-center gap-1 rounded-md bg-slate-300 px-2.5 py-1.5 text-[11px] font-black text-slate-600 opacity-70"><Route className="h-3.5 w-3.5" /> Assessment</button>
                                                        )}
                                                        {row.capabilities?.response_letter?.can_continue ? (
                                                            <button
                                                                type="button"
                                                                disabled={busy}
                                                                title="Continue incomplete response letter e-PIRMA route"
                                                                onClick={() => continueRoute(row, 'response_letter')}
                                                                className="inline-flex items-center gap-1 rounded-md bg-amber-700 px-2.5 py-1.5 text-[11px] font-black text-white disabled:opacity-50"
                                                            >
                                                                <Play className="h-3.5 w-3.5" /> Continue Response
                                                            </button>
                                                        ) : row.capabilities?.response_letter?.can_route ? (
                                                            <button
                                                                type="button"
                                                                disabled={busy}
                                                                title={row.capabilities?.response_letter?.route_blocked_reason || 'Route response letter'}
                                                                onClick={() => startRoute(row, 'response_letter')}
                                                                className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2.5 py-1.5 text-[11px] font-black text-white disabled:opacity-50"
                                                            >
                                                                <Route className="h-3.5 w-3.5" /> Response
                                                            </button>
                                                        ) : (
                                                            <button type="button" disabled title={row.capabilities?.response_letter?.route_blocked_reason} className="inline-flex items-center gap-1 rounded-md bg-slate-300 px-2.5 py-1.5 text-[11px] font-black text-slate-600 opacity-70"><Route className="h-3.5 w-3.5" /> Response</button>
                                                        )}
                                                    </>
                                                )}
                                                <button
                                                    type="button"
                                                    title="Track e-PIRMA Status"
                                                    aria-label="Track e-PIRMA Status"
                                                    onClick={() => setTracker({ open: true, row })}
                                                    className="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-[11px] font-black"
                                                >
                                                    <Eye className="h-3.5 w-3.5" /> Track e-PIRMA Status
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
            })}
                        />
                        {rows.length === 0 && (
                            <p className="py-10 text-center text-sm font-semibold text-slate-500">No forwarded assessments in this queue yet.</p>
                        )}
                    </div>
                </Card>
            </div>

            {drnEditor.open && (
                <div className="fixed inset-0 z-[140] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm">
                    <form onSubmit={saveDocumentDrns} className="w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950">
                        <div className="flex items-start justify-between border-b border-slate-200 bg-emerald-50 px-6 py-5">
                            <div><p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRRS AA document control</p><h2 className="text-xl font-black">Assign Document DRNs</h2><p className="mt-1 text-sm text-slate-600">Both references are required before either document can be routed through e-PIRMA.</p></div>
                            <button type="button" onClick={() => setDrnEditor((current) => ({ ...current, open: false }))} className="rounded-md border bg-white p-2"><X className="h-4 w-4" /></button>
                        </div>
                        <div className="grid gap-4 p-6 md:grid-cols-2">
                            {[['assessment_drn', 'Assessment DRN'], ['response_drn', 'Response Letter DRN']].map(([key, label]) => <label key={key} className="text-xs font-black uppercase text-slate-600">{label} *<input value={drnEditor[key]} onChange={(event) => setDrnEditor((current) => ({ ...current, [key]: event.target.value }))} placeholder="Complete DRN" className="mt-2 w-full normal-case" />{drnEditor.errors[key] && <span className="mt-1 block normal-case text-rose-600">{drnEditor.errors[key][0] || drnEditor.errors[key]}</span>}</label>)}
                            {drnEditor.errors.general && <p className="md:col-span-2 text-sm font-bold text-rose-700">{drnEditor.errors.general}</p>}
                        </div>
                        <div className="flex justify-end gap-2 border-t bg-slate-50 px-6 py-4"><button type="button" onClick={() => setDrnEditor((current) => ({ ...current, open: false }))} className="rounded-md border bg-white px-4 py-2 text-sm font-bold">Cancel</button><button disabled={drnEditor.saving} className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-60">{drnEditor.saving ? 'Saving...' : 'Save DRNs'}</button></div>
                    </form>
                </div>
            )}

            <EpirmaSignedDocumentsModal
                open={tracker.open}
                documents={tracker.row?.documents || []}
                busy={busyId === tracker.row?.id}
                canRetry={Boolean(
                    !(tracker.row?.is_completed || tracker.row?.epirma_aa_status === 'completed')
                    && (
                        tracker.row?.capabilities?.assessment?.can_route
                        || tracker.row?.capabilities?.response_letter?.can_route
                        || tracker.row?.capabilities?.assessment?.can_continue
                        || tracker.row?.capabilities?.response_letter?.can_continue
                    )
                )}
                onClose={() => setTracker({ open: false, row: null })}
                onRefresh={() => tracker.row && syncRow(tracker.row)}
                onView={(doc) => {
                    const isResponse = (doc.document_type || '') === 'response_letter';
                    const isSigned = Boolean(doc.is_signed || doc.preview_kind === 'signed' || doc.routing_status === 'signed');
                    const src = doc.app_view_url
                        || (tracker.row ? `/requests/${tracker.row.id}/epirma/documents/${doc.id}/view` : null)
                        || doc.local_view_url
                        || (isResponse ? tracker.row?.response_pdf_url : tracker.row?.assessment_pdf_url);
                    if (!src) return;
                    setPdfPreview({
                        open: true,
                        title: isResponse ? 'Response Letter' : 'Assessment',
                        subtitle: doc.document_name || tracker.row?.reference_number,
                        src,
                        kind: isSigned ? 'signed' : 'draft',
                        message: isSigned ? null : 'Draft / local preview — this is not the e-PIRMA signed PDF yet.',
                    });
                }}
                onContinue={(doc) => tracker.row && !(tracker.row.is_completed || tracker.row.epirma_aa_status === 'completed') && continueRoute(tracker.row, doc.document_type || 'assessment')}
                onRetry={(doc) => tracker.row && !(tracker.row.is_completed || tracker.row.epirma_aa_status === 'completed') && startRoute(tracker.row, doc.document_type || 'assessment')}
                onDelete={
                    tracker.row && !(tracker.row.is_completed || tracker.row.epirma_aa_status === 'completed')
                        ? async (doc) => {
                            if (!tracker.row || !window.confirm('Delete this e-PIRMA tracking entry?')) return;
                            setBusyId(tracker.row.id);
                            try {
                                await fetch(`/requests/${tracker.row.id}/epirma/documents/${doc.id}`, {
                                    method: 'DELETE',
                                    headers: {
                                        Accept: 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN': csrfToken(),
                                    },
                                    credentials: 'same-origin',
                                });
                                router.reload({ only: ['queue', 'summary'] });
                                setTracker({ open: false, row: null });
                            } finally {
                                setBusyId(null);
                            }
                        }
                        : undefined
                }
            />

            <PdfPreviewModal
                open={pdfPreview.open}
                title={pdfPreview.title}
                subtitle={pdfPreview.subtitle}
                src={pdfPreview.src}
                kind={pdfPreview.kind}
                message={pdfPreview.message}
                onClose={() => setPdfPreview({ open: false, title: '', subtitle: null, src: null, kind: null, message: null })}
            />
        </AppLayout>
    );
}
