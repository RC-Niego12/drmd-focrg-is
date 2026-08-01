import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthThemeToggle from '@/Components/AuthThemeToggle';
import CreativePageLoader from '@/Components/CreativePageLoader';
import DynamicAuthBackground from '@/Components/DynamicAuthBackground';

export default function ResetPassword({ email = '', token }) {
    const form = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });
    const { flash } = usePage().props;

    const submit = (event) => {
        event.preventDefault();
        form.post('/reset-password');
    };

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8">
            <DynamicAuthBackground />
            <Head title="Reset password" />
            <CreativePageLoader active={form.processing} />
            <div className="absolute right-4 top-4 z-20 sm:right-6 sm:top-6">
                <AuthThemeToggle />
            </div>
            <form onSubmit={submit} className="relative z-10 w-full max-w-sm rounded-lg border border-slate-200 bg-white/95 p-6 shadow-2xl backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-900/95">
                <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">DROMIS account recovery</p>
                <h1 className="mt-2 text-2xl font-black text-slate-950 dark:text-white">Create new password</h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-zinc-400">Use a password you can remember but others cannot guess.</p>

                {flash?.success && <p className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">{flash.success}</p>}

                <label className="mt-5 block text-sm font-medium text-slate-800 dark:text-zinc-100">
                    Email
                    <input className="mt-1 w-full" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoFocus />
                </label>
                <label className="mt-3 block text-sm font-medium text-slate-800 dark:text-zinc-100">
                    New password
                    <input className="mt-1 w-full" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </label>
                <label className="mt-3 block text-sm font-medium text-slate-800 dark:text-zinc-100">
                    Confirm password
                    <input className="mt-1 w-full" type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                </label>

                {Object.values(form.errors).length > 0 && (
                    <div className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">
                        {Object.values(form.errors).map((error) => <p key={error}>{error}</p>)}
                    </div>
                )}

                <button disabled={form.processing} className="mt-5 w-full rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-600">
                    Reset password
                </button>
                <Link href="/login" className="mt-3 block text-center text-sm font-bold text-brand-700 hover:underline dark:text-brand-100">
                    Back to sign in
                </Link>
            </form>
        </div>
    );
}
