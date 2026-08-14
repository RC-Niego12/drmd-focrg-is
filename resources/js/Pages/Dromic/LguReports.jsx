import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, CircleHelp, ClipboardCheck, ClipboardPaste, Clock3, Eye, FileCheck2, FilePlus2, History, ImagePlus, Images, ListChecks, Search, Send, Trash2, UploadCloud, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import ReadonlyDromicReportModal from '@/Components/ReadonlyDromicReportModal';
import SignedPdfPreview from '@/Components/SignedPdfPreview';
import DromicReportStatus, { CorrectedVersionMark, DromicAdvanceCopyMark, DromicSubmissionMark, DromicValidationMark, ReliefRequestMark, RequestSubmissionMark, RequestValidationMark, StatusLegend } from '@/Components/DromicReportStatus';
import { formatDate, formatDateTime } from '@/Utils/dateFormat';
import DrrsRequestsWorkspaceTabs from '@/Components/DrrsRequestsWorkspaceTabs';
import SectionTabs from '@/Components/SectionTabs';
import ReliefAssessmentGateBanner from '@/Components/ReliefAssessmentGateBanner';

export default function LguReports({ reports, incidentGroups = [], activeTab = 'incidents', filters = {}, reportDashboard = {}, requestDashboard = {}, canReviewDromic = false, canReviewRelief = false, reliefAssessmentGate = null }) {
    const rows = reports?.data ?? [];
    const [reviewReport, setReviewReport] = useState(null);
    const [historyPreview, setHistoryPreview] = useState(null);
    const [search, setSearch] = useState(filters.search || '');
    const summaryFiltersActive = Boolean(filters.search || filters.validation || filters.status || filters.classification || filters.series_key);
    const openTab = (tab, overrides = {}) => router.get('/dromic/lgu-reports', {
        tab,
        search: overrides.search ?? (tab === activeTab ? search : ''),
        validation: overrides.validation ?? (tab === activeTab ? filters.validation : ''),
        status: overrides.status ?? (tab === activeTab ? filters.status : ''),
        classification: overrides.classification ?? (tab === activeTab ? filters.classification : ''),
        series_key: overrides.series_key ?? (tab === activeTab ? filters.series_key : ''),
    }, { preserveScroll: true, preserveState: true, replace: true });
    const openReview = async (report, kind) => {
        const documentKind = kind === 'relief' ? 'request' : 'report';
        try {
            await window.axios.patch(`/lgu/dromic-sitrep/${report.id}/document-viewed`, { kind: documentKind }, { headers: { Accept: 'application/json' }, withXSRFToken: true });
        } catch {
            // Validation can continue if the non-blocking receipt call is unavailable.
        }
        setReviewReport({ report, kind });
    };
    const acknowledgeReceipt = async (report, kind) => {
        try {
            await window.axios.patch(`/lgu/dromic-sitrep/${report.id}/document-viewed`, { kind }, { headers: { Accept: 'application/json' }, withXSRFToken: true });
            router.reload({ only: ['reports'], preserveScroll: true });
        } catch (error) {
            const payload = error?.response?.data;
            const serverMessage = typeof payload?.message === 'string' && payload.message.trim()
                ? payload.message.trim()
                : (typeof payload?.error === 'string' && payload.error.trim() ? payload.error.trim() : null);
            window.alert(serverMessage || 'Unable to acknowledge receipt for this document.');
        }
    };

    return (
        <AppLayout title="LGU DROMIC Reports">
            <Head title="LGU DROMIC Reports" />
            <DrrsRequestsWorkspaceTabs active="lgu" />
            {canReviewRelief && (
                <ReliefAssessmentGateBanner
                    awaitingValidation={reliefAssessmentGate?.awaiting_validation ?? requestDashboard.awaiting_review}
                    needsLguAction={reliefAssessmentGate?.needs_lgu_action ?? requestDashboard.with_findings}
                    context="requests"
                />
            )}
            <Card className="rounded-t-none border-t-0 p-0 shadow-none">
                <div className="px-5 pt-5">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-700">Received LGU Reports</p>
                    <h1 className="mt-1 text-2xl font-black">LGU DROMIC / Situational Reports</h1>
                    <p className="mt-1 text-sm text-slate-500">Review DROMIC / SitRep completeness separately from the Request Letter (Relief Augmentation).</p>
                </div>
                <SectionTabs
                    label="LGU Report Views"
                    appearance="framed"
                    className="mt-4"
                    contentClassName="px-5"
                    value={activeTab}
                    onChange={(tab) => openTab(tab)}
                    ariaLabel="LGU DROMIC report views"
                    tabs={[
                        { id: 'incidents', label: 'All Incidents', icon: ListChecks },
                        { id: 'reports', label: 'Reports', icon: FileCheck2 },
                        { id: 'requests', label: 'Requests', icon: FilePlus2 },
                    ]}
                />
                {summaryFiltersActive && <div className="flex items-center gap-2 border-b border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-900"><Search className="h-4 w-4 shrink-0" />Filters are active. Summary cards reflect only the filtered table results.</div>}
                <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3 border-b border-slate-200 bg-slate-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-950/30">
                    {(activeTab === 'incidents' ? [
                        [ListChecks, 'Incidents reported', reportDashboard.incident_count, 'indigo'],
                        [Send, 'Submitted reports', reportDashboard.submitted, 'blue', [['Advance', Number(reportDashboard.submitted || 0) - Number(reportDashboard.signed_submitted || 0)], ['Signed', reportDashboard.signed_submitted]]],
                        [FilePlus2, 'Submitted requests', requestDashboard.submitted_total, 'violet', [['Advance', Number(requestDashboard.submitted_total || 0) - Number(requestDashboard.signed || 0)], ['Signed', requestDashboard.signed]]],
                        [AlertTriangle, 'Needs LGU Action', Number(reportDashboard.with_findings || 0) + Number(requestDashboard.with_findings || 0), 'rose', [['Reports', reportDashboard.with_findings], ['Requests', requestDashboard.with_findings]]],
                        [UploadCloud, 'Pending signed copies', Number(reportDashboard.pending_signed_copies || 0) + Number(requestDashboard.pending_signed_copies || 0), 'amber', [['Reports', reportDashboard.pending_signed_copies], ['Requests', requestDashboard.pending_signed_copies]]],
                    ] : activeTab === 'reports' ? [
                        [Send, 'Received reports', reportDashboard.submitted, 'blue'],
                        [UploadCloud, 'Pending signed copies', reportDashboard.pending_signed_copies, 'amber'],
                        [Clock3, 'Awaiting review', reportDashboard.awaiting_review, 'indigo'],
                        [CheckCircle2, 'Validated — no findings', reportDashboard.validated_no_findings, 'emerald'],
                        [AlertTriangle, 'Needs LGU Action', reportDashboard.with_findings, 'rose'],
                    ] : [
                        [FilePlus2, 'Request letters', requestDashboard.total, 'violet'],
                        [UploadCloud, 'Pending signed copy', requestDashboard.pending_signed_copies, 'amber'],
                        [Clock3, 'Awaiting DRRS review', requestDashboard.awaiting_review, 'blue'],
                        [CheckCircle2, 'Validated — no findings', requestDashboard.validated_no_findings, 'emerald'],
                        [AlertTriangle, 'Needs LGU Action', requestDashboard.with_findings, 'rose'],
                        [Send, 'Routed to DRRS', requestDashboard.routed, 'indigo'],
                    ]).map(([Icon, label, value, tone, breakdown]) => <MetricCard key={label} icon={Icon} label={label} value={value} tone={tone} breakdown={breakdown} />)}
                </div>
                <form onSubmit={(event) => { event.preventDefault(); openTab(activeTab, { search }); }} className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 md:flex-row md:flex-wrap dark:border-zinc-800 dark:bg-zinc-950/30">
                    <div className="relative min-w-[280px] flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={activeTab === 'incidents' ? 'Search document code, incident, LGU, or affected area...' : `Search document code, ${activeTab}, incident, or LGU...`} className="h-10 w-full pl-9" />
                    </div>
                    {activeTab !== 'incidents' && <select value={filters.status || ''} onChange={(event) => openTab(activeTab, { status: event.target.value })} className="h-10 min-w-[210px] font-bold">
                        <option value="">All submission statuses</option>
                        <option value="final">Final — awaiting submission</option>
                        <option value="advance_submitted">Submitted — advance copy</option>
                        <option value="submitted">Submitted — signed copies complete</option>
                    </select>}
                    {activeTab === 'reports' && <select value={filters.classification || ''} onChange={(event) => openTab(activeTab, { classification: event.target.value })} className="h-10 min-w-[190px] font-bold">
                        <option value="">All report types</option>
                        <option value="regular">Regular</option>
                        <option value="terminal">Terminal</option>
                        <option value="first_and_final">First and Final</option>
                    </select>}
                    {activeTab !== 'incidents' && <select value={filters.validation || ''} onChange={(event) => openTab(activeTab, { validation: event.target.value })} className="h-10 min-w-[220px] font-bold">
                        <option value="">All validation outcomes</option>
                        <option value="pending_review">Awaiting review</option>
                        <option value="under_review">Under review</option>
                        <option value="needs_lgu_action">Needs LGU Action</option>
                        <option value="validated_no_findings">Validated — no findings</option>
                    </select>}
                    <div className="flex shrink-0 gap-2">
                        <button type="submit" title={`Search ${activeTab}`} aria-label={`Search ${activeTab}`} className="inline-flex h-10 w-10 items-center justify-center rounded-md bg-emerald-700 text-white"><Search className="h-4 w-4" /></button>
                        <button type="button" title="Clear filters" aria-label="Clear filters" onClick={() => { setSearch(''); openTab(activeTab, { search: '', validation: '', status: '', classification: '' }); }} className="inline-flex h-10 w-10 items-center justify-center rounded-md border bg-white dark:bg-zinc-900"><X className="h-4 w-4" /></button>
                        {filters.series_key && <button type="button" onClick={() => openTab(activeTab, { series_key: '' })} className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-800">Show all incidents</button>}
                    </div>
                </form>
                <StatusLegend kind={activeTab === 'incidents' ? 'incident' : activeTab === 'requests' ? 'request' : 'report'} />
                {activeTab === 'incidents' ? <DataTable
                    columns={['Incident', 'Submitting LGU', 'Occurrence', 'Affected Barangays', 'Reports', 'Relief Request', 'Advance Copy', 'Signed Copy', 'DROMIC Validation', 'Last Reporter', 'Last Updated', 'Action']}
                    rows={incidentGroups.map((incident) => (
                        <tr key={incident.series_key} className={incident.latest_validation_status === 'needs_lgu_action' ? 'bg-rose-50 dark:bg-rose-950/20' : ''}>
                            <td className="w-[300px] max-w-[300px] px-4 py-3"><p title={incident.incident_name} className="whitespace-normal font-black leading-5">{incident.incident_name}</p><p className="mt-1 font-mono text-[11px] font-bold text-emerald-700">{incident.incident_code}</p><p className="mt-1 text-xs text-slate-500">{incidentDetailLabel(incident)}</p></td>
                            <td className="w-[220px] max-w-[220px] px-4 py-3"><p title={lguDisplayName(incident)} className="whitespace-normal font-bold leading-5">{lguDisplayName(incident)}</p></td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDate(incident.occurrence_started_at)}</td>
                            <td className="w-[300px] max-w-[300px] px-4 py-3"><p title={affectedAreasLabel(incident)} className="line-clamp-2 whitespace-normal leading-5">{affectedAreasLabel(incident)}</p></td>
                            <td className="whitespace-nowrap px-4 py-3"><p className="font-black">{incident.report_count} total</p><p className="text-xs text-slate-500">{incident.advance_count || 0} advance · {incident.signed_count || 0} signed</p></td>
                            <td className="w-24 px-3 py-3 text-center"><ReliefRequestMark included={incident.has_relief_request} /></td>
                            <td className="w-24 px-3 py-3 text-center"><DromicAdvanceCopyMark submissionStatus={incident.latest_report_status} perspective="recipient" /></td>
                            <td className="w-24 px-3 py-3 text-center"><DromicSubmissionMark submissionStatus={incident.latest_report_status} perspective="recipient" /></td>
                            <td className="w-24 px-3 py-3 text-center"><DromicValidationMark submissionStatus={incident.latest_report_status} validationStatus={incident.latest_validation_status} /></td>
                            <td className="w-[180px] max-w-[180px] px-4 py-3"><p className="line-clamp-2 whitespace-normal">{incident.last_reporter}</p></td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(incident.updated_at)}</td>
                            <td className="w-16 px-2 py-3 text-center"><button type="button" title="View all reports for this incident" aria-label="View all reports for this incident" onClick={() => openTab('reports', { series_key: incident.series_key, search: '', validation: '' })} className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-emerald-700 text-white"><ListChecks className="h-4 w-4" /></button></td>
                        </tr>
                    ))}
                /> : <DataTable
                    columns={activeTab === 'reports'
                        ? ['Report Title', 'Submitting LGU', 'Occurrence', 'Affected Areas', 'Last Reporter', 'Advance Copy', 'Signed Copy', 'DROMIC Validation', 'Corrected Version', 'Last Updated', 'Action']
                        : ['Request Code', 'Submitting LGU', 'Occurrence', 'Affected Areas', 'Last Reporter', 'Signed Request', 'DRRS Validation', 'FNI Processing', 'Corrected Version', 'Last Updated', 'Action']}
                    rows={rows.map((report) => (
                        <tr key={report.id} className={documentRowClass(report, activeTab)}>
                            <td className="w-[430px] min-w-[380px] max-w-[430px] px-4 py-3">
                                <p className="whitespace-normal break-words font-black leading-5">{activeTab === 'requests' ? report.request_reference : report.report_title || '-'}</p>
                                {report.correction_of_id && <span className="mt-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-black uppercase text-blue-700 ring-1 ring-blue-200">Current revision {Number(report.lgu_dromic_revision_number || 0)}</span>}
                                {activeTab === 'requests' && <p className="mt-1 whitespace-normal break-words text-xs font-bold leading-4 text-slate-700 dark:text-zinc-200">{report.report_title || '-'}</p>}
                                <p className="mt-1 whitespace-normal text-xs text-slate-500">{report.reference_number} · {formatDateTime(report.lgu_submitted_to_dswd_at)}</p>
                                <p className="mt-1 font-mono text-[11px] font-bold text-emerald-700">{report.incident_code}</p>
                            </td>
                            <td className="w-[220px] max-w-[220px] px-4 py-3"><p title={lguDisplayName(report)} className="whitespace-normal font-bold leading-5">{lguDisplayName(report)}</p></td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDate(report.lgu_dromic_payload?.occurrence_started_at || report.incident?.incident_date)}</td>
                            <td className="w-[300px] max-w-[300px] px-4 py-3"><p title={affectedAreasLabel(report)} className="line-clamp-2 whitespace-normal leading-5">{affectedAreasLabel(report)}</p></td>
                            <td className="w-[180px] max-w-[180px] px-4 py-3"><p className="whitespace-normal font-semibold leading-5">{report.last_reporter || '-'}</p></td>
                            {activeTab === 'reports' && <td className="w-24 px-3 py-3 text-center"><DromicAdvanceCopyMark submissionStatus={report.lgu_report_status} perspective="recipient" /></td>}
                            <td className="w-24 px-3 py-3 text-center">{activeTab === 'reports'
                                ? <DromicSubmissionMark submissionStatus={report.lgu_report_status} perspective="recipient" />
                                : <RequestSubmissionMark hasSignedRequest={Boolean(report.lgu_signed_request_path)} />}</td>
                            <td className="w-24 px-3 py-3 text-center">{activeTab === 'reports'
                                ? <DromicValidationMark submissionStatus={report.lgu_report_status} validationStatus={report.validation_status} validatedCopy={report.validated_copy} hasSignedReport={Boolean(report.lgu_signed_report_path)} />
                                : <RequestValidationMark hasSignedRequest={Boolean(report.lgu_signed_request_path)} validationStatus={report.relief_validation_status} />}</td>
                            {activeTab === 'requests' && <td className="min-w-[160px] px-4 py-3">{report.relief_request
                                ? <div><p className="font-black text-emerald-800">{report.relief_request.reference_number}</p><p className="mt-1 text-xs font-bold capitalize text-slate-500">{String(report.relief_request.status || '').replaceAll('_', ' ')}</p></div>
                                : <span className="text-xs text-slate-400">Starts after DRRS validation</span>}</td>}
                            <td className="w-24 px-3 py-3 text-center"><CorrectedVersionMark corrected={Boolean(report.correction_of_id) && (activeTab === 'requests' ? report.correction_target === 'request' && Boolean(report.lgu_signed_request_path) : report.correction_target !== 'request' && ['advance_submitted', 'submitted'].includes(report.lgu_report_status))} kind={activeTab === 'requests' ? 'request' : 'report'} validationStatus={activeTab === 'requests' ? report.relief_validation_status : report.validation_status} /></td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(report.updated_at)}</td>
                            <td className="min-w-[180px] px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    {activeTab === 'reports' && canReviewDromic && <button type="button" title="Preview the narrative and encoded data, compare signed copies, and record validation" aria-label="Preview and review DROMIC report" onClick={() => openReview(report, 'dromic')} className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-emerald-700 text-white"><ClipboardCheck className="h-4 w-4" /></button>}
                                    {activeTab === 'requests' && canReviewRelief && <button type="button" title="Preview the request letter and record DRRS validation" aria-label="Preview and review request letter" disabled={!report.lgu_signed_request_path} onClick={() => openReview(report, 'relief')} className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-violet-700 text-white disabled:cursor-not-allowed disabled:opacity-40"><FileCheck2 className="h-4 w-4" /></button>}
                                    {activeTab === 'reports' && report.can_acknowledge_report && !report.acked_at && (
                                        <button type="button" title="Acknowledge receipt of this DROMIC report (AOR)" aria-label="Acknowledge DROMIC report receipt" onClick={() => acknowledgeReceipt(report, 'report')} className="inline-flex h-8 items-center gap-1 rounded-md border border-sky-300 bg-sky-50 px-2 text-[11px] font-black text-sky-900">
                                            <Eye className="h-3.5 w-3.5" /> Ack
                                        </button>
                                    )}
                                    {activeTab === 'requests' && report.can_acknowledge_request && !report.relief_acked_at && (
                                        <button type="button" title="Acknowledge receipt of this request letter (AOR)" aria-label="Acknowledge request letter receipt" onClick={() => acknowledgeReceipt(report, 'request')} className="inline-flex h-8 items-center gap-1 rounded-md border border-sky-300 bg-sky-50 px-2 text-[11px] font-black text-sky-900">
                                            <Eye className="h-3.5 w-3.5" /> Ack
                                        </button>
                                    )}
                                    {(report.signed_document_versions || []).some((version) => version.kind === (activeTab === 'requests' ? 'request' : 'report')) && <button type="button" title="Preview previous signed document versions" aria-label="View signed document history" onClick={() => setHistoryPreview({ report, kind: activeTab === 'requests' ? 'request' : 'report' })} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700"><History className="h-4 w-4" /></button>}
                                    {activeTab === 'requests' && !canReviewRelief && <span className="text-[11px] font-semibold text-slate-500">DRRS review only</span>}
                                </div>
                                {activeTab === 'reports' && report.acked_at && <p className="mt-1 text-[11px] font-bold text-emerald-700">Acked by {report.acked_by} · {formatDateTime(report.acked_at)}</p>}
                                {activeTab === 'requests' && report.relief_acked_at && <p className="mt-1 text-[11px] font-bold text-emerald-700">Acked by {report.relief_acked_by} · {formatDateTime(report.relief_acked_at)}</p>}
                            </td>
                        </tr>
                    ))}
                />}
                {(activeTab === 'incidents' ? incidentGroups.length === 0 : rows.length === 0) && <div className="p-10 text-center text-sm text-slate-500">{activeTab === 'incidents' ? 'No incident reporting history is available yet.' : 'No LGU DROMIC / Situational Report has been submitted yet.'}</div>}
                {activeTab !== 'incidents' && reports?.links?.length > 3 && (
                    <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">
                        {reports.links.map((link, index) => link.url
                            ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                            : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}
                    </div>
                )}
            </Card>
            {reviewReport && <ReviewOutcomeModal key={`${reviewReport.report.id}-${reviewReport.kind}`} report={reviewReport.report} kind={reviewReport.kind} onClose={() => setReviewReport(null)} />}
            {historyPreview && <StaffSignedHistoryModal {...historyPreview} onClose={() => setHistoryPreview(null)} />}
        </AppLayout>
    );
}

