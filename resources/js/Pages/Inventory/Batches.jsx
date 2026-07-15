import { Head } from '@inertiajs/react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import { formatExpiryMonth } from '@/Utils/dateFormat';

export default function Batches({ batches }) {
    return (
        <AppLayout title="Inventory Batches">
            <Head title="Inventory Batches" />
            <Card>
                <DataTable columns={['Item', 'Batch', 'Warehouse', 'Quantity', 'Reserved', 'Expiry', 'Status']} rows={batches.data.map((batch) => (
                    <tr key={batch.id}><td className="px-4 py-3">{batch.item?.name}</td><td className="px-4 py-3">{batch.batch_number}</td><td className="px-4 py-3">{batch.warehouse?.name}</td><td className="px-4 py-3">{batch.quantity}</td><td className="px-4 py-3">{batch.reserved_quantity}</td><td className="px-4 py-3">{formatExpiryMonth(batch.expiration_date)}</td><td className="px-4 py-3">{batch.current_status}</td></tr>
                ))} />
            </Card>
        </AppLayout>
    );
}
