import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Eye, FileText } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import PdfPreviewModal from '@/Components/PdfPreviewModal';
import { formatDateTime } from '@/Utils/dateFormat';
import { listenRealtime } from '@/realtime';

export default function Index({ letters = [], focusId = null }) {
    const flash = usePage().props.flash ?? {};
    const [rows, setRows] = useState(letters);
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);
    const [message, setMessage] = useState(flash.success || null);
    const [pdfPreview, setPdfPreview] = useState({ open: false, title: '', subtitle: null, src: null });
    const realtimeReloadTimer = useRef(null);

    useEffect(() => {
        setRows(letters);
    }, [letters]);

    useEffect(() => {
        if (!focusId) return;
        const el = document.getElementById(`response-letter-${focusId}`);
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [focusId, rows]);

    useEffect(() => {
        const reloadLetters = () => {
            window.clearTimeout(realtimeReloadTimer.current);
            realtimeReloadTimer.current = window.setTimeout(() => {
                router.reload({
                    only: ['letters'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }, 350);
        };

        const stop = listenRealtime('lgu.fni.processing.updated', reloadLetters);

        return () => {
            stop();
            window.clearTimeout(realtimeReloadTimer.current);
        };
    }, []);

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const signedViewUrl = (row) => row.local_view_url || row.view_url || null;

    const hasSignedCopy = (row) => Boolean(row.has_signed && signedViewUrl(row));

    const acknowledge = async (row) => {
        if (!hasSignedCopy(row)) {
            setError('Signed response letter is not available yet.');
            return;
        }
        setBusyId(row.id);
        setError(null);
        try {
            const response = await fetch(`/lgu/response-letters/${row.id}/acknowledge`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ kind: 'signed' }),
            });
            const payload = await response.json().catch(() => null);
            if (!payload?.success) {
                setError(payload?.message || 'Unable to acknowledge receipt.');
                return;
            }
            setMessage(payload.message);
            setRows((current) => current.map((item) => (
                item.id === row.id
                    ? { ...item, acked_at: payload.acked_at, acked_by: { name: payload.acked_by } }
                    : item
            )));
        } catch {
            setError('Unable to acknowledge receipt right now.');
        } finally {
            setBusyId(null);
        }
    };

    const openLetter = (row) => {
        const src = signedViewUrl(row);
        if (!src) return;
        setPdfPreview({
            open: true,
            title: 'Signed Response Letter',
            subtitle: row.reference_number,
            src,
        });
    };

    const statusBadge = (row) => {
        if (!hasSignedCopy(row)) {
            return (
                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-black uppercase text-slate-700 dark:bg-zinc-800 dark:text-zinc-200">
                    Awaiting signed copy
                </span>
            );
        }
        if (row.acked_at) {
            return (
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[10px] font-black uppercase text-emerald-800">
                    <CheckCircle2 className="h-3 w-3" /> Acknowledged
                </span>
            );
        }
        return (
            <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-black uppercase text-amber-900">
                Awaiting acknowledgement
            </span>
        );
    };

    return (
        <AppLayout title="Response Letters">
            <Head title="Signed Response Letters" />
            <Card className="overflow-hidden p-0">
                <div className="border-b border-slate-200 bg-gradient-to-r from-emerald-50 via-white to-sky-50 px-5 py-4 dark:border-zinc-800 dark:from-emerald-950/30 dark:via-zinc-950 dark:to-sky-950/20">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">LGU receipts</p>
                    <h1 className="mt-0.5 text-2xl font-black">Signed Response Letters</h1>
                    <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">
                        Preview signed DSWD response letters in-app, then acknowledge receipt. Advance copies are available from Requests.
                    </p>
                </div>

                {(message || error) && (
                    <p className={`mx-5 mt-4 rounded-md border px-3 py-2 text-xs font-bold ${error ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}>
                        {error || message}
                    </p>
                )}

                <div className="p-4">
                    <DataTable
                        columns={['Reference', 'Released', 'Status', { label: 'Actions', align: 'right', actionColumn: true }]}
                        numbered={false}
                        rows={rows.map((row) => (
                            <tr
                                key={row.id}
                                id={`response-letter-${row.id}`}
                                className={Number(focusId) === Number(row.id) ? 'bg-emerald-50/70 dark:bg-emerald-950/20' : undefined}
                            >
                                <td className="px-4 py-3">
                                    <p className="font-black">{row.reference_number}</p>
                                    <p className="text-xs text-slate-500">{row.requesting_agency}</p>
                                    <p className="text-xs text-slate-400">{row.incident?.name || '—'}</p>
                                </td>
                                <td className="px-4 py-3 text-sm text-slate-600">
                                    {formatDateTime(row.sent_at || row.signed_at || row.advance_sent_at)}
                                </td>
                                <td className="px-4 py-3">
                                    {statusBadge(row)}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {hasSignedCopy(row) ? (
                                        <div className="inline-flex flex-wrap items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openLetter(row)}
                                                className="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs font-black"
                                            >
                                                <Eye className="h-3.5 w-3.5" /> View letter
                                            </button>
                                            {!row.acked_at && (
                                                <button
                                                    type="button"
                                                    disabled={busyId === row.id}
                                                    onClick={() => acknowledge(row)}
                                                    className="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-black text-white disabled:opacity-60"
                                                >
                                                    <FileText className="h-3.5 w-3.5" /> Acknowledge receipt
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <span className="text-sm font-semibold text-slate-400">-</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    />
                    {rows.length === 0 && (
                        <p className="py-10 text-center text-sm font-semibold text-slate-500">No response letters have been released to your LGU yet.</p>
                    )}
                </div>
            </Card>

            <PdfPreviewModal
                open={pdfPreview.open}
                title={pdfPreview.title}
                subtitle={pdfPreview.subtitle}
                src={pdfPreview.src}
                onClose={() => setPdfPreview({ open: false, title: '', subtitle: null, src: null })}
            />
        </AppLayout>
    );
}
