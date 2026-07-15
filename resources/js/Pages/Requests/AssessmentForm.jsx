import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Download, FileText, Pencil, Printer } from 'lucide-react';
import AppLayout, { Card } from '@/Layouts/AppLayout';
import { ResponseDrnModal } from '@/Components/DocumentDrnFields';

export default function AssessmentForm({ request, drnPrefixes = [] }) {
    const confirmedResponse = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('document') === 'response' && new URLSearchParams(window.location.search).get('confirmed') === '1';
    const [margin, setMargin] = useState('18');
    const [preview, setPreview] = useState(confirmedResponse ? 'response' : 'assessment');
    const [responsePromptAction, setResponsePromptAction] = useState(null);
    const [responseDrn, setResponseDrn] = useState(request.response_drn || '');
    const [responseVersion, setResponseVersion] = useState(
        () => `${request.updated_at || request.id}-${Date.now()}`,
    );
    const assessmentPdf = `/requests/${request.id}/assessment-pdf?margin=${margin}`;
    const responsePdf = `/requests/${request.id}/response-letter-pdf?v=${encodeURIComponent(responseVersion)}`;
    const previewUrl = preview === 'assessment' ? `${assessmentPdf}&inline=1` : `${responsePdf}&inline=1`;
    const hasCompleteResponseDrn = /^.+-\d{2}-\d{2}-.+$/.test(responseDrn.trim());

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

    return <AppLayout title="Assessment Documents">
        <Head title={`Assessment ${request.reference_number}`} />
        <Card className="overflow-hidden p-0">
            <div className="border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-950">
                <p className="text-xs font-black uppercase tracking-wide text-emerald-700">Submitted assessment</p>
                <div className="mt-1 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div><h1 className="text-2xl font-black">{request.reference_number}</h1><p className="text-sm text-slate-500">{request.requesting_agency} · {request.incident?.name || request.purpose || '-'} · Status: <span className="font-black capitalize">{request.status.replaceAll('_', ' ')}</span></p></div><Link href="/requests" className="text-sm font-black text-brand-700">Return to Requests</Link></div>
            </div>
            <div className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-5">
                <Action href={`${assessmentPdf}&inline=1`} icon={Printer} label="Print Assessment" note="Open print-ready PDF" target="_blank" />
                <Action href={assessmentPdf} icon={Download} label="Download Assessment" note="Save official PDF" />
                {request.source_document_url ? (
                    <Action href={`/requests/${request.id}/source-document`} icon={FileText} label="View Uploaded Document" note="Original request document" target="_blank" />
                ) : null}
                <Action onClick={() => openResponseAction('print')} icon={Printer} label="Print Response Letter" note={hasCompleteResponseDrn ? 'Open the print-ready PDF' : 'Enter the DRN, then open the print-ready PDF'} />
                <Action onClick={() => openResponseAction('pdf')} icon={Download} label="Download Response Letter" note={hasCompleteResponseDrn ? 'Save the official PDF' : 'Enter the DRN, then save the official PDF'} />
            </div>
            <div className="border-y border-slate-200 bg-slate-100 p-3 dark:border-zinc-800 dark:bg-zinc-900">
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
                <p className="mt-2 text-center text-xs font-semibold text-slate-500">{preview === 'assessment' ? 'Fit is the recommended one-page assessment layout. The selected margin is also used by Print and Download Assessment. Longer narratives may flow when a wider margin is selected.' : 'This PDF preview is converted directly from the generated official Response Letter.docx template, so the Word and PDF versions use the same layout.'}</p>
            </div>
            <iframe key={previewUrl} title={`${preview === 'assessment' ? 'Assessment' : 'Response letter'} PDF preview`} src={previewUrl} className="h-[calc(100vh-18rem)] min-h-[720px] w-full bg-white" />
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
