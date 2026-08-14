import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowRight, Building2, CheckCircle2, Eye, FileText, Pencil, PenLine, Share2, X } from 'lucide-react';
import AppLayout, { Card } from '@/Layouts/AppLayout';
import DocumentPreviewCanvas, {
    DEFAULT_DOCUMENT_PREVIEW_ZOOM,
    DOCUMENT_PREVIEW_ZOOM_OPTIONS,
} from '@/Components/DocumentPreviewCanvas';
import EpirmaSignedDocumentsModal from '@/Components/EpirmaSignedDocumentsModal';
import PdfPreviewModal from '@/Components/PdfPreviewModal';
import {
    PrintableAssessmentDocument,
    PrintableResponseLetterDocument,
} from '@/Components/PrintableAssessmentDocuments';
import SectionTabs from '@/Components/SectionTabs';
import { formatDateTime } from '@/Utils/dateFormat';
import { closeEpirmaTab, navigateEpirmaTab, openEpirmaTabPlaceholder } from '@/Utils/epirmaTab';
import { listenRealtime } from '@/realtime';

export default function AssessmentForm({ request, drnPrefixes = [], epirma: initialEpirma = null }) {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const roles = page.props.auth?.user?.roles || [];
    const isDrrsAa = roles.includes('DRRS AA') || roles.includes('Super Admin');
    const isRros = roles.some((role) => ['RROS', 'RROS AA'].includes(role));
    const confirmedResponse = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('document') === 'response' && new URLSearchParams(window.location.search).get('confirmed') === '1';
    const [margin, setMargin] = useState('18');
    const [preview, setPreview] = useState(confirmedResponse ? 'response' : 'assessment');
    const [previewMode, setPreviewMode] = useState('html'); // html = in-progress markup, pdf = generated PDF
    const [previewZoom, setPreviewZoom] = useState(DEFAULT_DOCUMENT_PREVIEW_ZOOM);
    const [responseDrn, setResponseDrn] = useState(request.response_drn || '');
    const [epirmaError, setEpirmaError] = useState(null);
    const [epirmaBusy, setEpirmaBusy] = useState(false);
    const [epirma, setEpirma] = useState(initialEpirma || { assessment: {}, response_letter: {}, forward: {}, documents: [] });
    const [trackerOpen, setTrackerOpen] = useState(false);
    const [forwardConfirmOpen, setForwardConfirmOpen] = useState(false);
    const [pdfPreview, setPdfPreview] = useState({ open: false, title: '', subtitle: null, src: null, kind: null, message: null });
    const [responseVersion, setResponseVersion] = useState(
        () => `${request.updated_at || request.id}-${Date.now()}`,
    );
    const realtimeReloadTimer = useRef(null);

    useEffect(() => {
        if (initialEpirma) setEpirma(initialEpirma);
    }, [initialEpirma]);

    useEffect(() => {
        const stop = listenRealtime('epirma.status.changed', (payload = {}) => {
            if (payload.request_id && Number(payload.request_id) !== Number(request.id)) {
                return;
            }
            window.clearTimeout(realtimeReloadTimer.current);
            realtimeReloadTimer.current = window.setTimeout(() => {
                router.reload({
                    only: ['request', 'epirma'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }, 350);
        });

        return () => {
            stop();
            window.clearTimeout(realtimeReloadTimer.current);
        };
    }, [request.id]);

    const assessmentPdf = `/requests/${request.id}/assessment-pdf?margin=${margin}`;
    const responsePdf = `/requests/${request.id}/response-letter-pdf?v=${encodeURIComponent(responseVersion)}`;
    const previewUrl = preview === 'assessment' ? `${assessmentPdf}&inline=1` : `${responsePdf}&inline=1`;
    const hasCompleteResponseDrn = /^.+-\d{2}-\d{2}-.+$/.test(responseDrn.trim());
    const status = request.assessment_status || 'draft';
    const isDraft = status === 'draft';
    const isFinal = status === 'final';
    const isSubmitted = status === 'submitted';
    const forwardCaps = epirma.forward || {};
    const activeCaps = preview === 'assessment' ? (epirma.assessment || {}) : (epirma.response_letter || {});
    const trackDocuments = epirma.documents || [];
    const editDraftHref = `/requests?tab=assessments&edit_assessment=${request.id}`;
    const assessmentsHref = '/requests?tab=assessments';
    const forwarded = Boolean(epirma.forwarded || request.epirma_forwarded_to_drrs_aa_at);
    const readOnly = Boolean(epirma.read_only || forwarded);

    const performResponseAction = (action) => {
        const version = Date.now();
        if (action === 'preview') {
            setResponseVersion(version);
            setPreview('response');
        } else if (action === 'print') {
            setPdfPreview({
                open: true,
                title: 'Response Letter',
                subtitle: request.reference_number,
                src: `/requests/${request.id}/response-letter-pdf?inline=1&v=${version}`,
            });
        } else {
            window.location.assign(`/requests/${request.id}/response-letter-pdf?v=${version}`);
        }
    };

    const openResponseAction = (action) => {
        if (action === 'preview' || hasCompleteResponseDrn) performResponseAction(action);
        else setEpirmaError('DRRS AA must assign both document DRNs before the response letter can be downloaded or printed.');
    };

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const refreshEpirma = async ({ sync = false } = {}) => {
        const response = await fetch(`/requests/${request.id}/epirma/documents${sync ? '?sync=1' : ''}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => null);
        if (payload?.success && Array.isArray(payload.data)) {
            setEpirma((current) => ({
                ...current,
                documents: payload.data,
            }));
            router.reload({ only: ['request', 'epirma'] });
            return payload.data;
        }
        return null;
    };

    const forwardToDrrsAa = () => {
        if (epirmaBusy || !forwardCaps.can_forward) return;
        setForwardConfirmOpen(true);
    };

    const confirmForwardToDrrsAa = async () => {
        setForwardConfirmOpen(false);
        setEpirmaBusy(true);
        setEpirmaError(null);
        try {
            const response = await fetch(`/requests/${request.id}/epirma/forward`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: '{}',
            });
            const payload = await response.json().catch(() => null);
            if (payload?.success) {
                if (payload.epirma) setEpirma(payload.epirma);
                router.reload({ only: ['request', 'epirma'] });
                return;
            }
            setEpirmaError(payload?.message || forwardCaps.blocked_reason || 'Unable to forward to DRRS AA.');
        } catch {
            setEpirmaError('Unable to forward to DRRS AA right now.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    const startRoute = async (documentTypeOverride = null) => {
        setEpirmaBusy(true);
        setEpirmaError(null);
        const tab = openEpirmaTabPlaceholder();
        try {
            const documentType = documentTypeOverride
                || (preview === 'assessment' ? 'assessment' : 'response_letter');
            const response = await fetch(`/requests/${request.id}/epirma/route`, {
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
                    setEpirmaError('Allow pop-ups for this site so the e-PIRMA transaction can open in a new tab.');
                }
                await refreshEpirma();
                return;
            }
            closeEpirmaTab(tab);
            setEpirmaError(payload?.message || activeCaps.route_blocked_reason || 'Unable to route with e-PIRMA.');
        } catch {
            closeEpirmaTab(tab);
            setEpirmaError('Unable to start e-PIRMA routing right now.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    const continueRoute = async (documentTypeOverride = null) => {
        setEpirmaBusy(true);
        setEpirmaError(null);
        const tab = openEpirmaTabPlaceholder();
        try {
            const documentType = documentTypeOverride
                || (preview === 'assessment' ? 'assessment' : 'response_letter');
            const response = await fetch(`/requests/${request.id}/epirma/continue`, {
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
                    setEpirmaError('Allow pop-ups for this site so the e-PIRMA transaction can open in a new tab.');
                }
                await refreshEpirma();
                return;
            }
            closeEpirmaTab(tab);
            setEpirmaError(payload?.message || activeCaps.continue_blocked_reason || 'Unable to continue e-PIRMA routing.');
        } catch {
            closeEpirmaTab(tab);
            setEpirmaError('Unable to continue e-PIRMA routing right now.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    const openTracker = async () => {
        setTrackerOpen(true);
        setEpirmaBusy(true);
        setEpirmaError(null);
        try {
            await refreshEpirma({ sync: true });
        } catch {
            setEpirmaError('Unable to load e-PIRMA status.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    const handleViewDocument = async (doc) => {
        setEpirmaBusy(true);
        setEpirmaError(null);
        try {
            const response = await fetch(`/requests/${request.id}/epirma/documents/${doc.id}/status`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => null);
            const data = payload?.data || {};
            const isResponse = (doc.document_type || data.document_type) === 'response_letter';
            const isSigned = Boolean(data.is_signed || doc.is_signed || data.routing_status === 'signed' || doc.routing_status === 'signed');
            // Prefer same-origin app proxy (streams signed remote PDF when available).
            const appViewUrl = data.app_view_url || doc.app_view_url
                || `/requests/${request.id}/epirma/documents/${doc.id}/view`;
            const draftUrl = isResponse
                ? `/requests/${request.id}/response-letter-pdf?inline=1`
                : `/requests/${request.id}/assessment-pdf?inline=1`;
            const src = isSigned
                ? (appViewUrl || data.view_url || data.authorized_view_url || doc.authorized_view_url || null)
                : (data.local_view_url || doc.local_view_url || draftUrl);
            setPdfPreview({
                open: true,
                title: isResponse ? 'Response Letter' : 'Assessment',
                subtitle: doc.document_name || request.reference_number,
                src,
                kind: isSigned ? 'signed' : 'draft',
                message: isSigned
                    ? (src ? null : 'Signed file unavailable from e-PIRMA')
                    : 'Draft / local preview — this is not the e-PIRMA signed PDF yet.',
            });
            await refreshEpirma();
        } catch {
            setEpirmaError('Unable to open the document right now.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    const handleRetryDocument = async (doc) => {
        const documentType = doc.document_type || 'assessment';
        setPreview(documentType === 'response_letter' ? 'response' : 'assessment');
        await startRoute(documentType);
    };

    const handleContinueDocument = async (doc) => {
        const documentType = doc.document_type || 'assessment';
        setPreview(documentType === 'response_letter' ? 'response' : 'assessment');
        await continueRoute(documentType);
    };

    const handleDeleteDocument = async (doc) => {
        if (!window.confirm(`Delete tracking for "${doc.document_name || 'this document'}"?`)) return;
        setEpirmaBusy(true);
        setEpirmaError(null);
        try {
            const response = await fetch(`/requests/${request.id}/epirma/documents/${doc.id}`, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => null);
            if (payload?.success && Array.isArray(payload.data)) {
                setEpirma((current) => ({ ...current, documents: payload.data }));
                router.reload({ only: ['request', 'epirma'] });
                return;
            }
            setEpirmaError(payload?.message || 'Failed to delete e-PIRMA document entry.');
        } catch {
            setEpirmaError('Unable to delete the e-PIRMA document entry.');
        } finally {
            setEpirmaBusy(false);
        }
    };

    return <AppLayout title="Assessment Documents">
        <Head title={`Assessment ${request.reference_number}`} />
        <Card className="overflow-hidden p-0">
            <div className="border-b border-slate-200 bg-gradient-to-r from-emerald-50 via-white to-brand-50/40 px-4 py-4 dark:border-zinc-800 dark:from-emerald-950/30 dark:via-zinc-950 dark:to-brand-950/20">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                            {isDraft ? 'Draft assessment workspace' : isFinal ? 'Signed assessment workspace' : 'Submitted assessment workspace'}
                        </p>
                        <div className="mt-0.5 flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-black">{request.reference_number}</h1>
                            <span className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${
                                isSubmitted ? 'bg-emerald-100 text-emerald-800'
                                    : isFinal ? 'bg-blue-100 text-blue-800'
                                        : 'bg-amber-100 text-amber-900'
                            }`}>
                                {status}
                            </span>
                            {forwarded && (
                                <span className="rounded-full bg-orange-100 px-2.5 py-0.5 text-[10px] font-black uppercase text-orange-800">
                                    Forwarded to DRRS AA · {String(epirma.aa_status || request.epirma_aa_status || 'pending').replace('_', ' ')}
                                </span>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            {request.requesting_agency} · {request.incident?.name || request.purpose || '-'}
                        </p>
                        {(flash.success || isDraft) && (
                            <p className="mt-2 inline-flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                                <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    {flash.success || 'Draft saved and documents generated.'}
                                    {isDraft && !forwarded ? ' Forward both documents to DRRS AA, who will assign their DRNs before e-PIRMA routing.' : ''}
                                    {forwarded ? ' DRRS AA will route these documents through e-PIRMA.' : ''}
                                </span>
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={assessmentsHref} className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">In Progress</Link>
                        <Link href="/requests" className="text-xs font-black text-brand-700 dark:text-brand-200">FNI Requests</Link>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    {!isDrrsAa && !isRros && !forwarded && (
                        <button
                            type="button"
                            disabled={epirmaBusy || !forwardCaps.can_forward}
                            title={forwardCaps.blocked_reason || 'Forward assessment and response letter to DRRS AA. An advance Response Letter copy will also be released to the LGU.'}
                            onClick={forwardToDrrsAa}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-orange-500 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-orange-600 disabled:opacity-60"
                        >
                            <Share2 className="h-4 w-4" />
                            Forward to DRRS AA
                        </button>
                    )}
                    {isDrrsAa && (
                        activeCaps.can_continue ? (
                            <button
                                type="button"
                                disabled={epirmaBusy}
                                title={activeCaps.continue_blocked_reason || 'Continue incomplete e-PIRMA route'}
                                onClick={() => continueRoute()}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-amber-600 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-amber-700 disabled:opacity-60"
                            >
                                Continue e-PIRMA
                            </button>
                        ) : activeCaps.can_route ? (
                            <button
                                type="button"
                                disabled={epirmaBusy}
                                title={activeCaps.route_blocked_reason || 'Route document through e-PIRMA'}
                                onClick={startRoute}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"
                            >
                                Route with e-PIRMA
                            </button>
                        ) : null
                    )}
                    <button
                        type="button"
                        disabled={epirmaBusy}
                        onClick={openTracker}
                        className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    >
                        <Eye className="h-4 w-4" />
                        Track e-PIRMA Status{trackDocuments.length ? ` (${trackDocuments.length})` : ''}
                    </button>
                    {isDraft && !isRros && !readOnly && (
                        <Link href={editDraftHref} className="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900 shadow-sm hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                            <Pencil className="h-4 w-4" />
                            Edit Draft
                        </Link>
                    )}
                    {/* Submit is intentionally omitted: RROS is notified when e-PIRMA signing completes.
                        Legacy final→submitted remains on the assessment-status API for rare non-e-PIRMA recovery only. */}
                    {(isFinal || isSubmitted) && !isRros && !isDrrsAa && !forwarded && (
                        <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: 'draft' })} className="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900 shadow-sm">
                            Reopen as Draft
                        </button>
                    )}
                </div>

                {(flash.error || epirmaError || (!forwardCaps.can_forward && forwardCaps.blocked_reason && !forwarded && !isDrrsAa && !isRros)) && (
                    <p className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                        {flash.error || epirmaError || forwardCaps.blocked_reason}
                    </p>
                )}

                {isDraft && !forwarded && !isDrrsAa && (
                    <ol className="mt-3 grid gap-2 border-t border-emerald-100 pt-3 text-[11px] font-semibold text-slate-600 dark:border-emerald-900/40 dark:text-zinc-400 sm:grid-cols-3">
                        <li><span className="mr-1 font-black text-emerald-700">1.</span> Complete assessment + Response Letter DRN</li>
                        <li><span className="mr-1 font-black text-emerald-700">2.</span> Forward to DRRS AA (also releases an advance Response Letter to the LGU)</li>
                        <li><span className="mr-1 font-black text-emerald-700">3.</span> DRRS AA routes via e-PIRMA; signed copy follows for LGU acknowledgement</li>
                    </ol>
                )}
                {!isDrrsAa && !isRros && forwardCaps.can_forward && (
                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        DRRS AA will assign the Assessment and Response Letter DRNs after this handoff. Neither document can be routed through e-PIRMA until both DRNs are complete.
                    </p>
                )}
                {forwarded && !isDrrsAa && !isRros && (
                    <p className="mt-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-bold text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
                        Forwarded to DRRS AA for DRN assignment and e-PIRMA routing.
                    </p>
                )}
            </div>

            {request.source_document_url ? (
                <div className="border-b p-4">
                    <Action href={`/requests/${request.id}/source-document`} icon={FileText} label="View Uploaded Document" note="Original request document" target="_blank" />
                </div>
            ) : null}

            <div className="border-b border-slate-200 bg-slate-100 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <SectionTabs
                        appearance="plain"
                        value={preview}
                        onChange={(next) => {
                            if (next === 'response') openResponseAction('preview');
                            else setPreview('assessment');
                        }}
                        ariaLabel="Document preview"
                        tabs={[
                            { id: 'assessment', label: 'Assessment Preview', icon: FileText },
                            { id: 'response', label: 'Response Letter Preview', icon: FileText },
                        ]}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-950">
                            {[
                                { id: 'html', label: 'Paper preview' },
                                { id: 'pdf', label: 'PDF' },
                            ].map((option) => (
                                <button
                                    key={option.id}
                                    type="button"
                                    onClick={() => setPreviewMode(option.id)}
                                    className={`rounded-md px-2.5 py-1 text-[11px] font-black ${
                                        previewMode === option.id
                                            ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                            : 'text-slate-600 hover:bg-slate-50 dark:text-zinc-300'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                        {previewMode === 'html' && (
                            <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-950">
                                {DOCUMENT_PREVIEW_ZOOM_OPTIONS.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => setPreviewZoom(option.value)}
                                        className={`rounded-md px-2.5 py-1 text-[11px] font-black ${
                                            previewZoom === option.value
                                                ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                                : 'text-slate-600 hover:bg-slate-50 dark:text-zinc-300'
                                        }`}
                                        title={`Zoom ${option.label}`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}
                        {preview === 'assessment' && !readOnly && previewMode === 'pdf' && (
                            <label className="flex flex-col gap-1 text-xs font-bold text-slate-600 sm:flex-row sm:items-center dark:text-zinc-300">
                                Page margin
                                <select value={margin} onChange={(event) => setMargin(event.target.value)} className="rounded-md border-slate-300 bg-white py-2 text-sm font-bold dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="18">Fit to one page — 0.25 in</option>
                                    <option value="27">Compact — 0.375 in</option>
                                    <option value="36">Balanced — 0.5 in</option>
                                    <option value="54">Wide — 0.75 in</option>
                                    <option value="72">Standard — 1 in</option>
                                </select>
                            </label>
                        )}
                        {readOnly && (
                            <span className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-600 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
                                Read-only preview
                            </span>
                        )}
                    </div>
                </div>
                <p className="mt-2 text-center text-xs font-semibold text-slate-500">
                    {isRros
                        ? 'Signed assessment and response letter released after DRRS AA e-PIRMA routing.'
                        : isDrrsAa
                            ? 'Route the active document tab through e-PIRMA. Transactions open in a new tab. Document content is read-only.'
                            : forwarded
                                ? 'Documents were forwarded to DRRS AA. Previews below are read-only reference copies of what was handed off.'
                                : previewMode === 'html'
                                    ? 'A4 paper preview from saved assessment data. Switch to PDF for the generated downloadable layout.'
                                    : 'DRRS PDRC prepares documents, then forwards them to DRRS AA for e-PIRMA routing.'}
                </p>
            </div>
            {previewMode === 'html' ? (
                <div className="h-[calc(100vh-22rem)] min-h-[640px] overflow-hidden">
                    <DocumentPreviewCanvas zoom={previewZoom} className="h-full" paperWidth="210mm">
                        {preview === 'response' ? (
                            <PrintableResponseLetterDocument request={request} formData={request} />
                        ) : (
                            <PrintableAssessmentDocument request={request} formData={request} />
                        )}
                    </DocumentPreviewCanvas>
                </div>
            ) : (
                <iframe
                    key={previewUrl}
                    title={`${preview === 'assessment' ? 'Assessment' : 'Response letter'} PDF preview`}
                    src={previewUrl}
                    className="h-[calc(100vh-22rem)] min-h-[640px] w-full bg-white"
                />
            )}
        </Card>

        <EpirmaSignedDocumentsModal
            open={trackerOpen}
            documents={trackDocuments}
            busy={epirmaBusy}
            canRetry={Boolean(isDrrsAa && (
                epirma.assessment?.can_route
                || epirma.assessment?.can_continue
                || epirma.response_letter?.can_route
                || epirma.response_letter?.can_continue
            ))}
            onClose={() => setTrackerOpen(false)}
            onRefresh={async () => {
                setEpirmaBusy(true);
                setEpirmaError(null);
                try {
                    await refreshEpirma({ sync: true });
                } catch {
                    setEpirmaError('Unable to refresh e-PIRMA status.');
                } finally {
                    setEpirmaBusy(false);
                }
            }}
            onView={handleViewDocument}
            onContinue={handleContinueDocument}
            onRetry={handleRetryDocument}
            onDelete={isDrrsAa ? handleDeleteDocument : undefined}
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

        {forwardConfirmOpen && (
            <ForwardToDrrsAaModal
                referenceNumber={request.reference_number}
                requestingAgency={request.requesting_agency}
                onCancel={() => setForwardConfirmOpen(false)}
                onConfirm={confirmForwardToDrrsAa}
            />
        )}

    </AppLayout>;
}

function ForwardToDrrsAaModal({ referenceNumber, requestingAgency, onCancel, onConfirm }) {
    const steps = [
        { icon: Share2, label: 'Forward to DRRS AA', detail: 'Assessment + response letter handoff', tone: 'orange' },
        { icon: FileText, label: 'Assign both DRNs', detail: 'Completed by DRRS AA', tone: 'amber' },
        { icon: PenLine, label: 'e-PIRMA routing', detail: 'Enabled only after both DRNs are saved', tone: 'sky' },
        { icon: FileText, label: 'Signed copy to LGU', detail: 'Final letter after routing completes', tone: 'emerald' },
    ];
    const tones = {
        orange: 'bg-orange-100 text-orange-700 ring-orange-200 dark:bg-orange-950/50 dark:text-orange-200 dark:ring-orange-900',
        amber: 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-200 dark:ring-amber-900',
        sky: 'bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-950/50 dark:text-sky-200 dark:ring-sky-900',
        emerald: 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-200 dark:ring-emerald-900',
    };

    return (
        <div
            className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="forward-drrs-aa-title"
                className="relative w-full max-w-lg overflow-hidden rounded-2xl border border-orange-200/70 bg-white shadow-2xl shadow-orange-950/20 dark:border-orange-900/50 dark:bg-zinc-950"
            >
                <div className="pointer-events-none absolute -right-16 -top-20 h-48 w-48 rounded-full bg-orange-400/25 blur-3xl" aria-hidden />
                <div className="pointer-events-none absolute -bottom-20 -left-10 h-44 w-44 rounded-full bg-emerald-400/20 blur-3xl" aria-hidden />

                <div className="relative border-b border-orange-100 bg-gradient-to-br from-orange-50 via-white to-emerald-50 px-5 py-4 dark:border-orange-950 dark:from-orange-950/40 dark:via-zinc-950 dark:to-emerald-950/30">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-500 text-white shadow-lg shadow-orange-500/30">
                                <Share2 className="h-5 w-5" />
                            </span>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-[0.18em] text-orange-700 dark:text-orange-300">
                                    DRRS PDRC handoff
                                </p>
                                <h2 id="forward-drrs-aa-title" className="mt-0.5 text-xl font-black text-slate-950 dark:text-white">
                                    Forward to DRRS AA?
                                </h2>
                                <p className="mt-1 font-mono text-xs font-bold text-emerald-700 dark:text-emerald-300">
                                    {referenceNumber}
                                    {requestingAgency ? ` · ${requestingAgency}` : ''}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={onCancel}
                            aria-label="Close"
                            className="rounded-lg p-2 text-slate-400 transition hover:bg-white/80 hover:text-slate-700 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <div className="relative space-y-4 px-5 py-4">
                    <p className="text-sm font-semibold leading-6 text-slate-600 dark:text-zinc-300">
                        This action sends the assessment and response letter to <span className="font-black text-slate-900 dark:text-white">DRRS AA</span>. DRRS AA assigns both document reference numbers before routing either document through e-PIRMA.
                    </p>

                    <ol className="space-y-2.5">
                        {steps.map((step, index) => {
                            const Icon = step.icon;
                            return (
                                <li key={step.label} className="flex items-start gap-3">
                                    <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-1 ${tones[step.tone]}`}>
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1 border-b border-dashed border-slate-200 pb-2.5 last:border-0 dark:border-zinc-800">
                                        <div className="flex items-center gap-2">
                                            <span className="text-[10px] font-black uppercase tracking-wide text-slate-400">Step {index + 1}</span>
                                            {index < steps.length - 1 && <ArrowRight className="h-3 w-3 text-slate-300 dark:text-zinc-600" />}
                                        </div>
                                        <p className="text-sm font-black text-slate-900 dark:text-zinc-50">{step.label}</p>
                                        <p className="text-xs font-semibold text-slate-500 dark:text-zinc-400">{step.detail}</p>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>

                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-bold leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        The signed Response Letter will follow after e-PIRMA routing is completed. The LGU will acknowledge the advance copy first, then the signed copy.
                    </div>
                </div>

                <div className="relative flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50/80 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:hover:bg-zinc-900"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-orange-500 px-4 py-2.5 text-sm font-black text-white shadow-sm shadow-orange-500/25 transition hover:bg-orange-600"
                    >
                        <Share2 className="h-4 w-4" />
                        Yes, forward now
                    </button>
                </div>
            </div>
        </div>
    );
}

function Action({ href, onClick, icon: Icon, label, note, target }) {
    const className = "group flex items-center gap-3 rounded-md border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-950";
    const content = <><span className="rounded-md bg-emerald-50 p-2 text-emerald-700 group-hover:bg-emerald-700 group-hover:text-white dark:bg-emerald-950"><Icon className="h-5 w-5" /></span><span><b className="block text-sm">{label}</b><span className="text-xs text-slate-500">{note}</span></span></>;
    return onClick ? <button type="button" onClick={onClick} className={className}>{content}</button> : <a href={href} target={target} rel={target ? 'noreferrer' : undefined} className={className}>{content}</a>;
}
