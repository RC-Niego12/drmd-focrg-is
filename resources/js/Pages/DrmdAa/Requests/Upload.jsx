import { Head, Link, useForm } from '@inertiajs/react';
import { Camera, ChevronDown, ChevronUp, Eye, FileCheck2, FilePlus2, Hash, Images, Maximize2, Minimize2, Pencil, Trash2, UploadCloud, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDate } from '@/Utils/dateFormat';
import DrmdAaRequestWorkspaceTabs from '@/Components/DrmdAaRequestWorkspaceTabs';
import SectionTabs from '@/Components/SectionTabs';
import SupportingDromicSitrepPanel from '@/Components/SupportingDromicSitrepPanel';

export default function Upload({ requestParties = [], defaultReceivedAt, transactions, submissionType = 'fni_request' }) {
    const isProposal = submissionType === 'proposal';
    const singular = isProposal ? 'Proposal' : 'FNI Request';
    const plural = isProposal ? 'Proposals' : 'FNI Requests';
    const endpoint = isProposal ? '/drmd-aa/proposals' : '/drmd-aa/requests';
    const [showModal, setShowModal] = useState(false);
    const [previewRow, setPreviewRow] = useState(null);
    const [previewTab, setPreviewTab] = useState('request');
    const [photoTarget, setPhotoTarget] = useState(null);
    const linkedPhotos = useForm({ camera_photos: [] });
    const form = useForm({ date_received_by_drmd: defaultReceivedAt, request_drn: '', request_party_id: '', office_agency_details: '', proposal_type: '', remarks: '', document: null, camera_photos: [] });
    const selectedParty = requestParties.find((row) => String(row.id) === String(form.data.request_party_id));
    const hasLibraryDetails = Boolean(selectedParty?.office_agency_details?.trim());
    const partyOptions = requestParties.map((party) => ({ value: String(party.id), label: party.requesting_party }));
    const error = (field) => form.errors[field] && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors[field]}</p>;

    const closeModal = () => {
        if (!form.processing) setShowModal(false);
    };

    const selectParty = (value) => {
        const party = requestParties.find((row) => String(row.id) === String(value));
        form.setData((data) => ({ ...data, request_party_id: value, office_agency_details: party?.office_agency_details || '' }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(endpoint, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('request_drn', 'request_party_id', 'office_agency_details', 'proposal_type', 'remarks', 'document');
                setShowModal(false);
            },
        });
    };

    return (
        <AppLayout title={plural}>
            <Head title={plural} />
            <DrmdAaRequestWorkspaceTabs active={isProposal ? 'proposals' : 'requests'} />
            <Card className="rounded-t-none border-t-0 shadow-none">
                <div className="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD AA Transaction Registry</p>
                        <h1 className="mt-1 text-xl font-black">{plural}</h1>
                        <p className="mt-1 text-sm text-slate-500">{isProposal
                            ? 'Recent and previous proposals endorsed to DRRS through your account.'
                            : 'One registry for requests received by DRMD AA and validated LGU relief requests attached to DROMIC reports.'}</p>
                    </div>
                    <button type="button" onClick={() => setShowModal(true)} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
                        <FilePlus2 className="h-4 w-4" /> Endorse {singular}
                    </button>
                </div>
                <DataTable columns={[
                    { label: 'Request Details', className: 'w-[20%]' },
                    ...(isProposal ? ['Proposal Type'] : []),
                    { label: 'DRN', className: 'w-[17%]' },
                    { label: 'Requesting Party / Office', className: 'w-[27%]' },
                    { label: 'Remarks', className: 'w-[11%]' },
                    { label: 'Status', className: 'w-[14%]' },
                    { label: 'Actions', align: 'center', actionColumn: true, className: 'w-[7%] !px-2' },
                ]} className="max-w-full !overflow-x-hidden !overflow-y-hidden" tableClassName="!min-w-0 table-fixed" rows={(transactions?.data ?? []).map((row) => {
                    const needsAaAction = Boolean(row.source_lgu_dromic_report && !String(row.request_drn || '').trim());
                    return (
                    <tr key={row.id} className={needsAaAction ? 'bg-amber-50 dark:bg-amber-950/25' : ''}>
                        <td className="px-4 py-3">
                            <p className="break-words font-black">{row.source_lgu_dromic_report?.lgu_relief_request_reference || row.reference_number}</p>
                            {row.source_lgu_dromic_report && <p className="mt-1 text-[10px] font-black uppercase tracking-wide text-violet-700">Linked LGU relief request</p>}
                            <p className="mt-1 text-[11px] font-semibold text-slate-500">Received {formatDate(row.date_received_by_drmd)}</p>
                        </td>
                        {isProposal && <td className="whitespace-nowrap px-4 py-3 font-bold">{row.proposal_type}</td>}
                        <td className="break-all px-4 py-3">{row.source_lgu_dromic_report ? <LguDrnEntry row={row} /> : <span className="font-semibold">{row.request_drn || '-'}</span>}</td>
                        <td className="px-4 py-3">
                            <p className="font-bold">{row.source_lgu_dromic_report ? uniformLguName(row) : row.requesting_agency}</p>
                            <p className="mt-1 text-xs text-slate-500">{row.office_agency_details || (row.source_lgu_dromic_report ? uniformLguOfficeDetails(row) : '-')}</p>
                        </td>
                        <td className="max-w-xs px-4 py-3">{row.remarks || '-'}</td>
                        <td className="px-4 py-3"><DrmdAaStatus row={row} /></td>
                        <td className="!px-2 py-3 text-center"><div className="inline-flex flex-col items-center gap-1.5">
                            {(row.source_lgu_dromic_report || row.source_document_url || row.drmd_aa_photo_paths?.length) ? <button type="button" onClick={() => { setPreviewRow(row); setPreviewTab(initialPreviewTab(row)); }} className="dromis-tip inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700" data-tip={row.source_lgu_dromic_report ? 'Preview request letter, report, and photos' : `Preview ${singular.toLowerCase()} documents`} data-tip-side="left" aria-label="Preview documents"><Eye className="h-4 w-4" /></button> : '-'}
                            {row.source_lgu_dromic_report && <button type="button" onClick={() => { linkedPhotos.setData('camera_photos', []); setPhotoTarget(row); }} className="dromis-tip inline-flex h-8 w-8 items-center justify-center rounded-md border border-blue-200 bg-blue-50 text-blue-700" data-tip="Take and attach supporting photos" data-tip-side="left" aria-label="Take supporting photos"><Camera className="h-4 w-4" /></button>}
                        </div></td>
                    </tr>
                );})} />
                {(transactions?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No {plural.toLowerCase()} have been endorsed yet.</div>}
                {transactions?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{transactions.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </Card>

            {previewRow && <DocumentPreviewModal row={previewRow} tab={previewTab} setTab={setPreviewTab} onClose={() => setPreviewRow(null)} />}
            {photoTarget && <PhotoCaptureModal
                title="Add photos to linked LGU relief request"
                files={linkedPhotos.data.camera_photos}
                setFiles={(files) => linkedPhotos.setData('camera_photos', files)}
                processing={linkedPhotos.processing}
                errors={linkedPhotos.errors}
                onClose={() => setPhotoTarget(null)}
                onSubmit={() => linkedPhotos.post(`/drmd-aa/requests/${photoTarget.id}/photos`, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => setPhotoTarget(null),
                })}
            />}

            {showModal && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
                    <div role="dialog" aria-modal="true" aria-label={`Endorse ${singular}`} className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-2xl dark:bg-zinc-900">
                        <div className="sticky top-0 z-10 flex items-start justify-between border-b border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <div><h2 className="text-xl font-black">Endorse {singular}</h2><p className="mt-1 text-sm text-slate-500">All fields are required except Remarks.</p></div>
                            <button type="button" onClick={closeModal} className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>
                        </div>
                        <form className="space-y-4 p-5" onSubmit={submit}>
                            {isProposal && <div><SearchableSelect label="Proposal Type *" options={['FFT/W', 'NFFT/W'].map((value) => ({ value, label: value }))} value={form.data.proposal_type} onChange={(value) => form.setData('proposal_type', value)} placeholder="Select proposal type" />{error('proposal_type')}</div>}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="block text-sm font-bold">Date Received by DRMD *<input required type="date" className="mt-1 w-full" value={form.data.date_received_by_drmd} onChange={(e) => form.setData('date_received_by_drmd', e.target.value)} />{error('date_received_by_drmd')}</label>
                                <label className="block text-sm font-bold">DRN of {singular} *<input required type="text" className="mt-1 w-full" value={form.data.request_drn} onChange={(e) => form.setData('request_drn', e.target.value)} />{error('request_drn')}</label>
                            </div>
                            <div><SearchableSelect label="Requesting Party *" options={partyOptions} value={form.data.request_party_id} onChange={selectParty} placeholder="Search or select requesting party" />{error('request_party_id')}</div>
                            <label className="block text-sm font-bold">Office / Agency Details *
                                <input required type="text" readOnly={hasLibraryDetails} className={`mt-1 w-full ${hasLibraryDetails ? 'bg-slate-100 text-slate-600 dark:bg-zinc-800' : ''}`} value={form.data.office_agency_details} onChange={(e) => form.setData('office_agency_details', e.target.value)} placeholder={selectedParty && !hasLibraryDetails ? 'No saved details - specify Office / Agency Details' : 'Select a requesting party first'} />
                                <span className="mt-1 block text-xs font-normal text-slate-500">{hasLibraryDetails ? 'Automatically populated from the Proposing Party Library.' : selectedParty ? 'Manual entry is required for this party.' : 'This will be populated after selecting a party.'}</span>{error('office_agency_details')}
                            </label>
                            <label className="block text-sm font-bold">Remarks <span className="font-normal text-slate-500">(optional)</span><textarea rows="3" className="mt-1 w-full" value={form.data.remarks} onChange={(e) => form.setData('remarks', e.target.value)} />{error('remarks')}</label>
                            <label className="block text-sm font-bold">{singular} Document <span className="font-normal text-slate-500">(choose a file or take photos)</span><input type="file" className="mt-1 w-full" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={(e) => form.setData('document', e.target.files?.[0] ?? null)} />{error('document')}</label>
                            <PhotoCapturePanel files={form.data.camera_photos} setFiles={(files) => form.setData('camera_photos', files)} />
                            {error('camera_photos')}
                            <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                                <div className="flex items-center gap-2 text-sm text-slate-500"><UploadCloud className="h-5 w-5" /><span>Immediately visible to DRRS after submission.</span></div>
                                <div className="flex gap-2"><button type="button" onClick={closeModal} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button><button type="submit" disabled={form.processing} className="rounded-md bg-emerald-600 px-5 py-2 text-sm font-black text-white disabled:opacity-60">{form.processing ? 'Endorsing...' : `Endorse ${singular}`}</button></div>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function LguDrnEntry({ row }) {
    const source = row.source_lgu_dromic_report;
    const drn = useForm({ request_drn: row.request_drn || '' });
    const [editing, setEditing] = useState(!String(row.request_drn || '').trim());

    if (!editing) {
        return (
            <div className="flex items-center gap-2">
                <span className="font-black">{row.request_drn}</span>
                <button
                    type="button"
                    onClick={() => setEditing(true)}
                    aria-label="Edit official DRN"
                    className="dromis-tip inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-700"
                    data-tip="Edit official DRN"
                    data-tip-side="bottom"
                >
                    <Pencil className="h-4 w-4" />
                </button>
            </div>
        );
    }

    return (
        <form className="flex items-center gap-2" onSubmit={(event) => {
            event.preventDefault();
            drn.patch(`/drmd-aa/lgu-intake/${source.id}/drn`, {
                preserveScroll: true,
                onSuccess: () => setEditing(false),
            });
        }}>
            <input
                value={drn.data.request_drn}
                onChange={(event) => drn.setData('request_drn', event.target.value)}
                placeholder="Enter official DRN"
                className="h-9 min-w-0 flex-1 text-sm"
            />
            <button
                type="submit"
                aria-label="Save the official DRN for this LGU request"
                className="dromis-tip inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-emerald-700 text-white disabled:opacity-50"
                data-tip="Save official DRN"
                data-tip-side="bottom"
                disabled={drn.processing}
            >
                <Hash className="h-4 w-4" />
            </button>
            {String(row.request_drn || '').trim() && <button
                type="button"
                onClick={() => {
                    drn.setData('request_drn', row.request_drn);
                    drn.clearErrors();
                    setEditing(false);
                }}
                aria-label="Cancel DRN editing"
                className="dromis-tip inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600"
                data-tip="Cancel editing"
                data-tip-side="bottom"
            >
                <X className="h-4 w-4" />
            </button>}
            {drn.errors.request_drn && <p className="text-xs font-bold text-rose-600">{drn.errors.request_drn}</p>}
        </form>
    );
}

function DrmdAaStatus({ row }) {
    const source = row.source_lgu_dromic_report;
    const workflow = row.assessment_status === 'final'
        ? ['FNI workflow · Acted', 'bg-blue-50 text-blue-800']
        : row.assessment_status === 'draft' || row.status === 'under_review'
            ? ['FNI workflow · Under review', 'bg-amber-50 text-amber-800']
            : ({
                endorsed: ['FNI workflow · Endorsed to DRRS', 'bg-emerald-50 text-emerald-800'],
                submitted: ['FNI workflow · Submitted', 'bg-blue-50 text-blue-800'],
                approved: ['FNI workflow · Approved', 'bg-emerald-50 text-emerald-800'],
                partially_approved: ['FNI workflow · Partially approved', 'bg-amber-50 text-amber-800'],
                rejected: ['FNI workflow · Not approved', 'bg-rose-50 text-rose-800'],
                released: ['FNI workflow · Released', 'bg-violet-50 text-violet-800'],
            }[row.status] || [`FNI workflow · ${String(row.status || 'Pending').replaceAll('_', ' ')}`, 'bg-slate-100 text-slate-700']);
    const validation = {
        pending_review: ['Request letter · Awaiting DRRS review', 'bg-slate-100 text-slate-700'],
        under_review: ['Request letter · Under DRRS review', 'bg-blue-50 text-blue-800'],
        needs_lgu_action: ['Request letter · Needs LGU action', 'bg-rose-50 text-rose-800'],
        validated_no_findings: ['Request letter · Validated — no findings', 'bg-emerald-50 text-emerald-800'],
    }[source?.lgu_relief_validation_status];

    return (
        <div className="flex flex-col items-start gap-1.5">
            {validation && <span className={`inline-flex rounded-full px-2 py-1 text-[10px] font-black uppercase leading-tight ${validation[1]}`}>{validation[0]}</span>}
            <span className={`inline-flex rounded-full px-2 py-1 text-[10px] font-black uppercase leading-tight ${workflow[1]}`}>{workflow[0]}</span>
            {source && <span className={`inline-flex rounded-full px-2 py-1 text-[10px] font-black uppercase leading-tight ${String(row.request_drn || '').trim() ? 'bg-violet-50 text-violet-800' : 'bg-orange-50 text-orange-800'}`}>
                {String(row.request_drn || '').trim() ? 'DRMD AA · DRN recorded' : 'DRMD AA action · DRN required'}
            </span>}
        </div>
    );
}

function DocumentPreviewModal({ row, tab, setTab, onClose }) {
    const source = row.source_lgu_dromic_report;
    const isLguRequest = Boolean(source);
    const photos = row.drmd_aa_photo_paths ?? [];
    const linkedIncidentReports = Array.isArray(row.linked_incident_reports) && row.linked_incident_reports.length > 0
        ? row.linked_incident_reports
        : (Array.isArray(source?.linked_incident_reports) ? source.linked_incident_reports : []);
    const sourceIsStandaloneLump = Boolean(source?.lgu_dromic_payload?.standalone_relief_request)
        || (Array.isArray(source?.lgu_dromic_payload?.linked_incident_series_keys) && source.lgu_dromic_payload.linked_incident_series_keys.length > 0);
    const sitrepFallback = source && !sourceIsStandaloneLump ? source : null;
    const requestSrc = isLguRequest
        ? `/lgu/dromic-sitrep/${source.id}/signed-copy/request#toolbar=0&navpanes=0`
        : `/requests/${row.id}/source-document`;
    const tabs = [
        ...(isLguRequest ? [
            { id: 'request', label: 'Request Letter', icon: FileCheck2 },
            { id: 'report', label: 'Supporting DROMIC / SitRep', icon: FileCheck2 },
        ] : row.source_document_url ? [
            { id: 'request', label: 'Uploaded Document', icon: FileCheck2 },
        ] : []),
        ...(photos.length ? [
            { id: 'photos', label: `Captured Photos (${photos.length})`, icon: Images },
        ] : []),
    ];
    const [controlsCollapsed, setControlsCollapsed] = useState(false);
    const [wideDocumentView, setWideDocumentView] = useState(false);
    const enterWideDocumentView = () => {
        setWideDocumentView(true);
        setControlsCollapsed(true);
    };
    const exitWideDocumentView = () => {
        setWideDocumentView(false);
        setControlsCollapsed(false);
    };
    const viewingLabel = tab === 'photos'
        ? 'Captured Photos'
        : tab === 'report'
            ? 'Supporting DROMIC / SitRep'
            : (isLguRequest ? 'Request Letter' : 'Uploaded Document');

    return (
        <div className={`fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/65 backdrop-blur-sm ${wideDocumentView ? 'p-0' : 'p-2 sm:p-4'}`}>
            <div role="dialog" aria-modal="true" aria-label="Request document preview" className={`relative flex flex-col overflow-hidden bg-white shadow-2xl dark:bg-zinc-900 ${wideDocumentView ? 'h-screen w-screen max-w-none rounded-none' : 'h-[96vh] w-[98vw] max-w-[1700px] rounded-xl'}`}>
                <div className={`flex items-start justify-between border-b ${wideDocumentView ? 'px-4 py-3' : 'p-4'}`}>
                    <div className="min-w-0">
                        <p className="text-xs font-black uppercase tracking-wide text-emerald-700">Request document preview</p>
                        <h2 className={`mt-1 font-black ${wideDocumentView ? 'truncate text-lg' : ''}`}>{source?.lgu_relief_request_reference || row.reference_number}</h2>
                    </div>
                    <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                        <button type="button" title={controlsCollapsed ? 'Show document controls' : 'Hide document tabs for more viewing space'} aria-label={controlsCollapsed ? 'Show document controls' : 'Hide document controls'} onClick={() => setControlsCollapsed((value) => !value)} className="rounded-md border p-2">
                            {controlsCollapsed ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}
                        </button>
                        <button type="button" title={wideDocumentView ? 'Restore normal preview size' : 'Widen document view — use the full screen'} aria-label={wideDocumentView ? 'Restore normal preview size' : 'Widen document view'} onClick={() => (wideDocumentView ? exitWideDocumentView() : enterWideDocumentView())} className={`inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-xs font-black uppercase tracking-wide ${wideDocumentView ? 'border-slate-300 bg-white text-slate-700' : 'border-violet-300 bg-violet-50 text-violet-800'}`}>
                            {wideDocumentView ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                            {wideDocumentView ? 'Exit wide' : 'Widen view'}
                        </button>
                        <button type="button" onClick={onClose} className="dromis-tip rounded-md border p-2" data-tip="Close preview" data-tip-side="bottom" aria-label="Close preview"><X className="h-4 w-4" /></button>
                    </div>
                </div>
                {tabs.length > 1 && !controlsCollapsed && <div className="border-b bg-white p-3 dark:bg-zinc-900">
                    <SectionTabs appearance="plain" value={tab} onChange={setTab} ariaLabel="Request documents" tabs={tabs} />
                </div>}
                {controlsCollapsed && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2 border-b bg-white px-3 py-2 dark:bg-zinc-900">
                        <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">Viewing</p>
                        <p className="text-xs font-semibold text-slate-700">{viewingLabel}</p>
                        <button type="button" onClick={() => setControlsCollapsed(false)} className="ml-auto text-xs font-black uppercase tracking-wide text-emerald-700 hover:underline">Switch document</button>
                    </div>
                )}
                {tab === 'photos'
                    ? <div className="grid min-h-0 flex-1 auto-rows-max grid-cols-1 gap-4 overflow-y-auto bg-slate-100 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        {photos.map((path, index) => <figure key={`${path}-${index}`} className="overflow-hidden rounded-lg border bg-white shadow-sm"><img src={`/requests/${row.id}/drmd-aa-photo/${index}`} alt={`Captured supporting evidence ${index + 1}`} className="h-64 w-full object-contain bg-slate-900" /><figcaption className="px-3 py-2 text-xs font-bold text-slate-600">Supporting photo {index + 1}</figcaption></figure>)}
                    </div>
                    : isLguRequest && tab === 'report'
                        ? <SupportingDromicSitrepPanel
                            reports={linkedIncidentReports}
                            fallbackReport={sitrepFallback}
                            showCopyTabs={!controlsCollapsed}
                            defaultCopyTab="advance"
                            hideSelectors={controlsCollapsed}
                        />
                        : <iframe key={requestSrc} title="Request document" src={requestSrc} className="min-h-0 w-full flex-1 bg-slate-100" />}
                {wideDocumentView && (
                    <button type="button" onClick={exitWideDocumentView} className="absolute bottom-4 right-4 z-10 inline-flex items-center gap-1.5 rounded-md border border-violet-300 bg-violet-700 px-3 py-2 text-xs font-black uppercase tracking-wide text-white shadow-lg hover:bg-violet-800" title="Exit wide view" aria-label="Exit wide view">
                        <Minimize2 className="h-4 w-4" />
                        Exit wide
                    </button>
                )}
            </div>
        </div>
    );
}

function initialPreviewTab(row) {
    return row.source_lgu_dromic_report || row.source_document_url ? 'request' : 'photos';
}

function PhotoCaptureModal({ title, files, setFiles, processing, errors, onClose, onSubmit }) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
            <div role="dialog" aria-modal="true" aria-label={title} className="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white shadow-2xl dark:bg-zinc-900">
                <div className="sticky top-0 z-10 flex items-center justify-between border-b bg-white px-5 py-4 dark:bg-zinc-900">
                    <div><p className="text-xs font-black uppercase tracking-wide text-blue-700">Camera evidence</p><h2 className="mt-1 text-lg font-black">{title}</h2></div>
                    <button type="button" onClick={onClose} className="dromis-tip rounded-md border p-2" data-tip="Close camera" data-tip-side="bottom" aria-label="Close camera"><X className="h-4 w-4" /></button>
                </div>
                <div className="p-5"><PhotoCapturePanel files={files} setFiles={setFiles} autoStart />{errors?.camera_photos && <p className="mt-2 text-xs font-bold text-rose-600">{errors.camera_photos}</p>}</div>
                <div className="sticky bottom-0 flex justify-end gap-2 border-t bg-white p-4 dark:bg-zinc-900">
                    <button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                    <button type="button" onClick={onSubmit} disabled={processing || files.length === 0} className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-50"><UploadCloud className="h-4 w-4" /> Attach {files.length || ''} Photo{files.length === 1 ? '' : 's'}</button>
                </div>
            </div>
        </div>
    );
}

function PhotoCapturePanel({ files = [], setFiles, autoStart = false }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const fileInputRef = useRef(null);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraError, setCameraError] = useState('');

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current) videoRef.current.srcObject = null;
        setCameraOpen(false);
    };

    const startCamera = async () => {
        setCameraError('');
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            streamRef.current = stream;
            setCameraOpen(true);
            requestAnimationFrame(() => {
                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    videoRef.current.play();
                }
            });
        } catch {
            setCameraError('Camera access is unavailable. Allow camera permission or use the photo picker.');
        }
    };

    useEffect(() => {
        if (autoStart) startCamera();
        return stopCamera;
    }, []);

    const capture = () => {
        const video = videoRef.current;
        if (!video?.videoWidth) return;
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob((blob) => {
            if (!blob) return;
            const photo = new File([blob], `drmd-aa-capture-${Date.now()}.jpg`, { type: 'image/jpeg' });
            setFiles([...files, photo].slice(0, 8));
        }, 'image/jpeg', 0.9);
    };

    return (
        <section className="rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/20">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div><p className="font-black text-blue-900 dark:text-blue-100">Take supporting photos</p><p className="text-xs text-slate-500">Capture up to 8 clear images. These are supporting evidence and do not replace the official signed document.</p></div>
                <div className="flex gap-2">
                    <button type="button" onClick={cameraOpen ? stopCamera : startCamera} className="inline-flex items-center gap-2 rounded-md bg-blue-700 px-3 py-2 text-xs font-black text-white">{cameraOpen ? <><X className="h-4 w-4" /> Close Camera</> : <><Camera className="h-4 w-4" /> Open Camera</>}</button>
                    <button type="button" onClick={() => fileInputRef.current?.click()} className="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-white px-3 py-2 text-xs font-black text-blue-800"><Images className="h-4 w-4" /> Choose Photos</button>
                    <input ref={fileInputRef} type="file" accept="image/*" capture="environment" multiple className="hidden" onChange={(event) => setFiles([...files, ...Array.from(event.target.files || [])].slice(0, 8))} />
                </div>
            </div>
            {cameraError && <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">{cameraError}</p>}
            {cameraOpen && <div className="mt-4 overflow-hidden rounded-lg bg-black">
                <video ref={videoRef} muted playsInline className="max-h-[52vh] w-full object-contain" />
                <div className="flex justify-center border-t border-white/20 bg-slate-950 p-3"><button type="button" onClick={capture} disabled={files.length >= 8} className="inline-flex items-center gap-2 rounded-full bg-white px-5 py-2 text-sm font-black text-slate-900 disabled:opacity-50"><Camera className="h-5 w-5" /> Capture Photo</button></div>
            </div>}
            {files.length > 0 && <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {files.map((file, index) => <CapturedPhoto key={`${file.name}-${file.lastModified}-${index}`} file={file} index={index} onRemove={() => setFiles(files.filter((_, fileIndex) => fileIndex !== index))} />)}
            </div>}
        </section>
    );
}

