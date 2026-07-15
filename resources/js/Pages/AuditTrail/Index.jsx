import { Head, router } from '@inertiajs/react';
import { FileClock, Search, ShieldCheck, UserCheck, UserX, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDateTime } from '@/Utils/dateFormat';

const optionList = (placeholder, values, map = (value) => ({ value, label: value })) => [
    { value: '', label: placeholder },
    ...values.map(map),
];

const eventTone = (event) => {
    if (event.includes('failed')) {
        return 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-200';
    }

    if (event.includes('login') || event.includes('created') || event.includes('registered')) {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-zinc-800 dark:text-zinc-200';
};

function Metric({ label, value, icon: Icon }) {
    return (
        <Card>
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase text-slate-500 dark:text-zinc-400">{label}</p>
                    <p className="mt-2 text-2xl font-extrabold">{Number(value ?? 0).toLocaleString()}</p>
                </div>
                <div className="flex h-11 w-11 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                    <Icon className="h-5 w-5" />
                </div>
            </div>
        </Card>
    );
}

export default function Index({ logs, filters, filterOptions, metrics }) {
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const [showFilters, setShowFilters] = useState(false);

    const changeFilter = (key, value) => {
        router.get('/audit-trail', { ...filters, [key]: value }, { preserveState: true, preserveScroll: true });
    };

    const applySearch = (event) => {
        event.preventDefault();
        router.get('/audit-trail', { ...filters, search: searchQuery }, { preserveState: true, preserveScroll: true });
    };

    const clearSearch = () => {
        setSearchQuery('');
        router.get('/audit-trail', { ...filters, search: '' }, { preserveState: true, preserveScroll: true });
    };

    const activeFilterCount = ['event', 'user_id'].filter((key) => Boolean(filters[key])).length;

    return (
        <AppLayout title="Audit Trail">
            <Head title="Audit Trail" />

            <div id="audit-summary" className="grid scroll-mt-28 gap-4 md:grid-cols-4">
                <Metric label="Total Events" value={metrics.total} icon={FileClock} />
                <Metric label="Today" value={metrics.today} icon={ShieldCheck} />
                <Metric label="Sign Ins" value={metrics.sign_ins} icon={UserCheck} />
                <Metric label="Sign Outs" value={metrics.sign_outs} icon={UserX} />
            </div>

            <ExportableCard id="audit-filters" title="System Activity Log" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <button
                        type="button"
                        onClick={() => setShowFilters((current) => !current)}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700"
                    >
                        <FileClock className="h-4 w-4" />
                        Filters
                        {activeFilterCount > 0 && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{activeFilterCount}</span>}
                    </button>
                    <form onSubmit={applySearch} className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            className="w-full pl-9 pr-24"
                            placeholder="Search event, user, IP address, or changed data..."
                            value={searchQuery}
                            onChange={(event) => setSearchQuery(event.target.value)}
                        />
                        {searchQuery && (
                            <button type="button" onClick={clearSearch} className="absolute right-16 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                                <X className="h-4 w-4" />
                            </button>
                        )}
                        <button type="submit" className="absolute right-1 top-1/2 -translate-y-1/2 rounded-md bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">
                            Search
                        </button>
                    </form>
                </div>

                {showFilters && (
                    <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2 dark:border-zinc-800">
                        <SearchableSelect
                            label="Event"
                            options={optionList('All Events', filterOptions.events, (event) => ({
                                value: event,
                                label: event.replace(/[._]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
                            }))}
                            value={filters.event ?? ''}
                            onChange={(value) => changeFilter('event', value)}
                            placeholder="Search event..."
                        />
                        <SearchableSelect
                            label="User"
                            options={optionList('All Users', filterOptions.users, (user) => ({
                                value: user.id,
                                label: `${user.name} (${user.office ?? 'No office'})`,
                            }))}
                            value={filters.user_id ?? ''}
                            onChange={(value) => changeFilter('user_id', value)}
                            placeholder="Search user..."
                        />
                    </div>
                )}
            </ExportableCard>

            <Card id="audit-log" className="mt-6 scroll-mt-28">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h2 className="font-semibold">System Activity Log</h2>
                    <p className="text-xs text-slate-500 dark:text-zinc-400">{logs.total} records</p>
                </div>
                <DataTable
                    stickyHeader
                    className="max-h-[calc(100vh-260px)] overflow-y-auto"
                    columns={['Date / Time', 'User', 'Event', 'Target', 'IP Address', 'Details']}
                    rows={logs.data.map((log) => (
                        <tr key={log.id}>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(log.created_at)}</td>
                            <td className="whitespace-nowrap px-4 py-3">
                                <div className="font-semibold">{log.user?.name ?? 'System / Guest'}</div>
                                <div className="text-xs text-slate-500 dark:text-zinc-400">{log.user?.office ?? log.user?.email ?? '-'}</div>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3">
                                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${eventTone(log.event)}`}>
                                    {log.event_label}
                                </span>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3">{log.auditable_type ? `${log.auditable_type} #${log.auditable_id}` : '-'}</td>
                            <td className="whitespace-nowrap px-4 py-3">{log.ip_address ?? '-'}</td>
                            <td className="min-w-[280px] px-4 py-3">
                                <details>
                                    <summary className="cursor-pointer text-sm font-semibold text-brand-700 dark:text-brand-100">View changes</summary>
                                    <pre className="mt-2 max-h-48 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">{JSON.stringify({ old: log.old_values, new: log.new_values }, null, 2)}</pre>
                                </details>
                            </td>
                        </tr>
                    ))}
                />
                <div className="mt-4 flex flex-wrap gap-2">
                    {logs.links.map((link) => (
                        <button
                            key={link.label}
                            type="button"
                            disabled={!link.url}
                            onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold ${link.active ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 disabled:opacity-40 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </Card>
        </AppLayout>
    );
}
