import { Head, Link, useForm } from '@inertiajs/react';
import { FilePlus2, UploadCloud, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatDate } from '@/Utils/dateFormat';

export default function Upload({ requestParties = [], defaultReceivedAt, transactions, submissionType = 'fni_request' }) {
    const isProposal = submissionType === 'proposal';
    const singular = isProposal ? 'Proposal' : 'FNI Request';
    const plural = isProposal ? 'Proposals' : 'FNI Requests';
    const endpoint = isProposal ? '/drmd-aa/proposals' : '/drmd-aa/requests';
    const [showModal, setShowModal] = useState(false);
    const form = useForm({ date_received_by_drmd: defaultReceivedAt, request_drn: '', request_party_id: '', office_agency_details: '', proposal_type: '', remarks: '', document: null });
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
            <Card>
                <div className="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <div>
                        <p className="text-xs font-black uppercase tracking-wide text-emerald-700">DRMD AA Transaction Registry</p>
                        <h1 className="mt-1 text-xl font-black">{plural}</h1>
                        <p className="mt-1 text-sm text-slate-500">Recent and previous {plural.toLowerCase()} endorsed to DRRS through your account.</p>
                    </div>
                    <button type="button" onClick={() => setShowModal(true)} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
                        <FilePlus2 className="h-4 w-4" /> Endorse {singular}
                    </button>
                </div>
                <DataTable columns={[
                    'Reference',
                    ...(isProposal ? ['Proposal Type'] : []),
                        'Date Received', 'DRN', 'Proposing Party', 'Office / Agency', 'Remarks', 'Status', 'Document',
                ]} rows={(transactions?.data ?? []).map((row) => (
                    <tr key={row.id}>
                        <td className="whitespace-nowrap px-4 py-3 font-black">{row.reference_number}</td>
                        {isProposal && <td className="whitespace-nowrap px-4 py-3 font-bold">{row.proposal_type}</td>}
                        <td className="whitespace-nowrap px-4 py-3">{formatDate(row.date_received_by_drmd)}</td>
                        <td className="whitespace-nowrap px-4 py-3">{row.request_drn}</td>
                        <td className="px-4 py-3 font-bold">{row.requesting_agency}</td>
                        <td className="px-4 py-3">{row.office_agency_details}</td>
                        <td className="max-w-xs px-4 py-3">{row.remarks || '-'}</td>
                        <td className="whitespace-nowrap px-4 py-3"><span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-black uppercase text-emerald-700">{row.status}</span></td>
                        <td className="whitespace-nowrap px-4 py-3">{row.source_document_url ? <a className="font-bold text-brand-700 underline" href={`/requests/${row.id}/source-document`} target="_blank" rel="noreferrer">View</a> : '-'}</td>
                    </tr>
                ))} />
                {(transactions?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">No {plural.toLowerCase()} have been endorsed yet.</div>}
                {transactions?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{transactions.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </Card>

            {showModal && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && closeModal()}>
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
                            <div><SearchableSelect label="Proposing Party *" options={partyOptions} value={form.data.request_party_id} onChange={selectParty} placeholder="Search or select proposing party" />{error('request_party_id')}</div>
                            <label className="block text-sm font-bold">Office / Agency Details *
                                <input required type="text" readOnly={hasLibraryDetails} className={`mt-1 w-full ${hasLibraryDetails ? 'bg-slate-100 text-slate-600 dark:bg-zinc-800' : ''}`} value={form.data.office_agency_details} onChange={(e) => form.setData('office_agency_details', e.target.value)} placeholder={selectedParty && !hasLibraryDetails ? 'No saved details - specify Office / Agency Details' : 'Select a requesting party first'} />
                                <span className="mt-1 block text-xs font-normal text-slate-500">{hasLibraryDetails ? 'Automatically populated from the Proposing Party Library.' : selectedParty ? 'Manual entry is required for this party.' : 'This will be populated after selecting a party.'}</span>{error('office_agency_details')}
                            </label>
                            <label className="block text-sm font-bold">Remarks <span className="font-normal text-slate-500">(optional)</span><textarea rows="3" className="mt-1 w-full" value={form.data.remarks} onChange={(e) => form.setData('remarks', e.target.value)} />{error('remarks')}</label>
                            <label className="block text-sm font-bold">{singular} Document *<input required type="file" className="mt-1 w-full" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={(e) => form.setData('document', e.target.files?.[0] ?? null)} />{error('document')}</label>
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
