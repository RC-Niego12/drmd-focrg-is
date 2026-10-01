import { usePage } from '@inertiajs/react';
import { ClipboardList, FileCheck2, FileSpreadsheet } from 'lucide-react';
import SectionTabs from '@/Components/SectionTabs';

export default function DrrsRequestsWorkspaceTabs({ active, onChange, mode }) {
    const roles = usePage().props.auth?.user?.roles ?? [];
    const url = usePage().url || '';
    const isRros = mode === 'rros'
        || (roles.some((role) => ['RROS', 'RROS AA'].includes(role)) && !roles.includes('DRRS'))
        || url.startsWith('/rros/requests');
    if (!roles.includes('DRRS') && !roles.includes('Super Admin') && !isRros) {
        return null;
    }

    return (
        <SectionTabs
            label={isRros ? 'RIS/DR/STF Workspace' : 'DRRS Requests Workspace'}
            appearance="stack-top"
            value={active}
            onChange={onChange}
            ariaLabel={isRros ? 'RIS/DR/STF workspace' : 'DRRS requests workspace'}
            tabs={isRros ? [
                { id: 'fni', label: 'RIS/DR', icon: ClipboardList, href: '/rros/requests' },
                { id: 'stf', label: 'STF', icon: FileSpreadsheet, href: '/rros/requests?section=stf' },
            ] : [
                // Request letters first — FNI assessments unlock only after Validated — No Findings.
                { id: 'lgu', label: 'Request letters', icon: FileCheck2, href: '/dromic/lgu-reports?tab=requests' },
                { id: 'fni', label: 'FNI assessments', icon: ClipboardList, href: '/requests' },
            ]}
        />
    );
}
