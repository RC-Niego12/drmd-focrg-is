<?php

namespace App\Services;

use App\Models\User;

class AiRogerSystemMapService
{
    public function forUser(User $user): string
    {
        $sections = [
            'Developer: Roger L. Ongue, PDO II. If users ask who developed or maintains this local system, identify Roger L. Ongue, PDO II as the developer shown in DROMIS.',
            'System identity: Disaster Response Operations Management Integrated System (DROMIS), DSWD Field Office Caraga / DRMD.',
            'Navigation rule: Only recommend pages the user role can access. If a page requires another role, say which role normally handles it.',
            'Common UI: left sidebar contains main navigation. Header contains notifications, dark mode, logout, and profile button near the signed-in user name. AI Roger is the floating assistant at the bottom-right.',
        ];

        foreach ($this->pages() as $page) {
            if ($this->canSee($user, $page['permissions'])) {
                $sections[] = $this->pageLine($page);
            }
        }

        $sections[] = $this->roleWorkflowGuide($user);

        return collect($sections)->implode("\n");
    }

    private function pages(): array
    {
        return [
            [
                'path' => '/',
                'label' => 'Dashboard',
                'permissions' => ['view dashboards', 'submit drmd aa requests'],
                'purpose' => 'Main overview cards and role-based summaries.',
                'sections' => ['Overview Cards', 'Warehouse Summary', 'FFP Summary', 'RTEF Summary', 'Bottled Water Summary', 'Non-Food Items', 'Standby & Stockpile', 'Near Expiry Summary', 'Recent Transactions'],
            ],
            [
                'path' => '/warehouses',
                'label' => 'Warehouses',
                'permissions' => ['manage warehouses'],
                'purpose' => 'View, sync, create, update, search, and manage warehouse master records.',
                'sections' => ['Overview & Sync', 'Warehouse Dashboard', 'Filters & Search', 'Warehouse Master List'],
            ],
            [
                'path' => '/inventory',
                'label' => 'Inventory',
                'permissions' => ['manage inventory', 'view dashboards'],
                'purpose' => 'View stockpile, receive/release items, search inventory, and monitor warehouse stock balances.',
                'sections' => ['Inventory Overview', 'Filters & Search', 'Warehouse Stockpile'],
            ],
            [
                'path' => '/inventory/e-stock-card',
                'label' => 'E-Stock Card',
                'permissions' => ['manage inventory'],
                'purpose' => 'View transaction ledger and stock card movements.',
                'sections' => ['Stock Card Overview', 'Filters & Search', 'Transaction Ledger'],
            ],
            [
                'path' => '/near-expiry',
                'label' => 'Near Expiry',
                'permissions' => ['manage near expiry'],
                'purpose' => 'Monitor expiring stock, ageing, and distribution plans.',
                'sections' => ['Expiry & Ageing Overview', 'Filters & Tabs', 'Monitoring Table', 'Distribution Plans'],
            ],
            [
                'path' => '/fni-issuances',
                'label' => 'FNI Issuances',
                'permissions' => ['manage inventory', 'view dashboards'],
                'purpose' => 'Review released FNI items, trends, breakdowns, recipients, and issuance ledger.',
                'sections' => ['Issuance Overview', 'Monthly Trend', 'Category Breakdown', 'Warehouse Distribution', 'Purpose of Transaction', 'Issuance Ledger'],
            ],
            [
                'path' => '/requests',
                'label' => 'FNI Requests',
                'permissions' => ['encode requests', 'monitor requests', 'process requests'],
                'purpose' => 'Encode, assess, monitor, approve/decide, and prepare request documents such as assessment and response letters.',
                'sections' => ['Encode Request', 'Request List'],
            ],
            [
                'path' => '/drmd-aa/requests',
                'label' => 'DRMD AA Requests',
                'permissions' => ['submit drmd aa requests'],
                'purpose' => 'DRMD AA uploads/endorses FNI request documents to DRRS.',
                'sections' => ['DRMD AA Transaction Registry', 'Endorse FNI Request'],
            ],
            [
                'path' => '/drmd-aa/proposals',
                'label' => 'DRMD AA Proposals',
                'permissions' => ['submit drmd aa requests'],
                'purpose' => 'DRMD AA uploads/endorses FFT/W or NFFT/W proposals to DRRS.',
                'sections' => ['Proposal Registry', 'Endorse Proposal'],
            ],
            [
                'path' => '/lgu/dromic-sitrep',
                'label' => 'DROMIC / SitRep',
                'permissions' => ['submit lgu dromic requests'],
                'purpose' => 'City/municipal LGUs submit DROMIC reports; relief augmentation request is optional. PLGUs only monitor city/municipal reports in their province.',
                'sections' => ['PLGU Monitoring View or LGU Disaster Reporting', 'Submitted DROMIC Reports', 'PDF Output', 'AI Generate/Polish Narrative'],
            ],
            [
                'path' => '/drmd-aa/lgu-intake',
                'label' => 'LGU Intake',
                'permissions' => ['route lgu dromic requests'],
                'purpose' => 'DRMD AA routes LGU relief requests to DRMD Chief, then routes Chief-directed requests to DRRS/concerned PDRC.',
                'sections' => ['LGU DROMIC / Relief Augmentation Intake', 'Route to DRMD Chief', 'Route to DRRS / PDRC'],
            ],
            [
                'path' => '/drmd-chief/lgu-intake',
                'label' => 'Chief Directives',
                'permissions' => ['route lgu dromic requests'],
                'purpose' => 'DRMD Chief reviews LGU requests, records directive, assigns section/employee, and returns to DRMD AA.',
                'sections' => ['LGU Request Directives', 'Directive / Processing Instruction', 'Assign Employee or Section'],
            ],
            [
                'path' => '/dispatches',
                'label' => 'Dispatch',
                'permissions' => ['manage dispatches'],
                'purpose' => 'Create and monitor dispatch plans for approved/released requests.',
                'sections' => ['Create Dispatch', 'Dispatch Records'],
            ],
            [
                'path' => '/libraries',
                'label' => 'Libraries',
                'permissions' => ['manage inventory', 'encode requests', 'manage users'],
                'purpose' => 'Manage operational references such as RROS, DRIMS, DRRS, and system configuration libraries.',
                'sections' => ['Libraries Overview', 'RROS References', 'DRIMS References', 'DRRS References', 'System Configuration'],
            ],
            [
                'path' => '/dromic',
                'label' => 'DROMIC',
                'permissions' => ['manage dromic reports'],
                'purpose' => 'DRIMS creates and monitors DROMIC reports for eligible approved/released requests.',
                'sections' => ['Create DROMIC / Situational Report', 'DROMIC / SitRep Reports'],
            ],
            [
                'path' => '/access-management',
                'label' => 'User Access',
                'permissions' => ['manage users'],
                'purpose' => 'Super Admin manages DRMD users, LGUs, outside/future users, access decisions, archive/restore/delete, and Super Admin assignment.',
                'sections' => ['Access Summary', 'DRMD Users', 'LGUs', 'Outside DRMD / Future Users', 'Archived Users', 'Assign Super Admin'],
            ],
            [
                'path' => '/psgc-addresses',
                'label' => 'PSGC Addresses',
                'permissions' => ['manage users', 'manage psgc addresses'],
                'purpose' => 'Manage local PSGC references, barangays, districts, and city/municipality assignments.',
                'sections' => ['Summary Cards', 'Local PSGC Address Reference', 'PSGC Barangay Browser', 'Province District Options', 'Districts'],
            ],
            [
                'path' => '/population',
                'label' => 'Population',
                'permissions' => ['manage users', 'manage population'],
                'purpose' => 'Manage barangay population records and view population maps/summaries.',
                'sections' => ['Summary Cards', 'Population Management', 'Interactive Map', 'Population by Province', 'Population Records'],
            ],
            [
                'path' => '/standby-funds',
                'label' => 'Standby Funds',
                'permissions' => ['manage standby funds'],
                'purpose' => 'DRMD Financial Analyst or Super Admin updates and syncs standby fund values.',
                'sections' => ['Current Standby Funds', 'WIT Sync Source', 'Update Standby Funds'],
            ],
            [
                'path' => '/audit-trail',
                'label' => 'Audit Trail',
                'permissions' => ['view audit logs'],
                'purpose' => 'View system activity logs and audit events.',
                'sections' => ['Activity Summary', 'Filters & Search', 'System Activity Log'],
            ],
        ];
    }

