import { Head, Link, useForm } from '@inertiajs/react';
import { Download, Send } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatDate } from '@/Utils/dateFormat';

export default function LguIntake({ requests }) {
    return (
        <AppLayout title="LGU Intake">
            <Head title="LGU Intake" />
            <Card>
                <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD AA Routing Desk</p>
                    <h1 className="mt-1 text-2xl font-black">LGU DROMIC / Relief Augmentation Intake</h1>
                    <p className="mt-1 text-sm text-slate-500">Route new LGU requests to the DRMD Chief, then endorse Chief-directed requests to DRRS and concerned response units.</p>
                </div>
                <DataTable columns={['Reference', 'LGU', 'Incident', 'Submitted', 'Status', 'Directive / Remarks', 'Action']} rows={(requests?.data ?? []).map((row) => (
                    <LguAaRow key={row.id} row={row} />
                ))} />
                {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No LGU requests awaiting DRMD AA action.</div>}
                {requests?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </Card>
        </AppLayout>
    );
}

function LguAaRow({ row }) {
    const routeChief = useForm({ remarks: row.drmd_aa_remarks || '' });
    const routeDrrs = useForm({ remarks: row.remarks || '' });
    const status = row.lgu_routing_status || row.status;
    const canRouteChief = status === 'for_drmd_aa_review';
    const canRouteDrrs = status === 'for_drmd_aa_routing';

    return (
        <tr>
            <td className="whitespace-nowrap px-4 py-3 font-black">{row.reference_number}</td>
            <td className="px-4 py-3">{row.requesting_agency}</td>
            <td className="px-4 py-3">{row.incident?.name || '-'}</td>
            <td className="whitespace-nowrap px-4 py-3">{formatDate(row.submitted_at)}</td>
            <td className="px-4 py-3"><span className="rounded-full bg-amber-50 px-2 py-1 text-xs font-black uppercase text-amber-700">{String(status || '').replaceAll('_', ' ')}</span></td>
            <td className="max-w-md px-4 py-3 text-sm">
                {row.drmd_chief_remarks ? <p><span className="font-black">Chief:</span> {row.drmd_chief_remarks}</p> : <p className="text-slate-400">No Chief directive yet.</p>}
                {row.drmd_assigned_section && <p className="mt-1 text-xs font-bold text-slate-500">Assigned section: {row.drmd_assigned_section}</p>}
            </td>
            <td className="min-w-[280px] px-4 py-3">
                <div className="mb-2">
                    <a href={`/lgu/dromic-requests/${row.id}/pdf?inline=1`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-black text-brand-700 underline"><Download className="h-3.5 w-3.5" /> View PDF</a>
                </div>
                {canRouteChief && (
                    <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); routeChief.patch(`/drmd-aa/lgu-intake/${row.id}/chief`, { preserveScroll: true }); }}>
                        <textarea className="w-full text-sm" rows="2" placeholder="DRMD AA remarks for Chief..." value={routeChief.data.remarks} onChange={(event) => routeChief.setData('remarks', event.target.value)} />
                        {routeChief.errors.remarks && <p className="text-xs font-bold text-rose-600">{routeChief.errors.remarks}</p>}
                        <button disabled={routeChief.processing} className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white disabled:opacity-60"><Send className="h-3.5 w-3.5" /> Route to DRMD Chief</button>
                    </form>
                )}
                {canRouteDrrs && (
                    <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); routeDrrs.patch(`/drmd-aa/lgu-intake/${row.id}/drrs`, { preserveScroll: true }); }}>
                        <textarea className="w-full text-sm" rows="2" placeholder="Final routing remarks to DRRS/PDRC..." value={routeDrrs.data.remarks} onChange={(event) => routeDrrs.setData('remarks', event.target.value)} />
                        <button disabled={routeDrrs.processing} className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-3 py-2 text-xs font-black text-white disabled:opacity-60"><Send className="h-3.5 w-3.5" /> Route to DRRS / PDRC</button>
                    </form>
                )}
                {!canRouteChief && !canRouteDrrs && <p className="text-xs font-bold text-slate-400">No DRMD AA action needed.</p>}
            </td>
        </tr>
    );
}
