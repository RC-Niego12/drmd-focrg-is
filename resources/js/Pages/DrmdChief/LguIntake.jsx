import { Head, Link } from '@inertiajs/react';
import { FileCheck2 } from 'lucide-react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatDateTime } from '@/Utils/dateFormat';

export default function LguIntake({ requests }) {
    return (
        <AppLayout title="Validated LGU Documents">
            <Head title="Validated LGU Documents" />
            <Card>
                <div className="border-b border-slate-200 p-5 dark:border-zinc-800">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD Chief Oversight</p>
                    <h1 className="mt-1 text-2xl font-black">Validated LGU Signed Copies</h1>
                    <p className="mt-1 text-sm text-slate-500">This registry contains only signed reports that passed DROMIC validation and, when relief was requested, signed request letters that also passed DRRS validation.</p>
                </div>
                <DataTable columns={['LGU Documents', 'LGU', 'Incident', 'Validated', 'FNI Request / DRN', 'Documents']} rows={(requests?.data ?? []).map((row) => (
                    <tr key={row.id}>
                        <td className="min-w-[250px] px-4 py-3"><p className="font-black">{row.reference_number}</p><p className="mt-1 font-mono text-xs font-bold text-emerald-700">DIS-INC-{String(row.lgu_dromic_series_key || `REQ-${row.id}`).slice(0, 12).toUpperCase()}</p>{row.lgu_relief_request_reference && <p className="mt-1 text-xs font-bold text-violet-700">{row.lgu_relief_request_reference}</p>}</td>
                        <td className="px-4 py-3 font-bold">{row.requesting_agency}</td>
                        <td className="px-4 py-3">{row.incident?.name || row.lgu_dromic_payload?.incident_name || '-'}</td>
                        <td className="whitespace-nowrap px-4 py-3">{formatDateTime(row.lgu_dromic_reviewed_at)}</td>
                        <td className="px-4 py-3">{row.relief_augmentation_request ? <><p className="font-black">{row.relief_augmentation_request.reference_number}</p><p className="text-xs text-slate-500">DRN: {row.relief_augmentation_request.request_drn || 'Pending DRMD AA entry'}</p></> : <span className="text-xs text-slate-400">Report only</span>}</td>
                        <td className="px-4 py-3"><div className="flex gap-2"><a title="View validated signed DROMIC report" href={`/lgu/dromic-sitrep/${row.id}/signed-copy/report`} target="_blank" rel="noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700"><FileCheck2 className="h-4 w-4" /></a>{row.lgu_signed_request_path && <a title="View validated signed request letter" href={`/lgu/dromic-sitrep/${row.id}/signed-copy/request`} target="_blank" rel="noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-violet-200 bg-violet-50 text-violet-700"><FileCheck2 className="h-4 w-4" /></a>}</div></td>
                    </tr>
                ))} />
                {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No fully validated LGU signed documents are available.</div>}
                {requests?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </Card>
        </AppLayout>
    );
}
