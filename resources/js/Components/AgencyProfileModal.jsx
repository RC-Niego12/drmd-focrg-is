import { useForm } from '@inertiajs/react';
import { Building2, Globe2, Mail, Phone, UploadCloud, X } from 'lucide-react';

const valueOrDash = (value) => String(value || '').trim() || '-';

export default function AgencyProfileModal({ user, onClose }) {
    const profile = user?.agency_profile || {};
    const form = useForm({
        agency_name: profile.agency_name || user?.office || 'Office of Civil Defense Caraga',
        acronym: profile.acronym || 'OCD Caraga',
        office_address: profile.office_address || '',
        contact_person: profile.contact_person || '',
        contact_designation: profile.contact_designation || '',
        email: profile.email || user?.email || '',
        alternate_email: profile.alternate_email || '',
        contact_number: profile.contact_number || user?.contact_number || '',
        hotline_number: profile.hotline_number || '',
        website: profile.website || '',
        facebook: profile.facebook || '',
        logo: null,
    });
    const submit = (event) => {
        event.preventDefault();
        form.post('/ocd/profile', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };
    const fields = [
        ['Agency Name', 'agency_name', 'text', true],
        ['Acronym', 'acronym'],
        ['Office Address', 'office_address'],
        ['Contact Person', 'contact_person'],
        ['Designation', 'contact_designation'],
        ['Official Email', 'email', 'email'],
        ['Alternate Email', 'alternate_email', 'email'],
        ['Contact Number', 'contact_number'],
        ['Emergency Hotline', 'hotline_number'],
        ['Official Website', 'website', 'url'],
        ['Facebook Page', 'facebook', 'url'],
    ];

    return (
        <div className="fixed inset-0 z-[90] flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 py-8 backdrop-blur-sm">
            <form onSubmit={submit} className="w-full max-w-4xl overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950">
                <header className="flex items-start justify-between border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div>
                        <p className="text-xs font-black uppercase tracking-widest text-brand-700">Agency identity and coordination details</p>
                        <h2 className="mt-1 text-2xl font-black">OCD Caraga Agency Profile</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 hover:bg-slate-200 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>
                </header>

                <div className="grid gap-6 p-5 lg:grid-cols-[220px_1fr]">
                    <aside className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="mx-auto flex h-36 w-36 items-center justify-center overflow-hidden rounded-full bg-white ring-1 ring-slate-200 dark:bg-zinc-950 dark:ring-zinc-700">
                            {profile.logo_url ? <img src={profile.logo_url} alt={profile.acronym || 'Agency logo'} className="h-full w-full object-contain p-3" /> : <Building2 className="h-16 w-16 text-slate-300" />}
                        </div>
                        <p className="mt-4 font-black">{valueOrDash(profile.acronym || profile.agency_name)}</p>
                        <label className="mt-4 inline-flex cursor-pointer items-center gap-2 rounded-md border border-brand-200 bg-white px-3 py-2 text-xs font-black text-brand-700">
                            <UploadCloud className="h-4 w-4" /> Add agency logo
                            <input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(event) => form.setData('logo', event.target.files?.[0] || null)} />
                        </label>
                        {form.data.logo && <p className="mt-2 break-all text-xs text-slate-500">{form.data.logo.name}</p>}
                    </aside>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {fields.map(([label, key, type = 'text', required = false]) => (
                            <label key={key} className={key === 'office_address' ? 'sm:col-span-2' : ''}>
                                <span className="text-xs font-black text-slate-700 dark:text-zinc-200">{label}{required ? ' *' : ''}</span>
                                <input type={type} required={required} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} className="mt-1 w-full" />
                                {form.errors[key] && <span className="mt-1 block text-xs font-bold text-rose-600">{form.errors[key]}</span>}
                            </label>
                        ))}
                        <div className="sm:col-span-2 grid gap-3 rounded-lg bg-brand-50 p-4 text-sm text-brand-900 sm:grid-cols-3 dark:bg-brand-950/30 dark:text-brand-100">
                            <span className="flex items-center gap-2"><Mail className="h-4 w-4" /> Official coordination</span>
                            <span className="flex items-center gap-2"><Phone className="h-4 w-4" /> Emergency contact</span>
                            <span className="flex items-center gap-2"><Globe2 className="h-4 w-4" /> Public information</span>
                        </div>
                    </div>
                </div>

                <footer className="flex justify-end gap-2 border-t border-slate-200 p-4 dark:border-zinc-800">
                    <button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                    <button disabled={form.processing} className="rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white disabled:opacity-60">{form.processing ? 'Saving…' : 'Save Agency Profile'}</button>
                </footer>
            </form>
        </div>
    );
}
