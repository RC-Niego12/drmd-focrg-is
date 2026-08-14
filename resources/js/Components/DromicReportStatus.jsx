import { AlertTriangle, CheckCircle2, ChevronDown, ChevronUp, Clock3, Eye, Info, XCircle } from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '@/Utils/dateFormat';

export default function DromicReportStatus({
    submissionStatus = 'draft',
    classification = 'regular',
    validationStatus,
    correctionScope,
    seenAt,
    viewerName,
    ackedAt,
    ackerName,
    reviewNote,
    reviewerName,
    reviewedAt,
    perspective = 'sender',
    isCorrectedVersion = false,
}) {
    const senderSubmission = {
        draft: ['Submission · Draft · Editable', 'bg-amber-50 text-amber-800', Clock3],
        final: ['Submission · Final · Awaiting submission', 'bg-blue-50 text-blue-800', Clock3],
        advance_submitted: ['Submission · Submitted · Advance copy', 'bg-orange-50 text-orange-800', Clock3],
        submitted: ['Submission · Submitted · Signed copies complete', 'bg-emerald-50 text-emerald-800', CheckCircle2],
    };
    const recipientSubmission = {
        draft: ['LGU status · Draft · Editable', 'bg-amber-50 text-amber-800', Clock3],
        final: ['LGU status · Final · Awaiting submission', 'bg-blue-50 text-blue-800', Clock3],
        advance_submitted: ['Receipt · Advance copy received', 'bg-orange-50 text-orange-800', Clock3],
        submitted: ['Receipt · Signed copies received', 'bg-emerald-50 text-emerald-800', CheckCircle2],
    };
    const submission = (perspective === 'recipient' ? recipientSubmission : senderSubmission)[submissionStatus]
        || [String(submissionStatus).replaceAll('_', ' '), 'bg-slate-100 text-slate-700', Clock3];
    const resolvedValidation = validationStatus || (['advance_submitted', 'submitted'].includes(submissionStatus) ? 'pending_review' : 'not_submitted');
    const validation = {
        not_submitted: ['Validation starts after submission', 'bg-slate-100 text-slate-600', Clock3],
        pending_review: ['DROMIC document · Awaiting review', 'bg-slate-100 text-slate-700', Clock3],
        under_review: ['DROMIC document · Under review', 'bg-blue-100 text-blue-800', Eye],
        needs_lgu_action: ['DROMIC document · Needs LGU Action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: ['DROMIC document · Validated — No Findings', 'bg-emerald-100 text-emerald-800', CheckCircle2],
        superseded: ['DROMIC document · Superseded by corrected submission', 'bg-slate-100 text-slate-700', CheckCircle2],
    }[resolvedValidation] || ['DROMIC document · Awaiting review', 'bg-slate-100 text-slate-700', Clock3];
    const lifecycle = classification === 'terminal' ? 'Terminal Report' : classification === 'first_and_final' ? 'First and Final Report' : null;
    const SubmissionIcon = submission[2];
    const ValidationIcon = validation[2];

    return <div className="flex min-w-[260px] flex-col items-start gap-1.5">
        {lifecycle && <span className="rounded-full bg-violet-50 px-2 py-1 text-xs font-black uppercase text-violet-800">{lifecycle}</span>}
        {isCorrectedVersion && ['advance_submitted', 'submitted'].includes(submissionStatus) && <span className="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-1 text-xs font-black uppercase text-sky-800 ring-1 ring-sky-200"><CheckCircle2 className="h-3.5 w-3.5" />Corrected version submitted</span>}
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${submission[1]}`}><SubmissionIcon className="h-3.5 w-3.5" />{submission[0]}</span>
        {resolvedValidation !== 'not_submitted' && <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${validation[1]}`}><ValidationIcon className="h-3.5 w-3.5" />{validation[0]}</span>}
        {resolvedValidation === 'needs_lgu_action' && correctionScope && <span className="rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black uppercase text-rose-700 ring-1 ring-rose-200">Correction: {correctionScope === 'both' ? 'Encoding + PDF' : correctionScope === 'encoding' ? 'Encoded entries' : 'PDF document'}</span>}
        {resolvedValidation === 'needs_lgu_action' && reviewNote && <div className="mt-0.5 w-full max-w-[320px] rounded-lg border border-rose-200 bg-rose-50 p-2.5 text-rose-900 shadow-sm dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100">
            <p className="flex items-center gap-1 text-[10px] font-black uppercase tracking-wide"><AlertTriangle className="h-3.5 w-3.5 shrink-0" />Required LGU correction</p>
            <p className="mt-1 whitespace-normal text-xs font-semibold leading-5">{reviewNote}</p>
        </div>}
        {ackedAt
            ? <p className="flex w-full items-center gap-1 pt-0.5 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5 shrink-0" />Acknowledged by {ackerName || viewerName || 'DSWD recipient'} · {formatDateTime(ackedAt)}</p>
            : (seenAt && <p className="flex w-full items-center gap-1 pt-0.5 text-[11px] font-bold text-sky-700"><Eye className="h-3.5 w-3.5 shrink-0" />Seen by {viewerName || 'DSWD recipient'} · {formatDateTime(seenAt)}</p>)}
        {resolvedValidation !== 'needs_lgu_action' && reviewNote && <p title={reviewNote} className="mt-1 line-clamp-2 max-w-[300px] text-xs font-semibold leading-5 text-slate-700 dark:text-zinc-200">{reviewNote}</p>}
        {reviewerName && <p className="text-[11px] text-slate-500">Reviewed by {reviewerName} · {formatDateTime(reviewedAt)}</p>}
    </div>;
}

