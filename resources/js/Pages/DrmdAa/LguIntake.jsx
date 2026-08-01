import { Head, Link, useForm } from '@inertiajs/react';
import { FileCheck2, Hash } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';
import DrmdAaRequestWorkspaceTabs from '@/Components/DrmdAaRequestWorkspaceTabs';

export default function LguIntake({ requests }) {
    return (
        <AppLayout title="Validated LGU Documents">
            <Head title="Validated LGU Documents" />
            <DrmdAaRequestWorkspaceTabs active="lgu" />
            <Card className="rounded-t-none border-t-0 shadow-none">
                <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD AA Registry</p>
                    <h1 className="mt-1 text-2xl font-black">Validated LGU Reports and Requests</h1>
                    <p className="mt-1 text-sm text-slate-500">Only validated signed copies appear here. LGU-origin relief requests are already available to DRRS/PDRC; record the official DRN without repeating the routing process.</p>
                </div>
                <DataTable columns={['LGU Documents', 'LGU', 'Incident', 'Validated', 'Linked FNI Request', 'DRN', 'Documents']} rows={(requests?.data ?? []).map((row) => (
                    <AaRow key={row.id} row={row} />
                ))} />
                {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No fully validated LGU signed documents are available.</div>}
                <Pagination links={requests?.links} />
            </Card>
        </AppLayout>
    );
}

function AaRow({ row }) {
    const linked = row.relief_augmentation_request;
    const form = useForm({ request_drn: linked?.request_drn || '' });
    return (
        <tr>
            <td className="min-w-[250px] px-4 py-3">
                <p className="font-black">{row.reference_number}</p>
                <p className="mt-1 font-mono text-xs font-bold text-emerald-700">DIS-INC-{String(row.lgu_dromic_series_key || `REQ-${row.id}`).slice(0, 12).toUpperCase()}</p>
                {row.lgu_relief_request_reference && <p className="mt-1 text-xs font-bold text-violet-700">{row.lgu_relief_request_reference}</p>}
            </td>
            <td className="px-4 py-3 font-bold">{row.requesting_agency}</td>
            <td className="px-4 py-3">{row.incident?.name || row.lgu_dromic_payload?.incident_name || '-'}</td>
            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(row.lgu_dromic_reviewed_at)}</td>
            <td className="px-4 py-3">{linked ? <div><p className="font-black">{linked.reference_number}</p><p className="text-xs capitalize text-slate-500">{String(linked.status || '').replaceAll('_', ' ')}</p></div> : <span className="text-xs text-slate-400">Report only</span>}</td>
            <td className="min-w-[230px] px-4 py-3">
                {linked ? <form className="flex items-center gap-2" onSubmit={(event) => { event.preventDefault(); form.patch(`/drmd-aa/lgu-intake/${row.id}/drn`, { preserveScroll: true }); }}>
                    <input value={form.data.request_drn} onChange={(event) => form.setData('request_drn', event.target.value)} placeholder="Enter official DRN" className="h-9 min-w-0 flex-1 text-sm" />
                    <button title="Save the official DRN for this LGU request" aria-label="Save DRN" disabled={form.processing} className="inline-flex h-9 w-9 items-center justify-center rounded-md bg-emerald-700 text-white disabled:opacity-50"><Hash className="h-4 w-4" /></button>
                    {form.errors.request_drn && <p className="text-xs font-bold text-rose-600">{form.errors.request_drn}</p>}
                </form> : <span className="text-xs text-slate-400">Not applicable</span>}
            </td>
            <td className="px-4 py-3"><DocumentLinks row={row} /></td>
        </tr>
    );
}

function DocumentLinks({ row }) {
    return <div className="flex flex-wrap gap-2">
        <a title="View validated signed DROMIC report" href={`/lgu/dromic-sitrep/${row.id}/signed-copy/report`} target="_blank" rel="noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700"><FileCheck2 className="h-4 w-4" /></a>
        {row.lgu_signed_request_path && <a title="View validated signed request letter" href={`/lgu/dromic-sitrep/${row.id}/signed-copy/request`} target="_blank" rel="noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-violet-200 bg-violet-50 text-violet-700"><FileCheck2 className="h-4 w-4" /></a>}
    </div>;
}

function Pagination({ links = [] }) {
    if (links.length <= 3) return null;
    return <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4">{links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>;
}
