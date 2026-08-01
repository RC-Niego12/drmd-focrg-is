import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, FileSignature, FileText, Pencil } from 'lucide-react';
import AppLayout, { Card } from '@/Layouts/AppLayout';
import { ResponseDrnModal } from '@/Components/DocumentDrnFields';

export default function AssessmentForm({ request, drnPrefixes = [] }) {
    const flash = usePage().props.flash ?? {};
    const confirmedResponse = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('document') === 'response' && new URLSearchParams(window.location.search).get('confirmed') === '1';
    const [margin, setMargin] = useState('18');
    const [preview, setPreview] = useState(confirmedResponse ? 'response' : 'assessment');
    const [responsePromptAction, setResponsePromptAction] = useState(null);
    const [responseDrn, setResponseDrn] = useState(request.response_drn || '');
    const [epirmaError, setEpirmaError] = useState(null);
    const [epirmaBusy, setEpirmaBusy] = useState(false);
    const [responseVersion, setResponseVersion] = useState(
        () => `${request.updated_at || request.id}-${Date.now()}`,
    );
    const assessmentPdf = `/requests/${request.id}/assessment-pdf?margin=${margin}`;
    const responsePdf = `/requests/${request.id}/response-letter-pdf?v=${encodeURIComponent(responseVersion)}`;
    const previewUrl = preview === 'assessment' ? `${assessmentPdf}&inline=1` : `${responsePdf}&inline=1`;
    const hasCompleteResponseDrn = /^.+-\d{2}-\d{2}-.+$/.test(responseDrn.trim());
    const status = request.assessment_status || 'draft';
    const isDraft = status === 'draft';
    const isFinal = status === 'final';
    const isSubmitted = status === 'submitted';
    const epirmaViewable = ['completed', 'signed'].includes(request.epirma_status);
    const epirmaPending = request.epirma_status === 'pending';
    const editDraftHref = `/requests?tab=assessments&edit_assessment=${request.id}`;
    const assessmentsHref = '/requests?tab=assessments';

    const performResponseAction = (action) => {
        const version = Date.now();
        if (action === 'preview') {
            setResponseVersion(version);
            setPreview('response');
        } else if (action === 'print') {
            window.open(`/requests/${request.id}/response-letter-pdf?inline=1&v=${version}`, '_blank', 'noopener,noreferrer');
        } else {
            window.location.assign(`/requests/${request.id}/response-letter-pdf?v=${version}`);
        }
    };

    const openResponseAction = (action) => {
        if (hasCompleteResponseDrn) performResponseAction(action);
        else setResponsePromptAction(action);
    };

    const startEpirmaSign = () => router.post(`/requests/${request.id}/epirma/sign`);

    const handleEpirma = async () => {
        setEpirmaBusy(true);
        setEpirmaError(null);
        try {
            if (epirmaViewable) {
                const response = await fetch(`/requests/${request.id}/epirma/status`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (payload?.success && payload?.data?.view_url) {
                    window.open(payload.data.view_url, '_blank', 'noopener,noreferrer');
                    router.reload({ only: ['request'] });
                    return;
                }

                // Pending/local handoff can leave status without a stored document UUID.
                // Fall back to starting a fresh signing session instead of looping on 422.
                if (String(payload?.message || '').toLowerCase().includes('uuid')) {
                    startEpirmaSign();
                    return;
                }

                setEpirmaError(payload?.message || 'Failed to fetch signed document status.');
                return;
            }

            startEpirmaSign();
        } catch {
            setEpirmaError('Unable to reach e-PIRMA right now. Please try again.');
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
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            {request.requesting_agency} · {request.incident?.name || request.purpose || '-'}
                        </p>
                        {(flash.success || isDraft) && (
                            <p className="mt-2 inline-flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                                <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    {flash.success || 'Draft saved and documents generated.'}
                                    {isDraft ? ' Preview below, then sign with e-PIRMA or continue editing the draft.' : ''}
                                </span>
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={assessmentsHref}
                            className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            Created Assessments
                        </Link>
                        <Link href="/requests" className="text-xs font-black text-brand-700 dark:text-brand-200">
                            FNI Requests
                        </Link>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    {isDraft && (
                        <button
                            type="button"
                            disabled={epirmaBusy}
                            onClick={handleEpirma}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
                        >
                            <FileSignature className="h-4 w-4" />
                            {epirmaViewable ? 'View e-PIRMA Document' : epirmaPending ? 'Continue e-PIRMA Signing' : 'Sign with e-PIRMA'}
                        </button>
                    )}
                    {isDraft && (
                        <Link
                            href={editDraftHref}
                            className="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900 shadow-sm transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        >
                            <Pencil className="h-4 w-4" />
                            Edit Draft
                        </Link>
                    )}
                    {isFinal && (
                        <button
                            type="button"
                            onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: 'submitted' })}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 py-3 text-sm font-black text-white shadow-sm"
                        >
                            Submit Assessment
                        </button>
                    )}
                    {(isFinal || isSubmitted) && (
                        <button
                            type="button"
                            onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: 'draft' })}
                            className="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900 shadow-sm"
                        >
                            Reopen as Draft
                        </button>
                    )}
                </div>
                {(flash.error || epirmaError) && (
                    <p className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                        {flash.error || epirmaError}
                    </p>
                )}
                {isDraft && (
                    <ol className="mt-3 grid gap-2 border-t border-emerald-100 pt-3 text-[11px] font-semibold text-slate-600 dark:border-emerald-900/40 dark:text-zinc-400 sm:grid-cols-3">
                        <li><span className="mr-1 font-black text-emerald-700">1.</span> Preview assessment & response letter</li>
                        <li><span className="mr-1 font-black text-emerald-700">2.</span> Sign with e-PIRMA (or edit draft if corrections are needed)</li>
                        <li><span className="mr-1 font-black text-emerald-700">3.</span> After signing, submit from Created Assessments</li>
                    </ol>
                )}
            </div>

            {request.source_document_url ? (
                <div className="border-b p-4">
                    <Action href={`/requests/${request.id}/source-document`} icon={FileText} label="View Uploaded Document" note="Original request document" target="_blank" />
                </div>
            ) : null}
            <div className="border-b border-slate-200 bg-slate-100 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="inline-flex rounded-md border border-slate-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-950">
                        <PreviewButton active={preview === 'assessment'} onClick={() => setPreview('assessment')}>Assessment Preview</PreviewButton>
                        <PreviewButton active={preview === 'response'} onClick={() => openResponseAction('preview')}>Response Letter Preview</PreviewButton>
                    </div>
                    {hasCompleteResponseDrn && <button type="button" onClick={() => setResponsePromptAction('edit')} className="inline-flex items-center justify-center gap-2 rounded-md border border-emerald-200 bg-white px-3 py-2 text-xs font-black text-emerald-700 shadow-sm transition hover:bg-emerald-50 dark:border-emerald-900 dark:bg-zinc-950 dark:text-emerald-200"><Pencil className="h-3.5 w-3.5" />Edit Response Letter DRN</button>}
                    {preview === 'assessment' && <label className="flex flex-col gap-1 text-xs font-bold text-slate-600 sm:flex-row sm:items-center dark:text-zinc-300">
                        Page margin
                        <select value={margin} onChange={(event) => setMargin(event.target.value)} className="rounded-md border-slate-300 bg-white py-2 text-sm font-bold dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="18">Fit to one page — 0.25 in</option>
                            <option value="27">Compact — 0.375 in</option>
                            <option value="36">Balanced — 0.5 in</option>
                            <option value="54">Wide — 0.75 in</option>
                            <option value="72">Standard — 1 in</option>
                        </select>
                    </label>}
                </div>
                <p className="mt-2 text-center text-xs font-semibold text-slate-500">{preview === 'assessment' ? 'Fit is the recommended one-page assessment layout. Longer narratives may flow when a wider margin is selected.' : 'This PDF preview is converted directly from the generated official Response Letter.docx template, so the Word and PDF versions use the same layout.'}</p>
            </div>
            <iframe key={previewUrl} title={`${preview === 'assessment' ? 'Assessment' : 'Response letter'} PDF preview`} src={previewUrl} className="h-[calc(100vh-22rem)] min-h-[640px] w-full bg-white" />
        </Card>
        {responsePromptAction && <ResponseDrnModal
            requestId={request.id}
            existingDrn={responseDrn}
            prefixOptions={drnPrefixes.filter((row) => row.context === 'response_letter').map((row) => row.value)}
            actionLabel={responsePromptAction === 'edit' ? 'Update Response Letter DRN' : responsePromptAction === 'preview' ? 'Save DRN & Preview' : responsePromptAction === 'print' ? 'Save DRN & Print Response Letter' : 'Save DRN & Download Response Letter'}
            onClose={() => setResponsePromptAction(null)}
            onSaved={(drn) => {
                const action = responsePromptAction;
                setResponseDrn(drn);
                setResponsePromptAction(null);
                if (action === 'edit') {
                    setResponseVersion(Date.now());
                    return;
                }
                performResponseAction(action);
            }}
        />}
    </AppLayout>;
}

function PreviewButton({ active, onClick, children }) {
    return <button type="button" onClick={onClick} className={`rounded px-4 py-2 text-sm font-black transition ${active ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800'}`}>{children}</button>;
}

function Action({ href, onClick, icon: Icon, label, note, target }) {
    const className = "group flex items-center gap-3 rounded-md border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-950";
    const content = <><span className="rounded-md bg-emerald-50 p-2 text-emerald-700 group-hover:bg-emerald-700 group-hover:text-white dark:bg-emerald-950"><Icon className="h-5 w-5" /></span><span><b className="block text-sm">{label}</b><span className="text-xs text-slate-500">{note}</span></span></>;
    return onClick ? <button type="button" onClick={onClick} className={className}>{content}</button> : <a href={href} target={target} rel={target ? 'noreferrer' : undefined} className={className}>{content}</a>;
}