function affectedAreasLabel(report) {
    const areas = [...new Set((report.lgu_dromic_payload?.affected_barangays || report.affected_barangays || [])
        .map((area) => String(area || '').trim())
        .filter(Boolean))];
    const available = [...new Set((report.available_barangays || [])
        .map((area) => String(area || '').trim())
        .filter(Boolean))];

    if (!areas.length) return report.barangay || report.municipality || '-';
    if (!available.length) return areas.join(', ');

    const selected = new Set(areas.map((area) => area.toLocaleLowerCase()));
    const excluded = available.filter((area) => !selected.has(area.toLocaleLowerCase()));
    if (excluded.length === 0 && areas.length === available.length) return 'All';
    if (excluded.length > 0 && excluded.length <= 2 && areas.length + excluded.length === available.length) {
        return `All except ${excluded.join(' and ')}`;
    }

    return areas.join(', ');
}

function reportSequenceLabel(report) {
    if (report.lgu_dromic_report_classification === 'terminal') return 'Terminal Report';
    if (report.lgu_dromic_report_classification === 'first_and_final') return 'First and Final Report';
    return `Report No. ${Number(report.lgu_dromic_report_number || 1)}`;
}

function lguDisplayName(record) {
    const name = displayPlaceName(record.canonical_lgu_name || record.requesting_agency || record.municipality || '-');
    const province = displayPlaceName(record.province || '');
    if (!province || name.toLocaleLowerCase().includes(province.toLocaleLowerCase())) return name;
    return `${name}, ${province}`;
}

