import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CreativePageLoader from '@/Components/CreativePageLoader';
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
            <div className="pointer-events-none absolute inset-0 bg-[#071B45]" />
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(206,17,38,0.45),transparent_45%),radial-gradient(circle_at_85%_10%,rgba(252,209,22,0.28),transparent_40%),radial-gradient(circle_at_70%_90%,rgba(10,42,107,0.9),transparent_50%)]" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-2 bg-gradient-to-r from-[#CE1126] via-[#FCD116] to-[#0A2A6B]" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-2 bg-gradient-to-r from-[#0A2A6B] via-[#FCD116] to-[#CE1126]" />

            <Head title="Set up MFA" />
            <CreativePageLoader active={form.processing || manualLoading} />

            <form
                onSubmit={submit}
                className="relative z-10 w-full max-w-md overflow-hidden rounded-xl border border-white/20 bg-white shadow-[0_24px_60px_rgba(0,0,0,0.35)]"
            >
                <div className="h-1.5 w-full bg-gradient-to-r from-[#CE1126] via-[#FCD116] to-[#0A2A6B]" />

                <div className="p-6 sm:p-7">
                    <p className="mb-5 text-xs font-bold uppercase tracking-[0.18em] text-[#0A2A6B]">Caraga Connect</p>

                    <h1 className="font-serif text-2xl font-bold tracking-tight text-[#0A2A6B]">Set up authenticator</h1>
                    <p className="mt-2 text-sm leading-relaxed text-slate-600">
                        Scan this QR code with Google Authenticator, Microsoft Authenticator, or a similar TOTP app, then enter the 6-digit code to finish linking.
                    </p>

                    <div className="mt-5 flex justify-center rounded-lg border border-[#0A2A6B]/15 bg-[#F8F6EF] p-4">
                        <div className="rounded-md bg-white p-2 shadow-sm" dangerouslySetInnerHTML={{ __html: qrSvg }} />
                    </div>

                    <div className="mt-4 rounded-lg border border-[#FCD116]/60 bg-[#FFF8D9] p-3 text-xs text-[#0A2A6B]">
                        <p><span className="font-bold">Account:</span> {account}</p>
                        <p className="mt-1"><span className="font-bold">Issuer:</span> {issuer || systemName}</p>
                        <p className="mt-2 break-all"><span className="font-bold">Manual key:</span> {secret}</p>
                    </div>

                    <div className="mt-5">
                        <p className="mb-2 text-sm font-bold text-[#0A2A6B]">Verification code</p>
                        <OtpInput
                            value={form.data.code}
                            onChange={(code) => form.setData('code', code)}
                            disabled={form.processing || manualLoading}
                            autoFocus
                            error={Boolean(form.errors.code)}
                            idPrefix="mfa-setup"
                        />
                        {form.errors.code && <p className="mt-2 text-sm font-medium text-[#CE1126]">{form.errors.code}</p>}
                    </div>

                    <button
                        disabled={form.processing || form.data.code.length !== 6}
                        className="mt-6 w-full rounded-md bg-[#0A2A6B] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#081F52] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Confirm and continue
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setManualLoading(true);
                            router.post('/logout', {}, { onFinish: () => setManualLoading(false) });
                        }}
                        className="mt-3 w-full rounded-md border border-[#CE1126]/30 bg-white px-4 py-2.5 text-sm font-semibold text-[#CE1126] transition hover:bg-[#CE1126]/5"
                    >
                        Sign out
                    </button>
                </div>
            </form>
        </div>
    );
}