export function CompactDromicStatus({
    submissionStatus = 'draft',
    validationStatus,
    isCorrectedVersion = false,
    perspective = 'sender',
}) {
    const signedComplete = submissionStatus === 'submitted';
    const submitted = ['advance_submitted', 'submitted'].includes(submissionStatus);
    const resolvedValidation = validationStatus || (submitted ? 'pending_review' : 'not_submitted');
    const validated = resolvedValidation === 'validated_no_findings';
    const validationLabel = {
        not_submitted: 'Validation has not started because the report is not submitted',
        pending_review: 'DROMIC / SitRep validation is awaiting review',
        under_review: 'DROMIC / SitRep is under review',
        needs_lgu_action: 'DROMIC / SitRep needs LGU action',
        validated_no_findings: 'DROMIC / SitRep validated — no findings',
        superseded: 'This version was superseded by a corrected submission',
    }[resolvedValidation] || 'DROMIC / SitRep validation is pending';
    const submissionLabel = signedComplete
        ? (perspective === 'recipient' ? 'Signed report received' : 'Signed report submitted')
        : submitted
            ? 'Advance copy submitted; signed report pending'
            : 'Report not yet submitted as a signed PDF';

    return <div className="flex items-center justify-center gap-2">
        <StatusMark checked={signedComplete} tone={submitted ? 'amber' : 'slate'} label={submissionLabel} />
        <StatusMark checked={validated} tone={resolvedValidation === 'needs_lgu_action' ? 'rose' : 'slate'} label={validationLabel} />
        {isCorrectedVersion && submitted && <StatusMark checked tone="sky" label="Corrected version submitted by the LGU" />}
    </div>;
}

export function DromicSubmissionMark({ submissionStatus = 'draft', perspective = 'sender' }) {
    const signedComplete = submissionStatus === 'submitted';
    const submitted = ['advance_submitted', 'submitted'].includes(submissionStatus);
    const label = signedComplete
        ? (perspective === 'recipient' ? 'Signed report received' : 'Signed report submitted')
        : submitted
            ? 'Advance copy submitted; signed report pending'
            : 'Report not yet submitted as a signed PDF';

    return <StatusMark checked={signedComplete} tone={submitted ? 'amber' : 'slate'} label={label} />;
}

export function DromicAdvanceCopyMark({ submissionStatus = 'draft', perspective = 'sender' }) {
    const submitted = ['advance_submitted', 'submitted'].includes(submissionStatus);
    const label = submitted
        ? (perspective === 'recipient' ? 'Advance copy received' : 'Advance copy submitted')
        : submissionStatus === 'final'
            ? 'Advance copy finalized but not yet submitted'
            : 'Advance copy not yet submitted';

    return <StatusMark checked={submitted} tone="slate" label={label} />;
}

