import { router } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck, X, XCircle } from 'lucide-react';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDateTime } from '@/Utils/dateFormat';

export default function AccessDecisionModal({ user, roleOptions, onClose, onSuccess }) {
    const [role, setRole] = useState(user.requested_role || roleOptions[0]?.value || 'RROS');
    const [responseMessage, setResponseMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const submit = (action) => {
        setProcessing(true);
        setErrors({});
        router.post(`/access-management/${user.id}/decision`, {
            action,
            role: action === 'approve' ? role : null,
            response_message: responseMessage,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.(action);
                onClose();
            },
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    };

    return createPortal(
        <div className="fixed inset-0 z-[240] flex items-center justify-center overflow-y-auto bg-slate-950/65 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="access-decision-title">
            <div className="w-full max-w-xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Caraga Connect access request</p>
                        <h2 id="access-decision-title" className="mt-1 text-xl font-black">{user.name}</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">{user.email}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close access request" data-tip="Close access request" data-tip-side="bottom" data-tip-preferred-side="bottom" data-tip-locked="true" className="dromis-tip rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-zinc-800 dark:hover:text-white">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="space-y-4 p-5">
                    <div className="grid gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100 sm:grid-cols-2">
                        <div><span className="text-xs font-bold uppercase">Requested level</span><p className="mt-1 text-lg font-black">{user.requested_role || 'Not selected'}</p></div>
                        <div><span className="text-xs font-bold uppercase">Submitted</span><p className="mt-1 font-bold">{formatDateTime(user.access_requested_at, 'Just now')}</p></div>
                        {(user.office || user.position) && <div className="sm:col-span-2"><span className="text-xs font-bold uppercase">Employee details</span><p className="mt-1">{[user.office, user.position, user.designation].filter(Boolean).join(' · ')}</p></div>}
                    </div>

                    <SearchableSelect label="Assign user level" options={roleOptions} value={role} onChange={setRole} />
                    {errors.role && <p className="text-sm font-semibold text-rose-600">{errors.role}</p>}

                    <label className="block text-sm font-bold">
                        Response or reason
                        <textarea
                            className="mt-1 min-h-24 w-full"
                            value={responseMessage}
                            onChange={(event) => setResponseMessage(event.target.value)}
                            placeholder="Optional for approval; required when disapproving."
                        />
                    </label>
                    {errors.response_message && <p className="text-sm font-semibold text-rose-600">{errors.response_message}</p>}
                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <button type="button" onClick={onClose} disabled={processing} className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold dark:border-zinc-700 dark:bg-zinc-950">Cancel</button>
                    <button type="button" onClick={() => submit('deny')} disabled={processing} className="inline-flex items-center gap-2 rounded-md bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700 disabled:opacity-60">
                        <XCircle className="h-4 w-4" /> Disapprove
                    </button>
                    <button type="button" onClick={() => submit('approve')} disabled={processing} className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white hover:bg-brand-800 disabled:opacity-60">
                        {processing ? <CheckCircle2 className="h-4 w-4 animate-pulse" /> : <ShieldCheck className="h-4 w-4" />}
                        Grant {role} access
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
