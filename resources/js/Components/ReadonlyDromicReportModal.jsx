import { FileCheck2, LockKeyhole, X } from 'lucide-react';

const labels = {
    area_rows: 'Status of Affected Population',
    evacuation_center_rows: 'Inside Evacuation Centers',
    related_incident_rows: 'Related Incidents',
    casualty_rows: 'Casualties',
    infrastructure_damage_rows: 'Damage to Infrastructure',
    agriculture_damage_rows: 'Damage and Losses to Agriculture',
    assistance_rows: 'Cost of Assistance Provided',
    class_suspension_rows: 'Class Suspension',
    work_suspension_rows: 'Work Suspension',
    road_bridge_rows: 'Status of Roads and Bridges',
    power_lifeline_rows: 'Status of Power Supply',
    water_lifeline_rows: 'Status of Water Supply',
    communication_lifeline_rows: 'Status of Communication Lines',
    seaport_rows: 'Status of Seaports',
    airport_rows: 'Status of Airports',
    land_transport_terminal_rows: 'Status of Land Transportation Terminals',
    stranded_transport_rows: 'Stranded Passengers and Transport',
    calamity_declaration_rows: 'Declaration of State of Calamity',
    preemptive_evacuation_rows: 'Pre-emptive Evacuation',
    cluster_gap_rows: 'Gaps, Challenges and Actions Undertaken',
    response_action_rows: 'Response Actions and Interventions',
    requested_fni_items: 'FNI needs for this incident',
    official_advisory_rows: 'Advisory Screenshots and Extracted Information',
    photo_documentation_rows: 'Photo Documentation',
};

const fieldLabels = {
    reporting_lgu: 'Reporting LGU (Name of LGU, Province)',
    created_by: 'Created By',
    report_submitted_at: 'Date/Time of Report',
    has_relief_request: 'Has Relief Request?',
    incident_name: 'Incident Name',
    occurrence_started_at: 'Date/Time of Occurrence',
    information_received_at: 'Date/Time Information Is Received/Gathered by LGU',
    incident_ended_at: 'Date/Time the Incident Ended',
};

const hiddenColumns = new Set([
    'psgc_code', 'barangay_code', 'barangay_address_code', 'barangay_origin_code',
    'barangay_address_city_code', 'barangay_address_province_code', 'data_url',
    'image_data_url', 'screenshot_data_url', 'disaggregation', 'disaggregation_completed',
    'client_id', 'id',
]);

const humanize = (value) => String(value || '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

const hasValue = (value) => {
    if (Array.isArray(value)) return value.length > 0;
    if (value && typeof value === 'object') return Object.keys(value).length > 0;
    return value === 0 || value === false || String(value || '').trim() !== '';
};

const formatNumber = (value) => new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 }).format(Number(value || 0));

const formatReadableDate = (value, includeTime = true) => {
    if (!hasValue(value)) return '-';
    const parsed = new Date(String(value));
    if (Number.isNaN(parsed.getTime())) return String(value);

    return new Intl.DateTimeFormat('en-PH', includeTime
        ? { dateStyle: 'long', timeStyle: 'short' }
        : { dateStyle: 'long' }).format(parsed);
};

const displayValue = (value, key = '') => {
    if (value === true) return 'Yes';
    if (value === false) return 'No';
    if (value === null || value === undefined || value === '') return '-';
    if (/(?:_at|_date|date_|issued_at|occurrence)/i.test(key) && /^\d{4}-\d{2}-\d{2}/.test(String(value))) {
        return formatReadableDate(value, String(value).includes('T') || String(value).includes(':'));
    }
    if (Array.isArray(value)) return value.map((item) => displayValue(item)).join(' · ') || '-';
    if (typeof value === 'object') {
        return Object.entries(value)
            .filter(([, item]) => hasValue(item))
            .map(([itemKey, item]) => `${humanize(itemKey)}: ${displayValue(item, itemKey)}`)
            .join('; ') || '-';
    }
    return String(value);
};

function ReadonlyFields({ entries }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {entries.map(([key, value]) => (
                <div key={key} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                    <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">{fieldLabels[key] || humanize(key)}</p>
                    <p className="mt-1 whitespace-pre-line text-sm font-semibold text-slate-900 dark:text-zinc-100">{displayValue(value, key)}</p>
                </div>
            ))}
        </div>
    );
}

