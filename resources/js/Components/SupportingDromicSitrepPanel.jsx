import { useState } from 'react';
import { FileCheck2, UploadCloud } from 'lucide-react';
import SectionTabs from '@/Components/SectionTabs';
import SignedPdfPreview from '@/Components/SignedPdfPreview';

/**
 * Supporting DROMIC / SitRep viewer used by Request Document Preview and DRRS validation.
 * Incident + SitRep number are selected via dropdowns (defaults to the latest report).
 */
export default function SupportingDromicSitrepPanel({
    reports = [],
    fallbackReport = null,
    showCopyTabs = true,
    defaultCopyTab = 'advance',
    hideSelectors = false,
    className = '',
}) {
    const fallbackIsStandaloneLump = Boolean(fallbackReport?.lgu_dromic_payload?.standalone_relief_request)
        || (Array.isArray(fallbackReport?.lgu_dromic_payload?.linked_incident_series_keys)
            && fallbackReport.lgu_dromic_payload.linked_incident_series_keys.length > 0)
        || (typeof fallbackReport?.reference_number === 'string' && fallbackReport.reference_number.includes('LGU-RELIEF-'));

    const linkedReports = Array.isArray(reports) && reports.length > 0
        ? reports
        : (fallbackReport?.id && !fallbackIsStandaloneLump
            ? [{
                id: fallbackReport.id,
                reference_number: fallbackReport.reference_number,
                report_number: fallbackReport.report_number || fallbackReport.lgu_dromic_report_number || 1,
                series_key: fallbackReport.series_key || fallbackReport.lgu_dromic_series_key || `report-${fallbackReport.id}`,
                incident_name: fallbackReport.incident_name || 'Linked incident',
                incident_code: fallbackReport.incident_code,
                area_label: fallbackReport.area_label,
                affected_barangays: fallbackReport.affected_barangays || [],
                report_title: fallbackReport.report_title,
                tab_label: fallbackReport.tab_label,
                lgu_signed_report_path: fallbackReport.lgu_signed_report_path,
                lgu_signed_report_name: fallbackReport.lgu_signed_report_name,
                has_signed_copy: Boolean(fallbackReport.has_signed_copy || fallbackReport.lgu_signed_report_path),
                has_advance_copy: true,
            }]
            : []);

    const incidentOptions = (() => {
        const seen = new Map();
        linkedReports.forEach((row) => {
            const key = row.series_key || `report-${row.id}`;
            if (seen.has(key)) return;
            seen.set(key, {
                series_key: key,
                label: [
                    row.area_label || (Array.isArray(row.affected_barangays) ? row.affected_barangays.join(', ') : null),
                    row.incident_name,
                    row.incident_code,
                ].filter(Boolean).join(' · ') || 'Linked incident',
            });
        });
        return [...seen.values()];
    })();

    const latestReport = linkedReports
        .slice()
        .sort((a, b) => (Number(b.report_number) || 0) - (Number(a.report_number) || 0) || Number(b.id) - Number(a.id))[0] || null;

    const preferredDefaultCopy = defaultCopyTab === 'signed' && latestReport?.has_signed_copy
        ? 'signed'
        : 'advance';

    const [seriesKey, setSeriesKey] = useState(latestReport?.series_key || incidentOptions[0]?.series_key || '');
    const [reportId, setReportId] = useState(latestReport?.id || null);
    const [copyTab, setCopyTab] = useState(preferredDefaultCopy);

    const reportsForIncident = linkedReports
        .filter((row) => (row.series_key || `report-${row.id}`) === seriesKey)
        .slice()
        .sort((a, b) => (Number(a.report_number) || 0) - (Number(b.report_number) || 0) || Number(a.id) - Number(b.id));

    const selected = reportsForIncident.find((row) => Number(row.id) === Number(reportId))
        || reportsForIncident[reportsForIncident.length - 1]
        || null;

    const hasSigned = Boolean(selected?.has_signed_copy || selected?.lgu_signed_report_path);
    const showSigned = showCopyTabs && copyTab === 'signed' && hasSigned;

    const selectIncident = (nextSeriesKey) => {
        const list = linkedReports
            .filter((row) => (row.series_key || `report-${row.id}`) === nextSeriesKey)
            .slice()
            .sort((a, b) => (Number(a.report_number) || 0) - (Number(b.report_number) || 0) || Number(a.id) - Number(b.id));
        const latest = list[list.length - 1] || null;
        setSeriesKey(nextSeriesKey);
        setReportId(latest?.id || null);
        setCopyTab(defaultCopyTab === 'signed' && latest?.has_signed_copy ? 'signed' : 'advance');
    };

    const selectReport = (nextId) => {
        const next = linkedReports.find((row) => Number(row.id) === Number(nextId));
        if (!next) return;
        setSeriesKey(next.series_key || `report-${next.id}`);
        setReportId(next.id);
        setCopyTab(defaultCopyTab === 'signed' && next.has_signed_copy ? 'signed' : 'advance');
    };

    if (!selected) {
        return (
            <div className={`flex min-h-0 flex-1 items-center justify-center bg-slate-100 p-6 text-center text-sm font-semibold text-slate-600 ${className}`}>
                No supporting LGU DROMIC / SitRep is available for preview.
            </div>
        );
    }

    return (
        <div className={`flex min-h-0 flex-1 flex-col bg-slate-100 ${className}`}>
            {!hideSelectors && (
                <div className="shrink-0 space-y-3 border-b bg-white p-3 dark:bg-zinc-900">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {incidentOptions.length > 1 && (
                            <label className="block text-[11px] font-black uppercase tracking-wide text-slate-500">
                                Incident / affected area
                                <select
                                    value={seriesKey}
                                    onChange={(event) => selectIncident(event.target.value)}
                                    className="mt-1 h-10 w-full rounded-md border-slate-300 text-sm font-bold"
                                >
                                    {incidentOptions.map((incident) => (
                                        <option key={incident.series_key} value={incident.series_key}>{incident.label}</option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <label className={`block text-[11px] font-black uppercase tracking-wide text-slate-500 ${incidentOptions.length <= 1 ? 'sm:col-span-2' : ''}`}>
                            DROMIC / SitRep report
                            <select
                                value={selected.id}
                                onChange={(event) => selectReport(event.target.value)}
                                className="mt-1 h-10 w-full rounded-md border-slate-300 text-sm font-bold"
                            >
                                {reportsForIncident.map((row) => {
                                    const isLatest = Number(row.id) === Number(reportsForIncident[reportsForIncident.length - 1]?.id);
                                    const area = row.area_label || (Array.isArray(row.affected_barangays) ? row.affected_barangays.join(', ') : '');
                                    return (
                                        <option key={row.id} value={row.id}>
                                            {`Report No. ${row.report_number || 1}${area ? ` · ${area}` : ''}${row.reference_number ? ` · ${row.reference_number}` : ''}${isLatest ? ' (latest)' : ''}${row.has_signed_copy ? ' · signed' : ''}`}
                                        </option>
                                    );
                                })}
                            </select>
                        </label>
                    </div>
                    {selected.report_title && (
                        <p className="text-xs font-semibold text-slate-500">
                            {selected.report_title}
                            {selected.area_label ? ` · ${selected.area_label}` : ''}
                        </p>
                    )}
                    {showCopyTabs && hasSigned && (
                        <SectionTabs
                            label="Document copy"
                            appearance="plain"
                            value={copyTab}
                            onChange={setCopyTab}
                            ariaLabel="Document copy"
                            tabs={[
                                { id: 'advance', label: 'Advance Copy', icon: FileCheck2, title: 'View the advance copy generated from the encoded report data' },
                                { id: 'signed', label: 'Signed Copy', icon: UploadCloud, title: 'View the signed PDF submitted by the LGU' },
                            ]}
                        />
                    )}
                </div>
            )}
            {showSigned ? (
                <SignedPdfPreview
                    src={`/lgu/dromic-sitrep/${selected.id}/signed-copy/report`}
                    filename={selected.lgu_signed_report_name || selected.reference_number || 'Signed DROMIC report.pdf'}
                    title={`${selected.incident_name || 'Linked incident'} signed DROMIC report`}
                    iframeClassName="min-h-0 w-full flex-1 bg-slate-100"
                />
            ) : (
                <iframe
                    key={`advance-${selected.id}`}
                    title={`${selected.incident_name || 'Linked incident'} advance DROMIC report`}
                    src={`/lgu/dromic-sitrep/${selected.id}/pdf?inline=1`}
                    className="min-h-0 w-full flex-1 bg-slate-100"
                />
            )}
        </div>
    );
}
