import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Archive, CheckCircle2, Crown, KeyRound, RotateCcw, Search, ShieldCheck, Trash2, UserCheck, UsersRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import SearchableSelect from '@/Components/SearchableSelect';
import AppLayout, { Card, DataTable, ExportableCard, TableActionButton } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';
import AccessDecisionModal from '@/Components/AccessDecisionModal';

const statusOptions = [
    { value: 'approved', label: 'Approved' },
    { value: 'pending', label: 'Pending' },
    { value: 'denied', label: 'Denied' },
];

function EmployeeAvatar({ user, size = 'table' }) {
    const [imageFailed, setImageFailed] = useState(false);
    const initials = String(user?.name || 'Employee')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    const dimensions = size === 'modal' ? 'h-16 w-16 text-lg' : 'h-10 w-10 text-xs';

    return (
        <span className={`group/avatar relative z-10 inline-flex shrink-0 ${dimensions}`}>
            <span className="absolute inset-0 rounded-full bg-gradient-to-br from-emerald-300 via-brand-400 to-sky-500 opacity-60 blur-sm transition duration-300 group-hover/avatar:scale-[1.7] group-hover/avatar:opacity-90" />
            <span className="relative flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-white bg-brand-700 font-black text-white shadow-md ring-1 ring-brand-200 transition duration-300 ease-out group-hover/avatar:z-[260] group-hover/avatar:scale-[1.75] group-hover/avatar:shadow-2xl dark:border-zinc-900 dark:ring-brand-800">
                {user?.avatar && !imageFailed
                    ? <img src={user.avatar} alt={`${user.name} employee photo`} className="h-full w-full object-cover" onError={() => setImageFailed(true)} />
                    : <span>{initials}</span>}
            </span>
        </span>
    );
}

