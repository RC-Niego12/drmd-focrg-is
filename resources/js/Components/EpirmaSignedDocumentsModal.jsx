import { useEffect, useRef } from 'react';
import { Eye, Play, RefreshCw, Trash2, X } from 'lucide-react';
import EpirmaDocumentUuid from '@/Components/EpirmaDocumentUuid';
import { formatDateTime } from '@/Utils/dateFormat';

function displayStatus(doc) {
    const value = String(doc?.routing_status || 'pending');
    if (value === 'signed' || value === 'completed') {
        return { label: 'COMPLETED', className: 'bg-emerald-600 text-white' };
    }
    if (value === 'partially_signed') {
        return { label: 'PARTIAL', className: 'bg-sky-600 text-white' };
    }
    if (value === 'routed' || value === 'pending') {
        return { label: doc?.action === 'sign' ? 'FOR SIGNING' : 'FOR ROUTING', className: 'bg-amber-500 text-white' };
    }
    if (value === 'failed' || value === 'cancelled') {
        return { label: 'UNAVAILABLE', className: 'bg-rose-600 text-white' };
    }
    return { label: 'UNAVAILABLE', className: 'bg-rose-600 text-white' };
}

function formatCreatedAt(doc) {
    return formatDateTime(doc?.routed_at || doc?.created_at || doc?.timestamp, '—');
}

function actionLabel(doc) {
    return doc?.action === 'sign' ? 'Sign' : 'Route';
}

function typeLabel(doc) {
    return doc?.document_type === 'response_letter' ? 'Response letter' : 'Assessment';
}

/** Continue re-opens document-routing only for pending (pre-handoff) rows. */
const CONTINUE_ROUTE_STATUSES = ['pending'];

function EpirmaTrackTable({
    documents = [],
    busy = false,
    canRetry = true,
    onView,
    onContinue,
    onRetry,
    onDelete,
    emptyLabel = 'No e-PIRMA transactions yet.',
}) {
    if (documents.length === 0) {
        return (
            <div className="rounded-md border border-dashed border-slate-200 px-4 py-10 text-center text-sm font-semibold text-slate-500 dark:border-zinc-700">
                {emptyLabel}
            </div>
        );
    }

    return (
        <div className="overflow-visible rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-slate-50 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th className="px-4 py-3">Document</th>
                        <th className="px-4 py-3">Action</th>
                        <th className="px-4 py-3">Date Created</th>
                        <th className="px-4 py-3">Latest Status</th>
                        <th className="px-4 py-3 text-right">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    {documents.map((doc) => {
                        const badge = displayStatus(doc);
                        const rowId = doc.id || doc.document_uuid;
                        const status = String(doc.routing_status || '');
                        const canContinueRow = canRetry
                            && doc.action === 'route'
                            && CONTINUE_ROUTE_STATUSES.includes(status)
                            && !doc.routed_at
                            && typeof onContinue === 'function';
                        const canRetryRow = canRetry && doc.action === 'route' && ['failed', 'cancelled'].includes(status);
                        return (
                            <tr key={rowId} className="border-t border-slate-100 dark:border-zinc-800">
                                <td className="px-4 py-3 font-semibold text-slate-800 dark:text-zinc-100">
                                    <div>{doc.document_name || typeLabel(doc)}</div>
                                    <div className="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                        {typeLabel(doc)}
                                        {doc.is_signed || doc.preview_kind === 'signed'
                                            ? ' · Signed'
                                            : doc.local_view_url
                                                ? ' · Draft / local preview'
                                                : ''}
                                    </div>
                                    <EpirmaDocumentUuid uuid={doc.document_uuid} />
                                </td>
                                <td className="px-4 py-3 text-slate-600 dark:text-zinc-300">{actionLabel(doc)}</td>
                                <td className="px-4 py-3 text-slate-600 dark:text-zinc-300">{formatCreatedAt(doc)}</td>
                                <td className="px-4 py-3">
                                    <span className={`inline-flex rounded px-2 py-0.5 text-[10px] font-black uppercase tracking-wide ${badge.className}`}>
                                        {badge.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <div className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => onView?.(doc)}
                                        className="inline-flex items-center gap-1 rounded-md bg-teal-600 px-3 py-1.5 text-xs font-black text-white hover:bg-teal-700 disabled:opacity-60"
                                    >
                                        <Eye className="h-3.5 w-3.5" /> View
                                    </button>
                                    {canContinueRow && (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                className="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-xs font-bold disabled:opacity-60"
                                                onClick={() => onContinue?.(doc)}
                                            >
                                                <Play className="h-3.5 w-3.5" /> Continue
                                            </button>
                                    )}
                                    {canRetryRow && (
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    className="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-xs font-bold disabled:opacity-60"
                                                    onClick={() => onRetry?.(doc)}
                                                >
                                                    <RefreshCw className="h-3.5 w-3.5" /> Retry Route
                                                </button>
                                    )}
                                    {typeof onDelete === 'function' && (
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    className="inline-flex items-center gap-1 rounded-md border border-rose-200 px-2.5 py-1.5 text-xs font-bold text-rose-700 disabled:opacity-60"
                                                    onClick={() => onDelete?.(doc)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" /> Delete
                                                </button>
                                    )}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/** Compact track table for embedding inside PdfPreviewModal tabs. */
export function EpirmaTrackStatusPanel({
    documents = [],
    busy = false,
    error = null,
    canRetry = false,
    onRefresh,
    onView,
}) {
    return (
        <div className="px-5 py-4">
            <div className="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-black text-slate-900 dark:text-zinc-50">Track e-PIRMA Status</p>
                    <p className="mt-0.5 text-xs font-semibold text-slate-500">
                        Status tracking for Assessment and Response Letter Sign and Route transactions.
                    </p>
                </div>
                {typeof onRefresh === 'function' && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => onRefresh?.()}
                        className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${busy ? 'animate-spin' : ''}`} />
                        Refresh
                    </button>
                )}
            </div>
            {error && (
                <p className="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </p>
            )}
            <EpirmaTrackTable
                documents={documents}
                busy={busy}
                canRetry={canRetry}
                onView={onView}
                emptyLabel="No e-PIRMA transactions yet."
            />
        </div>
    );
}

export default function EpirmaSignedDocumentsModal({
    open,
    documents = [],
    busy = false,
    canRetry = true,
    onClose,
    onRefresh,
    onView,
    onContinue,
    onRetry,
    onDelete,
}) {
    const rootRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') onClose?.();
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="epirma-signed-documents-title"
        >
            <div
                ref={rootRef}
                className="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-950"
            >
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                    <div>
                        <h2 id="epirma-signed-documents-title" className="text-lg font-black text-slate-900 dark:text-zinc-50">
                            Track e-PIRMA Status
                        </h2>
                        <p className="mt-0.5 text-xs font-semibold text-slate-500">
                            Status tracking for Assessment and Response Letter Sign and Route transactions.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => onRefresh?.()}
                            className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <RefreshCw className={`h-3.5 w-3.5 ${busy ? 'animate-spin' : ''}`} />
                            Refresh
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Close"
                            data-tip="Close"
                            data-tip-side="bottom"
                            data-tip-preferred-side="bottom"
                            data-tip-locked="true"
                            className="dromis-tip rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-zinc-800"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-auto px-5 py-4">
                    <EpirmaTrackTable
                        documents={documents}
                        busy={busy}
                        canRetry={canRetry}
                        onView={onView}
                        onContinue={onContinue}
                        onRetry={onRetry}
                        onDelete={onDelete}
                    />
                </div>
            </div>
        </div>
    );
}
