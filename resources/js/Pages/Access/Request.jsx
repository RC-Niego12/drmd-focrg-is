import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Clock3, LogOut, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import SearchableSelect from '@/Components/SearchableSelect';
import AppLayout, { Card } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';

export default function Request({ access, roleOptions }) {
    const [requestedRole, setRequestedRole] = useState(access.requested_role || roleOptions[0]?.value || 'RROS');
    const [processing, setProcessing] = useState(false);
    const { systemName } = usePage().props;
    const approved = access.status === 'approved';
    const submit = (event) => {
        event.preventDefault();
        setProcessing(true);
        router.post('/access/request', { requested_role: requestedRole }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout title="Access Request">
            <Head title="Access Request" />
            <div className="mx-auto max-w-3xl">
                <Card className="overflow-hidden p-0">
                    <div className="border-b border-slate-200 bg-slate-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex items-start gap-4">
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 ring-1 ring-brand-100 dark:bg-brand-950 dark:text-brand-100 dark:ring-brand-800">
                                {approved ? <CheckCircle2 className="h-6 w-6" /> : <Clock3 className="h-6 w-6" />}
                            </div>
                            <div>
                                <p className="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-100">Caraga Connect SSO</p>
                                <h2 className="mt-1 text-2xl font-black text-slate-950 dark:text-white">
                                    {approved ? 'Access granted' : 'Request system access'}
                                </h2>
                                <p className="mt-2 text-sm text-slate-600 dark:text-zinc-300">
                                    {approved
                                        ? `Your account has been approved as ${access.assigned_role}. You may now continue to the ${systemName}.`
                                        : 'Your SSO sign-in was successful. Please choose the user-level access you need and wait for Super Admin approval.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="p-6">
                        {approved ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="rounded-md bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                                    <p className="text-xs font-bold uppercase">Assigned user-level</p>
                                    <p className="mt-2 text-xl font-black">{access.assigned_role}</p>
                                </div>
                                <div className="rounded-md bg-slate-50 p-4 text-sm text-slate-700 dark:bg-zinc-950 dark:text-zinc-300">
                                    <p className="text-xs font-bold uppercase">Approved on</p>
                                    <p className="mt-2 font-semibold">{formatDateTime(access.approved_at, 'Recorded')}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => router.visit(access.home_url)}
                                    className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 sm:col-span-2"
                                >
                                    <ShieldCheck className="h-4 w-4" />
                                    Go to system homepage
                                </button>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="space-y-5">
                                <SearchableSelect
                                    label="Requested user-level"
                                    options={roleOptions}
                                    value={requestedRole}
                                    onChange={setRequestedRole}
                                />
                                <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                    <p className="font-bold">Pending Super Admin approval</p>
                                    <p className="mt-1">
                                        {access.requested_at
                                            ? `Your latest request was submitted on ${formatDateTime(access.requested_at)}.`
                                            : 'No access request has been submitted yet.'}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex items-center justify-center rounded-md bg-brand-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                                    >
                                        {processing ? 'Submitting...' : 'Submit access request'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => router.post('/logout')}
                                        className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
                                    >
                                        <LogOut className="h-4 w-4" />
                                        Sign out
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </Card>
            </div>
        </AppLayout>
    );
}
