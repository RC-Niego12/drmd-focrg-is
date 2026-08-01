const ROLE_ACRONYMS = {
    'Super Admin': 'SA',
    DRIMS: 'DRIMS',
    DRRS: 'DRRS',
    RROS: 'RROS',
    'DRMD AA': 'DRMD AA',
    'DRMD Chief': 'DRMD Chief',
    'DRMD Financial Analyst': 'DRMD FA',
    QRT: 'QRT',
    'Quick Response Team': 'QRT',
    LGU: 'LGU',
    'OCD Caraga': 'OCD',
};

const OFFICE_ACRONYM_PATTERNS = [
    { needle: /disaster response information management/i, acronym: 'DRIMS' },
    { needle: /disaster response and rehabilitation/i, acronym: 'DRRS' },
    { needle: /disaster response/i, acronym: 'DRRS' },
    { needle: /regional resource operations/i, acronym: 'RROS' },
    { needle: /resource operations/i, acronym: 'RROS' },
    { needle: /quick response/i, acronym: 'QRT' },
    { needle: /financial analyst/i, acronym: 'DRMD FA' },
    { needle: /administrative aide|drmd aa/i, acronym: 'DRMD AA' },
    { needle: /disaster response management/i, acronym: 'DRMD' },
];

export function roleAcronymLabel(roles = [], office = '', position = '') {
    const roleLabels = (Array.isArray(roles) ? roles : [])
        .map((role) => ROLE_ACRONYMS[role] || null)
        .filter(Boolean);

    if (roleLabels.length) {
        return [...new Set(roleLabels)].join(' / ');
    }

    const haystack = `${office || ''} ${position || ''}`.trim();
    if (!haystack) {
        return '';
    }

    const matched = OFFICE_ACRONYM_PATTERNS
        .filter((entry) => entry.needle.test(haystack))
        .map((entry) => entry.acronym);

    return [...new Set(matched)].join(' / ');
}

export default roleAcronymLabel;
