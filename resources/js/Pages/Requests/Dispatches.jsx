import { Head, useForm } from '@inertiajs/react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';

export default function Dispatches({ dispatches, requests, vehicles, libraryOptions = {} }) {
    const form = useForm({ request_id: requests[0]?.id ?? '', vehicle_id: '', destination: '', receiving_agency_lgu: '', driver: '', dispatcher: '', dispatch_date: new Date().toISOString().slice(0, 10), estimated_arrival: '', remarks: '' });

    return (
        <AppLayout title="Dispatch Planning">
            <Head title="Dispatch Planning" />
            <div className="grid gap-6 xl:grid-cols-[380px_1fr]">
                <ExportableCard id="dispatch-create" title="Dispatch Plans" className="scroll-mt-28" showExportButtons={false}>
                    <h2 className="mb-4 font-semibold">Create Dispatch</h2>
                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/dispatches', { onSuccess: () => form.reset() }); }}>
                        <select className="w-full" value={form.data.request_id} onChange={(e) => form.setData('request_id', e.target.value)}>{requests.map((request) => <option key={request.id} value={request.id}>{request.reference_number}</option>)}</select>
                        <select className="w-full" value={form.data.vehicle_id} onChange={(e) => form.setData('vehicle_id', e.target.value)}><option value="">No vehicle</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate_number}</option>)}</select>
                        {['destination', 'receiving_agency_lgu', 'driver', 'dispatcher'].map((field) => {
                            const options = field === 'destination' ? libraryOptions.delivery_site : field === 'receiving_agency_lgu' ? libraryOptions.recipient_requesting_party : [];
                            return <label key={field} className="block"><input list={options?.length ? `dispatch-${field}` : undefined} className="w-full" placeholder={field.replaceAll('_', ' ')} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />{options?.length > 0 && <datalist id={`dispatch-${field}`}>{options.map((option) => <option key={option} value={option} />)}</datalist>}</label>;
                        })}
                        <input type="date" className="w-full" value={form.data.dispatch_date} onChange={(e) => form.setData('dispatch_date', e.target.value)} />
                        <button className="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Create Dispatch</button>
                    </form>
                </ExportableCard>
                <Card id="dispatch-list" className="scroll-mt-28">
                    <DataTable columns={['Dispatch No.', 'Request', 'Destination', 'Agency/LGU', 'Date', 'Status']} rows={dispatches.data.map((dispatch) => (
                        <tr key={dispatch.id}><td className="px-4 py-3 font-medium">{dispatch.dispatch_number}</td><td className="px-4 py-3">{dispatch.request?.reference_number}</td><td className="px-4 py-3">{dispatch.destination}</td><td className="px-4 py-3">{dispatch.receiving_agency_lgu}</td><td className="px-4 py-3">{dispatch.dispatch_date}</td><td className="px-4 py-3">{dispatch.status}</td></tr>
                    ))} />
                </Card>
            </div>
        </AppLayout>
    );
}
