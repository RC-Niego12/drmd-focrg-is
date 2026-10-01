import { Head, useForm, usePage } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import AuthThemeToggle from '@/Components/AuthThemeToggle';

export default function LguCredentialSetup({
    username,
    name,
    role,
    lgu_name: lguName,
    password_is_default: passwordIsDefault,
    default_password: defaultPassword,
}) {
    const { flash } = usePage().props;
    const form = useForm({
        username: username || '',
        password: '',
        password_confirmation: '',
    });
    const retainForm = useForm({});

    const submitChange = (event) => {
        event.preventDefault();
        form.post('/lgu/credentials', { preserveScroll: true });
    };

    const retain = () => {
        retainForm.post('/lgu/credentials/retain');
    };

    return (
        <main className="relative flex min-h-[100dvh] items-center justify-center bg-slate-950 px-4 py-10 text-slate-950">
            <Head title="Review LGU login" />
            <div className="absolute right-4 top-4"><AuthThemeToggle className="border-white/20 bg-white/90" /></div>
            <div className="w-full max-w-lg rounded-3xl border border-white/40 bg-white/95 p-6 shadow-2xl dark:border-white/10 dark:bg-zinc-950/95 sm:p-8">
                <div className="mb-5 flex items-center gap-3">
                    <span className="rounded-xl bg-emerald-50 p-2.5 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <ShieldCheck className="h-5 w-5" />
                    </span>
                    <div>
                        <p className="text-[10px] font-black uppercase tracking-[.2em] text-emerald-700 dark:text-emerald-300">First login review</p>
                        <h1 className="text-2xl font-black text-slate-950 dark:text-white">Confirm your LGU credentials</h1>
                    </div>
                </div>

                <p className="text-sm font-medium text-slate-600 dark:text-zinc-300">
                    Signed in as <span className="font-black text-slate-900 dark:text-white">{name}</span>
                    {lguName ? <> · {lguName}</> : null}
                    {role ? <> · {String(role).replaceAll('_', ' ')}</> : null}.
                </p>

                <div className="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                    Current username: <span className="font-black">{username}</span>
                    {passwordIsDefault && defaultPassword ? (
                        <> · default password: <span className="font-black">{defaultPassword}</span></>
                    ) : null}
                </div>

                {flash?.success && <p className="mt-3 text-sm font-semibold text-emerald-700">{flash.success}</p>}
                {flash?.error && <p className="mt-3 text-sm font-semibold text-red-600">{flash.error}</p>}

                <form onSubmit={submitChange} className="mt-6 space-y-4">
                    <label className="block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">
                        New username
                        <input
                            className="mt-2 w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            value={form.data.username}
                            onChange={(event) => form.setData('username', event.target.value.toLowerCase())}
                            autoComplete="username"
                        />
                        {form.errors.username && <span className="mt-1 block text-xs font-bold normal-case text-red-600">{form.errors.username}</span>}
                    </label>
                    <label className="block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">
                        New password
                        <input
                            type="password"
                            className="mt-2 w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            autoComplete="new-password"
                        />
                        {form.errors.password && <span className="mt-1 block text-xs font-bold normal-case text-red-600">{form.errors.password}</span>}
                    </label>
                    <label className="block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">
                        Confirm new password
                        <input
                            type="password"
                            className="mt-2 w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            value={form.data.password_confirmation}
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            autoComplete="new-password"
                        />
                    </label>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-700 to-teal-600 px-4 py-3 text-sm font-black text-white disabled:opacity-60"
                    >
                        <KeyRound className="h-4 w-4" />
                        {form.processing ? 'Saving...' : 'Change credentials'}
                    </button>
                </form>

                <button
                    type="button"
                    onClick={retain}
                    disabled={retainForm.processing}
                    className="mt-3 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-900"
                >
                    Keep current username and password
                </button>
            </div>
        </main>
    );
}
