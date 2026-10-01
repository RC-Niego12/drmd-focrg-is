import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, HeartHandshake, LayoutDashboard, LockKeyhole, Mail, RadioTower, ShieldCheck, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import AuthThemeToggle from '@/Components/AuthThemeToggle';
import CreativePageLoader from '@/Components/CreativePageLoader';

export default function Login() {
    const [manualLoading, setManualLoading] = useState(false);
    const [animationCycle, setAnimationCycle] = useState(0);
    const [heroFading, setHeroFading] = useState(false);
    const { systemNameLong, systemName, flash, loginAudience = 'employee' } = usePage().props;
    const isLguLogin = loginAudience === 'lgu';
    const form = useForm({
        ...(isLguLogin ? { username: '' } : { email: 'rros@example.test' }),
        password: isLguLogin ? '' : 'password',
        remember: true,
    });
    const ssoError = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('sso_error') : null;
    const heroMessage = 'Decisions move faster when the whole response is connected.';
    const heroPillars = [
        { label: 'Preparedness', detail: 'Anticipate and equip', Icon: ShieldCheck, tone: 'from-blue-500/25 to-cyan-400/5', iconTone: 'text-cyan-200' },
        { label: 'Response', detail: 'Coordinate and deliver', Icon: RadioTower, tone: 'from-rose-500/25 to-orange-400/5', iconTone: 'text-rose-200' },
        { label: 'Recovery and Rehabilitation', detail: 'Restore and rebuild', Icon: HeartHandshake, tone: 'from-amber-400/25 to-yellow-300/5', iconTone: 'text-amber-100' },
    ];
    useEffect(() => {
        if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return undefined;
        let restartTimer;
        const cycle = window.setInterval(() => {
            setHeroFading(true);
            restartTimer = window.setTimeout(() => {
                setAnimationCycle((current) => current + 1);
                setHeroFading(false);
            }, 700);
        }, 8500);
        return () => {
            window.clearInterval(cycle);
            window.clearTimeout(restartTimer);
        };
    }, []);
    useEffect(() => {
        // A logout can arrive through an Inertia/PWA navigation while the old
        // app shell was scrolled or had its mobile drawer locked. Always start
        // the standalone sign-in screen from a clean, visible viewport.
        document.body.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('overflow');
        window.requestAnimationFrame(() => window.scrollTo({ top: 0, left: 0, behavior: 'auto' }));
    }, []);
    const submit = (e) => {
        e.preventDefault();
        setManualLoading(true);
        form.post(isLguLogin ? '/login-lgu' : '/login', {
            onFinish: () => setManualLoading(false),
        });
    };

    return (
        <main className="relative h-[100dvh] min-h-[100dvh] overflow-hidden bg-slate-950 text-slate-950">
            <Head title={isLguLogin ? 'LGU Login' : 'Login'} />
            <CreativePageLoader active={form.processing || manualLoading} />
            <div className="fixed inset-x-0 top-0 z-30 grid h-1.5 grid-cols-[1fr_1fr_1fr]" aria-hidden="true"><span className="bg-[#17479e]" /><span className="bg-[#ed1c24]" /><span className="bg-[#f7d117]" /></div>
            <div className="fixed inset-0 lg:hidden" aria-hidden="true">
                <img src="/images/login-bg.png" alt="" className="h-full w-full object-cover object-center" />
                <div className="absolute inset-0 bg-slate-950/65" />
            </div>
            <div className="fixed inset-y-0 left-0 right-[500px] hidden overflow-hidden bg-[#081d1a] lg:block">
                <img src="/images/login-bg.png" alt="" className="h-full w-full object-cover object-right" />
                <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(4,36,30,.82)_0%,rgba(4,36,30,.46)_45%,rgba(4,36,30,.08)_82%,rgba(4,36,30,.18)_100%)]" />
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-slate-950/15" />
            </div>

            <div className="relative z-10 grid h-[100dvh] min-h-[100dvh] overflow-hidden lg:grid-cols-[minmax(0,1fr)_500px]">
                <section className="relative hidden min-h-screen flex-col justify-center p-10 text-white lg:flex xl:p-14">
                    <div className="absolute left-10 top-8 xl:left-14 xl:top-10"><div><p className="text-sm font-black tracking-wide">DROMIS</p><p className="text-[9px] font-bold uppercase tracking-[.2em] text-white/65">Integrated response workspace</p></div></div>
                    <div key={animationCycle} className={`login-hero-sequence max-w-xl ${heroFading ? 'login-hero-sequence--fading' : ''}`}>
                        <div className="relative max-w-xl py-2"><h2 aria-label={heroMessage} className="login-written-message relative max-w-lg font-serif text-4xl font-semibold italic leading-[1.1] tracking-tight text-white drop-shadow-xl xl:text-5xl">{heroMessage.split(' ').map((word, wordIndex, words) => { const precedingCharacters = words.slice(0, wordIndex).reduce((total, entry) => total + entry.length + 1, 0); return <span key={`${word}-${wordIndex}`} className="inline-block whitespace-nowrap">{word.split('').map((character, characterIndex) => <span aria-hidden="true" key={`${character}-${characterIndex}`} style={{ animationDelay: `${0.35 + (precedingCharacters + characterIndex) * 0.035}s` }} className="login-written-letter">{character}</span>)}{wordIndex < words.length - 1 ? <span aria-hidden="true">&nbsp;</span> : null}</span>; })}</h2><span className="login-written-rule mt-5 block h-1 w-28 rounded-full bg-gradient-to-r from-emerald-300 via-cyan-300 to-transparent" /></div>
                        <p className="mt-5 max-w-lg text-base font-medium leading-7 text-white/80">One secure platform for preparedness, relief inventory, dispatch, incident reporting, and coordinated field action across Caraga.</p>
                        <div className="relative mt-7 max-w-lg"><span className="absolute left-[12%] right-[12%] top-7 h-px bg-gradient-to-r from-[#17479e] via-[#ed1c24] to-[#f7d117] opacity-80" aria-hidden="true" /><div className="relative grid grid-cols-3 gap-3">
                            {heroPillars.map(({ label, detail, Icon, tone, iconTone }, index) => <div key={label} style={{ '--pillar-delay': `${2.75 + index * 0.55}s` }} className={`login-pillar-card group relative min-h-32 overflow-hidden rounded-2xl border border-white/20 bg-gradient-to-br ${tone} px-4 py-4 shadow-[0_16px_45px_rgba(2,12,27,.28)] backdrop-blur-md transition hover:-translate-y-1 hover:border-white/45`}><span aria-hidden="true" className="absolute -right-2 -top-5 font-sans text-6xl font-black text-white/[.06]">0{index + 1}</span><span className="relative mb-3 flex h-10 w-10 items-center justify-center rounded-xl border border-white/20 bg-slate-950/45 shadow-inner transition duration-500 group-hover:rotate-6 group-hover:scale-110"><Icon className={`h-5 w-5 ${iconTone}`} /></span><span className="relative block text-[10px] font-black uppercase leading-4 tracking-wide text-white">{label}</span><span className="relative mt-1 block text-[9px] font-semibold leading-3 text-white/55">{detail}</span><span className="login-pillar-glint absolute inset-y-0 -left-1/2 w-1/3 -skew-x-12 bg-gradient-to-r from-transparent via-white/10 to-transparent" /></div>)}
                        </div></div>
                    </div>

                    <p className="absolute bottom-8 left-10 text-xs font-semibold text-white/60 xl:left-14">DROMIS · One platform. One response. One source of truth.</p>
                </section>

                <section className="login-form-section fixed inset-0 z-20 flex h-[100dvh] min-h-0 w-full items-start justify-center overflow-x-hidden overflow-y-auto border-l border-white/10 bg-slate-950/72 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[max(3.5rem,env(safe-area-inset-top))] backdrop-blur-md sm:items-center sm:px-8 sm:py-4 lg:relative lg:inset-auto lg:z-auto lg:bg-slate-950">
                    <div className="absolute right-4 top-4 z-20"><AuthThemeToggle className="border-white/20 bg-white/90" /></div>
                    <div className="login-card-shell relative z-10 w-full max-w-md shrink-0">
                        <form onSubmit={submit} className="login-card relative overflow-hidden rounded-3xl border border-white/40 bg-white/95 shadow-[0_30px_90px_rgba(2,12,27,.45)] backdrop-blur-xl dark:border-white/10 dark:bg-zinc-950/95">
                            <div className="absolute inset-x-0 top-0 grid h-1 grid-cols-3" aria-hidden="true"><span className="bg-[#17479e]" /><span className="bg-[#ed1c24]" /><span className="bg-[#f7d117]" /></div>
                            <div className="border-b border-slate-200 px-6 pb-5 pt-7 dark:border-zinc-800 sm:px-8">
                                <div className="mb-5 flex items-center justify-between gap-4">
                                    <div className="flex h-12 min-w-0 flex-1 items-center gap-1.5 sm:gap-2.5">
                                        <img src="/images/dswd_logo_3.png" alt="DSWD Field Office Caraga" className="h-8 min-w-0 max-w-[98px] flex-1 object-contain sm:h-9 sm:max-w-[132px] dark:hidden" />
                                        <img src="/images/dswd_logo_white.png" alt="DSWD Field Office Caraga" className="hidden h-8 min-w-0 max-w-[98px] flex-1 object-contain sm:h-9 sm:max-w-[132px] dark:block" />
                                        <img src="/images/drmd-ful-logo.png" alt="DRMD" className="h-8 min-w-0 max-w-[68px] flex-1 object-contain sm:h-9 sm:max-w-[92px]" />
                                        <img src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" className="h-7 w-auto max-w-[34px] shrink-0 object-contain sm:h-8 sm:max-w-none dark:hidden" />
                                        <img src="/images/bagong_pilipinas_white.png" alt="Bagong Pilipinas" className="hidden h-7 w-auto max-w-[34px] shrink-0 object-contain sm:h-8 sm:max-w-none dark:block" />
                                    </div>
                                    <div className="rounded-xl bg-emerald-50 p-2.5 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><ShieldCheck className="h-5 w-5" /></div>
                                </div>
                                <p className="text-[10px] font-black uppercase tracking-[.22em] text-emerald-700 dark:text-emerald-300">{isLguLogin ? 'LGU access portal' : 'Authorized personnel access'}</p>
                                <h1 className="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white">Sign in to DROMIS</h1>
                                <span className="mt-3 block h-1 w-16 rounded-full bg-gradient-to-r from-[#17479e] via-[#ed1c24] to-[#f7d117]" />
                                <p className="mt-2 text-sm font-medium leading-6 text-slate-500 dark:text-zinc-400">{isLguLogin ? 'Sign in with your LGU role username (LCE, LSWDO, or LDRRMO).' : (systemNameLong || systemName || 'Secure integrated disaster response workflow.')}</p>
                            </div>
                            <div className="p-6 sm:p-8">
                    {isLguLogin && (
                        <div className="mb-5 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-xs font-bold leading-5 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100">
                            Default usernames follow your LGU code, e.g. <span className="font-black">mlgu-tubod-sdn-lce</span>, <span className="font-black">mlgu-tubod-sdn-lswdo</span>, <span className="font-black">mlgu-tubod-sdn-ldrrmo</span>. Default password is <span className="font-black">password</span>. On first login you can change it or keep it.
                        </div>
                    )}
                    <label className="mb-4 block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">{isLguLogin ? 'Personnel Username' : 'Email or Username'}<div className="relative mt-2"><span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">{isLguLogin ? <UserRound className="h-4 w-4" /> : <Mail className="h-4 w-4" />}</span><input className="w-full rounded-xl border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900" value={isLguLogin ? form.data.username : form.data.email} onChange={(e) => (isLguLogin ? form.setData('username', e.target.value) : form.setData('email', e.target.value))} /></div></label>
                    <label className="mb-2 block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">Password<div className="relative mt-2"><LockKeyhole className="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-400" /><input type="password" className="w-full rounded-xl border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></div></label>
                    <div className="mb-4 text-right">
                        <Link href="/forgot-password" className="text-xs font-bold text-brand-700 hover:underline dark:text-brand-100">Forgot password?</Link>
                    </div>
                    {(form.errors.username || form.errors.email) && (
                        <p className="mb-3 text-sm text-red-600 dark:text-red-400">
                            {form.errors.username || form.errors.email}
                        </p>
                    )}
                    {flash?.error && <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{flash.error}</p>}
                    {ssoError && <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{ssoError}</p>}
                    <button disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-700 to-teal-600 px-4 py-3 text-sm font-black text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:shadow-xl disabled:translate-y-0 disabled:opacity-60">
                        {isLguLogin ? 'Sign in to LGU Portal' : 'Sign in securely'} <ArrowRight className="h-4 w-4" />
                    </button>
                    {isLguLogin ? (
                        <Link href="/login" className="mt-3 block w-full rounded-md border border-slate-200 px-4 py-2 text-center text-sm text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">
                            DSWD employee login
                        </Link>
                    ) : (
                        <>
                            <a href="/sso/login" onClick={() => setManualLoading(true)} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-signal-blue px-4 py-2.5 text-center text-sm font-bold text-white hover:opacity-95"><ShieldCheck className="h-4 w-4" /> Sign in with Caraga Connect</a>
                            <Link href="/login-lgu" className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-200 px-4 py-2.5 text-center text-sm font-bold text-brand-700 hover:bg-emerald-50 dark:border-emerald-900 dark:text-brand-100 dark:hover:bg-emerald-950/40">
                                <Building2 className="h-4 w-4" /> LGU portal login
                            </Link>
                            <button type="button" onClick={() => { setManualLoading(true); router.visit('/', { onFinish: () => setManualLoading(false) }); }} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900"><LayoutDashboard className="h-4 w-4" /> Open dashboard</button>
                        </>
                    )}
                    <div className="mt-6 space-y-1 text-center text-[10px] leading-tight text-slate-400 dark:text-slate-500">
                        <p className="font-medium">© Copyright 2026 <span aria-hidden="true">•</span> All Rights Reserved</p>
                        <p className="font-bold text-slate-500 dark:text-slate-400">Developer: Roger L. Ongue, Computer Programmer I / DRIMS Head</p>
                    </div>
                            </div>
                </form>
                    </div>
                </section>
            </div>
        </main>
    );
}
