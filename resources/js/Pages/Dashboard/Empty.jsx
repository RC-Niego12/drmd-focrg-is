import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Construction } from 'lucide-react';

export default function Empty({ dashboardRole = 'Dashboard' }) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="flex min-h-[60vh] items-center justify-center" aria-label={`${dashboardRole} dashboard ongoing development`}>
                <div className="w-full max-w-xl rounded-xl border border-dashed border-amber-300 bg-amber-50/80 p-10 text-center shadow-sm dark:border-amber-800 dark:bg-amber-950/20">
                    <Construction className="mx-auto h-12 w-12 text-amber-600" />
                    <p className="mt-4 text-xs font-black uppercase tracking-[0.2em] text-amber-700 dark:text-amber-300">Ongoing Development</p>
                    <h1 className="mt-2 text-2xl font-black text-slate-900 dark:text-white">This workspace is being prepared</h1>
                    <p className="mt-2 text-sm font-semibold text-slate-600 dark:text-zinc-300">Features and operational content for {dashboardRole} will appear here as they become available.</p>
                </div>
            </div>
        </AppLayout>
    );
}
