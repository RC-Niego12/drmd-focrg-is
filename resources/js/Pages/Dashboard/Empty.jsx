import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

export default function Empty() {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div aria-label="Empty DRMD AA dashboard" />
        </AppLayout>
    );
}