export function DromicValidationMark({ submissionStatus = 'draft', validationStatus, validatedCopy = null, hasSignedReport = null }) {
    const submitted = ['advance_submitted', 'submitted'].includes(submissionStatus);
    const resolved = validationStatus || (submitted ? 'pending_review' : 'not_submitted');
    const copy = validatedCopy
        || (resolved === 'validated_no_findings'
            ? (hasSignedReport ? 'signed' : (hasSignedReport === false ? 'advance' : null))
            : null);
    const validatedLabel = copy === 'signed'
        ? 'DROMIC / SitRep validated — signed PDF, no findings'
        : copy === 'advance'
            ? 'DROMIC / SitRep validated — advance copy, no findings'
            : 'DROMIC / SitRep validated — no findings';
    const label = {
        not_submitted: 'Validation has not started because the report is not submitted',
        pending_review: 'DROMIC / SitRep validation is awaiting review',
        under_review: 'DROMIC / SitRep is under review',
        needs_lgu_action: 'DROMIC / SitRep needs LGU action',
        validated_no_findings: validatedLabel,
        superseded: 'This version was superseded by a corrected submission',
    }[resolved] || 'DROMIC / SitRep validation is pending';

    return <StatusMark checked={resolved === 'validated_no_findings'} tone={resolved === 'needs_lgu_action' ? 'rose' : 'slate'} label={label} />;
}

export function RequestSubmissionMark({ hasSignedRequest = false }) {
    return <StatusMark checked={hasSignedRequest} tone="slate" label={hasSignedRequest ? 'Signed request PDF submitted' : 'Signed request PDF pending'} />;
}

export function ReliefRequestMark({ included = false }) {
    return <StatusMark checked={included} tone="slate" label={included ? 'Request Letter (Relief Augmentation) included' : 'No Request Letter (Relief Augmentation) included'} />;
}

export function RequestValidationMark({ hasSignedRequest = false, validationStatus }) {
    const resolved = hasSignedRequest ? (validationStatus || 'pending_review') : 'not_submitted';
    const label = {
        not_submitted: 'Signed request letter has not been submitted',
        pending_review: 'Request letter is awaiting DRRS review',
        under_review: 'Request letter is under DRRS review',
        needs_lgu_action: 'Request letter needs LGU action',
        validated_no_findings: 'Request letter validated — no findings',
        superseded: 'This request-letter version was superseded by a correction',
    }[resolved] || 'Request-letter validation is pending';

    return <StatusMark checked={resolved === 'validated_no_findings'} tone={resolved === 'needs_lgu_action' ? 'rose' : 'slate'} label={label} />;
}

export function CorrectedVersionMark({ corrected = false, kind = 'report', validationStatus }) {
    if (!corrected && validationStatus === 'validated_no_findings') {
        return <NotApplicableMark label={`Not applicable — this ${kind} passed validation and does not require a corrected version`} />;
    }

    return <StatusMark checked={corrected} tone={corrected ? 'sky' : 'slate'} label={corrected ? `Corrected ${kind} version submitted by the LGU` : `No corrected ${kind} version submitted`} />;
}

export function CompactRequestStatus({
    hasSignedRequest = false,
    validationStatus,
    isCorrectedVersion = false,
}) {
    const resolvedValidation = hasSignedRequest ? (validationStatus || 'pending_review') : 'not_submitted';
    const validated = resolvedValidation === 'validated_no_findings';
    const validationLabel = {
        not_submitted: 'Signed request letter has not been submitted',
        pending_review: 'Request letter is awaiting DRRS review',
        under_review: 'Request letter is under DRRS review',
        needs_lgu_action: 'Request letter needs LGU action',
        validated_no_findings: 'Request letter validated — no findings',
        superseded: 'This request-letter version was superseded by a correction',
    }[resolvedValidation] || 'Request-letter validation is pending';

    return <div className="flex items-center justify-center gap-2">
        <StatusMark checked={hasSignedRequest} tone="slate" label={hasSignedRequest ? 'Signed request PDF submitted' : 'Signed request PDF pending'} />
        <StatusMark checked={validated} tone={resolvedValidation === 'needs_lgu_action' ? 'rose' : 'slate'} label={validationLabel} />
        {isCorrectedVersion && hasSignedRequest && <StatusMark checked tone="sky" label="Corrected request version submitted by the LGU" />}
    </div>;
}

