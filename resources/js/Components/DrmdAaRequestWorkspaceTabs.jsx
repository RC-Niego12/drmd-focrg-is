import { usePage } from '@inertiajs/react';
import { FileText, Files } from 'lucide-react';
import SectionTabs from '@/Components/SectionTabs';

export default function DrmdAaRequestWorkspaceTabs({ active }) {
    const roles = usePage().props.auth?.user?.roles ?? [];

    if (!roles.includes('DRMD AA')) {
        return null;
    }

    return (
        <SectionTabs
            label="DRMD AA Request Workspace"
            appearance="stack-top"
            value={active}
            ariaLabel="DRMD AA request workspace"
            tabs={[
                { id: 'requests', label: 'FNI Requests', icon: FileText, href: '/drmd-aa/requests' },
                { id: 'proposals', label: 'Proposals', icon: Files, href: '/drmd-aa/proposals' },
            ]}
        />
    );
}