function CapturedPhoto({ file, index, onRemove }) {
    const [url, setUrl] = useState('');
    useEffect(() => {
        const nextUrl = URL.createObjectURL(file);
        setUrl(nextUrl);
        return () => URL.revokeObjectURL(nextUrl);
    }, [file]);
    return <figure className="relative overflow-hidden rounded-md border bg-white"><img src={url} alt={`New supporting photo ${index + 1}`} className="h-32 w-full object-cover" /><figcaption className="px-2 py-1 text-[11px] font-bold">Photo {index + 1}</figcaption><button type="button" onClick={onRemove} className="dromis-tip absolute right-1 top-1 rounded-full bg-rose-600 p-1.5 text-white shadow" data-tip="Remove photo" data-tip-side="bottom" aria-label={`Remove photo ${index + 1}`}><Trash2 className="h-3.5 w-3.5" /></button></figure>;
}

function uniformLguName(row) {
    const level = String(row.lgu_level || '').toLowerCase();
    const prefix = level.includes('province') || level === 'plgu'
        ? 'PLGU'
        : level.includes('city') || level === 'clgu'
            ? 'CLGU'
            : 'MLGU';
    const locality = titleCase(row.municipality || row.lgu || row.requesting_agency || 'LGU');
    const province = provinceAbbreviation(row.province);

    return `${prefix} - ${locality}${province ? `, ${province}` : ''}`;
}

function uniformLguOfficeDetails(row) {
    const locality = titleCase(row.municipality || row.lgu || row.requesting_agency || 'LGU');
    const province = titleCase(row.province || '');
    return `Local Government Unit of ${locality}${province ? `, ${province}` : ''}`;
}

function titleCase(value) {
    return String(value || '').toLocaleLowerCase().replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase());
}

function provinceAbbreviation(value) {
    const normalized = String(value || '').trim().toLocaleLowerCase();
    return {
        'agusan del norte': 'ADN',
        'agusan del sur': 'ADS',
        'dinagat islands': 'PDI',
        'province of dinagat islands': 'PDI',
        'surigao del norte': 'SDN',
        'surigao del sur': 'SDS',
    }[normalized] || titleCase(value);
}