function StatusMark({ checked, tone = 'slate', label }) {
    const checkedClasses = tone === 'sky'
        ? 'text-sky-700 bg-sky-50 ring-sky-200'
        : 'text-emerald-700 bg-emerald-50 ring-emerald-200';
    const uncheckedClasses = tone === 'rose'
        ? 'text-rose-700 bg-rose-50 ring-rose-200'
        : tone === 'amber'
            ? 'text-amber-700 bg-amber-50 ring-amber-200'
            : 'text-slate-500 bg-slate-50 ring-slate-200';

    return <button type="button" title={label} aria-label={label} className={`inline-flex h-7 w-7 cursor-help items-center justify-center rounded-full ring-1 ${checked ? checkedClasses : uncheckedClasses}`}>
        {checked ? <CheckCircle2 className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
        <span className="sr-only">{label}</span>
    </button>;
}

export function StatusLegend({ kind = 'report' }) {
    const [open, setOpen] = useState(true);
    const request = kind === 'request';
    const incident = kind === 'incident';

    return <div className="border-b border-slate-200 bg-white px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="flex items-center justify-between gap-3">
            <p className="inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500"><Info className="h-4 w-4" />Status legend</p>
            <button type="button" title={open ? 'Collapse status legend' : 'Show status legend'} aria-label={open ? 'Collapse status legend' : 'Show status legend'} onClick={() => setOpen((value) => !value)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 dark:bg-zinc-900 dark:text-zinc-200">
                {open ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
            </button>
        </div>
        {open && <div className="mt-2 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs sm:grid-cols-2 xl:grid-cols-5 dark:border-zinc-700 dark:bg-zinc-950/40">
            {incident && <LegendBinaryItem label="Relief request included / not included" />}
            <LegendItem classes="bg-emerald-50 text-emerald-700 ring-emerald-200" checked label={request ? 'Signed request received or validation passed' : 'Copy received or validation passed'} />
            <LegendItem classes="bg-slate-50 text-slate-500 ring-slate-200" label="Not submitted, not started, or still pending" />
            {!request && <LegendItem classes="bg-amber-50 text-amber-700 ring-amber-200" label="Advance copy received; signed copy still pending" />}
            <LegendItem classes="bg-rose-50 text-rose-700 ring-rose-200" label="Needs LGU Action" />
            <LegendItem classes="bg-sky-50 text-sky-700 ring-sky-200" checked label="Corrected version submitted" />
            <div className="flex items-center gap-2 font-semibold text-slate-700 dark:text-zinc-200"><NotApplicableMark label="Correction is not applicable after validation passed" /><span>Correction not applicable</span></div>
        </div>}
    </div>;
}

function LegendBinaryItem({ label }) {
    return <div className="flex items-center gap-2 font-semibold text-slate-700 dark:text-zinc-200">
        <span className="flex shrink-0 items-center gap-1">
            <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200"><CheckCircle2 className="h-4 w-4" /></span>
            <span className="text-slate-400">/</span>
            <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-50 text-slate-500 ring-1 ring-slate-200"><XCircle className="h-4 w-4" /></span>
        </span>
        <span>{label}</span>
    </div>;
}

function LegendItem({ checked = false, classes, label }) {
    return <div className="flex items-center gap-2 font-semibold text-slate-700 dark:text-zinc-200">
        <span className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full ring-1 ${classes}`}>{checked ? <CheckCircle2 className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}</span>
        <span>{label}</span>
    </div>;
}

function NotApplicableMark({ label }) {
    return <button type="button" title={label} aria-label={label} className="inline-flex h-7 min-w-7 cursor-help items-center justify-center rounded-full bg-violet-50 px-1 text-[8px] font-black tracking-tight text-violet-700 ring-1 ring-violet-200">
        N/A
        <span className="sr-only">{label}</span>
    </button>;
}
