import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthThemeToggle from '@/Components/AuthThemeToggle';
import CreativePageLoader from '@/Components/CreativePageLoader';
import DynamicAuthBackground from '@/Components/DynamicAuthBackground';
import OtpInput from '@/Components/OtpInput';

export default function MfaSetup({ secret, qrSvg, account, issuer }) {
    const form = useForm({ code: '' });
    const [manualLoading, setManualLoading] = useState(false);
    const { systemName } = usePage().props;

    const submit = (event) => {
        event.preventDefault();
        if (form.data.code.length !== 6) {
            return;
        }
        setManualLoading(true);
        form.post('/mfa/setup', {
            onFinish: () => setManualLoading(false),
        });
    };

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-8">
            <DynamicAuthBackground />
            <div className="pointer-events-none absolute inset-x-0 top-0 z-10 h-1.5 bg-gradient-to-r from-red-600 via-amber-300 to-brand-700" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 z-10 h-1.5 bg-gradient-to-r from-brand-700 via-amber-300 to-red-600" />

            <Head title="Set up MFA" />
            <CreativePageLoader active={form.processing || manualLoading} />
            <div className="absolute right-4 top-4 z-20 sm:right-6 sm:top-6">
                <AuthThemeToggle />
            </div>

            <form
                onSubmit={submit}
                className="relative z-10 w-full max-w-md overflow-hidden rounded-xl border border-slate-200 bg-white/95 shadow-2xl backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-900/95"
            >
                <div className="h-1.5 w-full bg-gradient-to-r from-red-600 via-amber-300 to-brand-700" />

                <div className="p-6 sm:p-7">
                    <p className="mb-5 text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-200">Caraga Connect</p>

                    <h1 className="font-serif text-2xl font-bold tracking-tight text-slate-950 dark:text-white">Set up authenticator</h1>
                    <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-zinc-300">
                        Scan this QR code with Google Authenticator, Microsoft Authenticator, or a similar TOTP app, then enter the 6-digit code to finish linking.
                    </p>

                    <div className="mt-5 flex justify-center rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-zinc-700 dark:bg-zinc-950">
                        <div className="rounded-md bg-white p-2 shadow-sm dark:bg-zinc-900" dangerouslySetInnerHTML={{ __html: qrSvg }} />
                    </div>

                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-slate-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                        <p><span className="font-bold">Account:</span> {account}</p>
                        <p className="mt-1"><span className="font-bold">Issuer:</span> {issuer || systemName}</p>
                        <p className="mt-2 break-all"><span className="font-bold">Manual key:</span> {secret}</p>
                    </div>

                    <div className="mt-5">
                        <p className="mb-2 text-sm font-bold text-slate-800 dark:text-zinc-100">Verification code</p>
                        <OtpInput
                            value={form.data.code}
                            onChange={(code) => form.setData('code', code)}
                            disabled={form.processing || manualLoading}
                            autoFocus
                            error={Boolean(form.errors.code)}
                            idPrefix="mfa-setup"
                        />
                        {form.errors.code && <p className="mt-2 text-sm font-medium text-red-600 dark:text-red-400">{form.errors.code}</p>}
                    </div>

                    <button
                        disabled={form.processing || form.data.code.length !== 6}
                        className="mt-6 w-full rounded-md bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-600 dark:hover:bg-brand-500"
                    >
                        Confirm and continue
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setManualLoading(true);
                            router.post('/logout', {}, { onFinish: () => setManualLoading(false) });
                        }}
                        className="mt-3 w-full rounded-md border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Sign out
                    </button>
                </div>
            </form>
        </div>
    );
}