function displayPlaceName(value) {
    return String(value || '').trim().toLocaleLowerCase()
        .replace(/(^|[\s.-])\p{L}/gu, (letter) => letter.toLocaleUpperCase())
        .replace(/\b(Mlgu|Plgu|Lgu)\b/g, (word) => word.toLocaleUpperCase());
}

function ReportDocumentStatus({ submissionStatus, validationStatus, report = null }) {
    return <DromicReportStatus
        perspective="recipient"
        submissionStatus={submissionStatus}
        classification={report?.lgu_dromic_report_classification}
        validationStatus={validationStatus}
        correctionScope={report?.correction_scope}
        seenAt={report?.seen_at}
        viewerName={report?.seen_by}
        reviewNote={report?.validation_note}
        reviewerName={report?.reviewer?.name}
        reviewedAt={report?.reviewed_at}
        isCorrectedVersion={Boolean(report?.correction_of_id) && report?.correction_target !== 'request'}
    />;
}

function RequestDocumentStatus({ report }) {
    const hasSignedRequest = Boolean(report.lgu_signed_request_path);
    return <div className="flex flex-col items-start gap-1.5">
        {report.correction_of_id && report.correction_target === 'request' && hasSignedRequest && <span className="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-1 text-xs font-black uppercase text-sky-800 ring-1 ring-sky-200"><CheckCircle2 className="h-3.5 w-3.5" />Corrected version submitted</span>}
        <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${hasSignedRequest ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>{hasSignedRequest ? 'Signed PDF received' : 'Signed PDF pending'}</span>
        {hasSignedRequest && <AugmentationBadge report={report} showWorkflow={false} />}
        {hasSignedRequest && report.relief_validation_status === 'needs_lgu_action' && report.relief_correction_scope && <span className="rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black uppercase text-rose-700 ring-1 ring-rose-200">Correction: {report.relief_correction_scope === 'both' ? 'Encoding + PDF' : report.relief_correction_scope === 'encoding' ? 'Encoded request entries' : 'PDF document'}</span>}
        {hasSignedRequest && <RoutingBadge report={report} />}
    </div>;
}

function IncidentCorrectionNotices({ incident }) {
    return <div className="mt-1.5 flex flex-col items-start gap-1">
        {incident.report_needs_lgu_action && incident.latest_validation_status !== 'needs_lgu_action' && <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black uppercase text-rose-700"><AlertTriangle className="h-3 w-3" />Report correction pending</span>}
        {incident.request_needs_lgu_action && incident.latest_relief_validation_status !== 'needs_lgu_action' && <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black uppercase text-rose-700"><AlertTriangle className="h-3 w-3" />Request correction pending</span>}
    </div>;
}

function ValidationStateBadge({ status = 'pending_review', validatedCopy = null }) {
    const validatedLabel = validatedCopy === 'signed'
        ? 'Validated — Signed PDF'
        : validatedCopy === 'advance'
            ? 'Validated — Advance Copy'
            : 'Validated — No Findings';
    const state = {
        pending_review: ['Awaiting Review', 'bg-slate-100 text-slate-700', Clock3],
        under_review: ['Under Review', 'bg-blue-100 text-blue-800', Eye],
        needs_lgu_action: ['Needs LGU Action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: [validatedLabel, 'bg-emerald-100 text-emerald-800', CheckCircle2],
        superseded: ['Superseded by corrected submission', 'bg-slate-100 text-slate-700', CheckCircle2],
    }[status] || ['Awaiting Review', 'bg-slate-100 text-slate-700', Clock3];
    const Icon = state[2];

    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${state[1]}`}><Icon className="h-3.5 w-3.5" />{state[0]}</span>;
}

function incidentDetailLabel(incident) {
    const name = String(incident.incident_name || '').trim().toLocaleLowerCase();
    const type = String(incident.incident_type || '').trim();
    const distinctType = type && type.toLocaleLowerCase() !== name ? type : '';
    const reportingState = incident.is_closed ? 'Reporting closed' : 'Active reporting';
    return [distinctType, reportingState].filter(Boolean).join(' · ');
}

function documentRowClass(report, activeTab) {
    const validationStatus = activeTab === 'requests'
        ? report.relief_validation_status
        : report.validation_status;

    if (validationStatus === 'needs_lgu_action') {
        return 'bg-rose-50 dark:bg-rose-950/20';
    }
    if (validationStatus === 'validated_no_findings') {
        return 'bg-emerald-50/40 dark:bg-emerald-950/10';
    }
    if (validationStatus === 'under_review') {
        return 'bg-blue-50/50 dark:bg-blue-950/10';
    }

    return '';
}

function StaffSignedHistoryModal({ report, kind, onClose }) {
    const versions = (report.signed_document_versions || []).filter((version) => version.kind === kind);
    const [selectedId, setSelectedId] = useState(versions[0]?.id || null);
    const selected = versions.find((version) => Number(version.id) === Number(selectedId));

    return <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/75 p-3 backdrop-blur-sm"><div className="flex h-[calc(100vh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900"><div className="flex items-start justify-between border-b p-4"><div><p className="text-xs font-black uppercase tracking-wide text-slate-500">Read-only signed document history</p><h2 className="mt-1 font-black">{kind === 'request' ? report.request_reference : report.reference_number}</h2><p className="mt-1 text-xs text-slate-500">Previous signed versions keep the PDF viewer header for navigation, download, and print.</p></div><button type="button" title="Close document history" aria-label="Close document history" onClick={onClose} className="rounded-md border p-2"><X className="h-4 w-4" /></button></div><div className="grid min-h-0 flex-1 lg:grid-cols-[280px_1fr]"><aside className="overflow-y-auto border-r p-3">{versions.map((version, index) => <button key={version.id} type="button" onClick={() => setSelectedId(version.id)} className={`mb-2 w-full rounded-lg border p-3 text-left ${Number(selectedId) === Number(version.id) ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'}`}><p className="text-xs font-black">Previous version {versions.length - index}</p><p className="mt-1 break-all text-[11px]">{version.original_name || `${kind}.pdf`}</p><p className="mt-1 text-[10px] text-slate-500">{formatDateTime(version.uploaded_at || version.created_at)}</p></button>)}</aside><div className="min-h-0 bg-slate-100">{selected ? <SignedPdfPreview src={`/lgu/dromic-sitrep/signed-history/${selected.id}`} filename={selected.original_name || `${kind}.pdf`} title="Historical signed document preview" iframeClassName="h-full min-h-[70vh] w-full flex-1 bg-slate-200" /> : <div className="flex h-full items-center justify-center text-sm text-slate-500">No previous signed versions are available.</div>}</div></div></div></div>;
}

function RequestedFniSummary({ rows = [] }) {
    const items = Array.isArray(rows) ? rows : [];
    return <aside className="overflow-y-auto border-l bg-white p-4 dark:bg-zinc-900"><p className="text-xs font-black uppercase tracking-wide text-violet-700">Requested FNIs</p><h3 className="mt-1 font-black">LGU Request Summary</h3><div className="mt-4 space-y-2">{items.length ? items.map((item, index) => <div key={`${item.fni_library_item_id || item.item_name}-${index}`} className="rounded-lg border border-violet-100 bg-violet-50 p-3 dark:border-violet-900 dark:bg-violet-950/20"><p className="font-black">{item.item_name || item.name || 'Requested FNI'}</p>{item.brand_description && <p className="mt-1 text-xs text-slate-500">{item.brand_description}</p>}<p className="mt-2 text-sm font-black text-violet-800">{Number(item.requested_quantity || 0).toLocaleString()} {item.unit_of_measure || item.unit || ''}</p></div>) : <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">No requested FNI line items were encoded.</p>}</div></aside>;
}

function ReviewOutcomeModal({ report, kind, onClose }) {
    const isRelief = kind === 'relief';
    const [reviewPanelCollapsed, setReviewPanelCollapsed] = useState(false);
    const [reviewContentTab, setReviewContentTab] = useState('narrative');
    const [reviewCopyTab, setReviewCopyTab] = useState('advance');
    const [reviewControlsCollapsed, setReviewControlsCollapsed] = useState(false);
    const [screenshotInputMessage, setScreenshotInputMessage] = useState('');
    const awaitingSignedReview = !isRelief && report.signed_review_pending;
    const hasSignedComparison = !isRelief && report.lgu_report_status === 'submitted' && Boolean(report.lgu_signed_report_path);
    const currentValidationStatus = isRelief
        ? (report.relief_validation_status || 'pending_review')
        : (report.validation_status || 'pending_review');
    const initialValidationStatus = ['under_review', 'needs_lgu_action', 'validated_no_findings'].includes(currentValidationStatus)
        ? currentValidationStatus
        : 'under_review';
    const correctionOptions = [
        {
            value: 'document',
            label: isRelief ? 'Replace the request-letter PDF only' : 'Replace the report PDF only',
            short: 'Replace PDF only',
            description: isRelief
                ? 'The LGU keeps its encoded request and FNI entries unchanged. Only the signed request-letter PDF is unlocked for replacement and DRRS review.'
                : 'The LGU keeps all encoded DROMIC data unchanged. Only the uploaded signed/advance report PDF is unlocked for replacement and another DROMIC review.',
        },
        {
            value: 'both',
            label: isRelief ? 'Correct request/FNI data and submit a new signed PDF' : 'Correct DROMIC data and submit a new signed PDF',
            short: 'Correct data and replace PDF',
            description: isRelief
                ? 'Use this when both the encoded request/FNI entries and the uploaded request letter are wrong. A correction draft is created and a replacement signed PDF is required.'
                : 'Use this when both the encoded DROMIC entries and the uploaded report PDF are wrong. A correction draft is created, the narrative is regenerated, and a replacement signed PDF is required.',
        },
    ];
    const reviewForm = useForm({
        validation_status: initialValidationStatus,
        review_note: isRelief
            ? (report.relief_validation_note || '')
            : (report.validation_note || ''),
        correction_scope: (isRelief
            ? report.relief_correction_scope
            : report.correction_scope) || 'document',
        screenshots: [],
    });
    const existingScreenshots = isRelief ? report.relief_validation_screenshots : report.validation_screenshots;
    const validationHistory = isRelief ? report.relief_validation_history : report.validation_history;
    const screenshotError = Object.entries(reviewForm.errors).find(([field]) => field === 'screenshots' || field.startsWith('screenshots.'))?.[1];
    const selectedCorrectionOption = correctionOptions.find((option) => option.value === reviewForm.data.correction_scope) || correctionOptions[0];
    const addScreenshotFiles = (files) => {
        const supported = Array.from(files || []).filter((file) => ['image/jpeg', 'image/png', 'image/webp'].includes(file.type));
        const withinSizeLimit = supported.filter((file) => file.size <= 5 * 1024 * 1024);
        const combined = [...reviewForm.data.screenshots, ...withinSizeLimit]
            .filter((file, index, list) => list.findIndex((candidate) => candidate.name === file.name && candidate.size === file.size && candidate.lastModified === file.lastModified) === index)
            .slice(0, 5);

        reviewForm.setData('screenshots', combined);
        if (supported.length !== Array.from(files || []).length) {
            setScreenshotInputMessage('Only JPG, PNG, and WebP screenshots can be attached.');
        } else if (withinSizeLimit.length !== supported.length) {
            setScreenshotInputMessage('Each screenshot must be 5 MB or smaller.');
        } else if (combined.length < reviewForm.data.screenshots.length + withinSizeLimit.length) {
            setScreenshotInputMessage('A maximum of five screenshots can be attached to one validation note.');
        } else {
            setScreenshotInputMessage(`${withinSizeLimit.length} screenshot${withinSizeLimit.length === 1 ? '' : 's'} attached.`);
        }
    };
    const pasteScreenshots = (event) => {
        const images = Array.from(event.clipboardData?.items || [])
            .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
            .map((item, index) => {
                const file = item.getAsFile();
                if (!file) return null;
                const extension = file.type === 'image/jpeg' ? 'jpg' : file.type.split('/')[1] || 'png';
                return new File([file], `pasted-screenshot-${Date.now()}-${index + 1}.${extension}`, {
                    type: file.type,
                    lastModified: Date.now(),
                });
            })
            .filter(Boolean);

        if (images.length === 0) return;
        event.preventDefault();
        addScreenshotFiles(images);
    };
    const submitReview = (event) => {
        event.preventDefault();
        reviewForm.post(`/dromic/lgu-reports/${report.id}/${isRelief ? 'relief-validation' : 'validation'}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm">
            <div className="flex h-[calc(100vh-1.5rem)] w-[96vw] max-w-[1800px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900">
                <div className="flex items-start justify-between border-b border-slate-200 p-5 dark:border-zinc-700">
                    <div>
                        <p className={`text-xs font-black uppercase tracking-wide ${isRelief ? 'text-violet-700' : 'text-emerald-700'}`}>{isRelief ? 'DRRS Request Letter Validation' : 'DROMIC / SitRep Validation'}</p>
                        <h2 className="mt-1 text-xl font-black">{isRelief ? report.request_reference : report.reference_number}</h2>
                        {isRelief && <p className="text-xs text-slate-500">Attached to {report.reference_number}</p>}
                        <p className="mt-1 text-sm text-slate-500">{report.requesting_agency || report.municipality || 'Reporting LGU'}</p>
                    </div>
                    <div className="flex items-center gap-2">{!isRelief && <button type="button" title={reviewControlsCollapsed ? 'Show document controls' : 'Collapse document controls for more viewing space'} aria-label={reviewControlsCollapsed ? 'Show document controls' : 'Collapse document controls'} onClick={() => setReviewControlsCollapsed((value) => !value)} className="rounded-md border p-2">{reviewControlsCollapsed ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}</button>}<button type="button" title={reviewPanelCollapsed ? 'Show validation panel' : 'Collapse validation panel for a wider document view'} aria-label={reviewPanelCollapsed ? 'Show validation panel' : 'Collapse validation panel'} onClick={() => setReviewPanelCollapsed((value) => !value)} className="rounded-md border p-2">{reviewPanelCollapsed ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</button><button type="button" onClick={onClose} className="rounded-md border p-2"><X className="h-4 w-4" /></button></div>
                </div>
                <div className={`grid min-h-0 flex-1 ${reviewPanelCollapsed ? 'grid-cols-1' : 'lg:grid-cols-[minmax(0,1fr)_460px]'}`}>
                    <div className="flex min-h-[45vh] min-w-0 flex-col bg-slate-100 dark:bg-zinc-950">
                        {!isRelief && !reviewControlsCollapsed && <div className="shrink-0 border-b bg-white p-3 dark:bg-zinc-900">
                            <SectionTabs
                                appearance="plain"
                                value={reviewContentTab}
                                onChange={(tab) => {
                                    setReviewContentTab(tab);
                                    if (tab === 'narrative') setReviewCopyTab('advance');
                                }}
                                ariaLabel="Report review format"
                                tabs={[
                                    { id: 'narrative', label: 'Narrative Report', icon: FileCheck2 },
                                    { id: 'encoded', label: 'Encoded Data', icon: ListChecks },
                                ]}
                            />
                        </div>}
                        {!isRelief && reviewContentTab === 'encoded'
                            ? <ReadonlyDromicReportModal report={report} embedded />
                            : <>
                                {hasSignedComparison && !reviewControlsCollapsed && (
                                    <div className="flex shrink-0 flex-wrap items-center gap-2 border-b bg-white p-3 dark:bg-zinc-900">
                                        <SectionTabs
                                            label="Document copy"
                                            appearance="plain"
                                            value={reviewCopyTab}
                                            onChange={setReviewCopyTab}
                                            ariaLabel="Document copy"
                                            tabs={[
                                                { id: 'advance', label: 'Advance Copy', icon: FileCheck2, title: 'View the advance copy generated from the encoded report data' },
                                                { id: 'signed', label: 'Signed Copy', icon: UploadCloud, title: 'View the signed PDF submitted by the LGU' },
                                            ]}
                                        />
                                    </div>
                                )}
                                {isRelief || (reviewCopyTab === 'signed' && report.lgu_signed_report_path) ? (
                                    <SignedPdfPreview
                                        src={isRelief
                                            ? `/lgu/dromic-sitrep/${report.id}/signed-copy/request`
                                            : `/lgu/dromic-sitrep/${report.id}/signed-copy/report`}
                                        filename={isRelief
                                            ? (report.request_reference || report.lgu_signed_request_name || 'Signed request letter.pdf')
                                            : (report.reference_number || report.lgu_signed_report_name || 'Signed DROMIC report.pdf')}
                                        title={isRelief ? 'Signed request letter under review' : 'Signed DROMIC report under review'}
                                    />
                                ) : (
                                    <iframe
                                        title="Advance-copy DROMIC report under review"
                                        src={`/lgu/dromic-sitrep/${report.id}/pdf?inline=1`}
                                        className="h-full min-h-[55vh] w-full flex-1"
                                    />
                                )}
                            </>}
                    </div>
                {!reviewPanelCollapsed && <form onSubmit={submitReview} onPaste={pasteScreenshots} className="space-y-4 overflow-y-auto border-l border-slate-200 p-5 dark:border-zinc-700">
                    {isRelief && <RequestedFniSummary rows={report.lgu_dromic_payload?.requested_fni_items} />}
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-950">
                        {isRelief
                            ? 'This outcome covers only the signed relief augmentation request document. Only DRRS personnel can set it, and it is separate from processing or approving assistance.'
                            : awaitingSignedReview
                                ? 'Advance copy is already cleared with no findings. Review the signed PDF now. Mark Needs LGU Action only if the signed copy has issues; otherwise choose Validated — No Findings (signed PDF).'
                                : report.lgu_signed_report_path
                                    ? 'This outcome covers the signed DROMIC PDF (and encoded data already reviewed). Advance-copy validation, if earlier, remains in history. It does not approve or deny relief augmentation.'
                                    : 'This outcome can validate the advance copy generated from encoded data even before the signed PDF is uploaded. After the LGU uploads the signed PDF, DSWD can still mark Needs LGU Action if that signed copy has issues. It does not approve or deny relief augmentation.'}
                    </div>
                    <ValidationHistoryList reportId={report.id} kind={isRelief ? 'request' : 'report'} history={validationHistory} />
                    <label className="block text-sm font-black">
                        Validation outcome
                        {['validated_no_findings', 'needs_lgu_action', 'under_review'].includes(currentValidationStatus) && (
                            <span className="ml-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                Current: {String(currentValidationStatus).replaceAll('_', ' ')}
                                {!isRelief && report.validated_copy ? ` (${report.validated_copy})` : ''}
                            </span>
                        )}
                        <select value={reviewForm.data.validation_status} onChange={(event) => reviewForm.setData('validation_status', event.target.value)} className="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                            <option value="under_review">Under {isRelief ? 'DRRS' : 'DROMIC'} Review</option>
                            <option value="needs_lgu_action">Needs LGU Action</option>
                            <option value="validated_no_findings">
                                {isRelief
                                    ? 'Validated — No Findings'
                                    : report.lgu_signed_report_path
                                        ? 'Validated — No Findings (signed PDF)'
                                        : 'Validated — No Findings (advance copy)'}
                            </option>
                        </select>
                    </label>
                    {!isRelief && reviewForm.data.validation_status === 'validated_no_findings' && (
                        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold leading-5 text-emerald-950">
                            {report.lgu_signed_report_path
                                ? 'You are clearing the signed PDF. The LGU validation check remains passed.'
                                : 'You are clearing the advance copy only. The LGU validation check stays passed unless a later signed-PDF review finds issues.'}
                        </p>
                    )}
                    {!isRelief && awaitingSignedReview && (
                        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-950">
                            Signed PDF uploaded · advance copy already validated. Choose Needs LGU Action if the signed file has defects; otherwise clear the signed PDF.
                        </p>
                    )}
                    {reviewForm.data.validation_status === 'needs_lgu_action' && <div className="block text-sm font-black">
                        <label>What must the LGU correct? <span className="text-rose-600">*</span>
                        <select value={reviewForm.data.correction_scope} onChange={(event) => reviewForm.setData('correction_scope', event.target.value)} className="mt-1 w-full rounded-md border-slate-300 text-sm font-bold" required>
                            {correctionOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                        </label>
                        <span className="mt-2 block rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs font-semibold leading-5 text-sky-950">
                            <span className="mb-1 flex items-center gap-1 font-black"><CircleHelp className="h-4 w-4 shrink-0" />What happens next</span>
                            {selectedCorrectionOption.description}
                        </span>
                        <span className="mt-3 block text-[10px] font-black uppercase tracking-wide text-slate-500">Hover or focus each option for details</span>
                        <span className="mt-2 grid grid-cols-1 gap-2">
                            {correctionOptions.map((option) => <button key={option.value} type="button" onClick={() => reviewForm.setData('correction_scope', option.value)} className={`group relative flex items-center justify-between rounded-md border px-3 py-2 text-left text-xs font-bold ${reviewForm.data.correction_scope === option.value ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'}`}>
                                <span>{option.short}</span><CircleHelp className="h-4 w-4 shrink-0 opacity-60" />
                                <span role="tooltip" className="pointer-events-none absolute bottom-[calc(100%+10px)] left-0 z-50 hidden w-full rounded-md bg-emerald-800 px-3 py-2 text-xs font-semibold leading-5 text-white shadow-xl group-hover:block group-focus:block">
                                    {option.description}
                                    <span className="absolute -bottom-1.5 left-5 h-3 w-3 rotate-45 bg-emerald-800" />
                                </span>
                            </button>)}
                        </span>
                    </div>}
                    <label className="block text-sm font-black">
                        Validation note {reviewForm.data.validation_status === 'needs_lgu_action' && <span className="text-rose-600">*</span>}
                        <textarea rows="5" value={reviewForm.data.review_note} onChange={(event) => reviewForm.setData('review_note', event.target.value)} placeholder={reviewForm.data.validation_status === 'needs_lgu_action' ? 'Clearly state the finding and what the LGU must correct...' : 'Optional note about the completed review...'} className="mt-1 w-full rounded-md border-slate-300 text-sm" required={reviewForm.data.validation_status === 'needs_lgu_action'} minLength={reviewForm.data.validation_status === 'needs_lgu_action' ? 10 : undefined} />
                    </label>
                    {Array.isArray(existingScreenshots) && existingScreenshots.length > 0 && <ValidationScreenshotGallery reportId={report.id} kind={isRelief ? 'request' : 'report'} screenshots={existingScreenshots} title="Attached validation screenshots" />}
                    {reviewForm.data.validation_status !== 'validated_no_findings' && <div tabIndex="0" className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        <div className="mb-2 flex items-start gap-2 rounded-md bg-emerald-50 p-2.5 text-xs text-emerald-950">
                            <ClipboardPaste className="mt-0.5 h-4 w-4 shrink-0" />
                            <span><strong>Paste directly:</strong> copy a screenshot or use Snipping Tool, click anywhere in this validation panel, then press <kbd className="rounded border border-emerald-300 bg-white px-1 py-0.5 font-black">Ctrl+V</kbd>.</span>
                        </div>
                        <label className="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-3 text-xs font-black text-slate-700 hover:border-emerald-300 hover:text-emerald-800">
                            <ImagePlus className="h-4 w-4" />
                            Choose screenshots from device
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                className="sr-only"
                                onChange={(event) => addScreenshotFiles(event.target.files)}
                            />
                        </label>
                        <p className="mt-2 text-[11px] leading-4 text-slate-500">Up to 5 JPG, PNG, or WebP images, 5 MB each. Add a note explaining the highlighted correction.</p>
                        {screenshotInputMessage && <p className={`mt-2 text-xs font-bold ${screenshotInputMessage.includes('attached') ? 'text-emerald-700' : 'text-amber-700'}`}>{screenshotInputMessage}</p>}
                        {reviewForm.data.screenshots.length > 0 && <div className="mt-3 space-y-1.5">
                            {reviewForm.data.screenshots.map((file) => <div key={`${file.name}-${file.lastModified}`} className="flex items-center justify-between gap-2 rounded-md bg-white px-3 py-2 text-xs">
                                <span className="min-w-0 truncate font-semibold">{file.name}</span>
                                <span className="shrink-0 text-slate-500">{(file.size / 1024 / 1024).toFixed(1)} MB</span>
                            </div>)}
                            <button type="button" onClick={() => { reviewForm.setData('screenshots', []); setScreenshotInputMessage(''); }} className="mt-1 inline-flex items-center gap-1 text-xs font-bold text-rose-700"><Trash2 className="h-3.5 w-3.5" />Clear selected screenshots</button>
                        </div>}
                    </div>}
                    {(reviewForm.errors.validation_status || reviewForm.errors.correction_scope || reviewForm.errors.review_note || screenshotError) && <p className="text-xs font-bold text-rose-600">{reviewForm.errors.validation_status || reviewForm.errors.correction_scope || reviewForm.errors.review_note || screenshotError}</p>}
                    <div className="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-zinc-700">
                        <button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                        <button disabled={reviewForm.processing} className={`inline-flex items-center gap-1 rounded-md px-4 py-2 text-sm font-black text-white disabled:opacity-50 ${isRelief ? 'bg-violet-700' : 'bg-emerald-700'}`}><CheckCircle2 className="h-4 w-4" /> Save Outcome</button>
                    </div>
                </form>}
                </div>
            </div>
        </div>
    );
}

function ValidationHistoryList({ reportId, kind, history = [] }) {
    const entries = Array.isArray(history)
        ? history.map((entry, index) => ({ entry, index })).reverse()
        : [];
    if (entries.length === 0) return <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">No previous validation notes.</div>;

    return <details className="rounded-lg border border-slate-200 bg-white">
        <summary className="flex cursor-pointer list-none items-center justify-between gap-2 p-3 text-xs font-black uppercase tracking-wide text-slate-700">
            <span className="inline-flex items-center gap-1.5"><History className="h-4 w-4" />Validation history</span>
            <span className="rounded-full bg-slate-100 px-2 py-0.5">{entries.length}</span>
        </summary>
        <div className="max-h-72 space-y-2 overflow-y-auto border-t border-slate-200 p-3">
            {entries.map(({ entry, index }) => <div key={`${entry.reviewed_at}-${index}`} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className={`rounded-full px-2 py-1 text-[10px] font-black uppercase ${entry.validation_status === 'validated_no_findings' ? 'bg-emerald-100 text-emerald-800' : entry.validation_status === 'needs_lgu_action' ? 'bg-rose-100 text-rose-800' : 'bg-blue-100 text-blue-800'}`}>
                        {entry.validation_status === 'validated_no_findings' && entry.validated_copy === 'signed'
                            ? 'validated signed pdf'
                            : entry.validation_status === 'validated_no_findings' && entry.validated_copy === 'advance'
                                ? 'validated advance copy'
                                : String(entry.validation_status || 'under_review').replaceAll('_', ' ')}
                    </span>
                    <span className="text-[10px] font-semibold text-slate-500">{formatDateTime(entry.reviewed_at)}</span>
                </div>
                {entry.review_note && <p className="mt-2 whitespace-pre-wrap text-xs font-semibold leading-5 text-slate-800">{entry.review_note}</p>}
                <p className="mt-2 text-[10px] text-slate-500">{entry.reviewer?.name || 'DSWD reviewer'}{entry.reviewer?.office ? ` · ${entry.reviewer.office}` : ''}</p>
                {Array.isArray(entry.screenshots) && entry.screenshots.length > 0 && <div className="mt-2 grid grid-cols-3 gap-2">
                    {entry.screenshots.map((screenshot, screenshotIndex) => <a key={`${screenshot.path}-${screenshotIndex}`} href={`/lgu/dromic-sitrep/${reportId}/validation-history-screenshot/${kind}/${index}/${screenshotIndex}`} target="_blank" rel="noreferrer" title="Open validation screenshot in full size" className="overflow-hidden rounded border bg-white"><img src={`/lgu/dromic-sitrep/${reportId}/validation-history-screenshot/${kind}/${index}/${screenshotIndex}`} alt={screenshot.name || `Validation screenshot ${screenshotIndex + 1}`} className="h-20 w-full object-cover" /></a>)}
                </div>}
            </div>)}
        </div>
    </details>;
}

function ValidationScreenshotGallery({ reportId, kind, screenshots = [], title = 'Validation screenshots' }) {
    if (!Array.isArray(screenshots) || screenshots.length === 0) return null;

    return <div className="rounded-lg border border-slate-200 bg-white p-3">
        <p className="mb-2 flex items-center gap-1.5 text-xs font-black uppercase tracking-wide text-slate-700"><Images className="h-4 w-4" />{title}</p>
        <div className="grid grid-cols-2 gap-2">
            {screenshots.map((screenshot, index) => <a
                key={`${screenshot.path || screenshot.name}-${index}`}
                href={`/lgu/dromic-sitrep/${reportId}/validation-screenshot/${kind}/${index}`}
                target="_blank"
                rel="noreferrer"
                title={`Open ${screenshot.name || `screenshot ${index + 1}`} in full size`}
                className="group overflow-hidden rounded-md border border-slate-200 bg-slate-100"
            >
                <img src={`/lgu/dromic-sitrep/${reportId}/validation-screenshot/${kind}/${index}`} alt={screenshot.name || `Validation screenshot ${index + 1}`} className="h-28 w-full object-cover transition group-hover:scale-[1.02]" />
                <p className="truncate bg-white px-2 py-1.5 text-[10px] font-bold text-slate-600">{screenshot.name || `Screenshot ${index + 1}`}</p>
            </a>)}
        </div>
    </div>;
}

function StatusGuide({ title, text, tone }) {
    const classes = { blue: 'border-blue-200 bg-blue-50 text-blue-950', emerald: 'border-emerald-200 bg-emerald-50 text-emerald-950', violet: 'border-violet-200 bg-violet-50 text-violet-950' };
    return <div className={`rounded-lg border p-3 ${classes[tone]}`}><p className="text-xs font-black uppercase">{title}</p><p className="mt-1 text-xs leading-5">{text}</p></div>;
}

function MetricCard({ icon: Icon, label, value = 0, tone = 'blue', breakdown = [] }) {
    const tones = {
        blue: 'border-sky-200 bg-sky-50 text-sky-800',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        indigo: 'border-indigo-200 bg-indigo-50 text-indigo-800',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        rose: 'border-rose-200 bg-rose-50 text-rose-800',
        violet: 'border-violet-200 bg-violet-50 text-violet-800',
    };
    return <div className={`relative h-[88px] overflow-hidden rounded-xl border p-3 shadow-sm ${tones[tone] || tones.blue}`}><div className="absolute -right-5 -top-5 h-16 w-16 rounded-full bg-current opacity-[0.07]" /><div className="relative flex h-full items-center justify-between gap-2"><div><p className="text-[10px] font-black uppercase tracking-wide">{label}</p><p className="mt-1 text-2xl font-black">{Number(value || 0).toLocaleString()}</p>{breakdown.length > 0 && <p className="mt-0.5 whitespace-nowrap text-[9px] font-bold opacity-80">{breakdown.map(([name, count]) => `${name}: ${Number(count || 0).toLocaleString()}`).join(' · ')}</p>}</div><span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/70"><Icon className="h-5 w-5" /></span></div></div>;
}

function reportLabel(report) {
    if (report.lgu_dromic_report_classification === 'terminal') return 'Terminal';
    if (report.lgu_dromic_report_classification === 'first_and_final') return 'First and Final';
    return `No. ${report.lgu_dromic_report_number || 1}`;
}

function SubmissionBadge({ status }) {
    const complete = status === 'submitted';
    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${complete ? 'bg-emerald-100 text-emerald-800' : 'bg-orange-100 text-orange-800'}`}>{complete ? <CheckCircle2 className="h-3.5 w-3.5" /> : <Clock3 className="h-3.5 w-3.5" />}{complete ? 'Submission · Submitted · Signed copies complete' : 'Submission · Submitted · Advance copy'}</span>;
}

function ValidationBadge({ report }) {
    const status = report.validation_status || 'pending_review';
    const validatedLabel = report.validated_copy === 'signed'
        ? 'DROMIC document · Validated — Signed PDF'
        : report.validated_copy === 'advance'
            ? (report.signed_review_pending
                ? 'DROMIC document · Advance validated · Signed PDF under review'
                : 'DROMIC document · Validated — Advance Copy')
            : 'DROMIC document · Validated — No Findings';
    const config = {
        pending_review: ['DROMIC document · Awaiting review', 'bg-slate-100 text-slate-700', Clock3],
        under_review: ['DROMIC document · Under review', 'bg-blue-100 text-blue-800', Eye],
        needs_lgu_action: ['DROMIC document · Needs LGU Action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: [validatedLabel, 'bg-emerald-100 text-emerald-800', CheckCircle2],
        superseded: ['DROMIC document · Superseded by corrected submission', 'bg-slate-100 text-slate-700', CheckCircle2],
    };
    const [label, classes, Icon] = config[status] || config.pending_review;
    return <div><span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${classes}`}><Icon className="h-3.5 w-3.5" />{label}</span>{report.acked_at ? <p className="mt-1 inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5" />Acknowledged by {report.acked_by || report.seen_by || 'DSWD recipient'} · {formatDate(report.acked_at)}</p> : (report.seen_at && <p className="mt-1 inline-flex items-center gap-1 text-[11px] font-bold text-sky-700"><Eye className="h-3.5 w-3.5" />Opened by {report.seen_by || 'DSWD recipient'} · {formatDate(report.seen_at)}</p>)}{report.validation_note && <p className="mt-2 line-clamp-2 text-xs font-semibold leading-5" title={report.validation_note}>{report.validation_note}</p>}{report.reviewer?.name && <p className="mt-1 text-[11px] text-slate-500">{report.reviewer.name}{report.reviewer.office ? ` · ${report.reviewer.office}` : ''} · {formatDate(report.reviewed_at)}</p>}</div>;
}

function AugmentationBadge({ report, showWorkflow = true }) {
    if (!report.has_relief_request) return <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-black uppercase text-slate-600">Not included</span>;
    if (report.lgu_report_status === 'advance_submitted' && !report.lgu_signed_request_path) {
        return <div><span className="rounded-full bg-rose-100 px-2 py-1 text-xs font-black uppercase text-rose-800">Signed request pending</span><p className="mt-1 text-[11px] text-slate-500">Augmentation review is not document-complete</p></div>;
    }
    const validationConfig = {
        pending_review: ['Awaiting DRRS Review', 'bg-slate-100 text-slate-700', Clock3],
        under_review: ['Under DRRS Review', 'bg-blue-100 text-blue-800', Eye],
        needs_lgu_action: ['Needs LGU Action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: ['Validated — No Findings', 'bg-emerald-100 text-emerald-800', CheckCircle2],
        superseded: ['Superseded by corrected submission', 'bg-slate-100 text-slate-700', CheckCircle2],
    };
    const workflow = {
        for_drmd_aa_review: 'Processing · DRMD review',
        for_drmd_chief_directive: 'Processing · Chief directive',
        for_drmd_aa_routing: 'Processing · Approved for routing',
        routed_to_drrs: 'Processing · Routed to DRRS',
    }[report.augmentation_status] || 'Processing · Request submitted';
    const [label, classes, Icon] = validationConfig[report.relief_validation_status] || validationConfig.pending_review;
    return <div><span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${classes}`}><Icon className="h-3.5 w-3.5" />{label}</span>{report.relief_acked_at ? <p className="mt-1 flex w-full items-center gap-1 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5 shrink-0" />Acknowledged by {report.relief_acked_by || report.relief_seen_by || 'DRRS recipient'} · {formatDateTime(report.relief_acked_at)}</p> : (report.relief_seen_at && <p className="mt-1 flex w-full items-center gap-1 text-[11px] font-bold text-sky-700"><Eye className="h-3.5 w-3.5 shrink-0" />Seen by {report.relief_seen_by || 'DRRS recipient'} · {formatDateTime(report.relief_seen_at)}</p>)}{showWorkflow && <p className="mt-1 text-[11px] font-bold text-violet-700">{workflow}</p>}{report.relief_validation_note && <p className="mt-2 line-clamp-2 text-xs font-semibold" title={report.relief_validation_note}>{report.relief_validation_note}</p>}{report.relief_reviewer?.name && <p className="mt-1 text-[11px] text-slate-500">{report.relief_reviewer.name} · {formatDateTime(report.relief_reviewed_at)}</p>}{report.relief_request && <p className="mt-1 text-[11px] font-semibold">{report.relief_request.reference_number}</p>}</div>;
}

function RoutingBadge({ report }) {
    const config = {
        for_drmd_aa_review: ['DRMD AA review', 'bg-sky-100 text-sky-800'],
        for_drmd_chief_directive: ['Chief directive', 'bg-amber-100 text-amber-800'],
        for_drmd_aa_routing: ['Approved for routing', 'bg-indigo-100 text-indigo-800'],
        routed_to_drrs: ['Routed to DRRS', 'bg-emerald-100 text-emerald-800'],
    };
    const [label, classes] = config[report.augmentation_status] || ['Request submitted', 'bg-slate-100 text-slate-700'];
    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${classes}`}><Send className="h-3.5 w-3.5" />{label}</span>;
}
