import { usePage } from '@inertiajs/react';
import { FileText, Files } from 'lucide-react';
import SystemTabs from '@/Components/SystemTabs';

export default function DrmdAaRequestWorkspaceTabs({ active }) {
    const roles = usePage().props.auth?.user?.roles ?? [];

    if (!roles.includes('DRMD AA')) {
        return null;
    }

    return (
        <div className="rounded-t-lg border border-b-0 border-slate-200 bg-white px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900">
            <p className="mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400">
                DRMD AA Request Workspace
            </p>
            <SystemTabs
                active={active}
                ariaLabel="DRMD AA request workspace"
                className="rounded-b-none border-b-0"
                items={[
                    { key: 'requests', label: 'FNI Requests', icon: FileText, href: '/drmd-aa/requests' },
                    { key: 'proposals', label: 'Proposals', icon: Files, href: '/drmd-aa/proposals' },
                ]}
            />
        </div>
    );
}
