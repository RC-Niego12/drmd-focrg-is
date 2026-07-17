import { Head, Link, useForm } from '@inertiajs/react';
import { Download, Send } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatDate } from '@/Utils/dateFormat';

export default function LguIntake({ requests, assignableUsers = [] }) {
    return (
        <AppLayout title="Chief LGU Directives">
            <Head title="Chief LGU Directives" />
            <Card>
                <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD Chief Workspace</p>
                    <h1 className="mt-1 text-2xl font-black">LGU Request Directives</h1>
                    <p className="mt-1 text-sm text-slate-500">Review DRMD AA-routed LGU requests, record processing directives, and return them to DRMD AA for endorsement.</p>
                </div>
                <DataTable columns={['Reference', 'LGU', 'Incident', 'AA Remarks', 'Status', 'Directive']} rows={(requests?.data ?? []).map((row) => (
                    <ChiefRow key={row.id} row={row} assignableUsers={assignableUsers} />
                ))} />
                {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No LGU requests awaiting Chief directive.</div>}
                {requests?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </Card>
        </AppLayout>
    );
}

function ChiefRow({ row, assignableUsers }) {
    const form = useForm({
        remarks: row.drmd_chief_remarks || '',
        assigned_to: row.drmd_assigned_to || '',
        assigned_section: row.drmd_assigned_section || 'DRRS',
    });
    const status = row.lgu_routing_status || row.status;
    const canAct = status === 'for_drmd_chief_directive';

    return (
        <tr>
            <td className="whitespace-nowrap px-4 py-3 font-black">{row.reference_number}</td>
            <td className="px-4 py-3">{row.requesting_agency}</td>
            <td className="px-4 py-3">
                <p>{row.incident?.name || '-'}</p>
                <p className="text-xs text-slate-500">{formatDate(row.incident?.incident_date)}</p>
            </td>
            <td className="max-w-xs px-4 py-3 text-sm">{row.drmd_aa_remarks || '-'}</td>
            <td className="px-4 py-3"><span className="rounded-full bg-amber-50 px-2 py-1 text-xs font-black uppercase text-amber-700">{String(status || '').replaceAll('_', ' ')}</span></td>
            <td className="min-w-[340px] px-4 py-3">
                <div className="mb-2">
                    <a href={`/lgu/dromic-requests/${row.id}/pdf?inline=1`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-black text-brand-700 underline"><Download className="h-3.5 w-3.5" /> View LGU PDF</a>
                </div>
                {canAct ? (
                    <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); form.patch(`/drmd-chief/lgu-intake/${row.id}/directive`, { preserveScroll: true }); }}>
                        <textarea className="w-full text-sm" rows="3" placeholder="Directive / processing instruction..." value={form.data.remarks} onChange={(event) => form.setData('remarks', event.target.value)} />
                        {form.errors.remarks && <p className="text-xs font-bold text-rose-600">{form.errors.remarks}</p>}
                        <div className="grid gap-2 sm:grid-cols-2">
                            <select className="w-full text-sm" value={form.data.assigned_section} onChange={(event) => form.setData('assigned_section', event.target.value)}>
                                {['DRRS', 'DRMD AA', 'DRIMS', 'RROS', 'Concerned PDRC', 'Other Section/Program'].map((section) => <option key={section} value={section}>{section}</option>)}
                            </select>
                            <select className="w-full text-sm" value={form.data.assigned_to} onChange={(event) => form.setData('assigned_to', event.target.value)}>
                                <option value="">Assign employee (optional)</option>
                                {assignableUsers.map((user) => <option key={user.id} value={user.id}>{user.name} · {user.office || 'DRMD'}</option>)}
                            </select>
                        </div>
                        {form.errors.assigned_section && <p className="text-xs font-bold text-rose-600">{form.errors.assigned_section}</p>}
                        <button disabled={form.processing} className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white disabled:opacity-60"><Send className="h-3.5 w-3.5" /> Return directive to DRMD AA</button>
                    </form>
                ) : (
                    <div className="rounded-md bg-slate-50 p-3 text-sm dark:bg-zinc-800">
                        <p className="font-bold">Directive already recorded.</p>
                        <p className="mt-1 text-slate-500">{row.drmd_chief_remarks || 'No directive text.'}</p>
                    </div>
                )}
            </td>
        </tr>
    );
}
