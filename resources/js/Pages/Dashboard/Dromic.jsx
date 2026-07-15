import { Head, useForm } from '@inertiajs/react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';

export default function Dromic({ reports, eligibleRequests }) {
    const form = useForm({ request_id: eligibleRequests[0]?.id ?? '', google_sheet_url: '', worksheet_name: '' });

    return (
        <AppLayout title="DROMIC Reporting">
            <Head title="DROMIC Reporting" />
            <div className="grid gap-6 xl:grid-cols-[380px_1fr]">
                <ExportableCard id="dromic-create" title="DROMIC Reports" className="scroll-mt-28">
                    <h2 className="mb-4 font-semibold">Create DROMIC Report</h2>
                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/dromic', { onSuccess: () => form.reset() }); }}>
                        <select className="w-full" value={form.data.request_id} onChange={(e) => form.setData('request_id', e.target.value)}>{eligibleRequests.map((request) => <option key={request.id} value={request.id}>{request.reference_number} · {request.incident?.name}</option>)}</select>
                        <input className="w-full" placeholder="Google Sheet URL" value={form.data.google_sheet_url} onChange={(e) => form.setData('google_sheet_url', e.target.value)} />
                        <input className="w-full" placeholder="Worksheet name" value={form.data.worksheet_name} onChange={(e) => form.setData('worksheet_name', e.target.value)} />
                        <button className="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Create Report</button>
                    </form>
                </ExportableCard>
                <Card id="dromic-list" className="scroll-mt-28">
                    <DataTable columns={['Report No.', 'Request', 'Affected LGU', 'Released', 'Sheet', 'Status']} rows={reports.data.map((report) => (
                        <tr key={report.id}><td className="px-4 py-3 font-medium">{report.report_number}</td><td className="px-4 py-3">{report.request?.reference_number}</td><td className="px-4 py-3">{report.affected_lgu}</td><td className="px-4 py-3">{report.date_released}</td><td className="px-4 py-3">{report.worksheet_name ?? 'Not linked'}</td><td className="px-4 py-3">{report.status}</td></tr>
                    ))} />
                </Card>
            </div>
        </AppLayout>
    );
}