export default function Index({ users, deletedUsers = [], roleOptions, requestableRoleOptions = [], metrics, ssoEmployees = [] }) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('');
    const [role, setRole] = useState('');
    const [activeSection, setActiveSection] = useState('drmd');
    const [editing, setEditing] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [superAdminId, setSuperAdminId] = useState('');
    const [assigningSuperAdmin, setAssigningSuperAdmin] = useState(false);

    const filteredRoleOptions = useMemo(() => [{ value: '', label: 'All roles' }, ...roleOptions], [roleOptions]);
    const filteredStatusOptions = [{ value: '', label: 'All statuses' }, ...statusOptions];
    const requestRoleOptions = useMemo(() => requestableRoleOptions.length ? requestableRoleOptions : roleOptions.filter((option) => option.value !== 'Super Admin' && option.value !== 'LGU' && option.value !== 'DRMD Chief'), [requestableRoleOptions, roleOptions]);
    const sections = useMemo(() => [
        { id: 'drmd', label: 'DRMD Users', description: 'Internal DROMIS users from DRMD / DSWD response sections.', count: metrics.drmd || users.filter((user) => user.category === 'drmd').length },
        { id: 'lgu', label: 'LGUs', description: 'Province, city, and municipal LGU accounts for DROMIC submissions.', count: metrics.lgu || users.filter((user) => user.category === 'lgu').length },
        { id: 'outside', label: 'Outside DRMD / Future Users', description: 'External partner accounts and future non-DRMD access levels.', count: metrics.outside || users.filter((user) => user.category === 'outside').length },
    ], [metrics, users]);
    const activeSectionMeta = sections.find((section) => section.id === activeSection) || sections[0];

    const assignSuperAdmin = () => {
        if (!superAdminId) return;
        setAssigningSuperAdmin(true);
        router.post('/access-management/super-admin', { user_id: Number(superAdminId) }, {
            preserveScroll: true,
            onFinish: () => setAssigningSuperAdmin(false),
        });
    };

    const visibleUsers = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return users.filter((user) => {
            const matchesStatus = !status || user.access_status === status;
            const matchesRole = !role || user.roles.includes(role);
            const matchesSection = user.category === activeSection;
            const haystack = [
                user.name,
                user.email,
                user.office,
                user.position,
                user.designation,
                user.lgu_name,
                user.lgu_level,
                user.lgu_psgc_code,
                user.requested_role,
                user.access_status,
                ...user.roles,
            ].filter(Boolean).join(' ').toLowerCase();

            return matchesSection && matchesStatus && matchesRole && (!needle || haystack.includes(needle));
        });
    }, [users, query, status, role, activeSection]);

    return (
        <AppLayout title="User Access">
            <Head title="User Access" />
            <div id="access-summary" className="grid scroll-mt-28 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard title="Users" value={metrics.total} icon={UsersRound} tone="slate" />
                <MetricCard title="Pending access" value={metrics.pending} icon={KeyRound} tone="amber" />
                <MetricCard title="Approved" value={metrics.approved} icon={UserCheck} tone="emerald" />
                <MetricCard title="Inactive" value={metrics.inactive} icon={X} tone="rose" />
                <MetricCard title="Archived" value={metrics.deleted} icon={Archive} tone="slate" />
            </div>

            <Card className="mt-6 border-brand-200 bg-brand-50/40 dark:border-brand-900 dark:bg-brand-950/20">
                <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-brand-700 text-white"><Crown className="h-5 w-5" /></div>
                        <div>
                            <h2 className="text-lg font-black">Assign a DROMIS Super Admin</h2>
                            <p className="mt-1 max-w-2xl text-sm text-slate-600 dark:text-zinc-300">Select an active employee identity discovered through Caraga Connect SSO. Promotion grants full administrative permissions and is recorded in the audit trail.</p>
                        </div>
                    </div>
                    <div className="flex w-full flex-col gap-3 sm:flex-row lg:max-w-2xl">
                        <div className="min-w-0 flex-1">
                            <SearchableSelect label="Caraga Connect SSO employee" options={ssoEmployees} value={superAdminId} onChange={setSuperAdminId} placeholder="Search employee name..." />
                        </div>
                        <button type="button" onClick={assignSuperAdmin} disabled={!superAdminId || assigningSuperAdmin} className="self-end rounded-md bg-brand-700 px-5 py-2.5 text-sm font-black text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50">
                            {assigningSuperAdmin ? 'Assigning...' : 'Assign Super Admin'}
                        </button>
                    </div>
                </div>
                {!ssoEmployees.length && <p className="mt-4 rounded-md bg-amber-50 p-3 text-sm font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-100">No active Caraga Connect identities are available yet. Employees appear here after their first successful SSO sign-in.</p>}
            </Card>

            <div className="mt-6 grid gap-3 lg:grid-cols-3">
                {sections.map((section) => (
                    <button
                        key={section.id}
                        type="button"
                        onClick={() => setActiveSection(section.id)}
                        className={`rounded-lg border p-4 text-left shadow-sm transition ${activeSection === section.id ? 'border-brand-500 bg-brand-50 text-brand-950 ring-2 ring-brand-100 dark:border-brand-500 dark:bg-brand-950/50 dark:text-white dark:ring-brand-900' : 'border-slate-200 bg-white hover:border-brand-200 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-brand-800'}`}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-base font-black">{section.label}</p>
                                <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">{section.description}</p>
                            </div>
                            <span className="rounded-full bg-white px-3 py-1 text-sm font-black text-brand-700 ring-1 ring-brand-100 dark:bg-zinc-900 dark:text-brand-100 dark:ring-brand-900">{Number(section.count || 0).toLocaleString()}</span>
                        </div>
                    </button>
                ))}
            </div>

            <ExportableCard id="access-users" title={`${activeSectionMeta.label} Access Management`} className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 className="text-lg font-black">{activeSectionMeta.label}</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">{activeSectionMeta.description}</p>
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
                        columns={activeSection === 'lgu'
                            ? ['LGU Account', 'Email / Username', 'LGU Level', 'PSGC', 'Current Role', 'Status', 'Created', { label: 'Actions', align: 'right', actionColumn: true }]
                            : ['Employee', 'Email', 'Position', 'Designation', 'Requested', 'Current Role', 'Status', 'Created', { label: 'Actions', align: 'right', actionColumn: true }]}
                        rows={visibleUsers.map((user) => (
                            <tr key={user.id} className={user.access_status === 'pending' ? 'bg-amber-50/60 dark:bg-amber-950/20' : undefined}>
                                <td className="whitespace-nowrap px-4 py-3 font-bold">
                                    <div className="flex items-center gap-3">
                                        <EmployeeAvatar user={user} />
                                        <div><p>{activeSection === 'lgu' ? (user.lgu_name || user.name) : user.name}</p>{activeSection !== 'lgu' && <p className="text-[10px] font-bold uppercase tracking-wide text-slate-400">Employee</p>}</div>
                                    </div>
                                </td>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <p>{user.email}</p>
                                    {activeSection === 'lgu' && <p className="text-xs font-bold text-slate-400">{user.email?.split('@')[0]}</p>}
                                </td>
                                {activeSection === 'lgu' ? (
                                    <>
                                        <td className="whitespace-nowrap px-4 py-3 capitalize">{String(user.lgu_level || '-').replaceAll('_', ' ')}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{user.lgu_psgc_code || '-'}</td>
                                    </>
                                ) : (
                                    <>
                                        <td className="whitespace-nowrap px-4 py-3">{user.position || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{user.designation || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{user.requested_role || '-'}</td>
                                    </>
                                )}
                                <td className="whitespace-nowrap px-4 py-3">{user.roles.join(', ') || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <StatusPill status={user.access_status} />
                                </td>
                                <td className="whitespace-nowrap px-4 py-3">{formatDateTime(user.created_at)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    <div className="inline-flex items-center gap-2">
                                        <TableActionButton icon={ShieldCheck} label="Manage" onClick={() => setEditing(user)} tone="brand" />
                                        <TableActionButton icon={Archive} label="Archive user" onClick={() => setDeleteTarget({ user, mode: 'soft' })} tone="amber" />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    />
                </div>
            </ExportableCard>

            <ExportableCard id="deleted-access-users" title="Archived Users" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-lg font-black">Archived Users</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">Restore users or permanently remove archived accounts when they are no longer needed.</p>
                    </div>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600 dark:bg-zinc-900 dark:text-zinc-300">
                        {deletedUsers.length.toLocaleString()} archived
                    </span>
                </div>

                <div className="mt-5 max-h-[42vh] overflow-auto rounded-md border border-slate-200 dark:border-zinc-800">
                    <DataTable
                        columns={['Name', 'Email', 'Previous Role', 'Status', 'Archived', { label: 'Actions', align: 'right', actionColumn: true }]}
                        rows={deletedUsers.map((user) => (
                            <tr key={user.id} className="bg-slate-50/70 text-slate-600 dark:bg-zinc-900/60 dark:text-zinc-300">
                                <td className="whitespace-nowrap px-4 py-3 font-bold"><div className="flex items-center gap-3"><EmployeeAvatar user={user} /><span>{user.name}</span></div></td>
                                <td className="whitespace-nowrap px-4 py-3">{user.email}</td>
                                <td className="whitespace-nowrap px-4 py-3">{user.roles.join(', ') || '-'}</td>
                                <td className="whitespace-nowrap px-4 py-3"><StatusPill status={user.access_status} /></td>
                                <td className="whitespace-nowrap px-4 py-3">{formatDateTime(user.deleted_at)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    <div className="inline-flex items-center gap-2">
                                        <TableActionButton icon={RotateCcw} label="Restore user" onClick={() => restoreUser(user)} tone="emerald" />
                                        <TableActionButton icon={Trash2} label="Permanently delete user" onClick={() => setDeleteTarget({ user, mode: 'hard' })} tone="rose" />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    />
                </div>
                {!deletedUsers.length && (
                    <p className="mt-4 rounded-md bg-slate-50 p-4 text-sm font-semibold text-slate-500 dark:bg-zinc-900 dark:text-zinc-400">
                        No archived users yet.
                    </p>
                )}
            </ExportableCard>

            {editing && editing.access_status === 'pending' ? (
                <AccessDecisionModal user={editing} roleOptions={requestRoleOptions} onClose={() => setEditing(null)} />
            ) : editing ? (
                <AccessModal user={editing} roleOptions={editing.roles.includes('Super Admin') ? roleOptions : requestRoleOptions} onClose={() => setEditing(null)} />
            ) : null}
            {deleteTarget ? (
                <DeleteUserModal target={deleteTarget} onClose={() => setDeleteTarget(null)} />
            ) : null}
        </AppLayout>
    );
}

function restoreUser(user) {
    router.post(`/access-management/${user.id}/restore`, {}, {
        preserveScroll: true,
    });
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

    return createPortal(
        <div className="fixed inset-0 z-[240] flex items-center justify-center overflow-y-auto bg-slate-950/65 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="user-access-modal-title">
            <form onSubmit={submit} className="w-full max-w-2xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-emerald-50 via-slate-50 to-sky-50 p-5 dark:border-zinc-800 dark:from-emerald-950/30 dark:via-zinc-900 dark:to-sky-950/30">
                    <div className="flex items-center gap-4">
                        <EmployeeAvatar user={user} size="modal" />
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-100">User-level access</p>
                            <h3 id="user-access-modal-title" className="mt-1 text-xl font-black">{user.name}</h3>
                            <p className="text-sm text-slate-500 dark:text-zinc-400">{user.email}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close user access" data-tip="Close user access" data-tip-side="bottom" data-tip-preferred-side="bottom" data-tip-locked="true" className="dromis-tip rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white">
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
        </div>,
        document.body,
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

function DeleteUserModal({ target, onClose }) {
    const { user, mode } = target;
    const isHardDelete = mode === 'hard';
    const [confirmation, setConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const canSubmit = !isHardDelete || confirmation === user.email;

    const submit = (event) => {
        event.preventDefault();
        if (!canSubmit) return;

        setProcessing(true);
        router.delete(isHardDelete ? `/access-management/${user.id}/force` : `/access-management/${user.id}`, {
            data: isHardDelete ? { confirmation } : {},
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <form onSubmit={submit} className="w-full max-w-lg overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start gap-3 border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md ${isHardDelete ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-100' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-100'}`}>
                        {isHardDelete ? <Trash2 className="h-5 w-5" /> : <Archive className="h-5 w-5" />}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-zinc-400">{isHardDelete ? 'Permanent deletion' : 'Archive user'}</p>
                        <h3 className="mt-1 text-xl font-black">{user.name}</h3>
                        <p className="break-all text-sm text-slate-500 dark:text-zinc-400">{user.email}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="space-y-4 p-5">
                    <div className={`rounded-md border p-4 text-sm ${isHardDelete ? 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100'}`}>
                        <div className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <p className="font-semibold">
                                {isHardDelete
                                    ? 'This permanently removes the archived account and cannot be undone.'
                                    : 'This deactivates the account and moves it to Archived Users. You can restore it later.'}
                            </p>
                        </div>
                    </div>

                    {isHardDelete && (
                        <label className="block text-sm font-medium">
                            Type the user email to confirm
                            <input
                                className="mt-1 w-full"
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                                placeholder={user.email}
                                required
                            />
                        </label>
                    )}
                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <button type="button" onClick={onClose} className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:hover:bg-zinc-800">
                        Cancel
                    </button>
                    <button type="submit" disabled={processing || !canSubmit} className={`inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-bold text-white shadow-sm transition disabled:opacity-60 ${isHardDelete ? 'bg-rose-700 hover:bg-rose-800' : 'bg-amber-600 hover:bg-amber-700'}`}>
                        {isHardDelete ? <Trash2 className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
                        {processing ? 'Processing...' : isHardDelete ? 'Permanently delete' : 'Archive user'}
                    </button>
                </div>
            </form>
        </div>
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
