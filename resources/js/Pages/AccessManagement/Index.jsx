import { Head, router } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Search, ShieldCheck, UserCheck, UsersRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import SearchableSelect from '@/Components/SearchableSelect';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';

const statusOptions = [
    { value: 'approved', label: 'Approved' },
    { value: 'pending', label: 'Pending' },
    { value: 'denied', label: 'Denied' },
];

export default function Index({ users, roleOptions, metrics }) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('');
    const [role, setRole] = useState('');
    const [editing, setEditing] = useState(null);

    const filteredRoleOptions = useMemo(() => [{ value: '', label: 'All roles' }, ...roleOptions], [roleOptions]);
    const filteredStatusOptions = [{ value: '', label: 'All statuses' }, ...statusOptions];

    const visibleUsers = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return users.filter((user) => {
            const matchesStatus = !status || user.access_status === status;
            const matchesRole = !role || user.roles.includes(role);
            const haystack = [
                user.name,
                user.email,
                user.office,
                user.position,
                user.designation,
                user.requested_role,
                user.access_status,
                ...user.roles,
            ].filter(Boolean).join(' ').toLowerCase();

            return matchesStatus && matchesRole && (!needle || haystack.includes(needle));
        });
    }, [users, query, status, role]);

    return (
        <AppLayout title="User Access">
            <Head title="User Access" />
            <div id="access-summary" className="grid scroll-mt-28 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard title="Users" value={metrics.total} icon={UsersRound} tone="slate" />
                <MetricCard title="Pending access" value={metrics.pending} icon={KeyRound} tone="amber" />
                <MetricCard title="Approved" value={metrics.approved} icon={UserCheck} tone="emerald" />
                <MetricCard title="Inactive" value={metrics.inactive} icon={X} tone="rose" />
            </div>

            <ExportableCard id="access-users" title="Access Management" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 className="text-lg font-black">Access Management</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">Approve SSO users and assign user-level access.</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3 lg:w-[46rem]">
                        <label className="block text-sm font-medium">
                            Search
                            <div className="relative mt-1">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="w-full pl-9"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Search users..."
                                />
                            </div>
                        </label>
                        <SearchableSelect label="Status" options={filteredStatusOptions} value={status} onChange={setStatus} />
                        <SearchableSelect label="Role" options={filteredRoleOptions} value={role} onChange={setRole} />
                    </div>
                </div>

                <div className="mt-5 max-h-[62vh] overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
                    <DataTable
                        columns={['Name', 'Email', 'Position', 'Designation', 'Requested', 'Current Role', 'Status', 'Created', { label: 'Actions', align: 'right', actionColumn: true }]}
                        rows={visibleUsers.map((user) => (
                            <tr key={user.id} className={user.access_status === 'pending' ? 'bg-amber-50/60 dark:bg-amber-950/20' : undefined}>
                                <td className="whitespace-nowrap px-4 py-3 font-bold">{user.name}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.email}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.position || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.designation || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.requested_role || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.roles.join(', ') || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <StatusPill status={user.access_status} />
                                </td>
                                <td className="whitespace-nowrap px-4 py-3">{formatDateTime(user.created_at)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    <TableActionButton icon={ShieldCheck} label="Manage" onClick={() => setEditing(user)} tone="brand" />
                                </td>
                            </tr>
                        ))}
                    />
                </div>
            </ExportableCard>

            {editing && (
                <AccessModal
                    user={editing}
                    roleOptions={roleOptions}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}

function AccessModal({ user, roleOptions, onClose }) {
    const [form, setForm] = useState({
        name: user.name || '',
        role: user.roles[0] || user.requested_role || 'RROS',
        access_status: user.access_status || 'pending',
        office: user.office || '',
        position: user.position || '',
        designation: user.designation || '',
        is_active: user.is_active,
    });
    const [processing, setProcessing] = useState(false);

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        setProcessing(true);
        router.patch(`/access-management/${user.id}`, form, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <form onSubmit={submit} className="w-full max-w-2xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-100">User-level access</p>
                        <h3 className="mt-1 text-xl font-black">{user.name}</h3>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">{user.email}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="grid gap-4 p-5 sm:grid-cols-2">
                    <label className="block text-sm font-medium sm:col-span-2">
                        User name
                        <input className="mt-1 w-full" value={form.name} onChange={(event) => update('name', event.target.value)} placeholder="Full name" required />
                    </label>
                    <SearchableSelect label="Assigned user-level" options={roleOptions} value={form.role} onChange={(value) => update('role', value)} />
                    <SearchableSelect label="Access status" options={statusOptions} value={form.access_status} onChange={(value) => update('access_status', value)} />
                    <label className="block text-sm font-medium">
                        Office
                        <input className="mt-1 w-full" value={form.office} onChange={(event) => update('office', event.target.value)} placeholder="Office / Section" />
                    </label>
                    <label className="block text-sm font-medium">
                        Position
                        <input className="mt-1 w-full" value={form.position || ''} onChange={(event) => update('position', event.target.value)} placeholder="Position" />
                    </label>
                    <label className="block text-sm font-medium">
                        Designation
                        <input className="mt-1 w-full" value={form.designation || ''} onChange={(event) => update('designation', event.target.value)} placeholder="Designation / functional assignment" />
                    </label>
                    <label className="flex items-center gap-3 rounded-md border border-slate-200 p-3 text-sm font-bold dark:border-zinc-800">
                        <input type="checkbox" checked={form.is_active} onChange={(event) => update('is_active', event.target.checked)} />
                        Active account
                    </label>
                    <div className="rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-zinc-900 dark:text-zinc-300">
                        Requested: <span className="font-bold">{user.requested_role || '-'}</span>
                        <br />
                        Requested on: <span className="font-bold">{formatDateTime(user.access_requested_at)}</span>
                    </div>
                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <button type="button" onClick={onClose} className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:hover:bg-zinc-800">
                        Cancel
                    </button>
                    <button type="submit" disabled={processing} className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60">
                        <CheckCircle2 className="h-4 w-4" />
                        {processing ? 'Saving...' : 'Save access'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function MetricCard({ title, value, icon: Icon, tone }) {
    const tones = {
        slate: 'bg-slate-50 text-slate-700 dark:bg-zinc-950 dark:text-zinc-200',
        amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-100',
        emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
        rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-100',
    };

    return (
        <Card>
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black">{Number(value || 0).toLocaleString()}</p>
                </div>
                <div className={`flex h-12 w-12 items-center justify-center rounded-md ${tones[tone]}`}>
                    <Icon className="h-6 w-6" />
                </div>
            </div>
        </Card>
    );
}

function StatusPill({ status }) {
    const styles = {
        approved: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-100 dark:ring-emerald-800',
        pending: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800',
        denied: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950 dark:text-rose-100 dark:ring-rose-800',
    };

    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-black capitalize ring-1 ${styles[status] || styles.pending}`}>
            {status}
        </span>
    );
}