    private function pageLine(array $page): string
    {
        return "Page: {$page['label']} ({$page['path']}) — {$page['purpose']} Sections/buttons: ".implode(', ', $page['sections']).'.';
    }

    private function canSee(User $user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission): bool => $user->can($permission));
    }

    private function roleWorkflowGuide(User $user): string
    {
        $parts = [];

        if ($user->can('submit lgu dromic requests')) {
            $parts[] = 'LGU guide: City/municipal LGUs go to /lgu/dromic-sitrep to submit DROMIC / Situational Reports. Use the checkbox "Include a Request for Relief Augmentation" only if requesting relief. PLGU accounts use the same menu only to monitor reports; they do not create separate reports.';
        }

        if ($user->can('route lgu dromic requests')) {
            $parts[] = 'LGU routing guide: DRMD AA uses /drmd-aa/lgu-intake to route new relief requests to Chief, then to DRRS/PDRC after directive. DRMD Chief uses /drmd-chief/lgu-intake to record directive and assignment.';
        }

        if ($user->can('submit drmd aa requests')) {
            $parts[] = 'DRMD AA document guide: Use /drmd-aa/requests for FNI requests and /drmd-aa/proposals for proposals. These upload source documents and endorse to DRRS.';
        }

        if ($user->can('encode requests') || $user->can('monitor requests') || $user->can('process requests')) {
            $parts[] = 'DRRS/RROS request guide: Use /requests to encode or assess requests, update assessment status, generate assessment PDF, prepare response letter, and record decisions according to permissions.';
        }

        if ($user->can('manage inventory')) {
            $parts[] = 'RROS inventory guide: Use /inventory for stockpile receipt/release and balances, /inventory/e-stock-card for ledger, /near-expiry for expiring stock, /fni-issuances for release history, /dispatches for dispatch plans.';
        }

        if ($user->can('manage users')) {
            $parts[] = 'Super Admin guide: Use /access-management for access approval, role assignment, LGU/DRMD/outside user separation, soft delete/archive, restore, hard delete, and assigning Super Admin from SSO employees.';
        }

        return 'Role workflow guide: '.($parts ? implode(' ', $parts) : 'No special workflow guide for this role.');
    }
}
