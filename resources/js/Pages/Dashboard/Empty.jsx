import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

export default function Empty({ dashboardRole = 'Dashboard' }) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div aria-label={`Empty ${dashboardRole} dashboard`} />
        </AppLayout>
    );
}
