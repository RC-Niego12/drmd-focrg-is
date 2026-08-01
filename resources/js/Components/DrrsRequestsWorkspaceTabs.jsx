import { usePage } from '@inertiajs/react';
import { ClipboardList, FileCheck2 } from 'lucide-react';
import SystemTabs from '@/Components/SystemTabs';

export default function DrrsRequestsWorkspaceTabs({ active }) {
    const roles = usePage().props.auth?.user?.roles ?? [];
    if (!roles.includes('DRRS') && !roles.includes('Super Admin')) {
        return null;
    }

    return (
        <div className="rounded-t-lg border border-b-0 border-slate-200 bg-white px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900">
            <p className="mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400">DRRS Requests Workspace</p>
            <SystemTabs
                active={active}
                ariaLabel="DRRS requests workspace"
                className="rounded-b-none border-b-0"
                items={[
                    { key: 'fni', label: 'FNI Requests', icon: ClipboardList, href: '/requests' },
                    { key: 'lgu', label: 'LGU Reports & Requests', icon: FileCheck2, href: '/dromic/lgu-reports' },
                ]}
            />
        </div>
    );
}
