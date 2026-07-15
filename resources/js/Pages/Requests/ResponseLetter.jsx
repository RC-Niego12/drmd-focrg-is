import { Head } from '@inertiajs/react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';

export default function ResponseLetter({ request }) {
    return (
        <AppLayout title="Response Letter">
            <Head title="Response Letter" />
            <Card>
                <div className="mb-8 flex justify-between gap-4 border-b border-slate-300 pb-4">
                    <div><p className="text-xs font-black uppercase">Disaster Response Management Division</p><h2 className="text-xl font-bold">Response Letter</h2><p className="text-sm text-slate-500">DRN: {request.response_drn || request.reference_number}</p></div>
                    <div className="flex gap-2 print:hidden"><a href={`/requests/${request.id}/response-letter-pdf`} className="rounded-md border border-brand-600 px-4 py-2 text-sm font-semibold text-brand-700">Download PDF</a><button onClick={() => window.print()} className="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Print</button></div>
                </div>
                <p className="text-sm font-bold">{request.requester}</p><p className="text-sm">{request.requester_position || request.office_agency_details}</p><p className="mb-6 text-sm">{request.requester_address || `${request.municipality}, ${request.province}`}</p>
                <p className="mb-4 text-sm">Dear {request.requester?.split(' ').slice(-1)[0] || 'Sir/Madam'}:</p>
                <p className="mb-4 text-sm leading-7">This is in reference to your request for food and non-food items intended for <strong>{Number(request.affected_families || 0).toLocaleString()}</strong> disaster-affected families due to <strong>{request.incident?.name}</strong>{request.incident_details ? ` (${request.incident_details})` : ''}.</p>
                <p className="mb-5 text-sm leading-7">After assessment by {request.assigned_social_worker || 'the assigned DRRS Social Worker'}, the following augmentation assistance has been approved:</p>
                {!['approved', 'partially_approved'].includes(request.status) && <p className="mb-4 border border-amber-300 bg-amber-50 p-3 text-center text-xs font-black text-amber-800 print:hidden">DRAFT — pending assessment/approval</p>}
                <DataTable columns={['Item', 'Quantity', 'Unit']} rows={request.items.filter((item) => Number(['approved', 'partially_approved'].includes(request.status) ? item.approved_quantity : item.requested_quantity) > 0).map((item) => (
                    <tr key={item.id}><td className="px-4 py-3">{item.item_name}</td><td className="px-4 py-3">{['approved', 'partially_approved'].includes(request.status) ? item.approved_quantity : item.requested_quantity}</td><td className="px-4 py-3">{item.unit}</td></tr>
                ))} />
                <p className="mt-6 text-sm leading-7">RROS personnel will prepare the Requisition and Issuance Slip for the approved items and coordinate delivery or pick-up with your designated focal person.</p>
                <p className="mt-4 text-sm">For your information. Thank you.</p>
                <div className="mt-10 grid gap-8 text-sm md:grid-cols-2"><p>Prepared by:<br /><strong>{request.assigned_social_worker || 'DRRS Authorized Personnel'}</strong></p><p>Approved by:<br /><strong>Regional Director</strong></p></div>
            </Card>
        </AppLayout>
    );
}
