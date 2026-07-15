import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthThemeToggle from '@/Components/AuthThemeToggle';
import CreativePageLoader from '@/Components/CreativePageLoader';
import DynamicAuthBackground from '@/Components/DynamicAuthBackground';

export default function Login() {
    const form = useForm({ email: 'rros@example.test', password: 'password', remember: true });
    const [manualLoading, setManualLoading] = useState(false);
    const { systemNameLong, systemName, flash } = usePage().props;
    const ssoError = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('sso_error') : null;
    const submit = (e) => {
        e.preventDefault();
        setManualLoading(true);
        form.post('/login', {
            onFinish: () => setManualLoading(false),
        });
    };

    return (
        <div className="relative flex min-h-screen flex-col items-center overflow-hidden px-4 pb-6 pt-6 sm:pt-8">
            <DynamicAuthBackground />
            <Head title="Login" />
            <CreativePageLoader active={form.processing || manualLoading} />
            <div className="absolute right-4 top-4 z-20 sm:right-6 sm:top-6">
                <AuthThemeToggle />
            </div>
            <div className="relative z-10 mb-4 w-full max-w-3xl px-2 text-center sm:mb-6" aria-label="One platform. One response. One source of truth.">
                <div className="mb-1.5 flex items-center justify-center gap-2 font-sans text-[9px] font-black uppercase tracking-[0.28em] text-brand-700 drop-shadow-sm dark:text-emerald-300">
                    <span className="relative flex h-2 w-2">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-500 opacity-60 motion-reduce:animate-none dark:bg-emerald-300" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-brand-600 dark:bg-emerald-300" />
                    </span>
                    Our shared commitment
                </div>
                <p className="sr-only">One platform. One response. One source of truth.</p>
                <div aria-hidden="true" className="flex min-h-7 w-full items-center justify-center overflow-visible whitespace-nowrap font-serif text-[12px] font-bold italic tracking-[0.01em] text-slate-800 drop-shadow-sm dark:text-white dark:drop-shadow-[0_2px_6px_rgba(0,0,0,0.55)] sm:text-base">
                    <span className="login-mission-typewriter inline-block">One platform. One response. One source of truth.</span>
                </div>
                <div aria-hidden="true" className="relative mx-auto mt-1 h-px w-64 max-w-[75vw] overflow-hidden bg-brand-700/20 dark:bg-white/20">
                    <span className="login-mission-sweep absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-transparent via-brand-500 to-transparent dark:via-emerald-300" />
                </div>
            </div>
            <div className="relative z-10 flex w-full max-w-sm flex-1 items-center py-4">
                <form onSubmit={submit} className="w-full rounded-lg border border-slate-200 bg-white/95 p-6 shadow-2xl backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-900/95">
                    <div className="mb-6 border-b border-slate-100 pb-5 dark:border-zinc-800">
                        <div className="mb-5 flex h-16 flex-nowrap items-center justify-center gap-2 px-1">
                            <div className="flex h-12 min-w-0 items-center justify-center">
                                <img src="/images/dswd_logo_3.png" alt="DSWD Field Office Caraga" className="h-full w-auto max-w-[120px] object-contain dark:hidden" />
                                <img src="/images/dswd_logo_white.png" alt="DSWD Field Office Caraga" className="hidden h-full w-auto max-w-[120px] object-contain dark:block" />
                            </div>
                            <div className="flex h-12 min-w-0 items-center justify-center">
                                <img src="/images/drmd-ful-logo.png" alt="Disaster Response Management Division" className="h-full w-auto max-w-[112px] object-contain" />
                            </div>
                            <div className="flex h-12 min-w-0 items-center justify-start overflow-hidden">
                                <img src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" className="h-full w-auto max-w-12 object-contain" />
                            </div>
                        </div>
                        <div className="text-sm font-semibold uppercase text-brand-700 dark:text-brand-100">DSWD FO Caraga</div>
                        <h1 className="text-2xl font-bold text-slate-950 dark:text-white">{systemNameLong || systemName}</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">Secure integrated disaster response workflow.</p>
                    </div>
                    <label className="mb-3 block text-sm font-medium text-slate-800 dark:text-zinc-100">Email<input className="mt-1 w-full" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></label>
                    <label className="mb-4 block text-sm font-medium text-slate-800 dark:text-zinc-100">Password<input type="password" className="mt-1 w-full" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></label>
                    {form.errors.email && <p className="mb-3 text-sm text-red-600 dark:text-red-400">{form.errors.email}</p>}
                    {flash?.error && <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{flash.error}</p>}
                    {ssoError && <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{ssoError}</p>}
                    <button disabled={form.processing} className="w-full rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-600">Sign in</button>
                    <a href="/sso/login" onClick={() => setManualLoading(true)} className="mt-3 block w-full rounded-md bg-signal-blue px-4 py-2 text-center text-sm font-semibold text-white hover:opacity-95">Sign in with Caraga Connect</a>
                    <button type="button" onClick={() => { setManualLoading(true); router.visit('/', { onFinish: () => setManualLoading(false) }); }} className="mt-3 w-full rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">Open dashboard</button>
                </form>
            </div>
        </div>
    );
}