function ReadonlyTable({ rows = [], columns: preferredColumns, totalColumns: preferredTotalColumns }) {
    const validRows = rows.filter((row) => row && typeof row === 'object' && !Array.isArray(row));
    const columns = (preferredColumns || [...new Set(validRows.flatMap((row) => Object.keys(row)))])
        .filter((column) => !hiddenColumns.has(column))
        .filter((column) => validRows.some((row) => hasValue(row[column])));
    const numericValue = (value) => {
        if (typeof value === 'boolean' || !hasValue(value)) return null;
        const normalized = String(value).replaceAll(',', '').trim();
        return normalized !== '' && Number.isFinite(Number(normalized)) ? Number(normalized) : null;
    };
    const inferredTotalColumns = columns.filter((column) => {
        const populated = validRows.map((row) => row[column]).filter(hasValue);
        return populated.length > 0 && populated.every((value) => numericValue(value) !== null);
    });
    const totalColumns = new Set((preferredTotalColumns || inferredTotalColumns).filter((column) => columns.includes(column)));
    const firstDescriptiveColumn = columns.find((column) => !totalColumns.has(column));

    if (!validRows.length || !columns.length) {
        return <p className="rounded-md border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">No encoded rows.</p>;
    }

    return (
        <div className="overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="min-w-full border-collapse text-xs">
                <thead className="bg-blue-100 text-slate-900">
                    <tr>
                        <th className="w-12 min-w-12 max-w-12 border border-slate-300 px-1 py-2 text-center font-black uppercase">No.</th>
                        {columns.map((column) => <th key={column} className="min-w-[130px] border border-slate-300 px-3 py-2 text-left font-black uppercase">{humanize(column)}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {validRows.map((row, index) => (
                        <tr key={index} className="bg-white dark:bg-zinc-950">
                            <td className="w-12 min-w-12 max-w-12 border border-slate-200 px-1 py-2 text-center font-black dark:border-zinc-800">{index + 1}</td>
                            {columns.map((column) => <td key={column} className="max-w-[320px] whitespace-pre-line border border-slate-200 px-3 py-2 align-top dark:border-zinc-800">{displayValue(row[column], column)}</td>)}
                        </tr>
                    ))}
                </tbody>
                <tfoot className="bg-cyan-100 font-black text-slate-950">
                    <tr>
                        <td className="w-12 max-w-12 border border-slate-400 px-1 py-2 text-center uppercase">Total</td>
                        {columns.map((column) => (
                            <td key={column} className="border border-slate-400 px-3 py-2 text-center">
                                {totalColumns.has(column)
                                    ? formatNumber(validRows.reduce((sum, row) => sum + (numericValue(row[column]) || 0), 0))
                                    : column === firstDescriptiveColumn
                                        ? `${formatNumber(validRows.length)} record(s)`
                                        : '-'}
                            </td>
                        ))}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

const ageSexDefinitions = [
    ['infant', 'Infant', '0-6 months old'],
    ['toddler', 'Toddler', '7 months-2 years old'],
    ['pre_school', 'Pre-School', '3-5 years old'],
    ['school_age', 'School Age', '6-12 years old'],
    ['teenage', 'Teenage', '13-17 years old'],
    ['adult', 'Adult', '18-59 years old'],
    ['elderly', 'Elderly', '60 years old and above'],
];

const sectoralDefinitions = [
    ['pwds', 'Persons with Disabilities (PWDs)', true],
    ['child_headed_family', 'Child-Headed Family', true],
    ['single_headed_family', 'Single-Headed Family', true],
    ['solo_parent', 'Solo Parent', true],
    ['pregnant_women', 'Pregnant Women', false],
    ['lactating_mothers', 'Lactating Mothers', false],
    ['four_ps', '4Ps Beneficiaries (4Ps)', true],
    ['indigenous_people', 'Indigenous People (IP)', true],
];

const countValue = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const numeric = Number(String(value).replaceAll(',', '').trim());
    return Number.isFinite(numeric) ? numeric : null;
};

const displayCount = (value) => {
    const numeric = countValue(value);
    return numeric === null ? '-' : formatNumber(numeric);
};

function DisaggregationMatrixTable({
    title,
    groupLabel,
    detailLabel,
    rows,
    values = {},
    showDetail = true,
}) {
    const bodyRows = rows.map(([key, label, detailOrHasMale, maybeHasMale], index) => {
        const hasMale = typeof detailOrHasMale === 'boolean'
            ? detailOrHasMale
            : (typeof maybeHasMale === 'boolean' ? maybeHasMale : true);
        const detail = typeof detailOrHasMale === 'string' ? detailOrHasMale : '';
        const entry = values[key] || {};
        const maleCum = hasMale ? entry.male_cum : null;
        const maleNow = hasMale ? entry.male_now : null;
        const femaleCum = entry.female_cum;
        const femaleNow = entry.female_now;
        const totalCum = (countValue(maleCum) || 0) + (countValue(femaleCum) || 0);
        const totalNow = (countValue(maleNow) || 0) + (countValue(femaleNow) || 0);

        return {
            key,
            index: index + 1,
            label,
            detail,
            hasMale,
            maleCum,
            maleNow,
            femaleCum,
            femaleNow,
            totalCum,
            totalNow,
        };
    });

    const totals = {
        maleCum: bodyRows.reduce((sum, row) => sum + (countValue(row.maleCum) || 0), 0),
        maleNow: bodyRows.reduce((sum, row) => sum + (countValue(row.maleNow) || 0), 0),
        femaleCum: bodyRows.reduce((sum, row) => sum + (countValue(row.femaleCum) || 0), 0),
        femaleNow: bodyRows.reduce((sum, row) => sum + (countValue(row.femaleNow) || 0), 0),
    };
    totals.totalCum = totals.maleCum + totals.femaleCum;
    totals.totalNow = totals.maleNow + totals.femaleNow;

    return (
        <div className="overflow-x-auto rounded-md border border-slate-200 dark:border-zinc-800">
            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
                {title}
            </div>
            <table className="min-w-full border-collapse text-xs">
                <thead className="bg-blue-100 text-slate-900">
                    <tr>
                        <th rowSpan={2} className="w-12 border border-slate-300 px-1 py-2 text-center font-black uppercase">No.</th>
                        <th rowSpan={2} className={`border border-slate-300 px-3 py-2 text-left font-black uppercase ${showDetail ? '' : ''}`}>{groupLabel}</th>
                        {showDetail && <th rowSpan={2} className="border border-slate-300 px-3 py-2 text-left font-black uppercase">{detailLabel}</th>}
                        <th colSpan={2} className="border border-slate-300 px-3 py-2 text-center font-black uppercase">Male</th>
                        <th colSpan={2} className="border border-slate-300 px-3 py-2 text-center font-black uppercase">Female</th>
                        <th colSpan={2} className="border border-slate-300 px-3 py-2 text-center font-black uppercase">Total</th>
                    </tr>
                    <tr>
                        {['CUM', 'NOW', 'CUM', 'NOW', 'CUM', 'NOW'].map((label, index) => (
                            <th key={`${label}-${index}`} className="border border-slate-300 px-2 py-1 text-center font-black uppercase">{label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {bodyRows.map((row) => (
                        <tr key={row.key} className="bg-white dark:bg-zinc-950">
                            <td className="border border-slate-200 px-1 py-2 text-center font-black dark:border-zinc-800">{row.index}</td>
                            <td className="border border-slate-200 px-3 py-2 font-semibold dark:border-zinc-800" colSpan={showDetail ? 1 : 1}>{row.label}</td>
                            {showDetail && <td className="border border-slate-200 px-3 py-2 text-slate-600 dark:border-zinc-800 dark:text-zinc-300">{row.detail || '-'}</td>}
                            <td className="border border-slate-200 px-2 py-2 text-center dark:border-zinc-800">{row.hasMale ? displayCount(row.maleCum) : <span className="text-slate-400">N/A</span>}</td>
                            <td className="border border-slate-200 px-2 py-2 text-center dark:border-zinc-800">{row.hasMale ? displayCount(row.maleNow) : <span className="text-slate-400">N/A</span>}</td>
                            <td className="border border-slate-200 px-2 py-2 text-center dark:border-zinc-800">{displayCount(row.femaleCum)}</td>
                            <td className="border border-slate-200 px-2 py-2 text-center dark:border-zinc-800">{displayCount(row.femaleNow)}</td>
                            <td className="border border-slate-200 px-2 py-2 text-center font-semibold dark:border-zinc-800">{formatNumber(row.totalCum)}</td>
                            <td className="border border-slate-200 px-2 py-2 text-center font-semibold dark:border-zinc-800">{formatNumber(row.totalNow)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="bg-cyan-100 font-black text-slate-950">
                    <tr>
                        <td className="border border-slate-400 px-1 py-2 text-center uppercase">Total</td>
                        <td className="border border-slate-400 px-3 py-2" colSpan={showDetail ? 2 : 1}>All groups</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.maleCum)}</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.maleNow)}</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.femaleCum)}</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.femaleNow)}</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.totalCum)}</td>
                        <td className="border border-slate-400 px-2 py-2 text-center">{formatNumber(totals.totalNow)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function EvacuationCenterDisaggregation({ rows = [] }) {
    const ecRows = rows.filter((row) => row && typeof row === 'object');

    if (!ecRows.length) {
        return <p className="rounded-md border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">No encoded rows.</p>;
    }

    return (
        <div className="space-y-4">
            <ReadonlyTable
                rows={ecRows.map((row) => ({
                    ...row,
                    disaggregated_data: row.disaggregation_completed ? 'Completed' : '-',
                }))}
                columns={['barangay_address', 'evacuation_center', 'families_cum', 'families_now', 'persons_cum', 'persons_now', 'barangay_origin', 'classrooms_used', 'disaggregated_data']}
                totalColumns={['families_cum', 'families_now', 'persons_cum', 'persons_now', 'classrooms_used']}
            />

            {ecRows.map((row, index) => {
                const centerName = row.evacuation_center || `Evacuation Center ${index + 1}`;
                const barangay = row.barangay_address || row.barangay_origin || 'Unspecified barangay';
                const disaggregation = row.disaggregation || {};

                return (
                    <div key={row.client_id || row.id || `${centerName}-${index}`} className="space-y-3 rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900 dark:bg-emerald-950/20">
                        <div>
                            <p className="text-[10px] font-black uppercase tracking-wide text-emerald-700">Evacuation Center {index + 1}</p>
                            <h4 className="text-sm font-black text-slate-900 dark:text-zinc-100">{centerName}</h4>
                            <p className="text-xs text-slate-600 dark:text-zinc-400">
                                {barangay}
                                {' · '}
                                Persons CUM {displayCount(row.persons_cum)} / NOW {displayCount(row.persons_now)}
                                {row.disaggregation_completed ? ' · Disaggregation completed' : ' · Disaggregation pending'}
                            </p>
                        </div>
                        <DisaggregationMatrixTable
                            title="Sex and Age Disaggregation"
                            groupLabel="Sex and Age Disaggregation"
                            detailLabel="Age Range"
                            rows={ageSexDefinitions}
                            values={disaggregation.age_sex || {}}
                            showDetail
                        />
                        <DisaggregationMatrixTable
                            title="Sectoral Disaggregation"
                            groupLabel="Sectoral Group"
                            detailLabel=""
                            rows={sectoralDefinitions.map(([key, label, hasMale]) => [key, label, '', hasMale])}
                            values={disaggregation.sectoral || {}}
                            showDetail={false}
                        />
                    </div>
                );
            })}
        </div>
    );
}

function Section({ title, children, na = false }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-base font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-200">{title}</h3>
                {na && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">Marked N/A</span>}
            </div>
            {na ? <p className="text-sm text-slate-500">This section was marked not applicable by the reporting LGU.</p> : children}
        </section>
    );
}

export function DromicEncodedReportBody({ report, className = '' }) {
    if (!report) return null;

    const payload = report.lgu_dromic_payload || {};
    const notApplicable = new Set(payload.not_applicable_sections || []);
    const areaRows = payload.area_rows || [];
    const outsideRows = areaRows.filter((row) => row.outside_ec_included);
    const damagedRows = areaRows.filter((row) => row.damaged_houses_included);
    const reportingLguName = payload.requesting_lgu || report.requesting_agency || report.municipality || '-';
    const reportingProvince = payload.province || report.province || report.incident?.province || '';
    const reportingLgu = reportingProvince && !String(reportingLguName).toLowerCase().includes(String(reportingProvince).toLowerCase())
        ? `${reportingLguName}, ${reportingProvince}`
        : reportingLguName;
    const incidentType = payload.incident_type === 'Others' ? payload.incident_type_other : payload.incident_type;
    const incidentDetails = payload.incident_specific_details;
    const incidentName = [incidentType || payload.incident_name || report.incident?.name, incidentDetails ? `(${incidentDetails})` : '']
        .filter(Boolean)
        .join(' ');
    const scalarEntries = [
        ['reporting_lgu', reportingLgu],
        ['created_by', payload.requester_name || report.requester || report.last_reporter],
        ['report_submitted_at', report.lgu_submitted_to_dswd_at || payload.submitted_to_dswd_at],
        ['has_relief_request', Boolean(payload.has_relief_request)],
        ['incident_name', incidentName],
        ['occurrence_started_at', payload.occurrence_started_at || payload.incident_date || report.incident?.incident_date],
        ['incident_status', payload.incident_status],
        ['information_received_at', payload.information_received_at],
        ...(payload.incident_status === 'Ended' || hasValue(payload.incident_ended_at) ? [['incident_ended_at', payload.incident_ended_at]] : []),
        ['affected_families', payload.affected_families ?? report.affected_families],
        ['affected_persons', payload.affected_persons ?? report.affected_persons],
        ['affected_areas', payload.affected_areas || payload.affected_barangays],
    ].filter(([, value]) => hasValue(value));
    const renderedArrays = new Set([
        'area_rows', 'evacuation_center_rows', 'assistance_rows', 'response_action_rows',
        'requested_fni_items', 'official_advisory_rows',
    ]);
    const additionalTables = Object.entries(payload)
        .filter(([key, value]) => key.endsWith('_rows') && Array.isArray(value) && !renderedArrays.has(key))
        .filter(([, value]) => value.length > 0);

    return (
        <div className={`space-y-4 bg-white p-4 text-slate-950 ${className}`.trim()}>
            <header className="border-b border-slate-200 pb-3">
                <p className="text-[10px] font-black uppercase tracking-widest text-emerald-700">DROMIC / Situational Report</p>
                <h2 className="mt-1 text-lg font-black">{report.reference_number || 'Draft DROMIC / Situational Report'}</h2>
                <p className="mt-1 text-xs text-slate-500">{reportingLgu} · Report No. {report.lgu_dromic_report_number || 1}</p>
            </header>
            <Section title="Incident Information"><ReadonlyFields entries={scalarEntries} /></Section>
            <Section title="Status of Affected Population">
                <ReadonlyTable rows={areaRows} columns={['area', 'psa_2024', 'affected_families', 'affected_persons']} totalColumns={['psa_2024', 'affected_families', 'affected_persons']} />
            </Section>
            <Section title="Status of Displaced Population — Inside Evacuation Centers" na={notApplicable.has('inside_ec')}>
                <EvacuationCenterDisaggregation rows={payload.evacuation_center_rows || []} />
            </Section>
            <Section title="Status of Displaced Population — Outside Evacuation Centers" na={notApplicable.has('outside_ec')}>
                <ReadonlyTable rows={outsideRows} columns={['area', 'outside_ec_families_cum', 'outside_ec_families_now', 'outside_ec_persons_cum', 'outside_ec_persons_now']} totalColumns={['outside_ec_families_cum', 'outside_ec_families_now', 'outside_ec_persons_cum', 'outside_ec_persons_now']} />
            </Section>
            <Section title="Status of Damaged Houses" na={notApplicable.has('damaged_houses')}>
                <ReadonlyTable rows={damagedRows} columns={['area', 'damaged_houses_totally', 'damaged_houses_partially', 'damaged_houses_estimated_cost', 'affected_families']} totalColumns={['damaged_houses_totally', 'damaged_houses_partially', 'damaged_houses_estimated_cost', 'affected_families']} />
            </Section>
            <Section title="Cost of Assistance Provided" na={notApplicable.has('assistance')}>
                <ReadonlyTable
                    rows={(payload.assistance_rows || []).map((row) => ({ ...row, total_cost: row.total_cost ?? (Number(row.quantity || 0) * Number(row.cost_per_unit || 0)) }))}
                    columns={['barangay', 'source', 'source_details', 'quantity', 'unit', 'item_type', 'particular', 'cost_per_unit', 'total_cost', 'families_served']}
                    totalColumns={['quantity', 'total_cost', 'families_served']}
                />
            </Section>
            {additionalTables.map(([key, rows]) => (
                <Section key={key} title={labels[key] || humanize(key)} na={notApplicable.has(key.replace(/_rows$/, ''))}>
                    <ReadonlyTable rows={rows} />
                </Section>
            ))}
            <Section title="Response Actions and Interventions">
                <ReadonlyTable rows={payload.response_action_rows || []} columns={['acted_by_office', 'acted_by_office_other', 'action_intervention']} />
            </Section>
            <Section title={payload.has_relief_request ? 'Request for Relief Augmentation' : 'FNI needs for this incident'}>
                <ReadonlyTable rows={payload.requested_fni_items || []} columns={['item_name', 'requested_quantity', 'unit_of_measure']} totalColumns={['requested_quantity']} />
            </Section>
            <Section title="PAGASA / PHIVOLCS Advisory Screenshots" na={notApplicable.has('advisory_screenshots')}>
                <ReadonlyTable rows={payload.official_advisory_rows || []} columns={['agency', 'advisory_title', 'issued_at', 'extracted_text', 'screenshot_attached']} />
            </Section>
            <Section title="Situation Overview">
                <div className="whitespace-pre-line rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold leading-7 text-slate-900">{payload.narrative || '-'}</div>
            </Section>
        </div>
    );
}

export default function ReadonlyDromicReportModal({ report, onClose, embedded = false }) {
    if (!report) return null;

    const payload = report.lgu_dromic_payload || {};
    const reportingLguName = payload.requesting_lgu || report.requesting_agency || report.municipality || '-';
    const reportingProvince = payload.province || report.province || report.incident?.province || '';
    const reportingLgu = reportingProvince && !String(reportingLguName).toLowerCase().includes(String(reportingProvince).toLowerCase())
        ? `${reportingLguName}, ${reportingProvince}`
        : reportingLguName;

    return (
        <div
            className={embedded ? 'flex h-full min-h-0 w-full overflow-hidden bg-slate-100 dark:bg-zinc-900' : 'fixed inset-0 z-[120] flex items-start justify-center overflow-hidden bg-slate-950/70 p-2 backdrop-blur-sm sm:p-4'}
            {...(embedded ? {} : { role: 'dialog', 'aria-modal': 'true', 'aria-label': report.reference_number || 'DROMIC / Situational Report' })}
        >
            <div className={embedded ? 'flex h-full min-h-0 w-full flex-col overflow-hidden bg-slate-100 dark:bg-zinc-900' : 'flex h-full w-full max-w-[1500px] flex-col overflow-hidden rounded-xl bg-slate-100 shadow-2xl dark:bg-zinc-900'}>
                <header className="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <div>
                        <p className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-emerald-700"><LockKeyhole className="h-4 w-4" /> Read-only encoded report</p>
                        <h2 className="mt-1 text-xl font-black">{report.reference_number || 'DROMIC / Situational Report'}</h2>
                        <p className="mt-1 text-sm text-slate-500">{reportingLgu} · Report No. {report.lgu_dromic_report_number || 1}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black uppercase text-emerald-700"><FileCheck2 className="h-4 w-4" /> View only</span>
                        {!embedded && <button type="button" onClick={onClose} className="rounded-md border border-slate-200 p-2 hover:bg-slate-100 dark:border-zinc-700 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>}
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
                    <DromicEncodedReportBody report={report} className="rounded-lg border border-slate-200 dark:border-zinc-800" />
                </div>
            </div>
        </div>
    );
}
